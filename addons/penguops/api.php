<?php
declare(strict_types=1);
use PenguLab\OpsStore;
use PenguLab\AiHub;
$p=$penguLab['db']->pdo();$auth=$penguLab['auth'];
if($addonAction==='report'){
    require_method('GET');if(session_status()===PHP_SESSION_ACTIVE)session_write_close();json_response(['ok'=>true,'report'=>OpsStore::report($penguLab)]);
}
require_admin($auth);
if($addonAction==='settings'){
    require_method('GET');$rules=[];
    foreach($penguLab['integrations']->list() as $i)$rules[]=['id'=>$i['id'],'name'=>$i['name'],'type'=>$i['type'],'rules'=>OpsStore::rules($p,$i['id'])];
    json_response(['ok'=>true,'rules'=>$rules,'profiles'=>AiHub::profiles($penguLab),'bases'=>AiHub::BASES]);
}
if($addonAction==='jobs'){
    require_method('GET');$rows=$p->query('SELECT id,profile_id,status,created_at,started_at,finished_at,result,usage_json FROM ops_ai_jobs ORDER BY created_at DESC LIMIT 10')->fetchAll();json_response(['ok'=>true,'jobs'=>$rows]);
}
require_method('POST');$in=json_body();
switch($addonAction){
case 'rules':
    $id=(string)($in['id']??'');if(!$penguLab['integrations']->full($id))throw new RuntimeException('Integration fehlt.');
    $r=$in['rules']??[];if(!is_array($r))throw new RuntimeException('Ungültige Regeln.');
    $warning=(int)($r['warning']??85);$critical=(int)($r['critical']??95);
    if($warning<1||$critical>100||$critical<=$warning)throw new RuntimeException('Grenzen müssen zwischen 1 und 100 liegen; kritisch muss größer sein.');
    $clean=static function($values,int $limit):array{if(!is_array($values))throw new RuntimeException('Liste erwartet.');$out=[];foreach($values as $v){if(!is_string($v)||mb_strlen($v)>200)throw new RuntimeException('Ungültige Ressourcen-ID.');if(trim($v)!=='')$out[]=trim($v);}if(count($out)>$limit)throw new RuntimeException('Zu viele Einträge (maximal '.$limit.').');return array_values(array_unique($out));};
    $entities=$clean($r['entities']??[],8);foreach($entities as $entity)if(!preg_match('/^(sensor|switch|light|cover)\.[a-zA-Z0-9_]+$/',$entity))throw new RuntimeException('Unterstützte HA-Domains: sensor, switch, light, cover.');
    $r=['warning'=>$warning,'critical'=>$critical,'required'=>$clean($r['required']??[],100),'entities'=>$entities,'entity_grace'=>max(0,min(86400,(int)($r['entity_grace']??300))),'maintenance_until'=>max(0,min(time()+30*86400,(int)($r['maintenance_until']??0))),'enabled'=>(bool)($r['enabled']??true)];
    $p->prepare('INSERT INTO ops_rules VALUES(?,?) ON CONFLICT(integration_id) DO UPDATE SET config_json=excluded.config_json')->execute([$id,json_encode($r,JSON_THROW_ON_ERROR)]);break;
case 'profile-save':AiHub::save($penguLab,$in);break;
case 'profile-delete':
    $q=$p->prepare("DELETE FROM ops_ai_profiles WHERE id=? AND NOT EXISTS(SELECT 1 FROM ops_ai_jobs WHERE profile_id=? AND status IN ('queued','running'))");$q->execute([(string)($in['id']??''),(string)($in['id']??'')]);if(!$q->rowCount())throw new RuntimeException('Profil fehlt oder hat einen aktiven Auftrag.');break;
case 'models':if(session_status()===PHP_SESSION_ACTIVE)session_write_close();json_response(['ok'=>true,'models'=>AiHub::models($penguLab,(string)($in['id']??''))]);
case 'preview':
    $profileId=(string)($in['id']??'');$profile=null;foreach(AiHub::profiles($penguLab) as $v)if($v['id']===$profileId)$profile=$v;
    if(!$profile)throw new RuntimeException('Profil fehlt.');
    $context=AiHub::context($penguLab);$token=bin2hex(random_bytes(20));
    $_SESSION['ops_preview']=['token'=>$token,'profile'=>$profile,'context'=>$context,'expires'=>time()+300];
    json_response(['ok'=>true,'token'=>$token,'context'=>$context,'profile'=>$profile]);
case 'analyze':
    $preview=$_SESSION['ops_preview']??null;
    if(!$preview||$preview['expires']<time()||!hash_equals($preview['token'],(string)($in['token']??'')))throw new RuntimeException('Vorschau abgelaufen. Bitte erneut öffnen.');
    $current=null;foreach(AiHub::profiles($penguLab) as $v)if($v['id']===$preview['profile']['id'])$current=$v;
    if($current!==$preview['profile'])throw new RuntimeException('KI-Profil geändert. Neue Vorschau erforderlich.');
    $job=AiHub::enqueue($penguLab,$current['id'],$preview['context']);unset($_SESSION['ops_preview']);json_response(['ok'=>true,'job'=>$job]);
case 'cancel':
    $p->prepare("UPDATE ops_ai_jobs SET status='cancelled',finished_at=?,result='Abgebrochen. Bereits beim Anbieter verarbeitete Tokens können berechnet werden.' WHERE id=? AND status IN ('queued','running')")->execute([time(),(string)($in['id']??'')]);break;
default:json_response(['ok'=>false,'error'=>'Unbekannte PenguOps-Aktion.'],404);
}
json_response(['ok'=>true]);
