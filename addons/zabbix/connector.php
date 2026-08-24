<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $endpoint = str_ends_with(strtolower($base), '/api_jsonrpc.php') ? $base : $base . '/api_jsonrpc.php';
    $token = trim((string)($integration['_secrets']['api_token'] ?? ''));
    $verify = (bool)$integration['verify_tls'];
    if ($token === '') throw new RuntimeException('Zabbix API token is missing.');

    $rpcId = 0;
    $rpc = static function(string $method, array $params = [], bool $auth = true) use ($http, $endpoint, $token, $verify, &$rpcId): mixed {
        $headers = ['Content-Type' => 'application/json-rpc'];
        if ($auth) $headers['Authorization'] = 'Bearer ' . $token;
        $res = $http->request('POST', $endpoint, [
            'verify_tls' => $verify,
            'headers' => $headers,
            'body' => json_encode(['jsonrpc'=>'2.0','method'=>$method,'params'=>$params,'id'=>++$rpcId], JSON_UNESCAPED_SLASHES),
            'timeout' => 10,
        ]);
        if ($res['status'] === 401) throw new RuntimeException('Zabbix authentication failed. Check the API token.');
        if ($res['status'] < 200 || $res['status'] >= 300) throw new RuntimeException('Zabbix returned HTTP ' . $res['status'] . '.');
        if (!is_array($res['json'])) throw new RuntimeException('Zabbix returned invalid JSON.');
        if (isset($res['json']['error'])) {
            $msg = is_array($res['json']['error']) ? (string)($res['json']['error']['data'] ?? $res['json']['error']['message'] ?? 'API error') : 'API error';
            throw new RuntimeException('Zabbix: ' . $msg);
        }
        return $res['json']['result'] ?? null;
    };

    if ($mode !== 'summary') throw new RuntimeException('Unsupported Zabbix connector mode.');

    $version = $rpc('apiinfo.version', [], false);
    $hosts = $rpc('host.get', [
        'output' => ['hostid','host','name','status','maintenance_status'],
    ]);
    if (!is_array($hosts)) $hosts = [];

    $problemCount = $rpc('problem.get', ['countOutput' => true, 'recent' => false]);
    $highCount = $rpc('problem.get', ['countOutput' => true, 'recent' => false, 'severities' => [4,5]]);
    $problems = $rpc('problem.get', [
        'output' => ['eventid','name','severity','clock','acknowledged'],
        'recent' => false,
        'sortfield' => ['eventid'],
        'sortorder' => 'DESC',
        'limit' => 3,
    ]);
    if (!is_array($problems)) $problems = [];

    $monitored = count(array_filter($hosts, static fn($h): bool => (string)($h['status'] ?? '1') === '0'));
    $maintenance = count(array_filter($hosts, static fn($h): bool => (string)($h['maintenance_status'] ?? '0') === '1'));
    $recent = array_map(static fn($p): array => [
        'name' => (string)($p['name'] ?? 'Problem'),
        'severity' => (int)($p['severity'] ?? 0),
        'clock' => (int)($p['clock'] ?? 0),
        'acknowledged' => (string)($p['acknowledged'] ?? '0') === '1',
    ], array_slice($problems, 0, 3));

    return [
        'service' => 'Zabbix',
        'version' => is_string($version) ? $version : '',
        'hosts_total' => count($hosts),
        'hosts_monitored' => $monitored,
        'hosts_maintenance' => $maintenance,
        'problems_total' => is_numeric($problemCount) ? (int)$problemCount : 0,
        'problems_high' => is_numeric($highCount) ? (int)$highCount : 0,
        'recent_problems' => $recent,
    ];
};
