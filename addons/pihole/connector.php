<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $password = (string)($integration['_secrets']['password'] ?? '');
    $verify = (bool)$integration['verify_tls'];
    $headers = [];
    $sid = '';

    if ($password !== '') {
        $auth = $http->request('POST', $base . '/api/auth', [
            'verify_tls' => $verify,
            'json' => ['password' => $password],
        ]);
        if ($auth['status'] !== 200 || !is_array($auth['json']) || empty($auth['json']['session']['sid'])) {
            throw new RuntimeException('Pi-hole authentication failed.');
        }
        $sid = (string)$auth['json']['session']['sid'];
        $headers['X-FTL-SID'] = $sid;
    }

    try {
        $summary = $http->request('GET', $base . '/api/stats/summary', [
            'verify_tls' => $verify,
            'headers' => $headers,
        ]);
        if ($summary['status'] < 200 || $summary['status'] >= 300 || !is_array($summary['json'])) {
            throw new RuntimeException('Pi-hole summary returned HTTP ' . $summary['status'] . '.');
        }

        $j = $summary['json'];
        $queries = (int)($j['queries']['total'] ?? $j['dns_queries_today'] ?? $j['queries'] ?? 0);
        $blocked = (int)($j['queries']['blocked'] ?? $j['ads_blocked_today'] ?? $j['blocked'] ?? 0);
        $percent = (float)($j['queries']['percent_blocked'] ?? $j['ads_percentage_today'] ?? ($queries > 0 ? $blocked / $queries * 100 : 0));
        $clients = (int)($j['clients']['active'] ?? $j['clients']['total'] ?? $j['unique_clients'] ?? 0);

        return [
            'service' => 'Pi-hole',
            'status' => 'online',
            'queries' => $queries,
            'blocked' => $blocked,
            'blocked_percent' => round($percent, 1),
            'clients' => $clients,
        ];
    } finally {
        // Pi-hole v6 sessions are limited resources. Close the short-lived widget session immediately.
        if ($sid !== '') {
            try {
                $http->request('DELETE', $base . '/api/auth', [
                    'verify_tls' => $verify,
                    'headers' => ['X-FTL-SID' => $sid],
                    'timeout' => 4,
                ]);
            } catch (Throwable) {
                // The session also expires server-side; a failed logout must not hide valid widget data.
            }
        }
    }
};
