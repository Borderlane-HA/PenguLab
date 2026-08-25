<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    $base = rtrim((string)($integration['base_url'] ?? ''), '/');
    $tokenId = trim((string)($integration['username'] ?? ''));
    $secret = trim((string)($integration['_secrets']['token_secret'] ?? ''));
    $verify = (bool)($integration['verify_tls'] ?? false);
    $cfg = is_array($integration['config'] ?? null) ? $integration['config'] : [];
    if ($base === '' || $tokenId === '' || $secret === '') throw new RuntimeException('PBS API token is incomplete.');

    $request = static function(string $path, array $query=[]) use ($http,$base,$tokenId,$secret,$verify): array {
        $url = $base . $path;
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $res = $http->request('GET', $url, [
            'verify_tls'=>$verify,
            'headers'=>['Authorization'=>'PBSAPIToken ' . $tokenId . ':' . $secret],
            'timeout'=>12,
        ]);
        if ($res['status'] === 401) throw new RuntimeException('PBS authentication failed. Check API Token ID and Secret.');
        if ($res['status'] === 403) throw new RuntimeException('PBS token has insufficient permissions. Grant an Audit-style read permission to the token.');
        if ($res['status'] < 200 || $res['status'] >= 300) throw new RuntimeException('PBS returned HTTP ' . $res['status'] . '.');
        if (!is_array($res['json']) || !array_key_exists('data',$res['json'])) throw new RuntimeException('PBS returned invalid JSON.');
        return $res['json'];
    };

    if ($mode !== 'summary') throw new RuntimeException('Unsupported PBS connector mode.');

    $versionResponse = $request('/api2/json/version');
    $versionData = is_array($versionResponse['data'] ?? null) ? $versionResponse['data'] : [];
    $days = max(1,min(180,(int)($cfg['summary_days'] ?? 30)));
    $since = time() - ($days * 86400);
    $tasks = [];
    $start = 0;
    $pageSize = 1000;
    // Fetch enough pages for busy PBS installations while keeping the widget request bounded.
    for ($page=0; $page<5; $page++) {
        $response = $request('/api2/json/nodes/localhost/tasks', ['since'=>$since,'start'=>$start,'limit'=>$pageSize]);
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
        foreach ($rows as $row) if (is_array($row)) $tasks[] = $row;
        $total = isset($response['total']) && is_numeric($response['total']) ? (int)$response['total'] : count($tasks);
        $start += count($rows);
        if (!$rows || $start >= $total || count($rows) < $pageSize) break;
    }

    $summary = [
        'backup'=>['label'=>'Backups','error'=>0,'warning'=>0,'ok'=>0],
        'prune'=>['label'=>'Prunes','error'=>0,'warning'=>0,'ok'=>0],
        'garbage_collection'=>['label'=>'Garbage collections','error'=>0,'warning'=>0,'ok'=>0],
        'sync'=>['label'=>'Syncs','error'=>0,'warning'=>0,'ok'=>0],
        'verify'=>['label'=>'Verify','error'=>0,'warning'=>0,'ok'=>0],
        'tape_backup'=>['label'=>'Tape Backup','error'=>0,'warning'=>0,'ok'=>0],
        'tape_restore'=>['label'=>'Tape Restore','error'=>0,'warning'=>0,'ok'=>0],
    ];

    $categoryFor = static function(string $worker): ?string {
        $worker = strtolower($worker);
        if (str_starts_with($worker,'backup')) return 'backup';
        if (str_starts_with($worker,'prune')) return 'prune';
        if (str_starts_with($worker,'garbage_collection') || str_starts_with($worker,'garbage-collection')) return 'garbage_collection';
        if (str_starts_with($worker,'sync') || str_starts_with($worker,'pull') || str_starts_with($worker,'push')) return 'sync';
        if (str_starts_with($worker,'verif')) return 'verify';
        if (str_starts_with($worker,'tape-backup') || str_starts_with($worker,'tape_backup')) return 'tape_backup';
        if (str_starts_with($worker,'tape-restore') || str_starts_with($worker,'tape_restore')) return 'tape_restore';
        return null;
    };
    $stateFor = static function(?string $status): ?string {
        if ($status === null || trim($status) === '') return null; // running tasks are not part of the completed summary
        $s = strtolower(trim($status));
        if ($s === 'ok' || str_starts_with($s,'ok ')) return 'ok';
        if (str_contains($s,'warn')) return 'warning';
        return 'error';
    };

    foreach ($tasks as $task) {
        $category = $categoryFor((string)($task['worker_type'] ?? ''));
        if ($category === null) continue;
        $state = $stateFor(isset($task['status']) ? (string)$task['status'] : null);
        if ($state === null) continue;
        $summary[$category][$state]++;
    }

    return [
        'service'=>'Proxmox Backup Server',
        'version'=>(string)($versionData['version'] ?? ''),
        'days'=>$days,
        'task_count'=>count($tasks),
        'task_summary'=>$summary,
    ];
};
