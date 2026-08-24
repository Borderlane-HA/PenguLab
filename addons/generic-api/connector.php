<?php
declare(strict_types=1);
use PenguLab\HttpClient;
return static function(array $integration,HttpClient $http,string $mode='summary'): array {
    $headers=[];$token=(string)($integration['_secrets']['bearer_token']??'');if($token!=='')$headers['Authorization']='Bearer '.$token;$opts=['verify_tls'=>(bool)$integration['verify_tls'],'headers'=>$headers];$user=(string)$integration['username'];$pass=(string)($integration['_secrets']['password']??'');if($user!==''||$pass!=='')$opts['basic']=$user.':'.$pass;
    $res=$http->request('GET',(string)$integration['base_url'],$opts);if($res['status']<200||$res['status']>=300||!is_array($res['json']))throw new RuntimeException('JSON API returned HTTP '.$res['status'].' or non-JSON data.');$values=[];foreach($res['json'] as $key=>$value){if(is_scalar($value)||$value===null)$values[(string)$key]=$value;if(count($values)>=8)break;}return['service'=>'Generic API','status'=>'online','values'=>$values];
};
