<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
  $line=trim($line);
  if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
  [$k,$v]=explode('=',$line,2);
  $env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
if(($env['DB_CONNECTION']??'')!=='mysql'){fwrite(STDERR,"DB_CONNECTION must be mysql\n");exit(1);}
try{
  $pdo=new PDO('mysql:host='.($env['DB_HOST']??'mysql').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
     $env['DB_USERNAME']??'hanamaru',$env['DB_PASSWORD']??'',
     [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('CREATE TABLE IF NOT EXISTS teachers (
     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     name VARCHAR(100) NOT NULL,
     active TINYINT(1) NOT NULL DEFAULT 1,
     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $pdo->exec('CREATE TABLE IF NOT EXISTS schedule_rules (
     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     scope ENUM("store","teacher","menu") NOT NULL,
     target_id BIGINT UNSIGNED NULL,
     decision ENUM("allow","deny") NOT NULL,
     label VARCHAR(120) NOT NULL DEFAULT "",
     start_date DATE NULL,
     end_date DATE NULL,
     weekdays VARCHAR(30) NOT NULL DEFAULT "0,1,2,3,4,5,6",
     start_time TIME NOT NULL,
     end_time TIME NOT NULL,
     enabled TINYINT(1) NOT NULL DEFAULT 1,
     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     INDEX idx_schedule_rules_scope (scope,target_id,enabled),
     INDEX idx_schedule_rules_dates (start_date,end_date)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $exists=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='menus' AND COLUMN_NAME='teacher_id'")->fetchColumn();
  if((int)$exists===0){
    $pdo->exec('ALTER TABLE menus ADD COLUMN teacher_id BIGINT UNSIGNED NULL, ADD INDEX idx_menus_teacher (teacher_id)');
  }
  echo "Scheduling tables and menus.teacher_id ready. Existing bookings unchanged.\n";
}catch(Throwable $e){fwrite(STDERR,"Scheduling migration failed: ".$e->getMessage()."\n");exit(1);}
