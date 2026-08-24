<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $key = (string)($integration['_secrets']['api_key'] ?? '');
    $secret = (string)($integration['_secrets']['api_secret'] ?? '');
    if ($key === '' || $secret === '') throw new RuntimeException('OPNsense API key and secret are required.');

    $opts = [
        'verify_tls' => (bool)$integration['verify_tls'],
        'basic' => $key . ':' . $secret,
        'timeout' => 8,
    ];

    $get = static function(string $path, bool $required = false) use ($http, $base, $opts): ?array {
        try {
            $result = $http->request('GET', $base . $path, $opts);
            if ($result['status'] >= 200 && $result['status'] < 300 && is_array($result['json'])) {
                return $result['json'];
            }
            if ($required) throw new RuntimeException('OPNsense endpoint ' . $path . ' returned HTTP ' . $result['status'] . '.');
        } catch (Throwable $e) {
            if ($required) throw $e;
        }
        return null;
    };

    $rows = static function(?array $payload): array {
        if (!$payload) return [];
        if (array_is_list($payload)) return array_values(array_filter($payload, 'is_array'));
        foreach (['rows','items','interfaces','data'] as $keyName) {
            if (isset($payload[$keyName]) && is_array($payload[$keyName])) {
                $candidate = $payload[$keyName];
                if (!array_is_list($candidate)) {
                    $candidate = array_values(array_filter($candidate, 'is_array'));
                }
                return array_values(array_filter($candidate, 'is_array'));
            }
        }
        // Some OPNsense endpoints return a keyed map of rows.
        $allArrays = array_values(array_filter($payload, 'is_array'));
        return count($allArrays) === count($payload) ? $allArrays : [];
    };

    $scalar = static function(array $row, array $keys, mixed $default = null): mixed {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && !is_array($row[$k]) && $row[$k] !== '') return $row[$k];
        }
        return $default;
    };

    if ($mode === 'arp') {
        $arp = $get('/api/diagnostics/interface/get_arp', true);
        $out = [];
        foreach ($rows($arp) as $row) {
            $ip = trim((string)$scalar($row, ['ip','ip_address','address','host'], ''));
            $mac = strtolower(trim((string)$scalar($row, ['mac','mac_address','ether','hwaddr'], '')));
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $out[] = [
                'ip' => $ip,
                'mac' => preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/i', $mac) ? $mac : '',
                'hostname' => trim((string)$scalar($row, ['hostname','host_name','name'], '')),
                'vendor' => trim((string)$scalar($row, ['manufacturer','vendor'], '')),
                'interface' => trim((string)$scalar($row, ['interface_name','interface','if'], '')),
            ];
        }
        return ['service'=>'OPNsense','status'=>'online','arp'=>$out];
    }

    $system = $get('/api/core/system/status', true) ?? [];
    $systemInfo = $get('/api/diagnostics/system/system_information') ?? [];
    $memory = $get('/api/diagnostics/system/memory') ?? $get('/api/diagnostics/interface/get_memory_statistics') ?? [];
    $interfacePayload = $get('/api/diagnostics/interface/get_interface_statistics') ?? [];
    $gatewayPayload = $get('/api/routes/gateway/status') ?? [];
    $wireguardPayload = $get('/api/wireguard/service/status') ?? [];

    $config = is_array($integration['config'] ?? null) ? $integration['config'] : [];
    $wantedInterface = strtolower(trim((string)($config['traffic_interface'] ?? 'wan')));
    $interfaceRows = $rows($interfacePayload);
    $chosen = null;
    foreach ($interfaceRows as $row) {
        $name = strtolower((string)$scalar($row, ['name','interface','device','if'], ''));
        $descr = strtolower((string)$scalar($row, ['description','descr','interface_name','label'], ''));
        if ($wantedInterface !== '' && ($name === $wantedInterface || $descr === $wantedInterface || str_contains($name, $wantedInterface) || str_contains($descr, $wantedInterface))) {
            $chosen = $row; break;
        }
    }
    if (!$chosen) {
        foreach ($interfaceRows as $row) {
            $name = strtolower((string)$scalar($row, ['name','interface','device','if'], ''));
            $descr = strtolower((string)$scalar($row, ['description','descr','interface_name','label'], ''));
            if ($name === 'lo0' || str_contains($descr, 'loopback')) continue;
            $chosen = $row; break;
        }
    }

    $num = static function(mixed $value): ?float {
        if (is_int($value) || is_float($value)) return (float)$value;
        if (is_string($value)) {
            $clean = str_replace([',',' '], '', $value);
            if (is_numeric($clean)) return (float)$clean;
        }
        return null;
    };

    $traffic = null;
    if (is_array($chosen)) {
        $rx = $num($scalar($chosen, ['ibytes','rxbytes','rx_bytes','bytes_received','inbytes','in_bytes']));
        $tx = $num($scalar($chosen, ['obytes','txbytes','tx_bytes','bytes_sent','outbytes','out_bytes']));
        $traffic = [
            'interface' => (string)$scalar($chosen, ['name','interface','device','if'], $wantedInterface ?: 'WAN'),
            'label' => (string)$scalar($chosen, ['description','descr','interface_name','label'], ''),
            'rx_bytes' => $rx,
            'tx_bytes' => $tx,
        ];
    }

    $gateway = null;
    $gatewayRows = $rows($gatewayPayload);
    if ($gatewayRows) {
        $g = $gatewayRows[0];
        foreach ($gatewayRows as $candidate) {
            $statusCandidate = strtolower((string)$scalar($candidate, ['status_translated','status'], ''));
            if ($statusCandidate === 'online' || $statusCandidate === 'none' || $statusCandidate === 'ok') { $g = $candidate; break; }
        }
        $gateway = [
            'name' => (string)$scalar($g, ['name','gateway','descr'], 'Gateway'),
            'status' => (string)$scalar($g, ['status_translated','status'], 'unknown'),
            'delay' => (string)$scalar($g, ['delay','rtt','latency'], ''),
            'loss' => (string)$scalar($g, ['loss','loss_percent','packet_loss'], ''),
        ];
    }

    $memoryPercent = null;
    $findMemory = null;
    $findMemory = static function(array $payload) use ($num, &$findMemory): ?float {
        foreach (['percent','used_percent','usage','memory_percent'] as $keyName) {
            if (isset($payload[$keyName])) {
                $v = $num($payload[$keyName]);
                if ($v !== null) return $v <= 1 ? $v * 100 : $v;
            }
        }
        $total = null; $used = null; $free = null;
        foreach (['total','memory_total','total_bytes'] as $k) if (isset($payload[$k])) { $total=$num($payload[$k]); break; }
        foreach (['used','memory_used','used_bytes','active'] as $k) if (isset($payload[$k])) { $used=$num($payload[$k]); break; }
        foreach (['free','memory_free','free_bytes'] as $k) if (isset($payload[$k])) { $free=$num($payload[$k]); break; }
        if ($total && $used !== null) return max(0, min(100, $used / $total * 100));
        if ($total && $free !== null) return max(0, min(100, ($total-$free) / $total * 100));
        foreach ($payload as $v) if (is_array($v)) { $found = $findMemory($v); if ($found !== null) return $found; }
        return null;
    };
    $memoryPercent = $findMemory($memory);

    $wgText = strtolower(json_encode($wireguardPayload, JSON_UNESCAPED_SLASHES) ?: '');
    $wireguard = [
        'available' => $wireguardPayload !== [],
        'running' => $wireguardPayload !== [] && !str_contains($wgText, 'stopped') && !str_contains($wgText, 'not running') && !str_contains($wgText, 'disabled'),
    ];

    $systemText = (string)($system['status'] ?? $system['message'] ?? 'API online');
    if ($systemText === '') $systemText = 'API online';

    return [
        'service'=>'OPNsense',
        'status'=>'online',
        'system_status'=>$systemText,
        'system'=>$system,
        'system_info'=>$systemInfo,
        'memory_percent'=>$memoryPercent !== null ? round($memoryPercent, 1) : null,
        'traffic'=>$traffic,
        'gateway'=>$gateway,
        'wireguard'=>$wireguard,
    ];
};
