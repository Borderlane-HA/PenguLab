<?php
declare(strict_types=1);
namespace PenguLab {
    // Secrets and network fixture only; production uses sodium and curl.
    final class Secrets {public function encrypt(array $p):string{return base64_encode(json_encode($p));}public function decrypt(string $p):array{return json_decode(base64_decode($p),true)?:[];}}
    final class HttpClient {public function request(string $m,string $url,array $o=[]):array{throw new \RuntimeException('No network in fixture');}}
    final class CurlFixture {public static array $options=[];public static array $calls=[];public static array $response=[];public static int $status=200;public static $onCall=null;}
    function curl_init($url){CurlFixture::$options=['url'=>$url];return new \stdClass();}
    function curl_setopt_array($ch,$options){CurlFixture::$options+=$options;return true;}
    function curl_setopt($ch,$key,$value){CurlFixture::$options[$key]=$value;return true;}
    function curl_exec($ch){CurlFixture::$calls[]=CurlFixture::$options;if(CurlFixture::$onCall)(CurlFixture::$onCall)();(CurlFixture::$options[CURLOPT_WRITEFUNCTION])($ch,json_encode(CurlFixture::$response));return true;}
    function curl_getinfo($ch,$opt){return CurlFixture::$status;}
    function curl_errno($ch){return 0;}
    function curl_close($ch){}
}
namespace {
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){$p=$root.'/src/'.substr($c,9).'.php';if(is_file($p))require_once $p;});
function check($ok,string $label):void{if(!$ok)throw new \RuntimeException($label);}
function rejects(callable $fn):void{try{$fn();}catch(\RuntimeException $e){return;}throw new \RuntimeException('Expected rejection');}
$dir=sys_get_temp_dir().'/ops-test-'.bin2hex(random_bytes(5));$db=new \PenguLab\Database($dir,$root);$p=$db->pdo();\PenguLab\OpsStore::install($p);\PenguLab\OpsStore::install($p);
$secrets=new \PenguLab\Secrets();$addons=new \PenguLab\AddonManager($db,$root.'/addons',$dir.'/addons');$addons->install('penguops');$addons->install('proxmox');$integrations=new \PenguLab\IntegrationManager($db,$addons,$secrets);
$i=$integrations->save(['type'=>'proxmox','name'=>'Visible','base_url'=>'https://pve.test','username'=>'test@pve!test','secrets'=>['token_secret'=>'DO_NOT_LEAK']]);
$j=$integrations->save(['type'=>'proxmox','name'=>'Hidden','base_url'=>'https://private.test','username'=>'test@pve!test','secrets'=>['token_secret'=>'DO_NOT_LEAK']]);
$id=$i['id'];$now=time();$r=\PenguLab\OpsStore::rules($p,$id);
check($r['warning']===85,'default threshold');
$sample=['cpu_percent'=>20,'memory_percent'=>87,'storage_percent'=>96,'ops_guests'=>[['id'=>'100','state'=>'stopped']]];$r['required']=['100'];
$issues=\PenguLab\OpsStore::evaluate('proxmox',$sample,$r,$now)['issues'];check(count($issues)===3,'resource and required checks');
check(count(\PenguLab\OpsStore::evaluate('docker',['containers'=>[['name'=>'optional','state'=>'stopped']]],$r+[], $now)['issues'])===1,'only missing required, not optional stopped');
$ha=['ops_entities'=>[['entity_id'=>'sensor.a','state'=>'unavailable','last_changed'=>gmdate(DATE_ATOM,$now-400)]]];check(count(\PenguLab\OpsStore::evaluate('homeassistant',$ha,$r,$now)['issues'])===1,'HA grace elapsed');$ha['ops_entities'][0]['last_changed']=gmdate(DATE_ATOM,$now-10);check(!\PenguLab\OpsStore::evaluate('homeassistant',$ha,$r,$now)['issues'],'HA grace not elapsed');
\PenguLab\OpsStore::record($p,$id,$issues,$now-100);\PenguLab\OpsStore::record($p,$id,$issues,$now-50);check((int)$p->query('SELECT COUNT(*) FROM ops_incidents')->fetchColumn()===3,'incident dedupe');
\PenguLab\OpsStore::record($p,$id,[['key'=>'api','severity'=>'warning','message'=>'Unavailable']],$now,true);check((int)$p->query("SELECT last_seen FROM ops_incidents WHERE rule_key='memory_percent'")->fetchColumn()===$now-50,'outage must not refresh observation');
\PenguLab\OpsStore::record($p,$id,[],$now);check((int)$p->query('SELECT COUNT(*) FROM ops_incidents WHERE resolved_at IS NULL')->fetchColumn()===0,'recovery');
\PenguLab\OpsStore::record($p,$id,$issues,$now+1);check((int)$p->query('SELECT COUNT(*) FROM ops_incidents')->fetchColumn()===7,'reopened incident separate episode');
$p->prepare('INSERT INTO ops_snapshots VALUES(?,?,1,?)')->execute([$id,$now,json_encode($sample)]);$p->prepare('INSERT INTO ops_snapshots VALUES(?,?,1,?)')->execute([$j['id'],$now,json_encode(['memory_percent'=>99])]);
$auth=new class($id){public function __construct(private string $id){}public function canIntegration(string $id):bool{return $id===$this->id;}};
$ctx=['db'=>$db,'secrets'=>$secrets,'integrations'=>$integrations,'addons'=>$addons,'auth'=>$auth];$report=\PenguLab\OpsStore::report($ctx);check($report['total']===1,'permissions filter before scoring');check(!str_contains(json_encode($report),'Hidden'),'hidden integration not leaked');check($report['score']===80,'transparent deductions');
$p->prepare('UPDATE ops_snapshots SET observed_at=? WHERE integration_id=?')->execute([$now-301,$id]);$report=\PenguLab\OpsStore::report($ctx);check($report['score']===null&&$report['unknown']===1,'stale never healthy');
$context=\PenguLab\AiHub::context($ctx);check(!str_contains(json_encode($context),'DO_NOT_LEAK')&&!str_contains(json_encode($context),'pve.test'),'AI context excludes secrets and endpoints');
foreach(\PenguLab\AiHub::BASES as $provider=>$base){
 \PenguLab\AiHub::save($ctx,['name'=>$provider,'provider'=>$provider,'base_url'=>$base,'model'=>'model-fixture','api_key'=>'key-fixture']);
 $profile=['provider'=>$provider,'base_url'=>$base,'model'=>'model-fixture','api_key'=>'key-fixture','max_tokens'=>2048];$spec=\PenguLab\AiHub::specification($profile,$context);
 check($spec['body']['model']==='model-fixture','model selection '.$provider);
 if($provider==='anthropic')check(isset($spec['body']['system'])&&count($spec['body']['messages'])===1,'Anthropic system separation');
 if($provider==='openai')check(isset($spec['body']['max_completion_tokens']),'OpenAI current token field');
 $response=match($provider){'anthropic'=>['content'=>[['type'=>'thinking','thinking'=>'hidden'],['type'=>'text','text'=>'Evidence']],'usage'=>['input_tokens'=>10],'stop_reason'=>'end_turn'],'ollama'=>['message'=>['content'=>'Evidence'],'eval_count'=>20,'done_reason'=>'stop'],default=>['choices'=>[['message'=>['content'=>'Evidence'],'finish_reason'=>'stop']],'usage'=>['total_tokens'=>30]]};
 check(\PenguLab\AiHub::parse($provider,$response)['text']==='Evidence','response parsing '.$provider);
 $profiles=\PenguLab\AiHub::profiles($ctx);$pid=end($profiles)['id'];foreach($profiles as $pro)if($pro['provider']===$provider)$pid=$pro['id'];
 \PenguLab\CurlFixture::$response=$provider==='ollama'?['models'=>[['name'=>'model-fixture']]]:['data'=>[['id'=>'model-fixture']]];
 check(\PenguLab\AiHub::models($ctx,$pid)===['model-fixture'],'model discovery '.$provider);
 $job=\PenguLab\AiHub::enqueue($ctx,$pid,$context);rejects(fn()=>\PenguLab\AiHub::enqueue($ctx,$pid,$context));
 rejects(fn()=>\PenguLab\AiHub::save($ctx,['id'=>$pid,'name'=>'changed','provider'=>$provider,'base_url'=>$base,'api_key'=>'new']));
 \PenguLab\CurlFixture::$response=$response;\PenguLab\AiHub::work($ctx);$q=$p->prepare('SELECT * FROM ops_ai_jobs WHERE id=?');$q->execute([$job]);$row=$q->fetch();check($row['status']==='done'&&$row['result']==='Evidence','worker completion '.$provider);
}
$profiles=\PenguLab\AiHub::profiles($ctx);check(!str_contains(json_encode($profiles),'key-fixture'),'public profile redaction');$profile=$profiles[0];
rejects(fn()=>\PenguLab\AiHub::save($ctx,['id'=>$profile['id'],'name'=>'changed','provider'=>'openai','base_url'=>'https://other.test/v1']));
rejects(fn()=>\PenguLab\AiHub::save($ctx,['name'=>'bad','provider'=>'openai','base_url'=>'http://insecure.test','api_key'=>'abc']));
$job=\PenguLab\AiHub::enqueue($ctx,$profile['id'],$context);\PenguLab\CurlFixture::$status=401;\PenguLab\AiHub::work($ctx);$q=$p->prepare('SELECT * FROM ops_ai_jobs WHERE id=?');$q->execute([$job]);$row=$q->fetch();check($row['status']==='failed'&&str_contains($row['result'],'401'),'upstream auth error surfaced safely');
\PenguLab\CurlFixture::$status=200;$job=\PenguLab\AiHub::enqueue($ctx,$profile['id'],$context);\PenguLab\CurlFixture::$onCall=static function()use($p,$job){$p->prepare("UPDATE ops_ai_jobs SET status='cancelled' WHERE id=?")->execute([$job]);};\PenguLab\AiHub::work($ctx);$q->execute([$job]);check($q->fetch()['status']==='cancelled','late completion cannot overwrite cancellation');
echo "PenguOps: rules, incident lifecycle, stale data, RBAC, context redaction, 6 provider adapters, model discovery, worker jobs, auth errors and cancellation passed.\n";
}
