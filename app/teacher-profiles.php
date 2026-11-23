<?php
declare(strict_types=1);
// Teacher profile and qualification master APIs; all require administrator login.
function teacherProfileRoute(string $action,string $method): void {
  if($action==='migrateTeacherProfiles' && $method==='POST'){
    requireMasterAdmin();checkCsrf();
    require_once __DIR__.'/teacher-profile-migration.php';
    $result=migrateTeacherProfileDirectory();
    jsonOut(['ok'=>true,'migration'=>$result]);
  }
  if($action==='teacherPhotoUpload' && $method==='POST'){
    requireAdmin();checkCsrf();
    global $root;
    $teacher=(int)($_POST['teacher_id']??0);
    if($teacher<1)fail('先生を指定してください');
    $exists=db()->prepare('SELECT id FROM teachers WHERE id=?');$exists->execute([$teacher]);
    if(!$exists->fetchColumn())fail('先生が見つかりません',404);
    $file=$_FILES['photo']??null;
    if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail('写真ファイルを選択してください');
    if((int)($file['size']??0)<1||(int)($file['size']??0)>5*1024*1024)fail('写真は5MB以下にしてください');
    $tmp=$file['tmp_name']??'';
    if(!is_uploaded_file($tmp))fail('画像のアップロードが不正です');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($types[$mime])||!@getimagesize($tmp))fail('JPEG・PNG・WebPの写真を指定してください');
    $dir=$root.'/public/uploads/teacher-photos';
    if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))fail('写真の保存先を作成できません',500);
    $name=$teacher.'-'.bin2hex(random_bytes(12)).'.'.$types[$mime];
    if(!move_uploaded_file($tmp,$dir.'/'.$name))fail('写真を保存できません',500);
    chmod($dir.'/'.$name,0644);
    $path='/uploads/teacher-photos/'.$name;
    $q=db()->prepare('INSERT INTO teacher_profiles(teacher_id,photo_path)
       VALUES(?,?) ON DUPLICATE KEY UPDATE photo_path=VALUES(photo_path),photo_source_url=NULL');
    try{$q->execute([$teacher,$path]);}
    catch(Throwable $e){@unlink($dir.'/'.$name);throw $e;}
    jsonOut(['ok'=>true,'photo_path'=>$path]);
  }
  if($action==='teacherProfile' && $method==='GET'){
    requireAdmin();
    $id=(int)($_GET['teacher_id']??0);
    if($id<1)fail('先生を選択してください');
    $q=db()->prepare('SELECT t.id,t.name,t.active,p.summary,p.career,p.qualifications_text,p.training,p.activity_area,
        p.message,p.photo_path,p.photo_source_url,p.source_url,p.source_checked_at
        FROM teachers t LEFT JOIN teacher_profiles p ON p.teacher_id=t.id WHERE t.id=?');
    $q->execute([$id]);$teacher=$q->fetch(PDO::FETCH_ASSOC);
    if(!$teacher)fail('先生が見つかりません',404);
    $links=db()->prepare('SELECT qm.id,qm.name,qm.category,tq.note FROM teacher_qualifications tq
      JOIN qualification_master qm ON qm.id=tq.qualification_id WHERE tq.teacher_id=? ORDER BY qm.name');
    $links->execute([$id]);
    jsonOut(['teacher'=>$teacher,'qualifications'=>$links->fetchAll()]);
  }
  if($action==='teacherProfileSave' && $method==='POST'){
    requireAdmin();checkCsrf();$v=body();$id=(int)($v['teacher_id']??0);
    if($id<1)fail('先生を選択してください');
    $fields=['summary'=>1500,'career'=>15000,'qualifications_text'=>8000,'training'=>8000,
      'activity_area'=>1000,'message'=>8000];
    $values=[];
    foreach($fields as $name=>$limit){
      $value=trim((string)($v[$name]??''));
      if(mb_strlen($value)>$limit)fail('入力が長すぎます：'.$name);
      $values[$name]=$value;
    }
    $q=db()->prepare('SELECT id FROM teachers WHERE id=?');$q->execute([$id]);
    if(!$q->fetchColumn())fail('先生が見つかりません',404);
    $sql='INSERT INTO teacher_profiles(teacher_id,'.implode(',',array_keys($fields)).') VALUES('.
      implode(',',array_fill(0,count($fields)+1,'?')).') ON DUPLICATE KEY UPDATE '.
      implode(',',array_map(static fn($k)=>$k.'=VALUES('.$k.')',array_keys($fields)));
    $q=db()->prepare($sql);$q->execute(array_merge([$id],array_values($values)));
    jsonOut(['ok'=>true]);
  }
  if($action==='qualificationMaster' && $method==='GET'){
    requireAdmin();
    $q=db()->query('SELECT q.id,q.name,q.category,COUNT(tq.teacher_id) AS teacher_count
      FROM qualification_master q LEFT JOIN teacher_qualifications tq ON tq.qualification_id=q.id
      GROUP BY q.id,q.name,q.category ORDER BY q.name LIMIT 2000');
    jsonOut(['qualifications'=>$q->fetchAll()]);
  }
  if($action==='qualificationSave' && $method==='POST'){
    requireAdmin();checkCsrf();$v=body();
    $id=(int)($v['id']??0);$name=trim((string)($v['name']??''));
    $category=trim((string)($v['category']??'未分類'));
    if($name===''||mb_strlen($name)>250||$category===''||mb_strlen($category)>80)fail('資格の名称・分類が不正です');
    try{
      if($id){
        $q=db()->prepare('UPDATE qualification_master SET name=?,category=? WHERE id=?');
        $q->execute([$name,$category,$id]);
      }else{
        $q=db()->prepare('INSERT INTO qualification_master(name,category) VALUES(?,?)');
        $q->execute([$name,$category]);
      }
    }catch(PDOException $e){
      if(($e->errorInfo[1]??0)===1062)fail('同名の資格が登録済みです',409);
      throw $e;
    }
    jsonOut(['ok'=>true]);
  }
  if($action==='teacherQualificationLink' && $method==='POST'){
    requireAdmin();checkCsrf();$v=body();
    $teacher=(int)($v['teacher_id']??0);$qualification=(int)($v['qualification_id']??0);
    $operation=(string)($v['operation']??'add');
    if($teacher<1||$qualification<1||!in_array($operation,['add','remove'],true))fail('指定が不正です');
    if($operation==='add'){
      $q=db()->prepare('INSERT IGNORE INTO teacher_qualifications(teacher_id,qualification_id) VALUES(?,?)');
    }else{
      $q=db()->prepare('DELETE FROM teacher_qualifications WHERE teacher_id=? AND qualification_id=?');
    }
    $q->execute([$teacher,$qualification]);
    jsonOut(['ok'=>true]);
  }
}
