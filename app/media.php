<?php
declare(strict_types=1);
// Included by index.php; all writes require admin session and CSRF.
function mediaList(): array {
    $stmt=db()->query('SELECT id, menu_id, kind FROM site_media ORDER BY id ASC');
    return $stmt->fetchAll();
}
function mediaServe(): never {
    $id=(int)($_GET['id']??0);
    if($id<1) fail('画像がありません',404);
    $q=db()->prepare('SELECT file_name,mime_type FROM site_media WHERE id=?');
    $q->execute([$id]);
    $m=$q->fetch();
    if(!$m) fail('画像がありません',404);
    global $root;
    $file=$root.'/storage/media/'.$m['file_name'];
    if(!is_file($file)) fail('画像ファイルがありません',404);
    header('Content-Type: '.$m['mime_type']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: '.filesize($file));
    readfile($file);
    exit;
}
function mediaUpload(): never {
    requireAdmin();
    checkCsrf();
    global $root;
    $kind=(string)($_POST['kind']??'menu');
    $menuId=(int)($_POST['menu_id']??0);
    if(!in_array($kind,['hero','menu'],true)) fail('画像の種類が不正です');
    if($kind==='hero') $menuId=0;
    if($kind==='menu') {
        $q=db()->prepare('SELECT COUNT(*) FROM menus WHERE id=?');$q->execute([$menuId]);
        if(!$q->fetchColumn()) fail('メニューがありません');
    }
    $q=db()->prepare('SELECT COUNT(*) FROM site_media WHERE kind=? AND menu_id <=> ?');
    $q->execute([$kind,$menuId?:null]);
    if((int)$q->fetchColumn()>=($kind==='hero'?1:20)) fail('画像の登録上限です（トップ1枚、メニュー20枚）');
    $file=$_FILES['image']??null;
    if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) fail('画像を選択してください');
    if((int)($file['size']??0)>5*1024*1024 || (int)($file['size']??0)<1) fail('画像は5MB以下にしてください');
    $tmp=$file['tmp_name']??'';
    if(!is_uploaded_file($tmp)) fail('アップロードが不正です');
    $finfo=new finfo(FILEINFO_MIME_TYPE);
    $mime=$finfo->file($tmp);
    $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($types[$mime]) || !@getimagesize($tmp)) fail('JPEG・PNG・WebP画像のみ利用できます');
    $dir=$root.'/storage/media';
    if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) fail('画像保存先を作成できません',500);
    $name=bin2hex(random_bytes(20)).'.'.$types[$mime];
    $dest=$dir.'/'.$name;
    if(!move_uploaded_file($tmp,$dest)) fail('画像を保存できません',500);
    chmod($dest,0640);
    try {
        $q=db()->prepare('INSERT INTO site_media(menu_id,kind,file_name,mime_type) VALUES(?,?,?,?)');
        $q->execute([$menuId?:null,$kind,$name,$mime]);
    }catch(Throwable $e){unlink($dest);throw $e;}
    jsonOut(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
}
function mediaDelete(): never {
    requireAdmin();checkCsrf();
    $v=body();$id=(int)($v['id']??0);
    $q=db()->prepare('SELECT file_name FROM site_media WHERE id=?');$q->execute([$id]);
    $m=$q->fetch();if(!$m) fail('画像がありません',404);
    db()->prepare('DELETE FROM site_media WHERE id=?')->execute([$id]);
    global $root;
    @unlink($root.'/storage/media/'.$m['file_name']);
    jsonOut(['ok'=>true]);
}
function inquiryCreate(): never {
    checkCsrf();
    $v=body();
    $name=trim((string)($v['name']??''));
    $email=trim((string)($v['email']??''));
    $message=trim((string)($v['message']??''));
    if(mb_strlen($name)<1 || mb_strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || mb_strlen($message)<5 || mb_strlen($message)>3000) fail('入力内容をご確認ください');
    $q=db()->prepare('INSERT INTO inquiries(name,email,message) VALUES(?,?,?)');
    $q->execute([$name,$email,$message]);
    jsonOut(['ok'=>true]);
}
function waitlistCreate(): never {
    checkCsrf();
    $v=body();
    $menu=(int)($v['menu_id']??0);
    $date=trim((string)($v['date']??''));
    $email=trim((string)($v['email']??''));
    $name=trim((string)($v['name']??''));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || mb_strlen($name)>100 || ($date!==''&&!dateValid($date))) fail('入力内容をご確認ください');
    $q=db()->prepare('SELECT COUNT(*) FROM menus WHERE id=? AND active=1');$q->execute([$menu]);
    if(!$q->fetchColumn()) fail('メニューがありません');
    $q=db()->prepare('INSERT INTO waitlist(menu_id,preferred_date,email,name) VALUES(?,?,?,?)');
    $q->execute([$menu,$date?:null,$email,$name]);
    jsonOut(['ok'=>true]);
}
function extraAdminData(): never {
    requireAdmin();
    jsonOut([
        'media'=>mediaList(),
        'inquiries'=>db()->query('SELECT * FROM inquiries ORDER BY id DESC LIMIT 200')->fetchAll(),
        'waitlist'=>db()->query('SELECT w.*,m.name AS menu_name FROM waitlist w JOIN menus m ON m.id=w.menu_id ORDER BY w.id DESC LIMIT 200')->fetchAll()
    ]);
}
