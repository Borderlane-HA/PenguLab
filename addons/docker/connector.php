<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    if ($mode !== 'summary') throw new RuntimeException('Unsupported Docker connector mode.');
    $base = rtrim((string)($integration['base_url'] ?? ''), '/');
    $user = trim((string)($integration['username'] ?? ''));
    $password = (string)($integration['_secrets']['password'] ?? '');
    $verify = (bool)($integration['verify_tls'] ?? false);
    if ($base === '') throw new RuntimeException('Docker API URL is required.');

    $request = static function(string $path) use ($http,$base,$user,$password,$verify): mixed {
        $options = ['verify_tls'=>$verify,'timeout'=>10];
        if ($user !== '') $options['basic'] = $user . ':' . $password;
        $res = $http->request('GET', $base . $path, $options);
        if ($res['status'] === 401 || $res['status'] === 403) throw new RuntimeException('Docker API authentication failed or access is denied.');
        if ($res['status'] < 200 || $res['status'] >= 300) throw new RuntimeException('Docker API returned HTTP ' . $res['status'] . '.');
        if (!is_array($res['json'])) throw new RuntimeException('Docker API returned invalid JSON.');
        return $res['json'];
    };

    $version = $request('/version');
    $apiVersion = preg_match('/^\d+\.\d+$/', (string)($version['ApiVersion'] ?? '')) ? (string)$version['ApiVersion'] : '';
    $prefix = $apiVersion !== '' ? '/v' . $apiVersion : '';
    $info = $request($prefix . '/info');
    $containers = $request($prefix . '/containers/json?all=true');
    $images = $request($prefix . '/images/json');
    if (!is_array($containers)) $containers=[];
    if (!is_array($images)) $images=[];

    $running = 0; $paused = 0; $stopped = 0;
    $containerRows=[];
    foreach ($containers as $c) {
        if (!is_array($c)) continue;
        $state = strtolower((string)($c['State'] ?? 'unknown'));
        if ($state === 'running') $running++; elseif ($state === 'paused') $paused++; else $stopped++;
        $names = is_array($c['Names'] ?? null) ? $c['Names'] : [];
        $name = trim((string)($names[0] ?? ''), '/');
        $containerRows[] = [
            'name'=>$name !== '' ? $name : substr((string)($c['Id'] ?? ''),0,12),
            'state'=>$state,
            'status'=>(string)($c['Status'] ?? ''),
            'image'=>(string)($c['Image'] ?? ''),
        ];
    }
    usort($containerRows, static function(array $a,array $b): int {
        $rank = static fn(string $s): int => $s === 'running' ? 0 : ($s === 'paused' ? 1 : 2);
        return [$rank($a['state']),strtolower($a['name'])] <=> [$rank($b['state']),strtolower($b['name'])];
    });

    $memTotal = is_numeric($info['MemTotal'] ?? null) ? (float)$info['MemTotal'] : null;
    $cfg = is_array($integration['config'] ?? null) ? $integration['config'] : [];
    $enabled = static fn(string $key,bool $default=true): bool => array_key_exists($key,$cfg) ? (bool)$cfg[$key] : $default;
    $metrics=[];
    if ($enabled('show_containers',true)) {
        $metrics[]=['label'=>'Container','value'=>$running . '/' . count($containers) . ' running'];
        $metrics[]=['label'=>'Stopped','value'=>$stopped];
    }
    if ($enabled('show_images',true)) $metrics[]=['label'=>'Images','value'=>count($images)];
    if ($enabled('show_resources',true)) {
        if (is_numeric($info['NCPU'] ?? null)) $metrics[]=['label'=>'CPUs','value'=>(int)$info['NCPU']];
        if ($memTotal !== null) $metrics[]=['label'=>'RAM','value'=>round($memTotal/1073741824,1) . ' GB'];
    }
    $limit=max(1,min(10,(int)($cfg['container_limit'] ?? 5)));
    $rows=[];
    if ($enabled('show_recent_containers',true)) foreach (array_slice($containerRows,0,$limit) as $c) {
        $rows[]=['label'=>$c['name'],'value'=>ucfirst($c['state']),'meta'=>$c['image']];
    }
    return [
        'service'=>'Docker Engine',
        'status'=>'Online',
        'version'=>(string)($version['Version'] ?? $info['ServerVersion'] ?? ''),
        'api_version'=>(string)($version['ApiVersion'] ?? ''),
        'metrics'=>$metrics,
        'rows'=>$rows,
        'containers_total'=>count($containers),
        'containers_running'=>$running,
        'containers_paused'=>$paused,
        'containers_stopped'=>$stopped,
        'images_total'=>count($images),
        'cpus'=>is_numeric($info['NCPU'] ?? null) ? (int)$info['NCPU'] : null,
        'memory_bytes'=>$memTotal,
        'name'=>(string)($info['Name'] ?? ''),
        'containers'=>$containerRows,
    ];
};
