<?php
declare(strict_types=1);
use PenguLab\HttpClient;
use PenguLab\AutomationEntity;

return static function(array $integration,HttpClient $http,string $mode='summary'): array {
    $base=rtrim((string)$integration['base_url'],'/');
    $secrets=$integration['_secrets']??[];
    $options=['verify_tls'=>(bool)$integration['verify_tls']];
    if(!empty($integration['username']))$options['basic']=$integration['username'].':'.($secrets['password']??'');
    $command=static function(string $name,array $args=[])use($base,$http,$options):array{
        $res=$http->request('POST',$base.'/v1/command/'.$name,$options+['json'=>(object)$args]);
        if($res['status']<200||$res['status']>=300)throw new RuntimeException('ioBroker REST API returned HTTP '.$res['status'].'. Check API URL, user and permissions.');
        if(!is_array($res['json']))throw new RuntimeException('ioBroker returned invalid JSON. Use the REST-API adapter URL (usually port 8093).');
        if(!empty($res['json']['error']))throw new RuntimeException('ioBroker rejected the request. Check data point permissions.');
        $data=$res['json'];
        if(array_key_exists('result',$data))return is_array($data['result'])?$data['result']:['value'=>$data['result']];
        if(array_key_exists('results',$data))return is_array($data['results'])?$data['results']:[];
        return $data;
    };
    $public=static function(string $id,array $object,?array $state=null):array{
        $c=is_array($object['common']??null)?$object['common']:[];
        $name=$c['name']??$id;if(is_array($name))$name=$name['de']??$name['en']??reset($name);
        $type=(string)($c['type']??'string');$write=($c['write']??false)===true;
        $role=(string)($c['role']??'');$button=$write&&str_starts_with($role,'button');
        $value=$state['val']??null;
        $e=['entity_id'=>$id,'name'=>(string)$name,'value_type'=>$type,'domain'=>$button?'button':($type==='boolean'?'switch':($type==='number'&&$write?'number':'sensor')),'writable'=>$write&&in_array($type,['boolean','number'],true),'state'=>$state===null||$value===null?'unavailable':(is_bool($value)?($value?'on':'off'):(is_scalar($value)?(string)$value:json_encode($value))),'value'=>$value,'unit'=>(string)($c['unit']??''),'role'=>$role,'device_class'=>str_contains($role,'temperature')?'temperature':(str_contains($role,'power')?'power':''),'last_updated'=>$state['ts']??null];
        foreach(['min','max','step'] as $k)if(isset($c[$k])&&is_numeric($c[$k]))$e[$k]=(float)$c[$k];
        return $e;
    };
    $pattern=trim((string)($integration['config']['state_pattern']??'0_userdata.0.*'))?:'0_userdata.0.*';
    $allowed=static fn(string $id):bool=>fnmatch($pattern,$id,FNM_NOESCAPE);
    if($mode==='summary')return ['service'=>'ioBroker','status'=>'online','api'=>$command('getVersion')];
    if($mode==='entities'){
        $objects=$command('getForeignObjects',['pattern'=>$pattern,'type'=>'state']);
        $entities=[];
        foreach($objects as $id=>$object){if(!is_array($object)||($object['type']??'')!=='state'||!$allowed((string)$id))continue;$entities[]=$public((string)$id,$object);if(count($entities)>=2000)break;}
        usort($entities,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        return ['entities'=>$entities];
    }
    if(str_starts_with($mode,'states:')){
        $entities=[];
        foreach(AutomationEntity::ids(substr($mode,7)) as $id){
            if(!$allowed($id)){$entities[]=AutomationEntity::unavailable($id);continue;}
            try{$object=$command('getObject',['id'=>$id]);$state=$command('getState',['id'=>$id]);$entities[]=$public($id,$object,$state);}
            catch(RuntimeException $e){$entities[]=AutomationEntity::unavailable($id);}
        }
        return ['entities'=>$entities];
    }
    if(str_starts_with($mode,'action:')){
        $p=json_decode((string)base64_decode(substr($mode,7),true),true);
        if(!is_array($p))throw new RuntimeException('Invalid action.');
        $id=(string)($p['entity_id']??'');if($id===''||!$allowed($id))throw new RuntimeException('Data point is outside the configured filter.');
        $object=$command('getObject',['id'=>$id]);
        if(($object['type']??'')!=='state')throw new RuntimeException('Unknown data point.');
        $value=AutomationEntity::value($public($id,$object),$p);
        $command('setState',['id'=>$id,'state'=>['val'=>$value,'ack'=>false]]);
        return ['ok'=>true];
    }
    throw new RuntimeException('Unsupported ioBroker connector mode.');
};
