<?php
declare(strict_types=1);
use PenguLab\HttpClient;
return static function(array $integration,HttpClient $http,string $mode='summary'): array {
    $base=rtrim((string)$integration['base_url'],'/');$key=(string)($integration['_secrets']['api_key']??'');$secret=(string)($integration['_secrets']['api_secret']??'');if($key===''||$secret==='')throw new RuntimeException('OPNsense API key and secret are required.');$opts=['verify_tls'=>(bool)$integration['verify_tls'],'basic'=>$key.':'.$secret];
    $status=$http->request('GET',$base.'/api/core/system/status',$opts);if($status['status']!==200||!is_array($status['json']))throw new RuntimeException('OPNsense system status returned HTTP '.$status['status'].'.');
    $memory=$http->request('GET',$base.'/api/diagnostics/interface/get_memory_statistics',$opts);$mem=is_array($memory['json'])?$memory['json']:[];
    return ['service'=>'OPNsense','status'=>'online','system'=>$status['json'],'memory'=>$mem];
};
