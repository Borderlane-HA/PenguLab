<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$ctx=require dirname(__DIR__).'/bootstrap.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$kind=in_array('--ai',$argv,true)?'ai':'collector';
$lock=fopen($ctx['dataDir'].'/penguops-'.$kind.'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$once=in_array('--once',$argv,true);
do{
    try{
        if($ctx['addons']->enabled('penguops')){
            if($kind==='ai')PenguLab\AiHub::work($ctx);
            else PenguLab\OpsCollector::run($ctx);
        }
    }catch(Throwable $e){fwrite(STDERR,"PenguOps worker error (".get_class($e)."). See data-directory permissions and configuration.\n");}
    if(!$once)sleep($kind==='ai'?2:60);
}while(!$once);
