<?php
declare(strict_types=1);

namespace PenguLab;

use PDO;
use RuntimeException;

final class AddonManager
{
    public function __construct(private Database $db, private string $addonsDir)
    {
        $this->syncInstalledVersions();
        $this->runPendingInstalls();
    }

    public function available(): array
    {
        $items = [];
        foreach (glob(rtrim($this->addonsDir, '/') . '/*/manifest.json') ?: [] as $file) {
            $manifest = json_decode((string)file_get_contents($file), true);
            if (!is_array($manifest)) continue;
            $id = trim((string)($manifest['id'] ?? ''));
            if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) continue;
            $manifest['_path'] = dirname($file);
            $items[$id] = $manifest;
        }
        ksort($items, SORT_NATURAL | SORT_FLAG_CASE);
        return $items;
    }

    public function all(): array
    {
        $available = $this->available();
        $installedRows = $this->db->pdo()->query('SELECT * FROM addons')->fetchAll();
        $installed = [];
        foreach ($installedRows as $row) {
            $installed[(string)$row['id']] = $row;
        }

        $out = [];
        foreach ($available as $id => $manifest) {
            $row = $installed[$id] ?? null;
            $public = $manifest;
            unset($public['_path'], $public['entrypoint'], $public['api'], $public['connector']);
            $public['installed'] = $row !== null;
            $public['enabled'] = $row !== null && (int)$row['enabled'] === 1;
            $public['installed_version'] = $row['version'] ?? null;
            $out[] = $public;
        }
        return $out;
    }

    public function enabled(string $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT enabled FROM addons WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (int)($stmt->fetchColumn() ?: 0) === 1;
    }

    public function manifest(string $id): ?array
    {
        $all = $this->available();
        return $all[$id] ?? null;
    }

    public function install(string $id): array
    {
        $manifest = $this->manifest($id);
        if (!$manifest) throw new RuntimeException('Unknown PenguHub package.');
        $now = gmdate(DATE_ATOM);
        $version = (string)($manifest['version'] ?? '0.0.0');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO addons(id,version,enabled,installed_at,updated_at) VALUES(:id,:version,1,:now,:now) '
            . 'ON CONFLICT(id) DO UPDATE SET version=excluded.version, enabled=1, updated_at=excluded.updated_at'
        );
        $stmt->execute(['id' => $id, 'version' => $version, 'now' => $now]);
        $this->runInstallScript($manifest);
        return $this->publicManifest($manifest, true, true);
    }

    public function uninstall(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE addons SET enabled = 0, updated_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate(DATE_ATOM), 'id' => $id]);
    }

    public function enabledManifests(): array
    {
        return array_filter($this->available(), fn(array $m): bool => $this->enabled((string)$m['id']));
    }

    public function entrypoint(string $id): ?string
    {
        if (!$this->enabled($id)) return null;
        $manifest = $this->manifest($id);
        if (!$manifest) return null;
        $entry = trim((string)($manifest['entrypoint'] ?? ''));
        if ($entry === '') return null;
        $path = $manifest['_path'] . '/' . ltrim($entry, '/');
        return is_file($path) ? $path : null;
    }

    public function apiFile(string $id): ?string
    {
        if (!$this->enabled($id)) return null;
        $manifest = $this->manifest($id);
        if (!$manifest) return null;
        $api = trim((string)($manifest['api'] ?? ''));
        if ($api === '') return null;
        $path = $manifest['_path'] . '/' . ltrim($api, '/');
        return is_file($path) ? $path : null;
    }

    public function widgetCatalog(): array
    {
        $items = [
            ['type' => 'clock', 'name' => 'Clock', 'description' => 'Time and date', 'icon' => 'clock', 'defaultSize' => [3,2]],
            ['type' => 'note', 'name' => 'Note', 'description' => 'A simple dashboard note', 'icon' => 'note', 'defaultSize' => [3,2]],
        ];
        foreach ($this->enabledManifests() as $manifest) {
            foreach (($manifest['widgets'] ?? []) as $widget) {
                if (!is_array($widget)) continue;
                $widget['addon_id'] = $manifest['id'];
                $items[] = $widget;
            }
        }
        return $items;
    }

    public function integrationTypes(): array
    {
        $types = [];
        foreach ($this->enabledManifests() as $manifest) {
            $integration = $manifest['integration'] ?? null;
            if (!is_array($integration)) continue;
            $integration['addon_id'] = $manifest['id'];
            $types[] = $integration;
        }
        return $types;
    }

    private function syncInstalledVersions(): void
    {
        foreach ($this->available() as $id => $manifest) {
            $stmt = $this->db->pdo()->prepare('SELECT version FROM addons WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $installed = $stmt->fetchColumn();
            if ($installed !== false && (string)$installed !== (string)($manifest['version'] ?? '')) {
                $this->db->pdo()->prepare('UPDATE addons SET version = :version, updated_at = :now WHERE id = :id')
                    ->execute(['version' => (string)$manifest['version'], 'now' => gmdate(DATE_ATOM), 'id' => $id]);
            }
        }
    }

    private function runPendingInstalls(): void
    {
        foreach ($this->enabledManifests() as $manifest) {
            $this->runInstallScript($manifest);
        }
    }

    private function runInstallScript(array $manifest): void
    {
        $install = $manifest['_path'] . '/install.php';
        if (is_file($install)) {
            $db = $this->db;
            require $install;
        }
    }

    private function publicManifest(array $manifest, bool $installed, bool $enabled): array
    {
        unset($manifest['_path'], $manifest['entrypoint'], $manifest['api'], $manifest['connector']);
        $manifest['installed'] = $installed;
        $manifest['enabled'] = $enabled;
        return $manifest;
    }
}
