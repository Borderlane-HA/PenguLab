<?php
declare(strict_types=1);

use PenguLab\Database;

/** @var array $penguLab */
/** @var string $addonAction */
$db = $penguLab['db'];
$pdo = $db->pdo();

switch ($addonAction) {
    case 'get':
        require_method('GET');
        $networks = ipm_networks($pdo);
        $devices = $pdo->query('SELECT * FROM ipm_devices ORDER BY ip ASC')->fetchAll();
        json_response(['ok'=>true,'networks'=>$networks,'devices'=>$devices]);

    case 'save-network':
        require_method('POST');
        $input = json_body();
        $id = trim((string)($input['id'] ?? '')) ?: Database::uuid('net');
        $name = mb_substr(trim(strip_tags((string)($input['name'] ?? ''))), 0, 100);
        $cidr = ipm_normalize_cidr((string)($input['cidr'] ?? ''));
        if ($name === '' || $cidr === '') throw new RuntimeException('Name and a valid IPv4 CIDR are required.');
        $gateway = ipm_optional_ip((string)($input['gateway'] ?? ''));
        $dhcpStart = ipm_optional_ip((string)($input['dhcp_start'] ?? ''));
        $dhcpEnd = ipm_optional_ip((string)($input['dhcp_end'] ?? ''));
        foreach ([$gateway,$dhcpStart,$dhcpEnd] as $candidate) {
            if ($candidate !== '' && !ipm_ip_in_cidr($candidate,$cidr)) throw new RuntimeException($candidate . ' is outside ' . $cidr);
        }
        if ($dhcpStart !== '' && $dhcpEnd !== '' && ip2long($dhcpStart) > ip2long($dhcpEnd)) throw new RuntimeException('DHCP start must be before DHCP end.');
        $dns = ipm_dns($input['dns'] ?? []);
        $vlan = mb_substr(trim(strip_tags((string)($input['vlan'] ?? ''))),0,20);
        $description = mb_substr(trim(strip_tags((string)($input['description'] ?? ''))),0,500);
        $now=gmdate(DATE_ATOM);
        $existing=$pdo->prepare('SELECT created_at FROM ipm_networks WHERE id=:id'); $existing->execute(['id'=>$id]); $created=$existing->fetchColumn() ?: $now;
        $stmt=$pdo->prepare('INSERT INTO ipm_networks(id,name,cidr,vlan,gateway,dhcp_start,dhcp_end,dns_json,description,source,created_at,updated_at) VALUES(:id,:name,:cidr,:vlan,:gateway,:dhcp_start,:dhcp_end,:dns,:description,\'manual\',:created,:updated) ON CONFLICT(id) DO UPDATE SET name=excluded.name,cidr=excluded.cidr,vlan=excluded.vlan,gateway=excluded.gateway,dhcp_start=excluded.dhcp_start,dhcp_end=excluded.dhcp_end,dns_json=excluded.dns_json,description=excluded.description,updated_at=excluded.updated_at');
        $stmt->execute(['id'=>$id,'name'=>$name,'cidr'=>$cidr,'vlan'=>$vlan,'gateway'=>$gateway,'dhcp_start'=>$dhcpStart,'dhcp_end'=>$dhcpEnd,'dns'=>json_encode($dns,JSON_UNESCAPED_SLASHES),'description'=>$description,'created'=>$created,'updated'=>$now]);
        json_response(['ok'=>true,'saved_id'=>$id,'networks'=>ipm_networks($pdo)]);

    case 'delete-network':
        require_method('POST');
        $id=trim((string)(json_body()['id']??''));
        $pdo->prepare('DELETE FROM ipm_networks WHERE id=:id')->execute(['id'=>$id]);
        json_response(['ok'=>true,'networks'=>ipm_networks($pdo)]);

    case 'save-device':
        require_method('POST');
        $input=json_body();
        $networkId=trim((string)($input['network_id']??''));
        $netStmt=$pdo->prepare('SELECT * FROM ipm_networks WHERE id=:id'); $netStmt->execute(['id'=>$networkId]); $network=$netStmt->fetch();
        if (!$network) throw new RuntimeException('Network not found.');
        $id=trim((string)($input['id']??'')) ?: Database::uuid('device');
        $hostname=mb_substr(trim(strip_tags((string)($input['hostname']??''))),0,120);
        $ip=trim((string)($input['ip']??''));
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !ipm_ip_in_cidr($ip,(string)$network['cidr'])) throw new RuntimeException('IP address is outside the selected network.');
        $mac=ipm_mac((string)($input['mac']??''));
        $type=in_array(($input['type']??''),['static','reservation','dhcp'],true)?(string)$input['type']:'static';
        $description=mb_substr(trim(strip_tags((string)($input['description']??''))),0,500);
        $now=gmdate(DATE_ATOM); $existing=$pdo->prepare('SELECT created_at FROM ipm_devices WHERE id=:id');$existing->execute(['id'=>$id]);$created=$existing->fetchColumn()?:$now;
        $stmt=$pdo->prepare('INSERT INTO ipm_devices(id,network_id,hostname,ip,mac,type,description,source,created_at,updated_at) VALUES(:id,:network_id,:hostname,:ip,:mac,:type,:description,\'manual\',:created,:updated) ON CONFLICT(id) DO UPDATE SET network_id=excluded.network_id,hostname=excluded.hostname,ip=excluded.ip,mac=excluded.mac,type=excluded.type,description=excluded.description,updated_at=excluded.updated_at');
        try {$stmt->execute(['id'=>$id,'network_id'=>$networkId,'hostname'=>$hostname,'ip'=>$ip,'mac'=>$mac,'type'=>$type,'description'=>$description,'created'=>$created,'updated'=>$now]);}
        catch (PDOException $e) { if (str_contains($e->getMessage(),'UNIQUE')) throw new RuntimeException('This IP address is already assigned in the network.'); throw $e; }
        json_response(['ok'=>true,'devices'=>$pdo->query('SELECT * FROM ipm_devices ORDER BY ip ASC')->fetchAll(),'networks'=>ipm_networks($pdo)]);

    case 'delete-device':
        require_method('POST');
        $id=trim((string)(json_body()['id']??''));
        $pdo->prepare('DELETE FROM ipm_devices WHERE id=:id')->execute(['id'=>$id]);
        json_response(['ok'=>true,'devices'=>$pdo->query('SELECT * FROM ipm_devices ORDER BY ip ASC')->fetchAll(),'networks'=>ipm_networks($pdo)]);

    case 'suggest-ip':
        require_method('GET');
        $networkId=trim((string)($_GET['network_id']??''));
        $stmt=$pdo->prepare('SELECT * FROM ipm_networks WHERE id=:id');$stmt->execute(['id'=>$networkId]);$network=$stmt->fetch();
        if(!$network) throw new RuntimeException('Network not found.');
        json_response(['ok'=>true,'ip'=>ipm_suggest_ip($pdo,$network)]);

    default:
        json_response(['ok'=>false,'error'=>'Unknown IP Manager action.'],404);
}

