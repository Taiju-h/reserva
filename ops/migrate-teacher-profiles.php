<?php
declare(strict_types=1);
// Additive migration for teacher profiles and reusable qualification master.
// Run in existing PHP container: php /var/www/html/ops/migrate-teacher-profiles.php
if(PHP_SAPI!=='cli')exit(1);
$vars=[];
$envFile=dirname(__DIR__).'/.env';
if(!is_readable($envFile)){fwrite(STDERR,"Missing .env\n");exit(1);}
foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
  $line=trim($line);
  if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
  [$k,$v]=explode('=',$line,2);
  $vars[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
if(($vars['DB_CONNECTION']??'')!=='mysql'){fwrite(STDERR,"DB_CONNECTION must be mysql\n");exit(1);}
try{
  $pdo=new PDO('mysql:host='.($vars['DB_HOST']??'mysql').';port='.($vars['DB_PORT']??'3306').';dbname='.($vars['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
      $vars['DB_USERNAME']??'hanamaru',$vars['DB_PASSWORD']??'',
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  $pdo->exec('CREATE TABLE IF NOT EXISTS teacher_profiles (
    teacher_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    summary TEXT NULL,
    career MEDIUMTEXT NULL,
    qualifications_text MEDIUMTEXT NULL,
    training MEDIUMTEXT NULL,
    activity_area TEXT NULL,
    message MEDIUMTEXT NULL,
    photo_path VARCHAR(255) NULL,
    photo_source_url TEXT NULL,
    source_url TEXT NULL,
    source_checked_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_teacher_profiles_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $pdo->exec('CREATE TABLE IF NOT EXISTS qualification_master (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(250) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT "未分類",
    UNIQUE KEY uq_qualification_name(name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $pdo->exec('CREATE TABLE IF NOT EXISTS teacher_qualifications (
    teacher_id BIGINT UNSIGNED NOT NULL,
    qualification_id BIGINT UNSIGNED NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT "",
    PRIMARY KEY (teacher_id,qualification_id),
    CONSTRAINT fk_tq_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    CONSTRAINT fk_tq_qualification FOREIGN KEY (qualification_id) REFERENCES qualification_master(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  echo "Teacher profiles and qualifications master are ready. Existing bookings unchanged.\n";
}catch(Throwable $e){fwrite(STDERR,"Migration failed: ".$e->getMessage()."\n");exit(1);}
