<?php
declare(strict_types=1);
// Single-command full import using the same validated importer as the admin UI.
// Does NOT require browser session, does NOT modify booking or payment records.
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
$root=dirname(__DIR__);
$envFile=$root.'/.env';
if(!is_readable($envFile)){fwrite(STDERR,"Missing .env\n");exit(1);}
$env=[];
foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
  $line=trim($line);
  if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
  [$k,$v]=explode('=',$line,2);
  $env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
if(($env['DB_CONNECTION']??'')!=='mysql'){fwrite(STDERR,"Requires MySQL configuration\n");exit(1);}
function db():PDO {
    static $connection=null;
    global $env;
    if($connection instanceof PDO)return $connection;
    $connection=new PDO(
      'mysql:host='.($env['DB_HOST']??'mysql').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
      $env['DB_USERNAME']??'hanamaru',$env['DB_PASSWORD']??'',
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $connection;
}
require_once $root.'/app/teacher-profile-migration.php';
require_once $root.'/app/teacher-source-sync.php';
try {
    $migration=migrateTeacherProfileDirectory();
    echo "MIGRATION ".json_encode($migration,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $names=require $root.'/app/teacher-directory.php';
    $missingOnly=in_array('--missing-only',$argv??[],true);
    $done=0;$profiles=0;$photos=0;$qualificationItems=0;$errors=[];$skipped=0;
    if($missingOnly){
      syncMemberTable();
      $q=db()->query('SELECT t.name, COALESCE(p.career,"") AS career,
        COALESCE(p.photo_path,"") AS photo_path,
        (SELECT COUNT(*) FROM teacher_photo_assets a WHERE a.teacher_id=t.id) AS asset_count,
        (SELECT COUNT(*) FROM teacher_qualifications tq WHERE tq.teacher_id=t.id) AS qualifications
        FROM teachers t LEFT JOIN teacher_profiles p ON p.teacher_id=t.id');
      $existing=[];
      foreach($q->fetchAll() as $r){
        $existing[syncMemberKey($r['name'])]=$r;
      }
    }
    foreach($names as $i=>$name){
      if($missingOnly){
        $r=$existing[syncMemberKey($name)]??null;
        if($r && $r['career']!=='' && ($r['photo_path']!=='' || (int)$r['asset_count']>0)
          && (int)$r['qualifications']>0){$skipped++;continue;}
      }
      try {
        $r=syncMemberOne($i);
        $done++;
        if($r['ok'])$profiles++;
        if($r['photo'])$photos++;
        $qualificationItems+=(int)($r['qualifications']??0);
        $line=$r['name'].': '.($r['ok']?'詳細あり':'詳細なし').', 写真 '.($r['photo']?'OK':'未取得').
             ', 資格 '.(int)($r['qualifications']??0).'件';
        if(!$r['photo'])$line.=' ('.($r['photo_status']??$r['status']).')';
        echo $line."\n";
        if(!$r['ok']||!$r['photo'])$errors[]=$line;
      }catch(Throwable $e){
        $line=$name.': ERROR '.$e->getMessage();
        echo $line."\n";$errors[]=$line;
      }
    }
    $db=db();
    $stored=(int)$db->query("SELECT COUNT(*) FROM teacher_photo_assets")->fetchColumn();
    $careers=(int)$db->query("SELECT COUNT(*) FROM teacher_profiles WHERE COALESCE(career,'')<>''")->fetchColumn();
    $summaries=(int)$db->query("SELECT COUNT(*) FROM teacher_profiles WHERE COALESCE(summary,'')<>''")->fetchColumn();
    $qualifications=(int)$db->query('SELECT COUNT(*) FROM teacher_qualifications')->fetchColumn();
    echo "FINAL ".json_encode([
        'teachers_total'=>count($names),'processed'=>$done,'skipped_complete'=>$skipped,
        'profiles_from_source'=>$profiles,'source_photos_saved'=>$photos,
        'photo_assets_total'=>$stored,'career_records_total'=>$careers,
        'summaries_total'=>$summaries,'qualification_links_total'=>$qualifications,
        'unresolved'=>$errors
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    if($errors){fwrite(STDERR,"WARNING: ".count($errors)." teacher records require review.\n");exit(2);}
}catch(Throwable $e){
    fwrite(STDERR,"FATAL: ".$e->getMessage()."\n");
    exit(1);
}
