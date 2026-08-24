<?php
declare(strict_types=1);

namespace PenguLab;

use RuntimeException;

final class IntegrationManager
{
    private HttpClient $http;

    public function __construct(
        private Database $db,
        private AddonManager $addons,
        private Secrets $secrets,
    ) {
        $this->http = new HttpClient();
    }

    public function list(): array
    {
        $rows = $this->db->pdo()->query('SELECT * FROM integrations WHERE enabled = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll();
        foreach ($rows as &$row) {
            $row = $this->publicRow($row);
        }
        unset($row);
        return $rows;
    }

    public function save(array $input): array
    {
        $id = trim((string)($input['id'] ?? '')) ?: Database::uuid('integration');
        $type = trim((string)($input['type'] ?? ''));
        $typeInfo = $this->typeInfo($type);
        if (!$typeInfo) throw new RuntimeException('Integration package is not installed.');

        $name = mb_substr(trim((string)($input['name'] ?? '')), 0, 100);
        if ($name === '') throw new RuntimeException('Integration name is required.');
        $baseUrl = trim((string)($input['base_url'] ?? ''));
        $username = mb_substr(trim((string)($input['username'] ?? '')), 0, 180);
        $verifyTls = !array_key_exists('verify_tls', $input) || (bool)$input['verify_tls'];

        $existing = $this->full($id);
        $secretPayload = $existing['_secrets'] ?? [];
        $config = is_array($input['config'] ?? null) ? $input['config'] : [];

        foreach (($typeInfo['fields'] ?? []) as $field) {
            if (!is_array($field)) continue;
            $key = (string)($field['key'] ?? '');
            if ($key === '') continue;
            if (!empty($field['secret'])) {
                $value = (string)($input['secrets'][$key] ?? '');
                if ($value !== '') $secretPayload[$key] = $value;
            } elseif (!in_array($key, ['base_url', 'username', 'name', 'verify_tls'], true) && array_key_exists($key, $input)) {
                $config[$key] = $input[$key];
            }
        }

        foreach (($typeInfo['fields'] ?? []) as $field) {
            if (!is_array($field) || empty($field['required'])) continue;
            $key = (string)($field['key'] ?? '');
            $value = match ($key) {
                'name' => $name,
                'base_url' => $baseUrl,
                'username' => $username,
                'verify_tls' => true,
                default => !empty($field['secret'])
                    ? (string)($secretPayload[$key] ?? '')
                    : (string)($config[$key] ?? $input[$key] ?? ''),
            };
            if ($value === '') {
                $label = trim((string)($field['label'] ?? $key));
                throw new RuntimeException(($label !== '' ? $label : $key) . ' is required.');
            }
        }

        if ($baseUrl !== '') {
            $parts = parse_url($baseUrl);
            if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                throw new RuntimeException('Integration URL must use HTTP or HTTPS.');
            }
            $baseUrl = rtrim($baseUrl, '/');
        }

        $now = gmdate(DATE_ATOM);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO integrations(id,type,name,base_url,username,secret_enc,verify_tls,config_json,enabled,last_status,last_error,last_checked_at,created_at,updated_at) '
            . 'VALUES(:id,:type,:name,:base_url,:username,:secret_enc,:verify_tls,:config_json,1,\'unknown\',\'\',NULL,:created,:updated) '
            . 'ON CONFLICT(id) DO UPDATE SET type=excluded.type,name=excluded.name,base_url=excluded.base_url,username=excluded.username,secret_enc=excluded.secret_enc,verify_tls=excluded.verify_tls,config_json=excluded.config_json,enabled=1,updated_at=excluded.updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'base_url' => $baseUrl,
            'username' => $username,
            'secret_enc' => $this->secrets->encrypt($secretPayload),
            'verify_tls' => $verifyTls ? 1 : 0,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created' => $existing['created_at'] ?? $now,
            'updated' => $now,
        ]);

        // A traffic graph is tied to the selected OPNsense interface. When that selection
        // changes, discard the old counter series instead of mixing two interfaces.
        if ($type === 'opnsense' && $existing) {
            $oldTraffic = (string)(($existing['config']['traffic_interface'] ?? 'auto'));
            $newTraffic = (string)(($config['traffic_interface'] ?? 'auto'));
            if ($oldTraffic !== $newTraffic || (string)($existing['base_url'] ?? '') !== $baseUrl) {
                $this->db->pdo()->prepare('DELETE FROM integration_metric_samples WHERE integration_id=:id AND metric=:metric')
                    ->execute(['id'=>$id,'metric'=>'traffic']);
                $this->db->pdo()->prepare('DELETE FROM integration_widget_cache WHERE integration_id=:id')->execute(['id'=>$id]);
            }
        }

        return $this->publicRow($this->row($id));
    }

    public function delete(string $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM integrations WHERE id = :id')->execute(['id' => $id]);
    }

    public function test(string $id): array
    {
        $result = $this->execute($id, 'summary');
        $this->updateStatus($id, 'online', '');
        return $result;
    }

    public function action(string $id, string $action): array
    {
        $allowed = ['protection_enable', 'protection_disable', 'protection_pause_300'];
        if (!in_array($action, $allowed, true)) {
            throw new RuntimeException('Unsupported integration action.');
        }
        $integration = $this->full($id);
        if (!$integration || !in_array((string)$integration['type'], ['pihole', 'adguardhome'], true)) {
            throw new RuntimeException('This integration does not expose protection controls.');
        }
        return $this->execute($id, 'action:' . $action);
    }

    public function execute(string $id, string $mode = 'summary'): array
    {
        $integration = $this->full($id);
        if (!$integration) throw new RuntimeException('Integration not found.');
        if (empty($integration['enabled'])) throw new RuntimeException('Integration is disabled.');
        $manifest = $this->manifestForType((string)$integration['type']);
        if (!$manifest) throw new RuntimeException('Required PenguHub package is not installed.');
        $connectorName = trim((string)($manifest['connector'] ?? ''));
        if ($connectorName === '') throw new RuntimeException('This integration has no connector.');
        $file = $manifest['_path'] . '/' . ltrim($connectorName, '/');
        if (!is_file($file)) throw new RuntimeException('Integration connector is missing.');
        $connector = require $file;
        if (!is_callable($connector)) throw new RuntimeException('Invalid integration connector.');
        try {
            $result = $connector($integration, $this->http, $mode);
            if (!is_array($result)) $result = ['value' => $result];
            $this->updateStatus($id, 'online', '');
            return $result;
        } catch (\Throwable $e) {
            $this->updateStatus($id, 'offline', $e->getMessage());
            throw $e;
        }
    }

    public function full(string $id): ?array
    {
        $row = $this->row($id);
        if (!$row) return null;
        $row['config'] = json_decode((string)$row['config_json'], true) ?: [];
        $row['_secrets'] = $this->secrets->decrypt((string)$row['secret_enc']);
        $row['verify_tls'] = (bool)$row['verify_tls'];
        return $row;
    }

    private function row(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function publicRow(array $row): array
    {
        $secrets = $this->secrets->decrypt((string)($row['secret_enc'] ?? ''));
        $row['config'] = json_decode((string)($row['config_json'] ?? '{}'), true) ?: [];
        $row['has_secrets'] = array_map(static fn($v): bool => $v !== '', $secrets);
        $row['verify_tls'] = (bool)$row['verify_tls'];
        $row['enabled'] = (bool)$row['enabled'];
        unset($row['secret_enc'], $row['config_json']);
        return $row;
    }

    private function typeInfo(string $type): ?array
    {
        foreach ($this->addons->integrationTypes() as $info) {
            if (($info['type'] ?? '') === $type) return $info;
        }
        return null;
    }

    private function manifestForType(string $type): ?array
    {
        foreach ($this->addons->enabledManifests() as $manifest) {
            if (($manifest['integration']['type'] ?? '') === $type) return $manifest;
        }
        return null;
    }

    private function updateStatus(string $id, string $status, string $error): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE integrations SET last_status=:status,last_error=:error,last_checked_at=:checked WHERE id=:id');
        $stmt->execute([
            'status' => $status,
            'error' => mb_substr($error, 0, 500),
            'checked' => gmdate(DATE_ATOM),
            'id' => $id,
        ]);
    }
}
