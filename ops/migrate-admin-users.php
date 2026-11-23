<?php
declare(strict_types=1);
// Safe, additive schema migration: create admin_users without touching existing data.
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
$vars=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $line=trim($line);
    if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
    [$key,$value]=explode('=',$line,2);
    $vars[trim($key)]=trim($value," \t\n\r\0\x0B\"'");
}
if(($vars['DB_CONNECTION']??'')!=='mysql'){fwrite(STDERR,"DB_CONNECTION must be mysql\n");exit(1);}
try{
    $pdo=new PDO('mysql:host='.($vars['DB_HOST']??'mysql').';port='.($vars['DB_PORT']??'3306').';dbname='.($vars['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
        $vars['DB_USERNAME']??'hanamaru',$vars['DB_PASSWORD']??'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(__DIR__.'/migrate-20261123-admin-users.sql'));
    echo "admin_users table is ready. Existing users and bookings unchanged.\n";
}catch(Throwable $e){fwrite(STDERR,"Migration failed: ".$e->getMessage()."\n");exit(1);}
