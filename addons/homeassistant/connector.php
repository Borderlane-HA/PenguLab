<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $token = trim((string)($integration['_secrets']['access_token'] ?? ''));
    if ($token === '') throw new RuntimeException('Home Assistant access token is missing.');
    $verify = (bool)$integration['verify_tls'];
    $headers = ['Authorization' => 'Bearer ' . $token];

    $requestJson = static function(string $method, string $path, array $options = []) use ($http, $base, $verify, $headers): array {
        $opts = ['verify_tls' => $verify, 'headers' => $headers] + $options;
        $res = $http->request($method, $base . $path, $opts);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            if ($res['status'] === 401) throw new RuntimeException('Home Assistant authentication failed. Check the Long-Lived Access Token.');
            throw new RuntimeException('Home Assistant returned HTTP ' . $res['status'] . '.');
        }
        if (!is_array($res['json'])) throw new RuntimeException('Home Assistant returned invalid JSON.');
        return $res['json'];
    };

    $publicEntity = static function(array $state): array {
        $attributes = is_array($state['attributes'] ?? null) ? $state['attributes'] : [];
        $entityId = (string)($state['entity_id'] ?? '');
        $domain = strtolower((string)strtok($entityId, '.'));
        $out = [
            'entity_id' => $entityId,
            'domain' => $domain,
            'name' => (string)($attributes['friendly_name'] ?? $entityId),
            'state' => (string)($state['state'] ?? 'unknown'),
            'unit' => (string)($attributes['unit_of_measurement'] ?? ''),
            'icon' => (string)($attributes['icon'] ?? ''),
            'device_class' => (string)($attributes['device_class'] ?? ''),
            'last_changed' => (string)($state['last_changed'] ?? ''),
            'last_updated' => (string)($state['last_updated'] ?? ''),
        ];
        if (array_key_exists('brightness', $attributes)) $out['brightness'] = is_numeric($attributes['brightness']) ? (int)$attributes['brightness'] : null;
        if (array_key_exists('current_position', $attributes)) $out['current_position'] = is_numeric($attributes['current_position']) ? (int)$attributes['current_position'] : null;
        if (array_key_exists('supported_features', $attributes)) $out['supported_features'] = is_numeric($attributes['supported_features']) ? (int)$attributes['supported_features'] : 0;
        return $out;
    };

    if ($mode === 'summary') {
        $config = $requestJson('GET', '/api/config');
        return [
            'service' => 'Home Assistant',
            'status' => 'online',
            'version' => (string)($config['version'] ?? ''),
            'location_name' => (string)($config['location_name'] ?? ''),
        ];
    }

    if ($mode === 'entities') {
        $states = $requestJson('GET', '/api/states');
        $allowed = ['sensor', 'switch', 'light', 'cover'];
        $entities = [];
        foreach ($states as $state) {
            if (!is_array($state)) continue;
            $entityId = (string)($state['entity_id'] ?? '');
            $domain = strtolower((string)strtok($entityId, '.'));
            if (!in_array($domain, $allowed, true)) continue;
            $entities[] = $publicEntity($state);
        }
        usort($entities, static fn(array $a, array $b): int => strnatcasecmp($a['name'] . ' ' . $a['entity_id'], $b['name'] . ' ' . $b['entity_id']));
        return ['service' => 'Home Assistant', 'entities' => $entities];
    }

    if (str_starts_with($mode, 'states:')) {
        $encoded = substr($mode, 7);
        $decoded = base64_decode($encoded, true);
        $ids = $decoded !== false ? json_decode($decoded, true) : null;
        if (!is_array($ids)) throw new RuntimeException('Invalid Home Assistant entity selection.');
        $ids = array_values(array_slice(array_unique(array_filter(array_map('strval', $ids))), 0, 8));
        $entities = [];
        foreach ($ids as $entityId) {
            if (!preg_match('/^(sensor|switch|light|cover)\.[a-zA-Z0-9_]+$/', $entityId)) continue;
            try {
                $state = $requestJson('GET', '/api/states/' . rawurlencode($entityId));
                $entities[] = $publicEntity($state);
            } catch (RuntimeException $e) {
                $entities[] = [
                    'entity_id' => $entityId,
                    'domain' => (string)strtok($entityId, '.'),
                    'name' => $entityId,
                    'state' => 'unavailable',
                    'unit' => '',
                    'icon' => '',
                    'device_class' => '',
                    'error' => $e->getMessage(),
                ];
            }
        }
        return ['service' => 'Home Assistant', 'entities' => $entities];
    }

    if (str_starts_with($mode, 'action:')) {
        $encoded = substr($mode, 7);
        $decoded = base64_decode($encoded, true);
        $payload = $decoded !== false ? json_decode($decoded, true) : null;
        if (!is_array($payload)) throw new RuntimeException('Invalid Home Assistant action.');
        $entityId = trim((string)($payload['entity_id'] ?? ''));
        if (!preg_match('/^(switch|light|cover)\.[a-zA-Z0-9_]+$/', $entityId)) throw new RuntimeException('Unsupported Home Assistant entity.');
        $domain = (string)strtok($entityId, '.');
        $action = (string)($payload['action'] ?? '');
        $service = match ([$domain, $action]) {
            ['switch', 'toggle'], ['light', 'toggle'] => 'toggle',
            ['switch', 'turn_on'], ['light', 'turn_on'] => 'turn_on',
            ['switch', 'turn_off'], ['light', 'turn_off'] => 'turn_off',
            ['cover', 'open'] => 'open_cover',
            ['cover', 'close'] => 'close_cover',
            ['cover', 'stop'] => 'stop_cover',
            ['cover', 'position'] => 'set_cover_position',
            default => throw new RuntimeException('Unsupported Home Assistant action.'),
        };
        $serviceData = ['entity_id' => $entityId];
        if ($domain === 'cover' && $action === 'position') {
            $serviceData['position'] = max(0, min(100, (int)($payload['position'] ?? 0)));
        }
        $requestJson('POST', '/api/services/' . $domain . '/' . $service, ['json' => $serviceData]);
        return ['service' => 'Home Assistant', 'entity_id' => $entityId, 'action' => $action, 'ok' => true];
    }

    throw new RuntimeException('Unsupported Home Assistant connector mode.');
};
