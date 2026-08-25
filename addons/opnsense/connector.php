<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $key = (string)($integration['_secrets']['api_key'] ?? '');
    $secret = (string)($integration['_secrets']['api_secret'] ?? '');
    if ($key === '' || $secret === '') throw new RuntimeException('OPNsense API key and secret are required.');

    $config = is_array($integration['config'] ?? null) ? $integration['config'] : [];
    $opt = static function(string $keyName, mixed $default = false) use ($config): mixed {
        return array_key_exists($keyName, $config) ? $config[$keyName] : $default;
    };

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
        foreach (['rows','items','interfaces','data','services'] as $keyName) {
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
            $clean = trim(str_replace(['%','ms','C','°',' '], '', str_replace(',', '.', $value)));
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

    // Core status stays required so a broken credential/URL is reported immediately.
    $system = $get('/api/core/system/status', true) ?? [];
    $systemInfo = $get('/api/diagnostics/system/system_information') ?? [];
    $systemTime = $opt('show_uptime', false) ? ($get('/api/diagnostics/system/system_time') ?? []) : [];
    $resources = $opt('show_memory', false) ? ($get('/api/diagnostics/system/system_resources') ?? $get('/api/diagnostics/system/systemResources') ?? []) : [];
    $diskPayload = $opt('show_disk', false) ? ($get('/api/diagnostics/system/system_disk') ?? $get('/api/diagnostics/system/systemDisk') ?? []) : [];
    $temperaturePayload = $opt('show_temperature', false) ? ($get('/api/diagnostics/system/system_temperature') ?? $get('/api/diagnostics/system/systemTemperature') ?? []) : [];
    $firewallStatesPayload = $opt('show_firewall_states', false) ? ($get('/api/diagnostics/firewall/pf_states') ?? $get('/api/diagnostics/firewall/pfStates') ?? []) : [];
    $servicesPayload = $opt('show_services', false) ? ($get('/api/core/service/search') ?? []) : [];
    $carpPayload = $opt('show_carp', false) ? ($get('/api/diagnostics/interface/get_vip_status') ?? $get('/api/diagnostics/interface/getVipStatus') ?? []) : [];

    $interfacePayload = $get('/api/diagnostics/interface/get_interface_statistics') ?? $get('/api/diagnostics/traffic/interface') ?? [];
    $interfaceNames = $get('/api/diagnostics/interface/get_interface_names') ?? [];
    $gatewayPayload = $opt('show_gateway', true)
        ? ($get('/api/routing/settings/search_gateway') ?? $post('/api/routing/settings/search_gateway') ?? $get('/api/routes/gateway/status') ?? [])
        : [];
    $needWireGuard = (bool)$opt('show_wireguard', false);
    $wireguardPayload = $needWireGuard ? ($get('/api/wireguard/service/show') ?? []) : [];

    // CPU is an SSE endpoint in current OPNsense. Read just one event when explicitly enabled.
    $cpuPercent = null;
    if ($opt('show_cpu', false) && extension_loaded('curl')) {
        $buffer = '';
        $ch = curl_init($base . '/api/diagnostics/cpu_usage/stream');
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $key . ':' . $secret,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => 1200,
                CURLOPT_TIMEOUT_MS => 2200,
                CURLOPT_SSL_VERIFYPEER => (bool)$integration['verify_tls'],
                CURLOPT_SSL_VERIFYHOST => !empty($integration['verify_tls']) ? 2 : 0,
                CURLOPT_HTTPHEADER => ['Accept: text/event-stream', 'User-Agent: PenguLab/2.9.0'],
                CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use (&$buffer): int {
                    $buffer .= $chunk;
                    // Stop after the first complete SSE data record. Returning 0 aborts curl,
                    // which is intentional; the already collected sample remains available.
                    if (preg_match('/(?:^|\n)data:\s*\{[^\r\n]+\}\r?\n/s', $buffer)) return 0;
                    return strlen($chunk);
                },
            ]);
            @curl_exec($ch);
            curl_close($ch);
            if (preg_match('/(?:^|\n)data:\s*(\{[^\r\n]+\})/s', $buffer, $m)) {
                $cpu = json_decode($m[1], true);
                if (is_array($cpu)) $cpuPercent = $num($cpu['total'] ?? null);
            }
        }
    }

    // OPNsense returns interface statistics as a keyed map under "statistics".
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
        if ($label === '' && $device !== '' && isset($interfaceNames[$device]) && is_scalar($interfaceNames[$device])) $label = trim((string)$interfaceNames[$device]);
        if ($label === '') $label = $device;
        $row['_stat_key'] = (string)$statKey;
        $row['_device'] = $device;
        $row['_label'] = $label;
        $row['_address'] = trim((string)($row['address'] ?? $addressFromKey));
        $statRows[] = $row;
    }
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

    $byDevice = [];
    foreach ($statRows as $row) {
        $device = trim((string)($row['_device'] ?? ''));
        if ($device === '' || $device === 'lo0') continue;
        $address = trim((string)($row['_address'] ?? ''));
        $cleanAddress = preg_replace('/%.+$/', '', $address);
        $isIp = filter_var($cleanAddress, FILTER_VALIDATE_IP) !== false;
        $score = $isIp ? (filter_var($cleanAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 3 : 2) : 1;
        if (!isset($byDevice[$device]) || $score > $byDevice[$device]['_score']) {
            $row['_score'] = $score;
            $byDevice[$device] = $row;
        }
    }
    foreach ($interfaceNames as $device => $label) {
        if (!is_string($device) || !is_scalar($label) || $device === 'lo0' || isset($byDevice[$device])) continue;
        $byDevice[$device] = ['_device'=>$device,'_label'=>(string)$label,'_address'=>'','_score'=>0];
    }

    $interfaces = [];
    foreach ($byDevice as $device => $row) {
        $label = trim((string)($row['_label'] ?? $device));
        $interfaces[] = ['id'=>$device,'label'=>$label !== '' ? $label : $device,'address'=>trim((string)($row['_address'] ?? ''))];
    }
    usort($interfaces, static fn(array $a, array $b): int => strnatcasecmp($a['label'] . $a['id'], $b['label'] . $b['id']));

    $wanted = strtolower(trim((string)($config['traffic_interface'] ?? 'auto')));
    $chosen = null;
    if ($wanted === '' || $wanted === 'auto' || $wanted === 'wan') {
        foreach ($byDevice as $row) if (strcasecmp(trim((string)($row['_label'] ?? '')), 'WAN') === 0) { $chosen = $row; break; }
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
        $traffic = [
            'interface' => (string)($chosen['_device'] ?? ''),
            'label' => (string)($chosen['_label'] ?? ''),
            'address' => (string)($chosen['_address'] ?? ''),
            'rx_bytes' => $num($scalar($chosen, ['received-bytes','ibytes','rxbytes','rx_bytes','bytes_received','inbytes','in_bytes'])),
            'tx_bytes' => $num($scalar($chosen, ['sent-bytes','obytes','txbytes','tx_bytes','bytes_sent','outbytes','out_bytes'])),
            'rx_errors' => $num($scalar($chosen, ['received-errors','rx_errors','ierrors'], 0)),
            'tx_errors' => $num($scalar($chosen, ['send-errors','sent-errors','tx_errors','oerrors'], 0)),
            'drops' => $num($scalar($chosen, ['dropped-packets','drops','drop'], 0)),
            'collisions' => $num($scalar($chosen, ['collisions'], 0)),
        ];
    }

    $gateway = null;
    $gateways = [];
    $gatewayRows = $rows($gatewayPayload);
    $cleanMetric = static fn(mixed $v): string => trim((string)$v) === '~' ? '' : trim((string)$v);
    foreach ($gatewayRows as $gatewayRow) {
        $gatewayStatus = trim((string)$scalar($gatewayRow, ['status_translated','status'], 'unknown'));
        if (strtolower($gatewayStatus) === 'none' || strtolower($gatewayStatus) === 'ok') $gatewayStatus = 'Online';
        $gateways[] = [
            'name' => (string)$scalar($gatewayRow, ['name','gateway','descr'], 'Gateway'),
            'status' => $gatewayStatus,
            'delay' => $cleanMetric($scalar($gatewayRow, ['delay','rtt','latency'], '')),
            'stddev' => $cleanMetric($scalar($gatewayRow, ['stddev','rttd','jitter'], '')),
            'loss' => $cleanMetric($scalar($gatewayRow, ['loss','loss_percent','packet_loss'], '')),
            'default' => !empty($gatewayRow['defaultgw']) || !empty($gatewayRow['default']),
        ];
    }
    if ($gateways) {
        $wantedGateway = strtolower(trim((string)($config['gateway_name'] ?? 'auto')));
        if ($wantedGateway !== '' && $wantedGateway !== 'auto') {
            foreach ($gateways as $candidate) {
                if (strtolower(trim((string)$candidate['name'])) === $wantedGateway) { $gateway = $candidate; break; }
            }
        }
        if (!$gateway) foreach ($gateways as $candidate) if (!empty($candidate['default'])) { $gateway = $candidate; break; }
        if (!$gateway) foreach ($gateways as $candidate) if (strtolower((string)$candidate['status']) === 'online') { $gateway = $candidate; break; }
        if (!$gateway) $gateway = $gateways[0];
    }

    $memoryPercent = null;
    if (isset($resources['memory']) && is_array($resources['memory'])) {
        $total = $num($resources['memory']['total'] ?? null); $used = $num($resources['memory']['used'] ?? null);
        if ($total && $used !== null) $memoryPercent = max(0, min(100, $used / $total * 100));
    }

    $diskPercent = null;
    foreach ($rows($diskPayload['devices'] ?? $diskPayload) as $device) {
        if (($device['mountpoint'] ?? '') !== '/' && $diskPercent !== null) continue;
        $pct = $num($device['used_pct'] ?? $device['used-percent'] ?? null);
        if ($pct !== null) { $diskPercent = $pct; if (($device['mountpoint'] ?? '') === '/') break; }
    }

    $temperature = null;
    $tempRows = $rows($temperaturePayload);
    $preferred = [];
    foreach ($tempRows as $t) {
        $value = $num($t['temperature'] ?? $t['temp'] ?? null); if ($value === null) continue;
        $entry = ['value'=>$value,'label'=>(string)($t['type_translated'] ?? $t['type'] ?? $t['device'] ?? 'Sensor')];
        if (strtolower((string)($t['type'] ?? '')) === 'cpu' || str_contains(strtolower((string)($t['device'] ?? '')), 'cpu')) $preferred[] = $entry;
        elseif ($temperature === null || $value > $temperature['value']) $temperature = $entry;
    }
    if ($preferred) {
        usort($preferred, static fn(array $a,array $b): int => $b['value'] <=> $a['value']);
        $temperature = $preferred[0];
    }

    $firewallStates = null;
    if ($firewallStatesPayload) {
        $current = $num($firewallStatesPayload['current'] ?? null); $limit = $num($firewallStatesPayload['limit'] ?? null);
        if ($current !== null || $limit !== null) $firewallStates = ['current'=>$current,'limit'=>$limit,'percent'=>$limit ? $current/$limit*100 : null];
    }

    $services = [];
    foreach ($rows($servicesPayload) as $service) {
        $services[] = [
            'id'=>(string)$scalar($service,['id','name'],'service'),
            'name'=>(string)$scalar($service,['description','name','id'],'Service'),
            'running'=>(bool)($service['running'] ?? false),
        ];
    }
    usort($services, static fn(array $a,array $b): int => ($a['running'] === $b['running'] ? strnatcasecmp($a['name'],$b['name']) : ($a['running'] ? 1 : -1)));

    $carp = [];
    foreach ($rows($carpPayload) as $vip) {
        $carp[] = [
            'interface'=>(string)$scalar($vip,['interface'],'CARP'),
            'address'=>(string)$scalar($vip,['subnet','address'],'') ,
            'status'=>(string)$scalar($vip,['status_txt','status'],'unknown'),
            'vhid'=>(string)$scalar($vip,['vhid_txt','vhid'],'') ,
        ];
    }

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
            'endpoint' => trim((string)($row['endpoint'] ?? $row['endpoint-current'] ?? '')),
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
        'available' => $wireguardPayload !== [],
        'running' => $wireguardPayload !== [],
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
        'uptime'=>(string)($systemTime['uptime'] ?? ''),
        'loadavg'=>(string)($systemTime['loadavg'] ?? ''),
        'cpu_percent'=>$cpuPercent !== null ? round($cpuPercent,1) : null,
        'memory_percent'=>$memoryPercent !== null ? round($memoryPercent,1) : null,
        'disk_percent'=>$diskPercent !== null ? round($diskPercent,1) : null,
        'temperature'=>$temperature,
        'firewall_states'=>$firewallStates,
        'services'=>$services,
        'carp'=>$carp,
        'interfaces'=>$interfaces,
        'traffic'=>$traffic,
        'gateway'=>$gateway,
        'gateways'=>$gateways,
        'wireguard'=>$wireguard,
    ];
};
