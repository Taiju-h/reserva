<?php
declare(strict_types=1);
// Admin users: master remains the existing ADMIN_PASSWORD_HASH environment login.
// Non-master users are stored as password hashes in MySQL.
function isMasterAdmin(): bool {
    return !empty($_SESSION['admin']) && ($_SESSION['admin_role']??'')==='master';
}
function requireMasterAdmin(): void {
    requireAdmin();
    if(!isMasterAdmin()) fail('マスター管理者のみ操作できます',403);
}
function activeAdminSession(): bool {
    if(empty($_SESSION['admin'])) return false;
    if(isMasterAdmin()) return true;
    $id=(int)($_SESSION['admin_user_id']??0);
    if($id<1) return false;
    $q=db()->prepare('SELECT active FROM admin_users WHERE id=?');
    $q->execute([$id]);
    return (int)$q->fetchColumn()===1;
}
function adminUsersRoute(string $action,string $method): void {
    if($action==='adminUsers' && $method==='GET'){
        requireMasterAdmin();
        $rows=db()->query('SELECT id,login_id,display_name,active,created_at,last_login_at FROM admin_users ORDER BY id')->fetchAll();
        jsonOut(['users'=>$rows]);
    }
    if($action==='adminUserCreate' && $method==='POST'){
        requireMasterAdmin();checkCsrf();$v=body();
        $login=trim((string)($v['login_id']??''));
        $name=trim((string)($v['display_name']??''));
        $password=(string)($v['password']??'');
        if(!preg_match('/^[A-Za-z0-9_.-]{3,50}$/D',$login)||strtolower($login)==='master')fail('IDは英数字・ハイフン・アンダーバー・ピリオドで3～50文字にしてください');
        if($name===''||mb_strlen($name)>80)fail('表示名は1～80文字にしてください');
        if(strlen($password)<12||strlen($password)>200)fail('パスワードは12～200文字にしてください');
        $hash=password_hash($password,PASSWORD_DEFAULT);
        try{
            $q=db()->prepare('INSERT INTO admin_users(login_id,display_name,password_hash,active) VALUES(?,?,?,1)');
            $q->execute([$login,$name,$hash]);
        }catch(PDOException $e){
            if(($e->errorInfo[1]??0)===1062)fail('そのログインIDは既に登録されています',409);
            throw $e;
        }
        jsonOut(['ok'=>true]);
    }
    if($action==='adminUserUpdate' && $method==='POST'){
        requireMasterAdmin();checkCsrf();$v=body();
        $id=(int)($v['id']??0);$operation=(string)($v['operation']??'');
        if($id<1)fail('管理者IDが不正です');
        if($operation==='disable'||$operation==='enable'){
            $q=db()->prepare('UPDATE admin_users SET active=? WHERE id=?');
            $q->execute([$operation==='enable'?1:0,$id]);
            if($q->rowCount()===0){
                $test=db()->prepare('SELECT id FROM admin_users WHERE id=?');$test->execute([$id]);
                if(!$test->fetchColumn())fail('管理者が見つかりません',404);
            }
        }elseif($operation==='reset_password'){
            $password=(string)($v['password']??'');
            if(strlen($password)<12||strlen($password)>200)fail('パスワードは12～200文字にしてください');
            $q=db()->prepare('UPDATE admin_users SET password_hash=?,session_version=session_version+1 WHERE id=?');
            $q->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
            if($q->rowCount()===0)fail('管理者が見つかりません',404);
        }else fail('操作が不正です');
        jsonOut(['ok'=>true]);
    }
}
