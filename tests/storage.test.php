<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
spl_autoload_register(static function($class)use($root){if(str_starts_with($class,'PenguLab\\'))require_once $root.'/src/'.substr($class,9).'.php';});
$source=file_get_contents($root.'/api.php');
eval('use PenguLab\\Database; use PenguLab\\FeedReader; use PenguLab\\Favicon; use PenguLab\\HttpClient; use PenguLab\\IntegrationManager; '.substr($source,strpos($source,'function json_response(')));
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$dir=sys_get_temp_dir().'/pengulab-test-'.bin2hex(random_bytes(4));
$db=new PenguLab\Database($dir,$root);$addons=new PenguLab\AddonManager($db,$root.'/addons',$dir.'/addons');
$addons->install('iobroker');$addons->install('nodered');
$types=array_column($addons->integrationTypes(),'type');check(in_array('iobroker',$types)&&in_array('nodered',$types),'new addons registered');
$db->pdo()->exec('DELETE FROM widgets');$db->setSetting('layout_engine','canvas8');
$a=create_widget($db,$addons,['type'=>'automation-entities','config'=>['integration_id'=>'test','entity_ids'=>['x']],'x'=>0,'y'=>0,'w'=>20,'h'=>10]);
$b=create_widget($db,$addons,['type'=>'clock','x'=>22,'y'=>0,'w'=>20,'h'=>11]);
$items=$db->widgets();$items[0]['y']=15;save_layout($db,$items,'canvas8');$saved=$db->widgets();check(array_column($saved,'y','id')[$items[0]['id']]===15,'save coordinates');
$bad=$saved;$bad[0]['x']=$bad[1]['x'];$bad[0]['y']=$bad[1]['y'];
$rejected=false;try{save_layout($db,$bad,'canvas8');}catch(RuntimeException $e){$rejected=true;}
check($rejected,'overlaps rejected');check($db->widgets()===$saved,'failed save is atomic');
$auth=new class {function isAdmin(){return false;}function canIntegration($id){return false;}function canIpManager(){return false;}};
$visible=visible_widgets(['db'=>$db,'auth'=>$auth]);check(count($visible)===1&&$visible[0]['type']==='clock','automation widgets respect integration permissions');
if(!extension_loaded('sodium')){echo "Storage tests passed (addons, layout persistence, collision rejection, permissions). Secret test skipped: sodium unavailable.\n";exit;}
$secrets=new PenguLab\Secrets($dir);$manager=new PenguLab\IntegrationManager($db,$addons,$secrets);
$public=$manager->save(['type'=>'nodered','name'=>'Bridge','base_url'=>'http://test:1880/pengulab','secrets'=>['access_token'=>'fixture-secret-123456']]);
check(!str_contains(json_encode($public),'fixture-secret'),'public integration hides token');
$row=$db->pdo()->query('SELECT secret_enc FROM integrations')->fetchColumn();check(!str_contains($row,'fixture-secret'),'encrypted at rest');
echo "Storage tests passed (addons, layout persistence, collision rejection, permissions, secrets).\n";
