<?php
declare(strict_types=1);
use PenguLab\HttpClient;
return static function(array $integration,HttpClient $http,string $mode='summary'): array {
    $base=rtrim((string)$integration['base_url'],'/');$verify=(bool)$integration['verify_tls'];$user=(string)$integration['username'];$pass=(string)($integration['_secrets']['password']??'');$opts=['verify_tls'=>$verify];if($user!==''||$pass!=='')$opts['basic']=$user.':'.$pass;
    $status=$http->request('GET',$base.'/control/status',$opts);if($status['status']!==200||!is_array($status['json']))throw new RuntimeException('AdGuard Home status returned HTTP '.$status['status'].'.');
    $stats=$http->request('GET',$base.'/control/stats',$opts);if($stats['status']!==200||!is_array($stats['json']))throw new RuntimeException('AdGuard Home stats returned HTTP '.$stats['status'].'.');$s=$stats['json'];$queries=(int)($s['num_dns_queries']??0);$blocked=(int)($s['num_blocked_filtering']??0);
    return ['service'=>'AdGuard Home','status'=>!empty($status['json']['running'])?'online':'offline','protection'=>!empty($status['json']['protection_enabled']),'version'=>(string)($status['json']['version']??''),'queries'=>$queries,'blocked'=>$blocked,'blocked_percent'=>round($queries>0?$blocked/$queries*100:0,1),'avg_processing_ms'=>round(((float)($s['avg_processing_time']??0))*1000,2)];
};
