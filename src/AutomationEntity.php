<?php
declare(strict_types=1);
namespace PenguLab;

/** Shared contract for explicitly exposed ioBroker and Node-RED values. */
final class AutomationEntity
{
    public static function ids(string $encoded): array
    {
        $raw=base64_decode($encoded,true);
        $ids=$raw===false?null:json_decode($raw,true);
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>8)throw new \RuntimeException('Select between one and eight data points.');
        foreach($ids as $id)if(!is_string($id)||$id===''||strlen($id)>512||preg_match('/[\x00-\x1f]/',$id))throw new \RuntimeException('Invalid data point ID.');
        return array_values(array_unique($ids));
    }
    public static function value(array $entity,array $payload): mixed
    {
        if(empty($entity['writable']))throw new \RuntimeException('This data point is read-only.');
        $action=(string)($payload['action']??'');
        if(($entity['domain']??'')==='button'){
            if($action!=='trigger')throw new \RuntimeException('Unsupported action.');
            return true;
        }
        if($action!=='set'||!array_key_exists('value',$payload))throw new \RuntimeException('Unsupported action.');
        $v=$payload['value'];
        if(($entity['value_type']??'')==='boolean'){
            if(!is_bool($v))throw new \RuntimeException('A boolean value is required.');
        }elseif(($entity['value_type']??'')==='number'){
            if((!is_int($v)&&!is_float($v))||!is_finite((float)$v))throw new \RuntimeException('A finite numeric value is required.');
            if(isset($entity['min'])&&$v<$entity['min']||isset($entity['max'])&&$v>$entity['max'])throw new \RuntimeException('Value is outside the allowed range.');
        }else throw new \RuntimeException('Only boolean, numeric and button controls are supported.');
        return $v;
    }
    public static function unavailable(string $id): array
    {
        return ['entity_id'=>$id,'name'=>$id,'domain'=>'sensor','state'=>'unavailable','writable'=>false];
    }
}
