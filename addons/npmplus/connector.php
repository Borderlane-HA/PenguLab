<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $identity = trim((string)($integration['username'] ?? ''));
    $password = (string)($integration['_secrets']['password'] ?? '');
    $verify = (bool)$integration['verify_tls'];
    if ($identity === '' || $password === '') throw new RuntimeException('NPMplus login credentials are missing.');

    $login = $http->request('POST', $base . '/api/tokens', [
        'verify_tls' => $verify,
        'json' => ['identity' => $identity, 'secret' => $password],
        'timeout' => 10,
    ]);
    if ($login['status'] < 200 || $login['status'] >= 300) {
        if ($login['status'] === 401 || $login['status'] === 403) throw new RuntimeException('NPMplus authentication failed. Check login and password.');
        if ($login['status'] === 429) throw new RuntimeException('NPMplus temporarily rate-limited the login. Try again shortly.');
        throw new RuntimeException('NPMplus login returned HTTP ' . $login['status'] . '.');
    }

    // Current NPMplus versions use secure HttpOnly cookies for sessions. Older/upstream
    // versions may still return a bearer token. Support both without depending on a
    // particular cookie name, because NPMplus changed its cookie handling in 2026.
    $headers = [];
    $cookies = [];
    foreach ((array)($login['headers']['set-cookie'] ?? []) as $cookieLine) {
        $pair = trim((string)explode(';', (string)$cookieLine, 2)[0]);
        if ($pair !== '' && str_contains($pair, '=')) $cookies[] = $pair;
    }
    if ($cookies !== []) $headers['Cookie'] = implode('; ', array_values(array_unique($cookies)));
    $token = is_array($login['json'] ?? null) ? trim((string)($login['json']['token'] ?? '')) : '';
    if ($token !== '') $headers['Authorization'] = 'Bearer ' . $token;
    if ($headers === []) throw new RuntimeException('NPMplus login succeeded but returned no usable session cookie or token.');

    $hostsResponse = $http->request('GET', $base . '/api/nginx/proxy-hosts?expand=owner,access_list,certificate', [
        'verify_tls' => $verify,
        'headers' => $headers,
        'timeout' => 12,
    ]);
    if ($hostsResponse['status'] < 200 || $hostsResponse['status'] >= 300) {
        if ($hostsResponse['status'] === 401 || $hostsResponse['status'] === 403) throw new RuntimeException('NPMplus session was not accepted for Proxy Hosts.');
        throw new RuntimeException('NPMplus Proxy Hosts returned HTTP ' . $hostsResponse['status'] . '.');
    }
    if (!is_array($hostsResponse['json'])) throw new RuntimeException('NPMplus returned invalid Proxy Host JSON.');

    $raw = $hostsResponse['json'];
    if (isset($raw['data']) && is_array($raw['data'])) $raw = $raw['data'];
    if (isset($raw['items']) && is_array($raw['items'])) $raw = $raw['items'];
    if (!array_is_list($raw)) {
        // Some API wrappers return an object keyed by id. Only keep array rows.
        $raw = array_values(array_filter($raw, 'is_array'));
    }

    $hosts = [];
    foreach ($raw as $index => $row) {
        if (!is_array($row)) continue;
        $domainsRaw = $row['domain_names'] ?? $row['domains'] ?? [];
        if (is_string($domainsRaw)) $domainsRaw = preg_split('/[\s,]+/', $domainsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $domains = [];
        foreach ((array)$domainsRaw as $domain) {
            $domain = strtolower(trim((string)$domain));
            if ($domain !== '') $domains[] = $domain;
        }
        $domains = array_values(array_unique($domains));
        if ($domains === []) continue;
        $primary = '';
        foreach ($domains as $domain) {
            if (!str_contains($domain, '*')) { $primary = $domain; break; }
        }
        if ($primary === '') $primary = $domains[0];

        $id = (string)($row['id'] ?? $row['uuid'] ?? '');
        if ($id === '') $id = substr(hash('sha256', implode('|', $domains) . '|' . ($row['forward_host'] ?? '') . '|' . ($row['forward_port'] ?? '')), 0, 16);
        $enabledRaw = $row['enabled'] ?? true;
        $enabled = is_bool($enabledRaw) ? $enabledRaw : !in_array(strtolower(trim((string)$enabledRaw)), ['0','false','off','disabled'], true);
        $certificateId = (int)($row['certificate_id'] ?? 0);
        $sslForced = (bool)($row['ssl_forced'] ?? $row['force_ssl'] ?? false);
        $scheme = ($certificateId > 0 || $sslForced) ? 'https' : 'http';
        $importable = $primary !== '' && !str_contains($primary, '*') && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $primary) === 1;
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $description = trim((string)($row['description'] ?? $meta['description'] ?? ''));
        $displayName = trim((string)($row['name'] ?? $meta['name'] ?? $description));
        if ($displayName === '') {
            $label = explode('.', ltrim($primary, '*.'))[0] ?? $primary;
            $displayName = ucwords(str_replace(['-','_'], ' ', $label));
        }
        $forwardScheme = trim((string)($row['forward_scheme'] ?? 'http')) ?: 'http';
        $forwardHost = trim((string)($row['forward_host'] ?? ''));
        $forwardPort = trim((string)($row['forward_port'] ?? ''));
        $hosts[] = [
            'key' => 'host:' . $id,
            'id' => $id,
            'name' => mb_substr($displayName, 0, 100),
            'domain' => $primary,
            'domains' => $domains,
            'url' => $importable ? ($scheme . '://' . $primary) : '',
            'enabled' => $enabled,
            'importable' => $importable,
            'https' => $scheme === 'https',
            'ssl_forced' => $sslForced,
            'certificate_id' => $certificateId,
            'forward_scheme' => $forwardScheme,
            'forward_host' => $forwardHost,
            'forward_port' => $forwardPort,
            'backend' => $forwardHost !== '' ? ($forwardScheme . '://' . $forwardHost . ($forwardPort !== '' ? ':' . $forwardPort : '')) : '',
            'description' => $description,
            'modified_on' => (string)($row['modified_on'] ?? ''),
        ];
    }
    usort($hosts, static fn(array $a, array $b): int => ((int)!empty($b['enabled']) <=> (int)!empty($a['enabled'])) ?: ((int)!empty($b['importable']) <=> (int)!empty($a['importable'])) ?: strnatcasecmp((string)$a['domain'], (string)$b['domain']));

    if ($mode === 'proxy_hosts') return ['service'=>'NPMplus','status'=>'online','hosts'=>$hosts];
    if ($mode !== 'summary') throw new RuntimeException('Unsupported NPMplus connector mode.');

    $enabledCount = count(array_filter($hosts, static fn(array $h): bool => !empty($h['enabled'])));
    $httpsCount = count(array_filter($hosts, static fn(array $h): bool => !empty($h['https'])));
    return [
        'service' => 'NPMplus',
        'status' => 'online',
        'hosts_total' => count($hosts),
        'hosts_enabled' => $enabledCount,
        'hosts_disabled' => max(0, count($hosts) - $enabledCount),
        'https_hosts' => $httpsCount,
        'metrics' => [
            ['label'=>'Proxy Hosts','value'=>count($hosts)],
            ['label'=>'Aktiv','value'=>$enabledCount],
            ['label'=>'Deaktiviert','value'=>max(0, count($hosts)-$enabledCount)],
            ['label'=>'HTTPS','value'=>$httpsCount],
        ],
    ];
};
