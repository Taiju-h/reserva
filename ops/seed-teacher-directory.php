<?php
declare(strict_types=1);
// Deterministic fallback to import the 42 published member-directory summaries.
// Run after migrate-teacher-profiles.php, before/without remote profile scraping.
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
  $line=trim($line);
  if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
  [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
if(($env['DB_CONNECTION']??'')!=='mysql')exit("DB_CONNECTION=mysql is required\n");
$pdo=new PDO('mysql:host='.($env['DB_HOST']??'mysql').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
  $env['DB_USERNAME']??'hanamaru',$env['DB_PASSWORD']??'',
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$rows=require $root.'/app/teacher-directory-summaries.php';
$norm=static fn(string $s):string=>preg_replace('/[\\s\\x{3000}]+/u','',trim($s));
$existing=$pdo->query('SELECT id,name FROM teachers')->fetchAll(PDO::FETCH_ASSOC);$ids=[];
foreach($existing as $row)$ids[$norm($row['name'])]=(int)$row['id'];
$create=$pdo->prepare('INSERT INTO teachers(name,active) VALUES(?,1)');
$profile=$pdo->prepare('INSERT INTO teacher_profiles(teacher_id,summary,training,source_url,source_checked_at)
  VALUES(?,?,?, ?, NOW()) ON DUPLICATE KEY UPDATE
    summary=IF(COALESCE(summary,"")="",VALUES(summary),summary),
    training=IF(COALESCE(training,"")="",VALUES(training),training),
    source_url=IF(COALESCE(source_url,"")="",VALUES(source_url),source_url),
    source_checked_at=NOW()');
$qual=$pdo->prepare('INSERT INTO qualification_master(name,category) VALUES(?,"未分類") ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
$link=$pdo->prepare('INSERT IGNORE INTO teacher_qualifications(teacher_id,qualification_id) VALUES(?,?)');
$stats=['teachers'=>0,'summaries'=>0,'qualification_links'=>0,'empty_summaries'=>[]];
$pdo->beginTransaction();
try{
 foreach($rows as [$name,$summary,$qualifications,$training]){
    $id=$ids[$norm($name)]??0;
    if(!$id){$create->execute([$name]);$id=(int)$pdo->lastInsertId();$ids[$norm($name)]=$id;}
    $profile->execute([$id,$summary,$training,'https://hanamaru-souken.com/member_list/']);
    $stats['teachers']++;
    if($summary!=='')$stats['summaries']++;else $stats['empty_summaries'][]=$name;
    foreach(array_filter(array_map('trim',explode('|',$qualifications))) as $nameOfQualification){
       $qual->execute([$nameOfQualification]);
       $qid=(int)$pdo->lastInsertId();
       $link->execute([$id,$qid]);$stats['qualification_links']++;
    }
 }
 $pdo->commit();
 echo "DIRECTORY_SUMMARY_IMPORT ".json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 fwrite(STDERR,"Import failed: ".$e->getMessage()."\n");exit(1);
}
