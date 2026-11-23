<?php
declare(strict_types=1);
// Read-only diagnostic: reports presence/format only. Never prints any secret.
$file=dirname(__DIR__).'/.env';
if(!is_file($file)){fwrite(STDERR,".env が見つかりません\n");exit(1);}
$env=[];
foreach(file($file,FILE_IGNORE_NEW_LINES) as $line){
    $line=trim($line);
    if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
    [$key,$value]=explode('=',$line,2);
    $env[trim($key)]=trim($value," \t\n\r\0\x0B\"'");
}
$mode=$env['STRIPE_MODE']??'disabled';
$secret=$env['STRIPE_SECRET_KEY']??'';
$webhook=$env['STRIPE_WEBHOOK_SECRET']??'';
$live=$env['STRIPE_LIVE_ENABLED']??'no';
echo "Stripe configuration (secrets are hidden)\n";
echo "mode: ".(in_array($mode,['disabled','test','live'],true)?$mode:'INVALID')."\n";
echo "secret_key: ".(($mode==='live'?str_starts_with($secret,'sk_live_'):str_starts_with($secret,'sk_test_')) && strlen($secret)>16?'OK':'MISSING / WRONG MODE')."\n";
echo "webhook_secret: ".(str_starts_with($webhook,'whsec_')&&strlen($webhook)>16?'OK':'MISSING')."\n";
echo "live_activation: ".($live==='yes'?'yes':'no')."\n";
$enabled=in_array($mode,['test','live'],true) &&
    ($mode!=='live'||$live==='yes') &&
    str_starts_with($secret,$mode==='live'?'sk_live_':'sk_test_') &&
    strlen($secret)>16 &&
    str_starts_with($webhook,'whsec_') &&
    strlen($webhook)>16;
echo "checkout_available: ".($enabled?'YES':'NO')."\n";
if(!$enabled) exit(2);
