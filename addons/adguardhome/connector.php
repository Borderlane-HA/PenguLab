<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $verify = (bool)$integration['verify_tls'];
    $user = (string)$integration['username'];
    $pass = (string)($integration['_secrets']['password'] ?? '');
    $opts = ['verify_tls' => $verify];
    if ($user !== '' || $pass !== '') $opts['basic'] = $user . ':' . $pass;

    if (str_starts_with($mode, 'action:')) {
        $action = substr($mode, 7);
        [$enabled, $duration] = match ($action) {
            'protection_enable' => [true, 0],
            'protection_disable' => [false, 0],
            'protection_pause_300' => [false, 300000],
            default => throw new RuntimeException('Unsupported AdGuard Home action.'),
        };
        $result = $http->request('POST', $base . '/control/protection', $opts + [
            'json' => ['enabled' => $enabled, 'duration' => $duration],
        ]);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new RuntimeException('AdGuard Home protection action returned HTTP ' . $result['status'] . '.');
        }
        return [
            'service' => 'AdGuard Home',
            'action' => $action,
            'protection' => $enabled,
            'pause_ms' => $duration,
        ];
    }

    $status = $http->request('GET', $base . '/control/status', $opts);
    if ($status['status'] !== 200 || !is_array($status['json'])) throw new RuntimeException('AdGuard Home status returned HTTP ' . $status['status'] . '.');
    $stats = $http->request('GET', $base . '/control/stats', $opts);
    if ($stats['status'] !== 200 || !is_array($stats['json'])) throw new RuntimeException('AdGuard Home stats returned HTTP ' . $stats['status'] . '.');
    $s = $stats['json'];
    $queries = (int)($s['num_dns_queries'] ?? 0);
    $blocked = (int)($s['num_blocked_filtering'] ?? 0);
    return [
        'service' => 'AdGuard Home',
        'status' => !empty($status['json']['running']) ? 'online' : 'offline',
        'protection' => !empty($status['json']['protection_enabled']),
        'protection_disabled_duration' => (int)($status['json']['protection_disabled_duration'] ?? 0),
        'version' => (string)($status['json']['version'] ?? ''),
        'queries' => $queries,
        'blocked' => $blocked,
        'blocked_percent' => round($queries > 0 ? $blocked / $queries * 100 : 0, 1),
        'avg_processing_ms' => round(((float)($s['avg_processing_time'] ?? 0)) * 1000, 2),
    ];
};
