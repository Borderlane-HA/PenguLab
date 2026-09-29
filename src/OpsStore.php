<?php
declare(strict_types=1);
namespace PenguLab;
use PDO;
final class OpsStore
{
    public static function install(PDO $p): void
    {
        $p->exec("CREATE TABLE IF NOT EXISTS ops_snapshots (integration_id TEXT PRIMARY KEY, observed_at INTEGER NOT NULL, ok INTEGER NOT NULL, data_json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS ops_rules (integration_id TEXT PRIMARY KEY, config_json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS ops_incidents (id TEXT PRIMARY KEY, integration_id TEXT NOT NULL, rule_key TEXT NOT NULL, severity TEXT NOT NULL, message TEXT NOT NULL, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL, resolved_at INTEGER);
CREATE INDEX IF NOT EXISTS ops_incident_lookup ON ops_incidents(integration_id,rule_key,resolved_at);
CREATE TABLE IF NOT EXISTS ops_ai_profiles (id TEXT PRIMARY KEY, name TEXT NOT NULL, provider TEXT NOT NULL, base_url TEXT NOT NULL, model TEXT NOT NULL, secret_enc TEXT NOT NULL, max_tokens INTEGER NOT NULL, timeout INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS ops_ai_jobs (id TEXT PRIMARY KEY, profile_id TEXT NOT NULL, status TEXT NOT NULL, created_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER, context_json TEXT NOT NULL, result TEXT NOT NULL DEFAULT '', usage_json TEXT NOT NULL DEFAULT '{}');
CREATE TABLE IF NOT EXISTS ops_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);");
    }
    public static function rules(PDO $p,string $id): array
    {
        $q=$p->prepare('SELECT config_json FROM ops_rules WHERE integration_id=?');$q->execute([$id]);
        return (json_decode((string)$q->fetchColumn(),true) ?: []) + ['warning'=>85,'critical'=>95,'required'=>[],'entities'=>[],'entity_grace'=>300,'maintenance_until'=>0,'enabled'=>true];
    }
    public static function evaluate(string $type,array $s,array $r,int $now): array
    {
        $issues=[];$checks=1;
        if(!in_array($type,['proxmox','docker','portainer'],true))$r['required']=[];
        $add=static function(string $key,string $severity,string $message) use (&$issues): void {$issues[]=['key'=>$key,'severity'=>$severity,'message'=>$message];};
        foreach (['cpu_percent'=>'CPU','memory_percent'=>'RAM','storage_percent'=>'Speicher'] as $k=>$label) {
            if (!isset($s[$k]) || !is_numeric($s[$k])) continue;
            $checks++;$v=(float)$s[$k];
            if ($v>=$r['warning']) $add($k,$v>=$r['critical']?'critical':'warning',"$label: $v % (Grenzen {$r['warning']}/{$r['critical']} %)");
        }
        foreach (($s['ops_resources']??[]) as $resource) {
            if (isset($resource['used_percent'])&&is_numeric($resource['used_percent'])) {
                $checks++;$v=(float)$resource['used_percent'];
                if($v>=$r['warning'])$add('storage:'.$resource['id'],$v>=$r['critical']?'critical':'warning','Speicher '.$resource['id'].': '.$v.' %');
            }
        }
        if(isset($s['nodes_total'],$s['nodes_online'])){
            $checks++;if($s['nodes_online']<$s['nodes_total'])$add('nodes','critical','Nicht alle Proxmox-Nodes sind online.');
        }
        $items=$s['containers']??$s['ops_guests']??[];
        foreach($items as $item){
            $id=(string)($item['id']??$item['name']??'');$state=strtolower((string)($item['state']??''));$checks++;
            if(str_contains(strtolower((string)($item['status']??'')),'(unhealthy)'))$add('unhealthy:'.$id,'critical','Container '.$id.' meldet unhealthy.');
            if(in_array($id,$r['required'],true)&&$state!=='running')$add('required:'.$id,'critical','Pflicht-Ressource '.$id.' ist '.$state.'.');
        }
        foreach($r['required'] as $id){
            if(!array_filter($items,static fn($x)=>(string)($x['id']??$x['name']??'')===$id))$add('missing:'.$id,'warning','Pflicht-Ressource '.$id.' fehlt in den gelieferten Daten.');
        }
        foreach(($s['ops_entities']??[]) as $entity){
            $checks++;$id=(string)$entity['entity_id'];
            if(!empty($entity['error'])){$add('entity-read:'.$id,'warning','Zustand von '.$id.' konnte nicht gelesen werden.');continue;}
            $changed=strtotime((string)($entity['last_changed']??''));
            if(in_array($entity['state']??'',['unavailable','unknown'],true)&&$changed!==false&&$now-$changed>=$r['entity_grace'])$add('entity:'.$id,'warning',$id.' ist seit '.gmdate(DATE_ATOM,$changed).' '.$entity['state'].'.');
        }
        if($type==='pbs'){
            $count=(int)($s['task_summary']['backup']['error']??0);$checks++;
            if($count>0)$add('backup-errors','warning',"$count fehlgeschlagene Backup-Tasks im Abfragefenster (".(int)($s['days']??0).' Tage).');
        }
        if(isset($s['ops_partial'])&&$s['ops_partial'])$add('partial','warning','Ein Teil der Daten konnte nicht abgefragt werden.');
        return ['issues'=>$issues,'checks'=>$checks];
    }
    public static function record(PDO $p,string $id,array $issues,int $now,bool $preserveMissing=false): void
    {
        $p->beginTransaction();
        try{
            $q=$p->prepare('SELECT * FROM ops_incidents WHERE integration_id=? AND resolved_at IS NULL');$q->execute([$id]);$open=[];
            foreach($q->fetchAll() as $row)$open[$row['rule_key']]=$row;
            foreach($issues as $issue){
                if(isset($open[$issue['key']])){
                    $p->prepare('UPDATE ops_incidents SET last_seen=?,severity=?,message=? WHERE id=?')->execute([$now,$issue['severity'],$issue['message'],$open[$issue['key']]['id']]);unset($open[$issue['key']]);
                }else $p->prepare('INSERT INTO ops_incidents VALUES(?,?,?,?,?,?,?,NULL)')->execute([Database::uuid('incident'),$id,$issue['key'],$issue['severity'],$issue['message'],$now,$now]);
            }
            if(!$preserveMissing)foreach($open as $row)$p->prepare('UPDATE ops_incidents SET resolved_at=? WHERE id=?')->execute([$now,$row['id']]);
            $p->commit();
        }catch(\Throwable $e){$p->rollBack();throw $e;}
    }
    public static function report(array $ctx): array
    {
        $p=$ctx['db']->pdo();$now=time();$services=[];$all=[];$unknown=0;$maintenance=0;$critical=0;$warning=0;
        foreach($ctx['integrations']->list() as $integration){
            $id=$integration['id'];if(isset($ctx['auth'])&&!$ctx['auth']->canIntegration($id))continue;
            $r=self::rules($p,$id);if(!$r['enabled'])continue;
            $q=$p->prepare('SELECT * FROM ops_snapshots WHERE integration_id=?');$q->execute([$id]);$row=$q->fetch();
            $age=$row?$now-(int)$row['observed_at']:null;$issues=[];$checks=0;
            if($r['maintenance_until']>$now){$status='maintenance';$maintenance++;}
            elseif(!$row||$age>300){$status='unknown';$unknown++;}
            elseif(!$row['ok']){$status='unknown';$unknown++;$issues=[['key'=>'api','severity'=>'warning','message'=>'API nicht erreichbar oder Zugriff verweigert; Dienstzustand unbekannt.']];}
            else{$evaluation=self::evaluate($integration['type'],json_decode($row['data_json'],true)?:[],$r,$now);$issues=$evaluation['issues'];$checks=$evaluation['checks'];$status=$issues?'warning':'healthy';}
            foreach($issues as $issue){if($issue['severity']==='critical'){$critical++;$status='critical';}else $warning++;}
            $services[]=['id'=>$id,'name'=>$integration['name'],'type'=>$integration['type'],'status'=>$status,'age'=>$age,'observed_at'=>$row?(int)$row['observed_at']:null,'checks'=>$checks,'issues'=>$issues];
            $q=$p->prepare('SELECT * FROM ops_incidents WHERE integration_id=? ORDER BY last_seen DESC LIMIT 50');$q->execute([$id]);$all=array_merge($all,$q->fetchAll());
        }
        usort($all,static fn($a,$b)=>$b['last_seen']<=>$a['last_seen']);
        $assessed=count($services)-$unknown-$maintenance;
        $score=$assessed>0?max(0,100-$critical*15-$warning*5):null;
        return ['score'=>$score,'score_label'=>'Teilbewertung: API und konfigurierte Prüfungen','deductions'=>['critical'=>15,'warning'=>5],'critical'=>$critical,'warning'=>$warning,'unknown'=>$unknown,'maintenance'=>$maintenance,'assessed'=>$assessed,'total'=>count($services),'services'=>$services,'incidents'=>array_slice($all,0,200),'generated_at'=>$now,'worker_at'=>(int)$p->query("SELECT value FROM ops_meta WHERE key='collector_at'")->fetchColumn()];
    }
}