function ipm_networks(PDO $pdo): array {
    $rows=$pdo->query('SELECT n.*, (SELECT COUNT(*) FROM ipm_devices d WHERE d.network_id=n.id) AS used_count FROM ipm_networks n ORDER BY name COLLATE NOCASE')->fetchAll();
    foreach($rows as &$row){$row['dns']=json_decode((string)$row['dns_json'],true)?:[];unset($row['dns_json']);$range=ipm_range((string)$row['cidr']);$row['capacity']=$range['capacity'];$row['used_count']=(int)$row['used_count'];$row['free_count']=max(0,$row['capacity']-$row['used_count']);}
    unset($row);return $rows;
}
function ipm_normalize_cidr(string $cidr): string { $cidr=trim($cidr);if(!preg_match('~^([0-9.]+)/(\d{1,2})$~',$cidr,$m))return'';$ip=$m[1];$mask=(int)$m[2];if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||$mask<1||$mask>30)return'';$long=ip2long($ip);$netmask=(0xFFFFFFFF << (32-$mask)) & 0xFFFFFFFF;$network=$long & $netmask;return long2ip($network).'/'.$mask; }
function ipm_optional_ip(string $ip): string {$ip=trim($ip);if($ip==='')return'';if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('Invalid IPv4 address: '.$ip);return$ip;}
function ipm_dns(mixed $value): array {$parts=is_array($value)?$value:preg_split('/[\s,;]+/',(string)$value);$out=[];foreach($parts?:[] as $p){$p=trim((string)$p);if($p==='')continue;if(!filter_var($p,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('Invalid DNS address: '.$p);$out[]=$p;}return array_values(array_unique($out));}
function ipm_mac(string $mac): string {$clean=strtolower((string)preg_replace('/[^0-9a-f]/i','',$mac));if($clean==='')return'';if(strlen($clean)!==12)throw new RuntimeException('Invalid MAC address.');return implode(':',str_split($clean,2));}
function ipm_range(string $cidr): array {[$ip,$maskRaw]=explode('/',$cidr);$mask=(int)$maskRaw;$base=ip2long($ip);$size=2**(32-$mask);$network=$base;$broadcast=$base+$size-1;return['network'=>$network,'first'=>$network+1,'last'=>$broadcast-1,'broadcast'=>$broadcast,'capacity'=>max(0,$size-2)];}
function ipm_ip_in_cidr(string $ip,string $cidr): bool {$range=ipm_range($cidr);$v=ip2long($ip);return$v>=$range['first']&&$v<=$range['last'];}
function ipm_suggest_ip(PDO $pdo,array $network): string {$range=ipm_range((string)$network['cidr']);$used=[];$stmt=$pdo->prepare('SELECT ip FROM ipm_devices WHERE network_id=:id');$stmt->execute(['id'=>$network['id']]);foreach($stmt->fetchAll() as $r)$used[(string)$r['ip']]=true;if($network['gateway']!=='')$used[(string)$network['gateway']]=true;$dhcpStart=$network['dhcp_start']!==''?ip2long((string)$network['dhcp_start']):null;$dhcpEnd=$network['dhcp_end']!==''?ip2long((string)$network['dhcp_end']):null;for($v=$range['first'];$v<=$range['last'];$v++){if($dhcpStart!==null&&$dhcpEnd!==null&&$v>=$dhcpStart&&$v<=$dhcpEnd)continue;$ip=long2ip($v);if(!isset($used[$ip]))return$ip;}for($v=$range['first'];$v<=$range['last'];$v++){$ip=long2ip($v);if(!isset($used[$ip]))return$ip;}return'';}
