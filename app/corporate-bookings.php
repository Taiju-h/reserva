<?php
declare(strict_types=1);
// Isolated company-funded pilot. Never calls Stripe and never modifies public menus.
function corporateCampaign(): array {
    $q=db()->prepare("SELECT * FROM corporate_campaigns WHERE code='tanseisha' LIMIT 1");
    $q->execute();
    return $q->fetch(PDO::FETCH_ASSOC)?:[];
}
function corporateAuthorized(array $campaign,string $code): bool {
    return $campaign && (int)$campaign['published']===1 &&
        $code!=='' && password_verify($code,(string)$campaign['access_code_hash']);
}
function corporateHoliday(string $date): bool {
    return in_array($date,['2027-01-01','2027-01-11','2027-02-11','2027-02-23'],true);
}
function corporateTimeAllowed(string $date,string $time): bool {
    if($date<'2026-12-20'||$date>'2027-02-28')return false;
    if($date>='2026-12-28'&&$date<='2027-01-03')return false;
    if(!preg_match('/^(?:[01]\d|2[0-3]):00$/D',$time))return false;
    $day=(int)(new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Asia/Tokyo')))->format('w');
    $hour=(int)substr($time,0,2);
    if($day===0||$day===6||corporateHoliday($date))return $hour>=9&&$hour<=19;
    return in_array($hour,[8,18,19],true);
}
function corporateTeacherName(int $teacher): ?array {
    $q=db()->prepare('SELECT t.id,t.name,COALESCE(p.summary,"") AS summary,COALESCE(p.career,"") AS career,
          COALESCE(p.photo_path,"") AS photo_path
        FROM teachers t LEFT JOIN teacher_profiles p ON p.teacher_id=t.id WHERE t.id=? AND t.active=1 LIMIT 1');
    $q->execute([$teacher]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function corporateAvailability(int $campaignId,int $teacherId,string $date): array {
    if(!dateValid($date)||$date<'2026-12-20'||$date>'2027-02-28')return [];
    if($date>='2026-12-28'&&$date<='2027-01-03')return [];
    $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $q=db()->prepare('SELECT s.id,DATE_FORMAT(s.starts_at,"%H:%i") AS time
      FROM corporate_slots s WHERE s.campaign_id=? AND s.teacher_id=? AND DATE(s.starts_at)=?
      AND s.starts_at>? AND s.booked_id IS NULL
      AND NOT EXISTS (
         SELECT 1 FROM bookings b JOIN menus m ON m.id=b.menu_id
         LEFT JOIN stripe_payments sp ON sp.booking_id=b.id
         WHERE m.teacher_id=s.teacher_id AND
           (b.status IN ("confirmed","pending_review") OR
             (b.status="pending_payment" AND sp.expires_at>NOW()))
           AND b.starts_at<s.ends_at AND b.ends_at>s.starts_at
       ) ORDER BY s.starts_at');
    $q->execute([$campaignId,$teacherId,$date,$now]);
    return array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),
      static fn($r)=>corporateTimeAllowed($date,$r['time'])));
}
function corporateRoute(string $action,string $method): void {
    if($action==='corporateAccess' && $method==='POST'){
        checkCsrf();$v=body();$campaign=corporateCampaign();
        if(!corporateAuthorized($campaign,(string)($v['access_code']??'')))fail('企業専用コードが正しくありません',403);
        $q=db()->prepare('SELECT t.id,t.name,COALESCE(p.summary,"") AS summary,
             COALESCE(p.career,"") AS career,COALESCE(p.photo_path,"") AS photo_path
           FROM corporate_teachers ct JOIN teachers t ON t.id=ct.teacher_id
           LEFT JOIN teacher_profiles p ON p.teacher_id=t.id
           WHERE ct.campaign_id=? AND ct.enabled=1 AND t.active=1 ORDER BY t.name');
        $q->execute([$campaign['id']]);$teachers=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($teachers as &$t)$t['photo_url']=$t['photo_path']!==''?('/?action=corporatePortrait&teacher_id='.$t['id']):null;
        unset($t);
        jsonOut(['ok'=>true,'campaign_name'=>$campaign['name'],'teachers'=>$teachers]);
    }
    if($action==='corporatePortrait' && $method==='GET'){
        $campaign=corporateCampaign();
        if(!$campaign||(int)$campaign['published']!==1){http_response_code(404);exit;}
        $teacher=(int)($_GET['teacher_id']??0);
        $q=db()->prepare('SELECT a.mime,a.image_bytes FROM corporate_teachers ct JOIN teacher_photo_assets a
          ON a.teacher_id=ct.teacher_id WHERE ct.campaign_id=? AND ct.teacher_id=? AND ct.enabled=1 LIMIT 1');
        $q->execute([$campaign['id'],$teacher]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){http_response_code(404);exit;}
        header('Content-Type: '.$row['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: public,max-age=3600');
        echo $row['image_bytes'];exit;
    }
    if($action==='corporateAvailability' && $method==='POST'){
        checkCsrf();$v=body();$campaign=corporateCampaign();
        if(!corporateAuthorized($campaign,(string)($v['access_code']??'')))fail('企業専用コードが正しくありません',403);
        $teacher=(int)($v['teacher_id']??0);$date=(string)($v['date']??'');
        $q=db()->prepare('SELECT COUNT(*) FROM corporate_teachers WHERE campaign_id=? AND teacher_id=? AND enabled=1');
        $q->execute([$campaign['id'],$teacher]);if(!$q->fetchColumn())fail('この先生は対象外です',403);
        jsonOut(['slots'=>corporateAvailability((int)$campaign['id'],$teacher,$date)]);
    }
    if($action==='corporateBook' && $method==='POST'){
        checkCsrf();$v=body();$campaign=corporateCampaign();
        if(!corporateAuthorized($campaign,(string)($v['access_code']??'')))fail('企業専用コードが正しくありません',403);
        if(time()-(int)($_SESSION['last_corporate_booking_at']??0)<60)fail('続けて予約する場合は1分後に再試行してください',429);
        $name=trim((string)($v['name']??''));$email=trim((string)($v['email']??''));
        $phone=trim((string)($v['phone']??''));$ref=trim((string)($v['employee_reference']??''));
        if(mb_strlen($name)<1||mb_strlen($name)>100||!filter_var($email,FILTER_VALIDATE_EMAIL)||
           strlen($email)>254||strlen($phone)>40||mb_strlen($ref)>80)fail('お客様情報を確認してください');
        if(empty($v['consent']))fail('個人情報の利用目的への同意が必要です');
        $slotId=(int)($v['slot_id']??0);$teacher=(int)($v['teacher_id']??0);
        $pdo=db();$pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT s.id,s.teacher_id,s.starts_at,s.ends_at,s.booked_id
              FROM corporate_slots s JOIN corporate_teachers ct
              ON ct.campaign_id=s.campaign_id AND ct.teacher_id=s.teacher_id AND ct.enabled=1
              WHERE s.id=? AND s.campaign_id=? AND s.teacher_id=? FOR UPDATE');
            $q->execute([$slotId,$campaign['id'],$teacher]);$slot=$q->fetch(PDO::FETCH_ASSOC);
            if(!$slot||$slot['booked_id']!==null){$pdo->rollBack();fail('この時間はすでに予約されています',409);}
            $date=substr($slot['starts_at'],0,10);$time=substr($slot['starts_at'],11,5);
            $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
            if($slot['starts_at']<=$now||!corporateTimeAllowed($date,$time)||
               (strtotime($slot['ends_at'])-strtotime($slot['starts_at']))!==3600){
                $pdo->rollBack();fail('この時間は受付できません',409);
            }
            // The same instructor may also be assigned to general paid consultations.
            $conflict=$pdo->prepare('SELECT COUNT(*) FROM bookings b JOIN menus m ON m.id=b.menu_id
              LEFT JOIN stripe_payments sp ON sp.booking_id=b.id
              WHERE m.teacher_id=? AND
                (b.status IN ("confirmed","pending_review") OR
                 (b.status="pending_payment" AND sp.expires_at>NOW()))
                AND b.starts_at<? AND b.ends_at>?');
            $conflict->execute([$teacher,$slot['ends_at'],$slot['starts_at']]);
            if((int)$conflict->fetchColumn()>0){$pdo->rollBack();fail('先生の別の予約と重複しています',409);}
            $q=$pdo->prepare('SELECT COUNT(*) FROM corporate_bookings WHERE campaign_id=?
                 AND customer_email=? AND status IN ("confirmed","completed")');
            $q->execute([$campaign['id'],$email]);
            if((int)$q->fetchColumn()>0){$pdo->rollBack();fail('このメールアドレスでは既に予約があります。変更は事務局へご連絡ください',409);}
            $insert=$pdo->prepare('INSERT INTO corporate_bookings
              (campaign_id,slot_id,teacher_id,customer_name,customer_email,customer_phone,employee_reference)
              VALUES(?,?,?,?,?,?,?)');
            $insert->execute([$campaign['id'],$slotId,$teacher,$name,$email,$phone,$ref?:null]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE corporate_slots SET booked_id=? WHERE id=? AND booked_id IS NULL')->execute([$id,$slotId]);
            $pdo->commit();$_SESSION['last_corporate_booking_at']=time();
            jsonOut(['ok'=>true,'booking_id'=>$id,'date'=>$date,'time'=>$time]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if($action==='corporateAdmin' && $method==='GET'){
        requireAdmin();$campaign=corporateCampaign();
        $teachers=db()->query('SELECT id,name,active FROM teachers ORDER BY name')->fetchAll();
        $assigned=[];$slots=[];$bookings=[];$counts=[];
        if($campaign){
          $q=db()->prepare('SELECT teacher_id FROM corporate_teachers WHERE campaign_id=? AND enabled=1');
          $q->execute([$campaign['id']]);$assigned=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
          $q=db()->prepare('SELECT s.id,s.teacher_id,t.name AS teacher_name,s.starts_at,s.booked_id
             FROM corporate_slots s JOIN teachers t ON t.id=s.teacher_id WHERE s.campaign_id=? ORDER BY s.starts_at,t.name');
          $q->execute([$campaign['id']]);$slots=$q->fetchAll();
          $q=db()->prepare('SELECT b.id,b.customer_name,b.customer_email,b.employee_reference,b.status,
            s.starts_at,t.name AS teacher_name
            FROM corporate_bookings b JOIN corporate_slots s ON s.id=b.slot_id JOIN teachers t ON t.id=b.teacher_id
            WHERE b.campaign_id=? ORDER BY s.starts_at');
          $q->execute([$campaign['id']]);$bookings=$q->fetchAll();
          foreach(['confirmed','completed','cancelled','no_show'] as $status){
            $counts[$status]=count(array_filter($bookings,static fn($x)=>$x['status']===$status));
          }
        }
        jsonOut(['campaign'=>$campaign?[
           'name'=>$campaign['name'],'published'=>(bool)$campaign['published'],'unit_price'=>(int)$campaign['unit_price'],
           'starts_on'=>$campaign['starts_on'],'ends_on'=>$campaign['ends_on']]:null,
           'teachers'=>$teachers,'assigned'=>$assigned,'slots'=>$slots,'bookings'=>$bookings,
           'counts'=>$counts,'completed_total_jpy'=>($counts['completed']??0)*8000]);
    }
    if($action==='corporateSetup' && $method==='POST'){
        requireMasterAdmin();checkCsrf();$v=body();
        $name=trim((string)($v['name']??'丹青社 セカンドキャリア相談'));
        $pass=(string)($v['access_code']??'');
        if(mb_strlen($name)<2||mb_strlen($name)>150)fail('案件名を確認してください');
        $ids=$v['teacher_ids']??[];
        if(!is_array($ids)||count($ids)>10||count($ids)!==count(array_unique($ids)))fail('講師は最大10名選択してください');
        $ids=array_values(array_unique(array_map('intval',$ids)));
        if(count($ids)>0){
          $marks=implode(',',array_fill(0,count($ids),'?'));
          $q=db()->prepare("SELECT COUNT(*) FROM teachers WHERE id IN ($marks) AND active=1");$q->execute($ids);
          if((int)$q->fetchColumn()!==count($ids))fail('先生の登録状態を確認してください');
        }
        $pdo=db();$pdo->beginTransaction();
        try{
          $campaign=corporateCampaign();
          if(!$campaign){
            if(strlen($pass)<12){$pdo->rollBack();fail('初期の企業専用コードは12文字以上にしてください');}
            $pdo->prepare('INSERT INTO corporate_campaigns(code,name,access_code_hash,starts_on,ends_on,unit_price,published)
              VALUES("tanseisha",?,?, "2026-12-20","2027-02-28",8000,0)')
              ->execute([$name,password_hash($pass,PASSWORD_DEFAULT)]);
            $campaign=corporateCampaign();
          }else{
            if($pass!==''&&strlen($pass)<12){$pdo->rollBack();fail('企業専用コードは12文字以上にしてください');}
            $pdo->prepare('UPDATE corporate_campaigns SET name=?,access_code_hash=IF(?="",access_code_hash,?) WHERE id=?')
              ->execute([$name,$pass,$pass===''?'':password_hash($pass,PASSWORD_DEFAULT),$campaign['id']]);
          }
          $pdo->prepare('UPDATE corporate_teachers SET enabled=0 WHERE campaign_id=?')->execute([$campaign['id']]);
          foreach($ids as $id){
             $pdo->prepare('INSERT INTO corporate_teachers(campaign_id,teacher_id,enabled) VALUES(?,?,1)
               ON DUPLICATE KEY UPDATE enabled=1')->execute([$campaign['id'],$id]);
          }
          // Never publish automatically. Master must explicitly review the ten teachers and their slots.
          $pdo->commit();jsonOut(['ok'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if($action==='corporatePublish' && $method==='POST'){
        requireMasterAdmin();checkCsrf();$v=body();$campaign=corporateCampaign();
        if(!$campaign)fail('先に案件と講師を登録してください');
        $publish=!empty($v['published'])?1:0;
        if($publish){
          $q=db()->prepare('SELECT COUNT(*) FROM corporate_teachers WHERE campaign_id=? AND enabled=1');
          $q->execute([$campaign['id']]);if((int)$q->fetchColumn()!==10)fail('講師10名を登録してから公開してください');
          $q=db()->prepare('SELECT COUNT(*) FROM corporate_slots WHERE campaign_id=?');
          $q->execute([$campaign['id']]);if(!$q->fetchColumn())fail('予約枠を登録してから公開してください');
        }
        db()->prepare('UPDATE corporate_campaigns SET published=? WHERE id=?')->execute([$publish,$campaign['id']]);
        jsonOut(['ok'=>true]);
    }
    if($action==='corporateGenerateSlots' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();$campaign=corporateCampaign();if(!$campaign)fail('先に案件を登録してください');
        $teacher=(int)($v['teacher_id']??0);$from=(string)($v['from']??'');$until=(string)($v['until']??'');
        if(!dateValid($from)||!dateValid($until)||$from>$until||$from<'2026-12-20'||$until>'2027-02-28')fail('日付は2026/12/20〜2027/2/28の範囲で選択してください');
        $q=db()->prepare('SELECT COUNT(*) FROM corporate_teachers WHERE campaign_id=? AND teacher_id=? AND enabled=1');
        $q->execute([$campaign['id'],$teacher]);if(!$q->fetchColumn())fail('対象の先生を選んでください');
        $pdo=db();$create=$pdo->prepare('INSERT IGNORE INTO corporate_slots(campaign_id,teacher_id,starts_at,ends_at) VALUES(?,?,?,?)');
        $created=0;
        $start=new DateTimeImmutable($from,new DateTimeZone('Asia/Tokyo'));
        $end=new DateTimeImmutable($until,new DateTimeZone('Asia/Tokyo'));
        $selected=$v['hours']??[];if(!is_array($selected)||count($selected)>12)fail('時間枠を選択してください');
        $hours=array_values(array_unique(array_map('intval',$selected)));
        foreach(new DatePeriod($start,new DateInterval('P1D'),$end->modify('+1 day')) as $day){
           $date=$day->format('Y-m-d');
           foreach($hours as $hour){
              $time=sprintf('%02d:00',$hour);
              if(!corporateTimeAllowed($date,$time))continue;
              $at=$date.' '.$time.':00';
              $create->execute([$campaign['id'],$teacher,$at,$day->setTime($hour+1,0)->format('Y-m-d H:i:s')]);
              $created+=$create->rowCount();
           }
        }
        jsonOut(['ok'=>true,'created'=>$created]);
    }
    if($action==='corporateSlotDelete' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();$campaign=corporateCampaign();
        if(!$campaign)fail('案件がありません',404);
        $slot=(int)($v['id']??0);if($slot<1)fail('枠が不正です');
        $q=db()->prepare('DELETE FROM corporate_slots WHERE id=? AND campaign_id=? AND booked_id IS NULL');
        $q->execute([$slot,$campaign['id']]);
        if($q->rowCount()!==1)fail('予約済み、または存在しない枠は削除できません',409);
        jsonOut(['ok'=>true]);
    }
    if($action==='corporateBookingStatus' && $method==='POST'){
       requireAdmin();checkCsrf();$v=body();
       $id=(int)($v['id']??0);$status=(string)($v['status']??'');
       if($id<1||!in_array($status,['confirmed','completed','cancelled','no_show'],true))fail('予約状態が不正です');
       $pdo=db();$pdo->beginTransaction();
       try{
          $q=$pdo->prepare('SELECT b.status,b.slot_id FROM corporate_bookings b WHERE b.id=? FOR UPDATE');
          $q->execute([$id]);$current=$q->fetch();
          if(!$current){$pdo->rollBack();fail('予約がありません',404);}
          if($current['status']==='completed' && $status!=='completed'){$pdo->rollBack();fail('請求確定済みの予約は変更できません');}
          if($current['status']==='cancelled'&&$status!=='cancelled'){$pdo->rollBack();fail('取消済みの予約は新規で取り直してください');}
          $pdo->prepare('UPDATE corporate_bookings SET status=?,completed_at=IF(?="completed",NOW(),NULL) WHERE id=?')
            ->execute([$status,$status,$id]);
          if($status==='cancelled')$pdo->prepare('UPDATE corporate_slots SET booked_id=NULL WHERE id=? AND booked_id=?')
            ->execute([$current['slot_id'],$id]);
          $pdo->commit();jsonOut(['ok'=>true]);
       }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
