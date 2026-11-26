<?php
declare(strict_types=1);
// Isolated company-funded pilot. Never calls Stripe and never modifies public menus.
final class CorporateBookingConflict extends RuntimeException {}
function corporateCampaign(): array {
    $q=db()->prepare("SELECT * FROM corporate_campaigns WHERE code='tanseisha' LIMIT 1");
    $q->execute();
    return $q->fetch(PDO::FETCH_ASSOC)?:[];
}
function corporateAuthorized(array $campaign,string $code): bool {
    return $campaign && (int)$campaign['published']===1 &&
        $code!=='' && password_verify($code,(string)$campaign['access_code_hash']);
}
require_once __DIR__.'/corporate-policy.php';
function corporateTimeAllowed(string $date,string $time): bool {
    return corporateAllowedStart($date,$time);
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
    if($action==='corporateRoster' && $method==='GET'){
        requireMasterAdmin();
        $campaign=corporateCampaign();
        $rows=[];
        if($campaign){
            $q=db()->prepare('SELECT id,email,display_name,active FROM corporate_invitees WHERE campaign_id=? ORDER BY id DESC LIMIT 400');
            $q->execute([(int)$campaign['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        jsonOut(['roster'=>$rows,'required'=>(int)($campaign['roster_required']??0)===1,
            'campaign_ready'=>(bool)$campaign]);
    }
    if($action==='corporateRosterImport' && $method==='POST'){
        requireMasterAdmin();checkCsrf();$v=body();
        $campaign=corporateCampaign();if(!$campaign)fail('先に企業案件を作成してください');
        $text=trim((string)($v['entries']??''));
        if($text===''||strlen($text)>50000)fail('名簿の入力は50KB以内で指定してください');
        $lines=preg_split('/\\r\\n|\\n|\\r/',$text);
        if(count($lines)>300)fail('1回の登録は300行までです');
        $users=[];$errors=[];
        foreach($lines as $i=>$line){
            if(trim($line)==='')continue;
            $cells=str_getcsv(trim($line));
            if(count($cells)<1||count($cells)>2){$errors[]=$i+1;continue;}
            // One row: email OR email,name. Never need to expose the roster to the public.
            $email=strtolower(trim((string)$cells[0]));
            $name=isset($cells[1])?trim((string)$cells[1]):'';
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||mb_strlen($name)>100){$errors[]=$i+1;continue;}
            $users[$email]=$name;
        }
        if($errors)fail('形式を確認してください（行番号：'.implode(',',array_slice($errors,0,20)).'）。1行につきメールアドレス,氏名（任意）で入力します');
        if(!$users)fail('登録対象がありません');
        $stmt=db()->prepare('INSERT INTO corporate_invitees(campaign_id,email,display_name,active) VALUES(?,?,?,1)
             ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1');
        $db=db();$db->beginTransaction();
        try{
            foreach($users as $email=>$name)$stmt->execute([(int)$campaign['id'],$email,$name?:null]);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        jsonOut(['ok'=>true,'processed'=>count($users)]);
    }
    if($action==='corporateRosterStatus' && $method==='POST'){
        requireMasterAdmin();checkCsrf();$v=body();$campaign=corporateCampaign();
        if(!$campaign)fail('企業案件がありません');
        $id=(int)($v['id']??0);$active=!empty($v['active'])?1:0;
        if($id<1)fail('対象が不正です');
        $q=db()->prepare('UPDATE corporate_invitees SET active=? WHERE id=? AND campaign_id=?');
        $q->execute([$active,$id,(int)$campaign['id']]);
        jsonOut(['ok'=>true]);
    }
    if($action==='corporateRosterEnforcement' && $method==='POST'){
        requireMasterAdmin();checkCsrf();$v=body();$campaign=corporateCampaign();
        if(!$campaign)fail('企業案件がありません');
        $required=!empty($v['required'])?1:0;
        if($required===1){
            $q=db()->prepare('SELECT COUNT(*) FROM corporate_invitees WHERE campaign_id=? AND active=1');
            $q->execute([(int)$campaign['id']]);
            if((int)$q->fetchColumn()===0)fail('事前名簿が空です。先にメールアドレスを登録してください');
        }
        $q=db()->prepare('UPDATE corporate_campaigns SET roster_required=? WHERE id=?');
        $q->execute([$required,(int)$campaign['id']]);
        jsonOut(['ok'=>true,'required'=>(bool)$required]);
    }
    if($action==='corporateAccess' && $method==='POST'){
        checkCsrf();$v=body();$campaign=corporateCampaign();
        if(!corporateAuthorized($campaign,(string)($v['access_code']??'')))fail('企業専用コードが正しくありません',403);
        $name=trim((string)($v['name']??''));
        $email=strtolower(trim((string)($v['email']??'')));
        $phone=trim((string)($v['phone']??''));
        $reference=trim((string)($v['employee_reference']??''));
        if(mb_strlen($name)<1||mb_strlen($name)>100||!filter_var($email,FILTER_VALIDATE_EMAIL)||
           strlen($email)>254||strlen($phone)>40||mb_strlen($reference)>80)fail('氏名・メールアドレスをご確認ください');
        if(empty($v['consent']))fail('個人情報の利用目的への同意が必要です');
        if(!corporateEmailVerified($campaign,$email))fail('メールアドレスの確認が必要です',403);
        if((int)($campaign['roster_required']??0)===1){
            $check=db()->prepare('SELECT id FROM corporate_invitees WHERE campaign_id=? AND email=? AND active=1 LIMIT 1');
            $check->execute([(int)$campaign['id'],$email]);
            if(!$check->fetchColumn())fail('このメールアドレスでは受付できません。ご案内されたメールアドレスをご確認ください',403);
        }
        // An email address identifies the customer record, but is not proof of ownership.
        // Store it only for the active company campaign; never expose records by email lookup.
        $q=db()->prepare('INSERT IGNORE INTO corporate_customers
           (campaign_id,email,name,phone,employee_reference) VALUES(?,?,?,?,?)');
        $q->execute([$campaign['id'],$email,$name,$phone,$reference?:null]);
        $q=db()->prepare('SELECT id FROM corporate_customers WHERE campaign_id=? AND email=?');
        $q->execute([$campaign['id'],$email]);
        $_SESSION['corporate_customer_id']=(int)$q->fetchColumn();
        $_SESSION['corporate_campaign_id']=(int)$campaign['id'];
        $_SESSION['corporate_customer_email']=$email;
        $q=db()->prepare('SELECT t.id,t.name,COALESCE(p.summary,"") AS summary,
             COALESCE(p.career,"") AS career,COALESCE(p.photo_path,"") AS photo_path
           FROM corporate_teachers ct JOIN teachers t ON t.id=ct.teacher_id
           LEFT JOIN teacher_profiles p ON p.teacher_id=t.id
           WHERE ct.campaign_id=? AND ct.enabled=1 AND t.active=1 ORDER BY t.name');
        $q->execute([$campaign['id']]);$teachers=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($teachers as &$t){
            $path=(string)$t['photo_path'];
            if(str_starts_with($path,'/uploads/teacher-photos/'))
                $t['photo_url']=$path;
            else {
                $q=db()->prepare('SELECT COUNT(*) FROM teacher_photo_assets WHERE teacher_id=?');
                $q->execute([$t['id']]);
                $t['photo_url']=(int)$q->fetchColumn()>0?'/?action=corporatePortrait&teacher_id='.$t['id']:null;
            }
        }
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
        $name=trim((string)($v['name']??''));$email=strtolower(trim((string)($v['email']??'')));
        $phone=trim((string)($v['phone']??''));$ref=trim((string)($v['employee_reference']??''));
        if(mb_strlen($name)<1||mb_strlen($name)>100||!filter_var($email,FILTER_VALIDATE_EMAIL)||
           strlen($email)>254||strlen($phone)>40||mb_strlen($ref)>80)fail('お客様情報を確認してください');
        if(empty($v['consent']))fail('個人情報の利用目的への同意が必要です');
        if(!corporateEmailVerified($campaign,$email))fail('メールアドレスの確認が必要です',403);
        if((int)($campaign['roster_required']??0)===1){
            $check=db()->prepare('SELECT id FROM corporate_invitees WHERE campaign_id=? AND email=? AND active=1 LIMIT 1');
            $check->execute([(int)$campaign['id'],$email]);
            if(!$check->fetchColumn())fail('事前名簿に登録されたメールアドレスをご確認ください',403);
        }
        $customerId=(int)($_SESSION['corporate_customer_id']??0);
        if($customerId<1||(int)($_SESSION['corporate_campaign_id']??0)!==(int)$campaign['id']
           ||($_SESSION['corporate_customer_email']??'')!==$email)
           fail('先にお客様情報を登録してください',401);
        $slotId=(int)($v['slot_id']??0);$teacher=(int)($v['teacher_id']??0);
        $pdo=db();bookingLock($pdo);$pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT s.id,s.teacher_id,s.starts_at,s.ends_at,s.booked_id
              FROM corporate_slots s JOIN corporate_teachers ct
              ON ct.campaign_id=s.campaign_id AND ct.teacher_id=s.teacher_id AND ct.enabled=1
              WHERE s.id=? AND s.campaign_id=? AND s.teacher_id=? FOR UPDATE');
            $q->execute([$slotId,$campaign['id'],$teacher]);$slot=$q->fetch(PDO::FETCH_ASSOC);
            if(!$slot||$slot['booked_id']!==null){throw new CorporateBookingConflict('この時間はすでに予約されています');}
            $date=substr($slot['starts_at'],0,10);$time=substr($slot['starts_at'],11,5);
            $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
            if($slot['starts_at']<=$now||!corporateTimeAllowed($date,$time)||
               (strtotime($slot['ends_at'])-strtotime($slot['starts_at']))!==3600){
                throw new CorporateBookingConflict('この時間は受付できません');
            }
            // The same instructor may also be assigned to general paid consultations.
            $conflict=$pdo->prepare('SELECT COUNT(*) FROM bookings b JOIN menus m ON m.id=b.menu_id
              LEFT JOIN stripe_payments sp ON sp.booking_id=b.id
              WHERE m.teacher_id=? AND
                (b.status IN ("confirmed","pending_review") OR
                 (b.status="pending_payment" AND sp.expires_at>NOW()))
                AND b.starts_at<? AND b.ends_at>?');
            $conflict->execute([$teacher,$slot['ends_at'],$slot['starts_at']]);
            if((int)$conflict->fetchColumn()>0){throw new CorporateBookingConflict('先生の別の予約と重複しています');}
            $q=$pdo->prepare('SELECT COUNT(*) FROM corporate_bookings WHERE campaign_id=?
                 AND customer_email=? AND status IN ("confirmed","completed")');
            $q->execute([$campaign['id'],$email]);
            if((int)$q->fetchColumn()>0){throw new CorporateBookingConflict('このメールアドレスでは既に予約があります。変更は事務局へご連絡ください');}
            $insert=$pdo->prepare('INSERT INTO corporate_bookings
              (campaign_id,slot_id,teacher_id,customer_id,customer_name,customer_email,customer_phone,employee_reference)
              VALUES(?,?,?,?,?,?,?,?)');
            $insert->execute([$campaign['id'],$slotId,$teacher,$customerId,$name,$email,$phone,$ref?:null]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE corporate_slots SET booked_id=? WHERE id=? AND booked_id IS NULL')->execute([$id,$slotId]);
            $pdo->commit();$_SESSION['last_corporate_booking_at']=time();
            bookingUnlock($pdo);
            // Reservation is committed first; a mail-provider outage must not cancel it.
            $confirmationSent=false;
            if(corporateEmailReady()){
                try{
                    $teacherName=corporateTeacherName($teacher);
                    $confirmationSent=corporateEmailBookingConfirmation(
                        $email,$name,(string)($teacherName['name']??'担当講師'),$date,$time,$id);
                }catch(Throwable $mailError){
                    error_log('[corporate] confirmation email could not be sent for booking #'.$id);
                }
            }
            jsonOut(['ok'=>true,'booking_id'=>$id,'date'=>$date,'time'=>$time,
                'confirmation_email_sent'=>$confirmationSent]);
        }catch(CorporateBookingConflict $e){
            if($pdo->inTransaction())$pdo->rollBack();
            bookingUnlock($pdo);
            fail($e->getMessage(),409);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();bookingUnlock($pdo);throw $e;}
    }
    if($action==='corporateInvoiceCsv' && $method==='GET'){
        requireAdmin();
        $campaign=corporateCampaign();
        if(!$campaign)fail('企業案件は未設定です',404);
        $month=trim((string)($_GET['month']??''));
        if($month!=='' && !preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/D',$month))
            fail('請求対象月はYYYY-MM形式で指定してください');
        $sql='SELECT b.id AS booking_id,s.starts_at,t.name AS teacher_name
            FROM corporate_bookings b
            JOIN corporate_slots s ON s.id=b.slot_id
            JOIN teachers t ON t.id=b.teacher_id
            WHERE b.campaign_id=? AND b.status="completed"';
        $args=[(int)$campaign['id']];
        if($month!==''){
            $sql.=' AND s.starts_at>=? AND s.starts_at<?';
            $begin=new DateTimeImmutable($month.'-01');
            $args[]=$begin->format('Y-m-d H:i:s');
            $args[]=$begin->modify('+1 month')->format('Y-m-d H:i:s');
        }
        $sql.=' ORDER BY s.starts_at,b.id';
        $q=db()->prepare($sql);$q->execute($args);
        $items=$q->fetchAll(PDO::FETCH_ASSOC);
        $unit=(int)$campaign['unit_price'];
        $out=fopen('php://temp','w+');
        fwrite($out,"\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($out,['予約ID','相談日','相談開始時刻','担当講師','実施件数','単価（円）','金額（円）']);
        foreach($items as $item){
            $teacher=(string)$item['teacher_name'];
            if(preg_match('/^[\s]*[=+\-@]/u',$teacher))$teacher="'".$teacher;
            fputcsv($out,[(int)$item['booking_id'],substr($item['starts_at'],0,10),
                substr($item['starts_at'],11,5),$teacher,1,$unit,$unit]);
        }
        fputcsv($out,['合計','','','',count($items),'',count($items)*$unit]);
        rewind($out);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tanseisha-invoice-'.$month.'.csv"');
        header('Cache-Control: no-store');
        fpassthru($out);fclose($out);exit;
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
           'counts'=>$counts,'completed_total_jpy'=>($counts['completed']??0)*(int)$campaign['unit_price']]);
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
          // Personal data intake must not be enabled before TLS and privacy review.
          if(setting('CORPORATE_TLS_APPROVED')!=='yes')
              fail('公開できません。SSL警告を解消してからサーバー設定でCORPORATE_TLS_APPROVED=yesを設定してください',409);
          if(setting('CORPORATE_PRIVACY_APPROVED')!=='yes')
              fail('公開できません。個人情報の利用目的・保存期間・アクセス権限を確認し、CORPORATE_PRIVACY_APPROVED=yesを設定してください',409);
          if(corporateEmailVerificationRequired()&&!corporateEmailReady())
              fail('公開できません。メール本人確認に必要な送信設定が未完了です',409);
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
          $q=$pdo->prepare('SELECT b.status,b.slot_id,s.ends_at FROM corporate_bookings b
               JOIN corporate_slots s ON s.id=b.slot_id WHERE b.id=? FOR UPDATE');
          $q->execute([$id]);$current=$q->fetch();
          if(!$current){$pdo->rollBack();fail('予約がありません',404);}
          if(in_array($status,['completed','no_show'],true)){
              $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
              if($current['ends_at']>$now){
                  $pdo->rollBack();fail('相談時間が終了するまでは実施済み・欠席に変更できません',409);
              }
          }
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
