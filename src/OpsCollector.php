<?php
declare(strict_types=1);
namespace PenguLab;
final class OpsCollector
{
    public static function run(array $ctx): void
    {
        $p=$ctx['db']->pdo();
        foreach($ctx['integrations']->list() as $integration){
            $id=$integration['id'];$r=OpsStore::rules($p,$id);
            if(!$r['enabled']||$r['maintenance_until']>time())continue;
            $now=time();$ok=true;
            try{
                $summary=$ctx['integrations']->execute($id);
                if($integration['type']==='homeassistant'&&$r['entities']){
                    $res=$ctx['integrations']->execute($id,'states:'.base64_encode(json_encode($r['entities'])));
                    $summary['ops_entities']=$res['entities']??[];
                }
                $issues=OpsStore::evaluate($integration['type'],$summary,$r,$now)['issues'];
                OpsStore::record($p,$id,$issues,$now);
            }catch(\Throwable $e){
                // Do not resolve resource incidents during missing observations.
                $ok=false;$summary=[];
                OpsStore::record($p,$id,[['key'=>'api','severity'=>'warning','message'=>'API nicht erreichbar oder Zugriff verweigert; Dienstzustand unbekannt.']],$now,true);
            }
            $p->prepare('INSERT INTO ops_snapshots VALUES(?,?,?,?) ON CONFLICT(integration_id) DO UPDATE SET observed_at=excluded.observed_at,ok=excluded.ok,data_json=excluded.data_json')->execute([$id,$now,$ok?1:0,json_encode($summary,JSON_THROW_ON_ERROR)]);
        }
        $p->prepare("INSERT INTO ops_meta VALUES('collector_at',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([(string)time()]);
        $p->prepare('DELETE FROM ops_incidents WHERE resolved_at IS NOT NULL AND resolved_at<?')->execute([time()-90*86400]);
        $p->prepare("DELETE FROM ops_ai_jobs WHERE status NOT IN ('queued','running') AND created_at<?")->execute([time()-30*86400]);
    }
}
