<?php
declare(strict_types=1);
use PenguLab\HttpClient;
use PenguLab\AutomationEntity;

return static function(array $integration,HttpClient $http,string $mode='summary'):array{
    $base=rtrim((string)$integration['base_url'],'/');
    $token=(string)($integration['_secrets']['access_token']??'');
    if($token==='')throw new RuntimeException('Node-RED bridge token is required.');
    $request=static function(string $method,string $path,?array $body=null)use($base,$token,$integration,$http):array{
        $options=['verify_tls'=>(bool)$integration['verify_tls'],'headers'=>['Authorization'=>'Bearer '.$token]];
        if($body!==null)$options['json']=$body;
        $res=$http->request($method,$base.$path,$options);
        if($res['status']<200||$res['status']>=300)throw new RuntimeException('Node-RED bridge returned HTTP '.$res['status'].'. Check the imported PenguLab flow, bridge URL and token.');
        if(!is_array($res['json'])||empty($res['json']['ok']))throw new RuntimeException('Invalid PenguLab bridge response.');
        return $res['json'];
    };
    if($mode==='summary'){$d=$request('GET','/health');if(($d['protocol']??0)!==1)throw new RuntimeException('Unsupported PenguLab bridge protocol.');return ['service'=>'Node-RED','status'=>'online','bridge_protocol'=>1];}
    $get=static function()use($request):array{
        $d=$request('GET','/entities');$out=[];
        foreach(($d['entities']??[]) as $e){if(!is_array($e)||!is_string($e['entity_id']??null))continue;
            $e=array_intersect_key($e,array_flip(['entity_id','name','value_type','domain','writable','state','value','unit','min','max','step','device_class']));
            $e['name']=(string)($e['name']??$e['entity_id']);$e['state']=(string)($e['state']??'unavailable');$e['writable']=($e['writable']??false)===true;
            if(!in_array($e['domain']??'', ['sensor','switch','number','button'],true))$e['domain']='sensor';
            $out[$e['entity_id']]=$e;
        }return $out;
    };
    if($mode==='entities')return ['entities'=>array_values($get())];
    if(str_starts_with($mode,'states:')){$all=$get();$entities=[];foreach(AutomationEntity::ids(substr($mode,7)) as $id)$entities[]=$all[$id]??AutomationEntity::unavailable($id);return ['entities'=>$entities];}
    if(str_starts_with($mode,'action:')){
        $p=json_decode((string)base64_decode(substr($mode,7),true),true);if(!is_array($p))throw new RuntimeException('Invalid action.');
        $id=(string)($p['entity_id']??'');$all=$get();if(!isset($all[$id]))throw new RuntimeException('Unknown Node-RED data point.');
        $value=AutomationEntity::value($all[$id],$p);
        return $request('POST','/action',['entity_id'=>$id,'action'=>$p['action'],'value'=>$value]);
    }
    throw new RuntimeException('Unsupported Node-RED connector mode.');
};
