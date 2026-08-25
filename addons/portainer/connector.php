<?php
declare(strict_types=1);

use PenguLab\HttpClient;

return static function(array $integration, HttpClient $http, string $mode='summary'): array {
    if ($mode !== 'summary') throw new RuntimeException('Unsupported Portainer connector mode.');
    $base = rtrim((string)($integration['base_url'] ?? ''), '/');
    $token = trim((string)($integration['_secrets']['api_key'] ?? ''));
    $verify = (bool)($integration['verify_tls'] ?? true);
    $cfg = is_array($integration['config'] ?? null) ? $integration['config'] : [];
    $endpointFilter = trim((string)($cfg['endpoint_id'] ?? ''));
    if ($base === '' || $token === '') throw new RuntimeException('Portainer URL and API token are required.');

    $request = static function(string $path) use ($http,$base,$token,$verify): mixed {
        $res = $http->request('GET', $base . $path, [
            'verify_tls'=>$verify,
            'headers'=>['X-API-Key'=>$token],
            'timeout'=>12,
        ]);
        if ($res['status'] === 401 || $res['status'] === 403) throw new RuntimeException('Portainer authentication failed or the API token has insufficient permissions.');
        if ($res['status'] < 200 || $res['status'] >= 300) throw new RuntimeException('Portainer returned HTTP ' . $res['status'] . '.');
        if (!is_array($res['json'])) throw new RuntimeException('Portainer returned invalid JSON.');
        return $res['json'];
    };

    $status=[];
    try { $status=$request('/api/status'); } catch (Throwable $e) { /* version is optional */ }
    $endpoints=$request('/api/endpoints');
    if (!is_array($endpoints)) $endpoints=[];
    if ($endpointFilter !== '') {
        $endpoints=array_values(array_filter($endpoints, static fn($e): bool => is_array($e) && (string)($e['Id'] ?? '') === $endpointFilter));
        if (!$endpoints) throw new RuntimeException('The selected Portainer Environment ID is not accessible.');
    }

    $containersTotal=0; $containersRunning=0; $envRows=[];
    foreach ($endpoints as $endpoint) {
        if (!is_array($endpoint)) continue;
        $id=(int)($endpoint['Id'] ?? 0); if ($id <= 0) continue;
        $online=(int)($endpoint['Status'] ?? 0) === 1;
        $row=['id'=>$id,'name'=>(string)($endpoint['Name'] ?? ('Environment '.$id)),'online'=>$online,'containers'=>null,'running'=>null];
        if ($online) {
            try {
                $containers=$request('/api/endpoints/' . $id . '/docker/containers/json?all=true');
                if (is_array($containers)) {
                    $row['containers']=count($containers);
                    $row['running']=count(array_filter($containers, static fn($c): bool => is_array($c) && strtolower((string)($c['State'] ?? '')) === 'running'));
                    $containersTotal += $row['containers'];
                    $containersRunning += $row['running'];
                }
            } catch (Throwable $e) { /* keep endpoint visible even if Docker proxy is unavailable */ }
        }
        $envRows[]=$row;
    }
    $stacks=[];
    try { $stacks=$request('/api/stacks'); } catch (Throwable $e) { $stacks=[]; }
    if (!is_array($stacks)) $stacks=[];

    $enabled = static fn(string $key,bool $default=true): bool => array_key_exists($key,$cfg) ? (bool)$cfg[$key] : $default;
    $onlineCount=count(array_filter($envRows,static fn($e): bool => !empty($e['online'])));
    $metrics=[];
    if ($enabled('show_environments',true)) $metrics[]=['label'=>'Environments','value'=>$onlineCount . '/' . count($envRows) . ' online'];
    if ($enabled('show_containers',true)) $metrics[]=['label'=>'Container','value'=>$containersRunning . '/' . $containersTotal . ' running'];
    if ($enabled('show_stacks',true)) $metrics[]=['label'=>'Stacks','value'=>count($stacks)];
    $rows=[];
    $limit=max(1,min(10,(int)($cfg['environment_limit'] ?? 5)));
    if ($enabled('show_environment_list',true)) foreach (array_slice($envRows,0,$limit) as $e) {
        $meta=$e['containers']===null?'':($e['running'].'/'.$e['containers'].' container running');
        $rows[]=['label'=>$e['name'],'value'=>$e['online']?'Online':'Offline','meta'=>$meta];
    }
    return [
        'service'=>'Portainer',
        'status'=>'Online',
        'version'=>(string)($status['Version'] ?? $status['version'] ?? ''),
        'metrics'=>$metrics,
        'rows'=>$rows,
        'environments_total'=>count($envRows),
        'environments_online'=>$onlineCount,
        'containers_total'=>$containersTotal,
        'containers_running'=>$containersRunning,
        'stacks_total'=>count($stacks),
        'environments'=>$envRows,
    ];
};
