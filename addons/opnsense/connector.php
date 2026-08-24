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
            if ($result['status'] >= 200 && $result['status'] < 300 && is_array($result['json'])) return $result['json'];
            if ($required) throw new RuntimeException('OPNsense endpoint ' . $path . ' returned HTTP ' . $result['status'] . '.');
        } catch (Throwable $e) {
            if ($required) throw $e;
        }
        return null;
    };

    $post = static function(string $path, array $json = [], bool $required = false) use ($http, $base, $opts): ?array {
        try {
            $requestOpts = $opts;
            $requestOpts['json'] = $json;
            $result = $http->request('POST', $base . $path, $requestOpts);
            if ($result['status'] >= 200 && $result['status'] < 300 && is_array($result['json'])) return $result['json'];
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
                if (!array_is_list($candidate)) $candidate = array_values(array_filter($candidate, 'is_array'));
                return array_values(array_filter($candidate, 'is_array'));
            }
        }
        $allArrays = array_values(array_filter($payload, 'is_array'));
        return count($allArrays) === count($payload) ? $allArrays : [];
    };

    $scalar = static function(array $row, array $keys, mixed $default = null): mixed {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && !is_array($row[$k]) && $row[$k] !== '') return $row[$k];
        }
        return $default;
    };

    $num = static function(mixed $value): ?float {
        if (is_int($value) || is_float($value)) return (float)$value;
        if (is_string($value)) {
            $clean = str_replace([',',' '], '', $value);
            if (is_numeric($clean)) return (float)$clean;
        }
        return null;
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
                'interface' => trim((string)$scalar($row, ['intf_description','interface_name','interface','intf','if'], '')),
            ];
        }
        return ['service'=>'OPNsense','status'=>'online','arp'=>$out];
    }

    $system = $get('/api/core/system/status', true) ?? [];
    $systemInfo = $get('/api/diagnostics/system/system_information') ?? [];
    $memory = $get('/api/diagnostics/system/memory') ?? $get('/api/diagnostics/interface/get_memory_statistics') ?? [];
    $interfacePayload = $get('/api/diagnostics/interface/get_interface_statistics') ?? [];
    $interfaceNames = $get('/api/diagnostics/interface/get_interface_names') ?? [];
    // Current OPNsense dashboard widgets read gateway status from routing/settings/search_gateway.
    // Keep the legacy endpoint as a fallback for older releases.
    $gatewayPayload = $get('/api/routing/settings/search_gateway') ?? $post('/api/routing/settings/search_gateway') ?? $get('/api/routes/gateway/status') ?? [];
    $wireguardService = $get('/api/wireguard/service/status') ?? [];
    $wireguardPayload = $get('/api/wireguard/service/show') ?? [];

    // OPNsense returns interface statistics as a keyed map under "statistics":
    //   "[WAN] (vtnet0) / 10.0.0.2" => { name, received-bytes, sent-bytes, ... }
    // Preserve that key because it contains the logical interface description.
    $statMap = is_array($interfacePayload['statistics'] ?? null) ? $interfacePayload['statistics'] : [];
    $statRows = [];
    foreach ($statMap as $statKey => $row) {
        if (!is_array($row)) continue;
        $device = trim((string)($row['name'] ?? ''));
        $label = '';
        $addressFromKey = '';
        if (preg_match('/^\[([^\]]+)\]\s+\(([^)]+)\)\s+\/\s+(.+)$/', (string)$statKey, $m)) {
            $label = trim($m[1]);
            if ($device === '') $device = trim($m[2]);
            $addressFromKey = trim($m[3]);
        } elseif (preg_match('/^\[([^\]]+)\]\s+\/\s+(.+)$/', (string)$statKey, $m)) {
            $label = trim($m[1]);
            $addressFromKey = trim($m[2]);
        }
        if ($label === '' && $device !== '' && isset($interfaceNames[$device]) && is_scalar($interfaceNames[$device])) {
            $label = trim((string)$interfaceNames[$device]);
        }
        if ($label === '') $label = $device;
        $row['_stat_key'] = (string)$statKey;
        $row['_device'] = $device;
        $row['_label'] = $label;
        $row['_address'] = trim((string)($row['address'] ?? $addressFromKey));
        $statRows[] = $row;
    }

    // Older/future OPNsense variants may use a different outer shape. Keep a generic fallback.
    if ($statRows === []) {
        foreach ($rows($interfacePayload) as $row) {
            $device = trim((string)$scalar($row, ['name','interface','device','if'], ''));
            $label = trim((string)$scalar($row, ['description','descr','interface_name','label'], ''));
            if ($label === '' && $device !== '' && isset($interfaceNames[$device]) && is_scalar($interfaceNames[$device])) $label = trim((string)$interfaceNames[$device]);
            $row['_device'] = $device;
            $row['_label'] = $label !== '' ? $label : $device;
            $row['_address'] = trim((string)$scalar($row, ['address','ip','ip_address'], ''));
            $statRows[] = $row;
        }
    }

    // Build one selectable item per real interface. Prefer an IP-address row over the link/MAC row,
    // because OPNsense's per-address statistics contain the counters used by its diagnostics UI.
    $byDevice = [];
    foreach ($statRows as $row) {
        $device = trim((string)($row['_device'] ?? ''));
        if ($device === '' || $device === 'lo0') continue;
        $address = trim((string)($row['_address'] ?? ''));
        $isIp = filter_var(preg_replace('/%.+$/', '', $address), FILTER_VALIDATE_IP) !== false;
        $score = $isIp ? (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 3 : 2) : 1;
        if (!isset($byDevice[$device]) || $score > $byDevice[$device]['_score']) {
            $row['_score'] = $score;
            $byDevice[$device] = $row;
        }
    }

    // Include configured interfaces even when no statistics row exists yet.
    foreach ($interfaceNames as $device => $label) {
        if (!is_string($device) || !is_scalar($label) || $device === 'lo0' || isset($byDevice[$device])) continue;
        $byDevice[$device] = ['_device'=>$device,'_label'=>(string)$label,'_address'=>'','_score'=>0];
    }

    $interfaces = [];
    foreach ($byDevice as $device => $row) {
        $label = trim((string)($row['_label'] ?? $device));
        $interfaces[] = [
            'id' => $device,
            'label' => $label !== '' ? $label : $device,
            'address' => trim((string)($row['_address'] ?? '')),
        ];
    }
    usort($interfaces, static fn(array $a, array $b): int => strnatcasecmp($a['label'] . $a['id'], $b['label'] . $b['id']));

    $config = is_array($integration['config'] ?? null) ? $integration['config'] : [];
    $wanted = strtolower(trim((string)($config['traffic_interface'] ?? 'auto')));
    $chosen = null;

    // "auto" resolves the interface whose logical OPNsense description is WAN. This works regardless
    // of whether the physical device is vtnet0, igb0, pppoe0, a VLAN, a LAGG, etc.
    if ($wanted === '' || $wanted === 'auto' || $wanted === 'wan') {
        foreach ($byDevice as $row) {
            if (strcasecmp(trim((string)($row['_label'] ?? '')), 'WAN') === 0) { $chosen = $row; break; }
        }
    }
    if (!$chosen && $wanted !== '' && $wanted !== 'auto') {
        foreach ($byDevice as $row) {
            $device = strtolower(trim((string)($row['_device'] ?? '')));
            $label = strtolower(trim((string)($row['_label'] ?? '')));
            if ($device === $wanted || $label === $wanted) { $chosen = $row; break; }
        }
    }
    if (!$chosen && $byDevice !== []) $chosen = reset($byDevice) ?: null;

    $traffic = null;
    if (is_array($chosen)) {
        $rx = $num($scalar($chosen, ['received-bytes','ibytes','rxbytes','rx_bytes','bytes_received','inbytes','in_bytes']));
        $tx = $num($scalar($chosen, ['sent-bytes','obytes','txbytes','tx_bytes','bytes_sent','outbytes','out_bytes']));
        // Missing counters are not zero traffic. Keep them null so the frontend doesn't draw a false flat line.
        $traffic = [
            'interface' => (string)($chosen['_device'] ?? ''),
            'label' => (string)($chosen['_label'] ?? ''),
            'address' => (string)($chosen['_address'] ?? ''),
            'rx_bytes' => $rx,
            'tx_bytes' => $tx,
        ];
    }

    $gateway = null;
    $gatewayRows = $rows($gatewayPayload);
    if ($gatewayRows) {
        // Prefer the active/default gateway. If that flag is unavailable, prefer an online gateway.
        $g = $gatewayRows[0];
        foreach ($gatewayRows as $candidate) {
            if (!empty($candidate['defaultgw']) || !empty($candidate['default'])) { $g = $candidate; break; }
        }
        if (empty($g['defaultgw']) && empty($g['default'])) {
            foreach ($gatewayRows as $candidate) {
                $statusCandidate = strtolower((string)$scalar($candidate, ['status_translated','status'], ''));
                if ($statusCandidate === 'online' || $statusCandidate === 'none' || $statusCandidate === 'ok') { $g = $candidate; break; }
            }
        }
        $delay = trim((string)$scalar($g, ['delay','rtt','latency'], ''));
        if ($delay === '~') $delay = '';
        $loss = trim((string)$scalar($g, ['loss','loss_percent','packet_loss'], ''));
        if ($loss === '~') $loss = '';
        $gateway = [
            'name' => (string)$scalar($g, ['name','gateway','descr'], 'Gateway'),
            'status' => (string)$scalar($g, ['status_translated','status'], 'unknown'),
            'delay' => $delay,
            'loss' => $loss,
        ];
    }

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

    $wgText = strtolower(json_encode($wireguardService, JSON_UNESCAPED_SLASHES) ?: '');
    $peerRows = $rows($wireguardPayload);
    $peers = [];
    foreach ($peerRows as $row) {
        if (strtolower((string)($row['type'] ?? '')) !== 'peer') continue;
        $status = strtolower(trim((string)($row['peer-status'] ?? 'offline')));
        if (!in_array($status, ['online','stale','offline'], true)) $status = 'offline';
        $peers[] = [
            'interface' => trim((string)($row['if'] ?? '')),
            'name' => trim((string)($row['name'] ?? 'Peer')),
            'status' => $status,
            'allowed_ips' => trim((string)($row['allowed-ips'] ?? '')),
            'latest_handshake' => $row['latest-handshake-epoch'] ?? null,
            'rx' => $num($row['transfer-rx'] ?? null),
            'tx' => $num($row['transfer-tx'] ?? null),
        ];
    }
    usort($peers, static function(array $a, array $b): int {
        $rank = ['online'=>0,'stale'=>1,'offline'=>2];
        return ($rank[$a['status']] ?? 3) <=> ($rank[$b['status']] ?? 3) ?: strnatcasecmp($a['name'], $b['name']);
    });
    $wireguard = [
        'available' => $wireguardService !== [] || $wireguardPayload !== [],
        'running' => ($wireguardService !== [] || $wireguardPayload !== []) && !str_contains($wgText, 'stopped') && !str_contains($wgText, 'not running') && !str_contains($wgText, 'disabled'),
        'peers' => $peers,
        'online' => count(array_filter($peers, static fn(array $p): bool => $p['status'] === 'online')),
        'stale' => count(array_filter($peers, static fn(array $p): bool => $p['status'] === 'stale')),
        'offline' => count(array_filter($peers, static fn(array $p): bool => $p['status'] === 'offline')),
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
        'interfaces'=>$interfaces,
        'traffic'=>$traffic,
        'gateway'=>$gateway,
        'wireguard'=>$wireguard,
    ];
};
