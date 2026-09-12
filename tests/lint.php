<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);$count=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file){
    if($file->getExtension()!=='php'||str_contains($file->getPathname(),'/legacy/'))continue;
    token_get_all(file_get_contents($file->getPathname()),TOKEN_PARSE);$count++;
}
echo "PHP native parser: $count files passed.\n";
