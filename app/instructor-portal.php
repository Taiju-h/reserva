<?php
declare(strict_types=1);
// Instructor portal. Credentials are independent of admin accounts and can only
// operate on this instructor's own slots in the assigned corporate campaign.
function instructorSession(): ?array {
    $id=(int)($_SESSION['teacher_user_id']??0);
    $version=(int)($_SESSION['teacher_version']??0);
    if($id<1||$version<1)return null;
    $q=db()->prepare('SELECT a.teacher_id,a.login_id,a.version,t.name FROM teacher_logins a
        JOIN teachers t ON t.id=a.teacher_id WHERE a.teacher_id=? AND a.version=?
          AND a.active=1 AND t.active=1');
    $q->execute([$id,$version]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function instructorRequire(): array {
    $teacher=instructorSession();
    if(!$teacher)fail('先生のログインが必要です',401);
    return $teacher;
}
function instructorSlots(int $teacherId): array {
    $campaign=corporateCampaign();
    if(!$campaign)return [];
    $q=db()->prepare('SELECT s.id,s.starts_at,s.ends_at,
       IF(s.booked_id IS NULL,"空き","予約済み") AS state
       FROM corporate_slots s JOIN corporate_teachers ct ON ct.campaign_id=s.campaign_id
          AND ct.teacher_id=s.teacher_id AND ct.enabled=1
       WHERE s.campaign_id=? AND s.teacher_id=? AND s.starts_at>=NOW()
       ORDER BY s.starts_at LIMIT 500');
    $q->execute([(int)$campaign['id'],$teacherId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function instructorRoute(string $action,string $method): void {
    if($action==='instructorAccountSave' && $method==='POST'){
        requireMasterAdmin();checkCsrf();
        $v=body();$id=(int)($v['teacher_id']??0);
        $login=strtolower(trim((string)($v['login_id']??'')));
        $password=(string)($v['password']??'');
        $active=!empty($v['active'])?1:0;
        if($id<1||!preg_match('/^[a-z0-9_.-]{3,100}$/D',$login))fail('先生IDまたはログインIDが不正です');
        if($password!==''&&(strlen($password)<12||strlen($password)>200))fail('パスワードは12文字以上にしてください');
        $q=db()->prepare('SELECT id FROM teachers WHERE id=? AND active=1');$q->execute([$id]);
        if(!$q->fetchColumn())fail('有効な先生を指定してください');
        $q=db()->prepare('SELECT teacher_id FROM teacher_logins WHERE teacher_id=?');$q->execute([$id]);
        $exists=(bool)$q->fetchColumn();
        if(!$exists && $password==='')fail('新規登録では12文字以上のパスワードが必要です');
        try{
            if($exists){
                if($password!==''){
                    $q=db()->prepare('UPDATE teacher_logins SET login_id=?,password_hash=?,active=?,version=version+1 WHERE teacher_id=?');
                    $q->execute([$login,password_hash($password,PASSWORD_DEFAULT),$active,$id]);
                }else{
                    $q=db()->prepare('UPDATE teacher_logins SET login_id=?,active=?,version=version+1 WHERE teacher_id=?');
                    $q->execute([$login,$active,$id]);
                }
            }else{
                $q=db()->prepare('INSERT INTO teacher_logins(teacher_id,login_id,password_hash,active)
                    VALUES(?,?,?,?)');
                $q->execute([$id,$login,password_hash($password,PASSWORD_DEFAULT),$active]);
            }
        }catch(PDOException $e){
            if(($e->errorInfo[1]??0)===1062)fail('同じログインIDが既に登録されています',409);
            throw $e;
        }
        jsonOut(['ok'=>true]);
    }
    if($action==='instructorAccounts' && $method==='GET'){
        requireMasterAdmin();
        $q=db()->query('SELECT a.teacher_id,a.login_id,a.active,t.name FROM teacher_logins a
          JOIN teachers t ON t.id=a.teacher_id ORDER BY t.name');
        jsonOut(['accounts'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if($action==='instructorLogin' && $method==='POST'){
        checkCsrf();$v=body();
        $login=strtolower(trim((string)($v['login_id']??'')));
        $pass=(string)($v['password']??'');
        $attempt=(int)($_SESSION['instructor_failures']??0);
        $failedAt=(int)($_SESSION['instructor_failed_at']??0);
        if($attempt>=5 && time()-$failedAt<900)fail('認証試行が上限です。15分後に再試行してください',429);
        $q=db()->prepare('SELECT a.teacher_id,a.password_hash,a.version FROM teacher_logins a
          JOIN teachers t ON t.id=a.teacher_id
          WHERE a.login_id=? AND a.active=1 AND t.active=1');
        $q->execute([$login]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||!password_verify($pass,(string)$row['password_hash'])){
            $_SESSION['instructor_failures']=($failedAt && time()-$failedAt>=900)?1:$attempt+1;
            $_SESSION['instructor_failed_at']=time();
            fail('ログインIDまたはパスワードが正しくありません',401);
        }
        session_regenerate_id(true);
        unset($_SESSION['instructor_failures'],$_SESSION['instructor_failed_at']);
        $_SESSION['teacher_user_id']=(int)$row['teacher_id'];
        $_SESSION['teacher_version']=(int)$row['version'];
        jsonOut(['ok'=>true]);
    }
    if($action==='instructorLogout' && $method==='POST'){
        checkCsrf();
        unset($_SESSION['teacher_user_id'],$_SESSION['teacher_version']);
        session_regenerate_id(true);
        jsonOut(['ok'=>true]);
    }
    if($action==='instructorMySlots' && $method==='GET'){
        $teacher=instructorRequire();
        $campaign=corporateCampaign();
        $assigned=false;
        if($campaign){
            $q=db()->prepare('SELECT COUNT(*) FROM corporate_teachers
              WHERE campaign_id=? AND teacher_id=? AND enabled=1');
            $q->execute([$campaign['id'],$teacher['teacher_id']]);$assigned=(bool)$q->fetchColumn();
        }
        jsonOut(['teacher'=>['name'=>$teacher['name'],'id'=>(int)$teacher['teacher_id']],
          'assigned'=>$assigned,'slots'=>$assigned?instructorSlots((int)$teacher['teacher_id']):[]]);
    }
    if($action==='instructorSlotAdd' && $method==='POST'){
        $teacher=instructorRequire();checkCsrf();$v=body();
        $campaign=corporateCampaign();if(!$campaign)fail('案件がまだ登録されていません',404);
        $q=db()->prepare('SELECT COUNT(*) FROM corporate_teachers
            WHERE campaign_id=? AND teacher_id=? AND enabled=1');
        $q->execute([$campaign['id'],$teacher['teacher_id']]);
        if(!$q->fetchColumn())fail('この案件の担当講師に指定されていません',403);
        $date=(string)($v['date']??'');$time=(string)($v['time']??'');
        if(!dateValid($date)||!corporateTimeAllowed($date,$time))fail('この日時は受付対象外です');
        $tz=new DateTimeZone('Asia/Tokyo');
        $start=new DateTimeImmutable($date.' '.$time,$tz);
        if($start<=new DateTimeImmutable('now',$tz))fail('過去の日時には登録できません');
        $end=$start->modify('+60 minutes');
        $q=db()->prepare('SELECT COUNT(*) FROM corporate_slots WHERE teacher_id=? AND starts_at<? AND ends_at>?');
        $q->execute([$teacher['teacher_id'],$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s')]);
        if((int)$q->fetchColumn()>0)fail('登録済みの枠と重複しています',409);
        $pdo=db();
        $q=$pdo->prepare('INSERT IGNORE INTO corporate_slots(campaign_id,teacher_id,starts_at,ends_at)
            VALUES(?,?,?,?)');
        $q->execute([$campaign['id'],$teacher['teacher_id'],
          $start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
        jsonOut(['ok'=>true,'created'=>$q->rowCount()]);
    }
    if($action==='instructorSlotDelete' && $method==='POST'){
        $teacher=instructorRequire();checkCsrf();$id=(int)(body()['id']??0);
        if($id<1)fail('枠を選択してください');
        $q=db()->prepare('DELETE FROM corporate_slots WHERE id=? AND teacher_id=?
           AND booked_id IS NULL AND starts_at>NOW()');
        $q->execute([$id,$teacher['teacher_id']]);
        if($q->rowCount()!==1)fail('予約済み、または削除できない枠です',409);
        jsonOut(['ok'=>true]);
    }
}