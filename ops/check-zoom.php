<?php
declare(strict_types=1);
// Read-only Zoom S2S credentials / host discovery check. Does NOT create a meeting.
// Run inside the PHP app container: php ops/check-zoom.php
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/app/zoom.php';
$env=[];
foreach(file(dirname(__DIR__).'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
    [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
if(!zoomConfigured($env)){fwrite(STDERR,"Zoom config incomplete (Account ID, Client ID, Client Secret, host email).\n");exit(2);}
try{
    $token=zoomAccessToken($env);
    if($token==='')throw new RuntimeException('Zoom OAuth returned empty token');
    echo "PASS Zoom OAuth token acquired. Host: ".$env['ZOOM_HOST_EMAIL']."\n";
    echo "No meeting created; no token or secret printed.\n";
}catch(Throwable $e){
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
