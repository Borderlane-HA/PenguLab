<?php
declare(strict_types=1);
namespace PenguLab {
    // HTTP contract fixture uses the exact {error,result} envelope of REST-API 4.0.2.
    final class HttpClient {
        public array $calls=[];
        public bool $deny=false;
        public function request(string $method,string $url,array $options=[]):array {
            $this->calls[]=[$method,$url,$options];
            if($this->deny)return ['status'=>401,'json'=>['error'=>'denied']];
            $body=(array)($options['json']??[]);$id=$body['id']??'';
            $obj=['type'=>'state','common'=>['name'=>['de'=>'Test'],'type'=>str_ends_with($id,'number')?'number':'boolean','write'=>!str_ends_with($id,'readonly'),'min'=>0,'max'=>100]];
            $result=match(basename($url)) {
                'getVersion'=>['version'=>'4.0.2','name'=>'rest-api'],
                'getForeignObjects'=>['0_userdata.0.switch'=>$obj],
                'getObject'=>$obj,
                'getState'=>['val'=>false,'ts'=>1234],
                'setState'=>null,
                'health'=>['ok'=>true,'protocol'=>1],
                'entities'=>['ok'=>true,'entities'=>[['entity_id'=>'demo.switch','name'=>'Switch','value_type'=>'boolean','domain'=>'switch','writable'=>true,'state'=>'off','value'=>false]]],
                'action'=>['ok'=>true,'accepted'=>true],
                default=>throw new \RuntimeException('Unexpected URL '.$url),
            };
            return ['status'=>200,'json'=>str_contains($url,'/v1/command/')?['error'=>null,'result'=>$result]:$result];
        }
    }
}
namespace {
    if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
    require __DIR__.'/../src/AutomationEntity.php';
    function check(bool $ok,string $label):void{if(!$ok)throw new \RuntimeException($label);}
    function rejects(callable $fn):void{try{$fn();}catch(\RuntimeException $e){return;}throw new \RuntimeException('Expected rejection');}
    function encode(array $value):string{return base64_encode(json_encode($value));}
    $http=new \PenguLab\HttpClient();
    $io=require __DIR__.'/../addons/iobroker/connector.php';
    $config=['base_url'=>'http://fixture:8093','username'=>'test','verify_tls'=>false,'_secrets'=>['password'=>'fixture'],'config'=>['state_pattern'=>'0_userdata.0.*']];
    check($io($config,$http)['status']==='online','io summary');
    check(count($io($config,$http,'entities')['entities'])===1,'io discovery unwraps result');
    $data=$io($config,$http,'states:'.encode(['0_userdata.0.switch']));
    check($data['entities'][0]['state']==='off','boolean false retained');
    check($data['entities'][0]['name']==='Test','translated name');
    $io($config,$http,'action:'.encode(['entity_id'=>'0_userdata.0.switch','action'=>'set','value'=>true]));
    $last=end($http->calls);check(((array)$last[2]['json'])['state']===['val'=>true,'ack'=>false],'command uses ack=false');
    rejects(fn()=>$io($config,$http,'action:'.encode(['entity_id'=>'0_userdata.0.readonly','action'=>'set','value'=>true])));
    rejects(fn()=>$io($config,$http,'action:'.encode(['entity_id'=>'outside.switch','action'=>'set','value'=>true])));
    rejects(fn()=>$io($config,$http,'action:'.encode(['entity_id'=>'0_userdata.0.number','action'=>'set','value'=>101])));
    rejects(fn()=>$io($config,$http,'action:'.encode(['entity_id'=>'0_userdata.0.switch','action'=>'set','value'=>'true'])));
    $nr=require __DIR__.'/../addons/nodered/connector.php';$n=['base_url'=>'http://fixture:1880/pengulab','verify_tls'=>false,'_secrets'=>['access_token'=>'fixture-token-123456']];
    check($nr($n,$http)['bridge_protocol']===1,'bridge health');
    check(count($nr($n,$http,'entities')['entities'])===1,'bridge discovery');
    $d=$nr($n,$http,'states:'.encode(['demo.switch','missing']));check($d['entities'][1]['state']==='unavailable','missing entity');
    $nr($n,$http,'action:'.encode(['entity_id'=>'demo.switch','action'=>'set','value'=>true]));
    $last=end($http->calls);check($last[2]['headers']['Authorization']==='Bearer fixture-token-123456','bridge auth');
    rejects(fn()=>$nr($n,$http,'action:'.encode(['entity_id'=>'missing','action'=>'set','value'=>true])));
    $http->deny=true;rejects(fn()=>$nr($n,$http));rejects(fn()=>$io($config,$http));
    echo "Connector contract tests passed (discovery, read, write, auth, bounds, read-only, missing IDs).\n";
}
