<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)$integration['base_url'], '/');
    $tokenId = trim((string)($integration['username'] ?? ''));
    $secret = trim((string)($integration['_secrets']['token_secret'] ?? ''));
    $verify = (bool)$integration['verify_tls'];
    if ($tokenId === '' || $secret === '') throw new RuntimeException('Proxmox API token is incomplete.');

    $request = static function(string $path) use ($http, $base, $tokenId, $secret, $verify): mixed {
        $res = $http->request('GET', $base . $path, [
            'verify_tls' => $verify,
            'headers' => ['Authorization' => 'PVEAPIToken=' . $tokenId . '=' . $secret],
            'timeout' => 10,
        ]);
        if ($res['status'] === 401) throw new RuntimeException('Proxmox authentication failed. Check API Token ID and Secret.');
        if ($res['status'] === 403) throw new RuntimeException('Proxmox API token has insufficient permissions. A read-only PVEAuditor-style token is sufficient.');
        if ($res['status'] < 200 || $res['status'] >= 300) throw new RuntimeException('Proxmox returned HTTP ' . $res['status'] . '.');
        if (!is_array($res['json']) || !array_key_exists('data', $res['json'])) throw new RuntimeException('Proxmox returned invalid JSON.');
        return $res['json']['data'];
    };

    if ($mode !== 'summary') throw new RuntimeException('Unsupported Proxmox connector mode.');

    $versionData = $request('/api2/json/version');
    $resources = $request('/api2/json/cluster/resources');
    if (!is_array($resources)) $resources = [];

    $nodes = array_values(array_filter($resources, static fn($r): bool => is_array($r) && ($r['type'] ?? '') === 'node'));
    $guests = array_values(array_filter($resources, static fn($r): bool => is_array($r) && in_array(($r['type'] ?? ''), ['qemu','lxc'], true)));
    $storages = array_values(array_filter($resources, static fn($r): bool => is_array($r) && ($r['type'] ?? '') === 'storage'));
    $onlineNodes = array_values(array_filter($nodes, static fn($r): bool => strtolower((string)($r['status'] ?? '')) === 'online'));
    $runningGuests = array_values(array_filter($guests, static fn($r): bool => strtolower((string)($r['status'] ?? '')) === 'running'));

    $cpuValues = [];
    $mem = 0.0; $maxMem = 0.0;
    foreach ($onlineNodes as $node) {
        if (isset($node['cpu']) && is_numeric($node['cpu'])) $cpuValues[] = (float)$node['cpu'] * 100;
        if (isset($node['mem'],$node['maxmem']) && is_numeric($node['mem']) && is_numeric($node['maxmem'])) {
            $mem += (float)$node['mem']; $maxMem += (float)$node['maxmem'];
        }
    }
    $storageUsed = 0.0; $storageMax = 0.0;
    foreach ($storages as $storage) {
        if (!isset($storage['disk'],$storage['maxdisk']) || !is_numeric($storage['disk']) || !is_numeric($storage['maxdisk'])) continue;
        $storageUsed += (float)$storage['disk']; $storageMax += (float)$storage['maxdisk'];
    }

    return [
        'service' => 'Proxmox VE',
        'version' => is_array($versionData) ? (string)($versionData['version'] ?? '') : '',
        'nodes_total' => count($nodes),
        'nodes_online' => count($onlineNodes),
        'guests_total' => count($guests),
        'guests_running' => count($runningGuests),
        'vms_total' => count(array_filter($guests, static fn($r): bool => ($r['type'] ?? '') === 'qemu')),
        'lxcs_total' => count(array_filter($guests, static fn($r): bool => ($r['type'] ?? '') === 'lxc')),
        'cpu_percent' => $cpuValues ? round(array_sum($cpuValues) / count($cpuValues), 1) : null,
        'memory_percent' => $maxMem > 0 ? round(($mem / $maxMem) * 100, 1) : null,
        'storage_percent' => $storageMax > 0 ? round(($storageUsed / $storageMax) * 100, 1) : null,
    ];
};
