<?php
declare(strict_types=1);
// Favicon store is deliberately separate from site_media, with database persistence.
function faviconTable(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS site_favicons (
      kind VARCHAR(10) NOT NULL PRIMARY KEY,
      mime VARCHAR(30) NOT NULL,
      image_bytes MEDIUMBLOB NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
function faviconRoute(string $action,string $method): void {
    if($action==='favicon' && $method==='GET'){
        $kind=(string)($_GET['kind']??'public');
        if(!in_array($kind,['public','admin'],true)){$kind='public';}
        try {
            faviconTable();
            $q=db()->prepare('SELECT mime,image_bytes FROM site_favicons WHERE kind=?');
            $q->execute([$kind]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
        }catch(Throwable $e){$row=false;}
        if($row){
            header('Content-Type: '.$row['mime']);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=3600');
            echo $row['image_bytes'];
            exit;
        }
        // SVG defaults are readable even before database migration.
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        readfile(__DIR__.'/../public/assets/favicon-'.$kind.'.svg');
        exit;
    }
    if($action==='faviconUpload' && $method==='POST'){
        requireMasterAdmin();checkCsrf();
        $kind=(string)($_POST['kind']??'');
        if(!in_array($kind,['public','admin'],true))fail('対象を選択してください');
        $f=$_FILES['icon']??null;
        if(!is_array($f)||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail('画像を選択してください');
        if((int)($f['size']??0)<1||(int)($f['size']??0)>1024*1024)fail('画像は1MB以内にしてください');
        $tmp=(string)($f['tmp_name']??'');
        if(!is_uploaded_file($tmp))fail('アップロードされたファイルを確認できません');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)||!getimagesize($tmp))fail('PNG・JPEG・WebP画像のみ対応します');
        $data=file_get_contents($tmp);
        if(!is_string($data))fail('画像を読み取れません');
        faviconTable();
        $q=db()->prepare('INSERT INTO site_favicons(kind,mime,image_bytes) VALUES(?,?,?)
          ON DUPLICATE KEY UPDATE mime=VALUES(mime),image_bytes=VALUES(image_bytes),updated_at=CURRENT_TIMESTAMP');
        $q->bindValue(1,$kind);
        $q->bindValue(2,$mime);
        $q->bindValue(3,$data,PDO::PARAM_LOB);
        $q->execute();
        jsonOut(['ok'=>true]);
    }
    if($action==='faviconReset' && $method==='POST'){
        requireMasterAdmin();checkCsrf();
        $kind=(string)(body()['kind']??'');
        if(!in_array($kind,['public','admin'],true))fail('対象が不正です');
        faviconTable();
        $q=db()->prepare('DELETE FROM site_favicons WHERE kind=?');$q->execute([$kind]);
        jsonOut(['ok'=>true]);
    }
}
