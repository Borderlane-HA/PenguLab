<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $password = (string)($integration['_secrets']['password'] ?? '');
    $verify = (bool)$integration['verify_tls'];
    $headers = [];
    $sid = '';
    $config = is_array($integration['config'] ?? null) ? $integration['config'] : [];

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
        $csrf = (string)($auth['json']['session']['csrf'] ?? '');
        if ($csrf !== '') $headers['X-FTL-CSRF'] = $csrf;
    }

    try {
        if (str_starts_with($mode, 'action:')) {
            $action = substr($mode, 7);
            [$blocking, $timer] = match ($action) {
                'protection_enable' => [true, null],
                'protection_disable' => [false, null],
                'protection_pause_300' => [false, 300],
                default => throw new RuntimeException('Unsupported Pi-hole action.'),
            };
            $result = $http->request('POST', $base . '/api/dns/blocking', [
                'verify_tls' => $verify,
                'headers' => $headers,
                'json' => ['blocking' => $blocking, 'timer' => $timer],
            ]);
            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new RuntimeException('Pi-hole protection action returned HTTP ' . $result['status'] . '.');
            }
            return [
                'service' => 'Pi-hole',
                'action' => $action,
                'protection' => $blocking,
                'pause_seconds' => $timer,
            ];
        }

        $summary = $http->request('GET', $base . '/api/stats/summary', [
            'verify_tls' => $verify,
            'headers' => $headers,
        ]);
        if ($summary['status'] < 200 || $summary['status'] >= 300 || !is_array($summary['json'])) {
            throw new RuntimeException('Pi-hole summary returned HTTP ' . $summary['status'] . '.');
        }

        $blockingResult = $http->request('GET', $base . '/api/dns/blocking', [
            'verify_tls' => $verify,
            'headers' => $headers,
        ]);
        $protection = true;
        if ($blockingResult['status'] >= 200 && $blockingResult['status'] < 300 && is_array($blockingResult['json'])) {
            $rawBlocking = $blockingResult['json']['blocking'] ?? true;
            if (is_bool($rawBlocking)) {
                $protection = $rawBlocking;
            } elseif (is_numeric($rawBlocking)) {
                $protection = ((int)$rawBlocking) !== 0;
            } else {
                $normalized = strtolower(trim((string)$rawBlocking));
                $protection = in_array($normalized, ['true','1','on','enabled','active'], true);
            }
        }

        $j = $summary['json'];
        $queries = (int)($j['queries']['total'] ?? $j['dns_queries_today'] ?? $j['queries'] ?? 0);
        $blocked = (int)($j['queries']['blocked'] ?? $j['ads_blocked_today'] ?? $j['blocked'] ?? 0);
        $percent = (float)($j['queries']['percent_blocked'] ?? $j['ads_percentage_today'] ?? ($queries > 0 ? $blocked / $queries * 100 : 0));
        $clients = (int)($j['clients']['active'] ?? $j['clients']['total'] ?? $j['unique_clients'] ?? 0);

        $recentBlocked = [];
        $recentBlockedError = '';
        if (!empty($config['show_recent_blocked'])) {
            $count = max(1, min(10, (int)($config['recent_blocked_count'] ?? 3)));
            try {
                // Pi-hole v6 exposes the recent query log through /api/queries. Fetch a small
                // recent window and filter locally so this remains compatible with installations
                // that don't support every query-filter parameter yet.
                $queryLog = $http->request('GET', $base . '/api/queries?start=0&length=100', [
                    'verify_tls' => $verify,
                    'headers' => $headers,
                    'timeout' => 6,
                ]);
                if ($queryLog['status'] >= 200 && $queryLog['status'] < 300 && is_array($queryLog['json'])) {
                    $blockedStates = [
                        'GRAVITY','REGEX','DENYLIST','EXTERNAL_BLOCKED_IP','EXTERNAL_BLOCKED_NULL',
                        'EXTERNAL_BLOCKED_NXRA','QUERY_EXTERNAL_BLOCKED_EDE15','GRAVITY_CNAME',
                        'REGEX_CNAME','DENYLIST_CNAME','SPECIAL_DOMAIN',
                    ];
                    $rows = is_array($queryLog['json']['queries'] ?? null) ? $queryLog['json']['queries'] : [];
                    usort($rows, static fn(array $a, array $b): int => ((float)($b['time'] ?? 0)) <=> ((float)($a['time'] ?? 0)));
                    foreach ($rows as $row) {
                        if (!is_array($row)) continue;
                        $statusRaw = $row['status'] ?? '';
                        $status = strtoupper(trim((string)$statusRaw));
                        $blockedNumeric = [1,4,5,6,7,8,9,10,11,15,16,18];
                        $isBlocked = (is_int($statusRaw) || (is_string($statusRaw) && ctype_digit($statusRaw)))
                            ? in_array((int)$statusRaw, $blockedNumeric, true)
                            : in_array($status, $blockedStates, true);
                        if (!$isBlocked) continue;
                        $domain = trim((string)($row['domain'] ?? $row['query']['domain'] ?? ''));
                        if ($domain === '') continue;
                        $client = $row['client'] ?? [];
                        $clientName = is_array($client) ? trim((string)($client['name'] ?? $client['ip'] ?? '')) : trim((string)$client);
                        $recentBlocked[] = [
                            'domain' => $domain,
                            'client' => $clientName,
                            'time' => (float)($row['time'] ?? 0),
                            'reason' => $status,
                        ];
                        if (count($recentBlocked) >= $count) break;
                    }
                } else {
                    $recentBlockedError = 'Query log returned HTTP ' . $queryLog['status'] . '.';
                }
            } catch (Throwable $e) {
                $recentBlockedError = $e->getMessage();
            }
        }

        return [
            'service' => 'Pi-hole',
            'status' => 'online',
            'protection' => $protection,
            'queries' => $queries,
            'blocked' => $blocked,
            'blocked_percent' => round($percent, 1),
            'clients' => $clients,
            'recent_blocked' => $recentBlocked,
            'recent_blocked_error' => $recentBlockedError,
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
