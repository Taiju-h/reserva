<?php
declare(strict_types=1);
ini_set('display_errors', '0');
session_set_cookie_params(['httponly'=>true, 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite'=>'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
$root = dirname(__DIR__);
$env = [];
if (is_file($root.'/.env')) {
    foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line=trim($line);
        if ($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue;
        [$k,$v]=explode('=',$line,2);
        $env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
    }
}
date_default_timezone_set($env['APP_TIMEZONE'] ?? 'Asia/Tokyo');
function setting(string $key, string $fallback=''): string { global $env; return $env[$key] ?? $fallback; }
function jsonOut(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function fail(string $message,int $status=400): never { jsonOut(['error'=>$message],$status); }
function db(): PDO {
    static $pdo=null;
    if ($pdo) return $pdo;
    if (setting('DB_CONNECTION') !== 'mysql') fail('DB_CONNECTION=mysql を設定してください',503);
    if (!extension_loaded('pdo_mysql')) fail('サーバーのPDO MySQL拡張が必要です',503);
    $host=setting('DB_HOST','mysql');
    $port=(int)setting('DB_PORT','3306');
    $name=setting('DB_DATABASE','hanamaru');
    $user=setting('DB_USERNAME','hanamaru');
    $pass=setting('DB_PASSWORD');
    if ($host==='' || $port<1 || $port>65535 || $name==='' || $user==='' || $pass==='') fail('MySQL接続設定が不足しています',503);
    $dsn='mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4';
    $pdo=new PDO($dsn,$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5
    ]);
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}
function bookingLock(PDO $pdo): void {
    $q=$pdo->query("SELECT GET_LOCK('hanamaru-booking-writes',10)");
    if((int)$q->fetchColumn()!==1) fail('予約が混み合っています。再度お試しください',503);
}
function bookingUnlock(PDO $pdo): void {
    $pdo->query("SELECT RELEASE_LOCK('hanamaru-booking-writes')");
}
function prefs(): array { return db()->query('SELECT `key`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR); }
function menus(bool $onlyActive=true): array {
    return db()->query('SELECT * FROM menus'.($onlyActive?' WHERE active=1':'').' ORDER BY id DESC')->fetchAll();
}
function admin(): bool { return activeAdminSession(); }
function requireAdmin(): void { if (!admin()) fail('管理者ログインが必要です',401); }
function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function checkCsrf(): void { if (!hash_equals(csrf(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) fail('CSRFトークンが不正です',403); }
function body(): array { $v=json_decode(file_get_contents('php://input'),true); return is_array($v)?$v:[]; }
function dateValid(string $d): bool { $t=DateTimeImmutable::createFromFormat('!Y-m-d',$d); return $t!==false && $t->format('Y-m-d')===$d; }
function availability(int $menuId,string $date): array {
    if(!dateValid($date)) fail('日付が不正です');
    $menu=db()->prepare('SELECT * FROM menus WHERE id=? AND active=1');$menu->execute([$menuId]);$m=$menu->fetch();
    if(!$m) fail('メニューがありません',404);
    $s=prefs();$tz=new DateTimeZone(date_default_timezone_get());
    $day=new DateTimeImmutable($date.' 00:00:00',$tz);
    $closed=array_filter(explode(',',$s['closed_weekdays']??''),static fn($v)=>$v!=='');
    if(in_array($date,array_filter(array_map('trim',explode(',', $s['closed_dates']??''))),true)) return [];
    $today=new DateTimeImmutable('today',$tz);
    $leadMinutes=(int)($s['lead_minutes']??60);
    $maxDays=(int)($s['max_advance_days']??90);
    if($day<$today || $day>$today->modify('+'.$maxDays.' days')) return [];
    if(in_array($day->format('w'),$closed,true)) return [];
    $opening=$s['open_time']??'07:00';$closing=$s['close_time']??'22:00';
    if(!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$opening) || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$closing)) fail('営業時間設定が不正です',500);
    $teacherId=isset($m['teacher_id'])&&$m['teacher_id']!==null?(int)$m['teacher_id']:null;
    if($teacherId!==null){$q=db()->prepare('SELECT active FROM teachers WHERE id=?');$q->execute([$teacherId]);if((int)$q->fetchColumn()!==1)return [];}
    $start=new DateTimeImmutable($date.' '.$opening,$tz);$end=new DateTimeImmutable($date.' '.$closing,$tz);
    if($end<=$start) return [];
    $step=max(5,min(120,(int)($s['slot_minutes']??30)));
    $duration=(int)$m['duration'];$now=new DateTimeImmutable('now',$tz);
    stripeDb();
    $rows=db()->prepare('SELECT b.starts_at, b.ends_at FROM bookings b
        JOIN menus bm ON bm.id=b.menu_id
        LEFT JOIN stripe_payments sp ON sp.booking_id=b.id
        WHERE (b.status IN (\'confirmed\',\'pending_review\') OR (b.status=\'pending_payment\' AND sp.expires_at > ?))
        AND b.starts_at < ? AND b.ends_at > ?
        AND (? IS NULL OR bm.teacher_id IS NULL OR bm.teacher_id=?)');
    $rows->execute([(new DateTimeImmutable())->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s'),$teacherId,$teacherId]);
    $busy=$rows->fetchAll();
    // Prevent a paid/general menu from overlapping a confirmed company booking
    // for the same teacher. The corporate schema is optional until migrated.
    if($teacherId!==null){
        static $corporateSlotsReady=null;
        if($corporateSlotsReady===null){
            $check=db()->query("SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='corporate_slots'");
            $corporateSlotsReady=(int)$check->fetchColumn()===1;
        }
        if($corporateSlotsReady){
            $corporateBusy=db()->prepare('SELECT starts_at,ends_at FROM corporate_slots
              WHERE teacher_id=? AND booked_id IS NOT NULL AND starts_at<? AND ends_at>?');
            $corporateBusy->execute([$teacherId,$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s')]);
            $busy=array_merge($busy,$corporateBusy->fetchAll());
        }
    }
    $out=[];
    for($t=$start;$t->modify('+'.$duration.' minutes')<=$end;$t=$t->modify('+'.$step.' minutes')){
        if($t<$now->modify('+'.$leadMinutes.' minutes')) continue;
        $tEnd=$t->modify('+'.$duration.' minutes');
        if(!scheduleAllows($menuId,$teacherId,$date,$t->format('H:i'),$tEnd->format('H:i')))continue;
        $free=true;
        foreach($busy as $b){if($t->format('Y-m-d H:i:s')<$b['ends_at'] && $tEnd->format('Y-m-d H:i:s')>$b['starts_at']){$free=false;break;}}
        if($free) $out[]=$t->format('H:i');
    }
    return $out;
}
require_once $root.'/app/admin-users.php';
require_once $root.'/app/scheduling.php';
require_once $root.'/app/teacher-profiles.php';
require_once $root.'/app/teacher-source-sync.php';
require_once $root.'/app/stripe.php';
require_once $root.'/app/media.php';
require_once $root.'/app/favicons.php';
require_once $root.'/app/corporate-bookings.php';
require_once $root.'/app/corporate-email.php';
require_once $root.'/app/instructor-portal.php';
$action=(string)($_GET['action']??'');
$method=$_SERVER['REQUEST_METHOD']??'GET';
try {
    scheduleRoute($action,$method);
    teacherProfileRoute($action,$method);
    syncMemberRoute($action,$method);
    faviconRoute($action,$method);
    corporateRoute($action,$method);
    corporateEmailRoute($action,$method);
    instructorRoute($action,$method);
    if($action==='media' && $method==='GET') mediaServe();
    if($action==='mediaUpload' && $method==='POST') mediaUpload();
    if($action==='mediaDelete' && $method==='POST') mediaDelete();
    if($action==='inquiry' && $method==='POST') inquiryCreate();
    if($action==='waitlist' && $method==='POST') waitlistCreate();
    if($action==='extraAdminData' && $method==='GET') extraAdminData();
    if($action==='stripeWebhook' && $method==='POST') stripeWebhook();
    if($action==='createCheckout' && $method==='POST') stripeRequestBooking();
    if($action==='paymentStatus' && $method==='GET') stripeStatus();
    if($action==='config' && $method==='GET') jsonOut(['settings'=>prefs(),'menus'=>menus(),'admin'=>admin(),'master'=>isMasterAdmin(),'csrf'=>csrf(),'stripe_test_enabled'=>stripeEnabled(),'stripe_mode'=>stripeEnabled()?stripeMode():'disabled','payment_method'=>'card','media'=>mediaList()]);
    if($action==='availability' && $method==='GET') jsonOut(['times'=>availability((int)($_GET['menu_id']??0),(string)($_GET['date']??''))]);
    if($action==='login' && $method==='POST'){
        checkCsrf();$data=body();$hash=setting('ADMIN_PASSWORD_HASH');
        $attempts=(int)($_SESSION['login_attempts']??0);
        $last=(int)($_SESSION['last_login_failure']??0);
        if($attempts>=5 && time()-$last<900) fail('ログイン試行回数が上限です。15分後に再試行してください',429);
        $password=(string)($data['password']??'');
        $login=trim((string)($data['login_id']??''));
        $ok=false;$user=null;
        if($login===''||strtolower($login)==='master'){
            if($hash!=='' && password_verify($password,$hash)){
                $ok=true;$login='master';
            }
        }else{
            if(preg_match('/^[A-Za-z0-9_.-]{3,50}$/D',$login)){
                $q=db()->prepare('SELECT id,login_id,display_name,password_hash,session_version FROM admin_users WHERE login_id=? AND active=1 LIMIT 1');
                $q->execute([$login]);$user=$q->fetch(PDO::FETCH_ASSOC);
                if($user && password_verify($password,$user['password_hash']))$ok=true;
            }
        }
        if(!$ok){
            $_SESSION['login_attempts']=($last && time()-$last>=900)?1:$attempts+1;
            $_SESSION['last_login_failure']=time();
            fail('認証に失敗しました',401);
        }
        session_regenerate_id(true);
        unset($_SESSION['login_attempts'],$_SESSION['last_login_failure']);
        $_SESSION['admin']=true;
        $_SESSION['admin_role']=$login==='master'?'master':'staff';
        if($user){
            $_SESSION['admin_user_id']=(int)$user['id'];
            $_SESSION['admin_session_version']=(int)$user['session_version'];
            $q=db()->prepare('UPDATE admin_users SET last_login_at=NOW() WHERE id=?');
            $q->execute([(int)$user['id']]);
        }else{
            unset($_SESSION['admin_user_id'],$_SESSION['admin_session_version']);
        }
        jsonOut(['ok'=>true,'csrf'=>csrf(),'role'=>$_SESSION['admin_role']]);
    }
    if($action==='logout' && $method==='POST'){checkCsrf();$_SESSION=[];session_destroy();jsonOut(['ok'=>true]);}
    if($action==='book' && $method==='POST'){
        if(stripeEnabled()) fail('有料メニューはカード決済画面からお申し込みください',409);
        if(time()-(int)($_SESSION['last_booking_request_at']??0)<60) fail('連続申込みは1分ほど空けてください',429);
        checkCsrf();$v=body();
        $id=(int)($v['menu_id']??0);$date=(string)($v['date']??'');$time=(string)($v['time']??'');
        $name=trim((string)($v['name']??''));$email=trim((string)($v['email']??''));$phone=trim((string)($v['phone']??''));$note=trim((string)($v['note']??''));
        if(mb_strlen($name)<1 || mb_strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || strlen($phone)>40 || mb_strlen($note)>1000) fail('入力内容をご確認ください');
        if(!preg_match('/^\d\d:\d\d$/',$time)) fail('時間が不正です');
        $pdo=db();bookingLock($pdo);$pdo->beginTransaction();
        try {
            if(!in_array($time,availability($id,$date),true)) { $pdo->rollBack();fail('この時間は満席です。別の時間を選択してください',409); }
            $stmt=$pdo->prepare('SELECT duration,price FROM menus WHERE id=? AND active=1');$stmt->execute([$id]);$menu=$stmt->fetch();
            if(!$menu) {$pdo->rollBack();fail('メニューがありません',404);}
            $duration=(int)$menu['duration'];
            if((int)$menu['price']>0){$pdo->rollBack();fail('オンライン決済を準備中です。現在、有料メニューの申込みは受け付けられません',503);}
            $begin=new DateTimeImmutable($date.' '.$time);
            $insert=$pdo->prepare('INSERT INTO bookings(menu_id,starts_at,ends_at,customer_name,email,phone,note,status) VALUES(?,?,?,?,?,?,?,?)');
            $insert->execute([$id,$begin->format('Y-m-d H:i:s'),$begin->modify('+'.$duration.' minutes')->format('Y-m-d H:i:s'),$name,$email,$phone,$note,'confirmed']);
            $bookingId=(int)$pdo->lastInsertId();$pdo->commit();
            $_SESSION['last_booking_request_at']=time();
            jsonOut(['ok'=>true,'booking_id'=>$bookingId,'status'=>'confirmed','payment_method'=>'free','payment_received'=>false]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally {bookingUnlock($pdo);}
    }
    if($action==='adminBook' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();
        $menuId=(int)($v['menu_id']??0);$date=(string)($v['date']??'');
        $time=(string)($v['time']??'');$name=trim((string)($v['name']??''));
        $email=trim((string)($v['email']??''));
        $phone=trim((string)($v['phone']??''));
        $note=trim((string)($v['note']??''));
        if(!dateValid($date)||!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$time)
            ||mb_strlen($name)<1||mb_strlen($name)>100||!filter_var($email,FILTER_VALIDATE_EMAIL)
            ||strlen($email)>254||strlen($phone)>40||mb_strlen($note)>1000) fail('予約情報が不正です');
        $pdo=db();bookingLock($pdo);$pdo->beginTransaction();
        try{
            if(!in_array($time,availability($menuId,$date),true)){
                $pdo->rollBack();fail('その時間は予約できません',409);
            }
            $q=$pdo->prepare('SELECT duration FROM menus WHERE id=? AND active=1');
            $q->execute([$menuId]);$duration=(int)$q->fetchColumn();
            $from=new DateTimeImmutable($date.' '.$time);
            $to=$from->modify('+'.$duration.' minutes');
            $q=$pdo->prepare('INSERT INTO bookings(menu_id,starts_at,ends_at,customer_name,email,phone,note,status) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$menuId,$from->format('Y-m-d H:i:s'),$to->format('Y-m-d H:i:s'),$name,$email,$phone,$note,'confirmed']);
            $id=(int)$pdo->lastInsertId();$pdo->commit();jsonOut(['ok'=>true,'booking_id'=>$id]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally {bookingUnlock($pdo);}
    }
    adminUsersRoute($action,$method);
    if($action==='adminData' && $method==='GET'){
        requireAdmin();$q=db()->query('SELECT b.*,m.name AS menu_name FROM bookings b JOIN menus m ON m.id=b.menu_id ORDER BY b.starts_at DESC LIMIT 500');
        jsonOut(['menus'=>menus(false),'settings'=>prefs(),'bookings'=>$q->fetchAll(),'master'=>isMasterAdmin()]);
    }
    require_once $root.'/app/operations.php';
    if($action==='importResevaMenus' && $method==='POST'){
        requireAdmin();checkCsrf();
        // Eight reference menus; specified prices JPY 5,500 / 7,000 / 6,500.
        // Names and known durations come from the supplied RESERVA screenshot.
        $rows=[
            ['初めての転職コース',40,5500],
            ['仕事もやもや相談',40,5500],
            ['学生就活コース',30,5500],
            ['女性のキャリア相談コース',40,5500],
            ['独立・経営相談',50,5500],
            ['上田晶美に相談',50,7000],
            ['ビジネス英語力チェック',50,5500],
            ['マネー相談',50,6500] // Duration provisional; confirm with operator.
        ];
        $db=db();$db->beginTransaction();$added=0;$updated=0;
        try {
            $exists=$db->prepare('SELECT id FROM menus WHERE name=? LIMIT 1');
            $create=$db->prepare('INSERT INTO menus(name,description,duration,price,active) VALUES(?,?,?,?,1)');
            $enable=$db->prepare('UPDATE menus SET price=?, active=1 WHERE id=?');
            foreach($rows as [$name,$minutes,$price]){
                $exists->execute([$name]);$id=$exists->fetchColumn();
                if($id!==false){
                    // Preserve existing descriptions, durations and referenced menu IDs.
                    $enable->execute([$price,$id]);$updated++;
                }else{
                    $desc=$minutes.'分の個別相談';
                    if($name==='マネー相談')$desc.='（所要時間は仮設定）';
                    $create->execute([$name,$desc,$minutes,$price]);$added++;
                }
            }
            $db->commit();
        } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
        jsonOut(['ok'=>true,'added'=>$added,'updated'=>$updated]);
    }
    if($action==='saveMenu' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();
        $id=(int)($v['id']??0);$name=trim((string)($v['name']??''));$desc=trim((string)($v['description']??''));
        $duration=(int)($v['duration']??0);$price=(int)($v['price']??-1);$active=!empty($v['active'])?1:0;
        if(!$name || mb_strlen($name)>100 || mb_strlen($desc)>2000 || $duration<5 || $duration>600 || $price<0 || $price>10000000)fail('メニュー設定が不正です');
        if($id){$stmt=db()->prepare('UPDATE menus SET name=?,description=?,duration=?,price=?,active=? WHERE id=?');$stmt->execute([$name,$desc,$duration,$price,$active,$id]);}
        else {$stmt=db()->prepare('INSERT INTO menus(name,description,duration,price,active) VALUES(?,?,?,?,?)');$stmt->execute([$name,$desc,$duration,$price,$active]);}
        jsonOut(['ok'=>true]);
    }
    if($action==='saveSettings' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();
        foreach(['site_name','open_time','close_time','slot_minutes','closed_weekdays','closed_dates','lead_minutes','max_advance_days'] as $key){
            if(!array_key_exists($key,$v))continue;
            $val=trim((string)$v[$key]);if(strlen($val)>120)fail('設定値が長すぎます');
            if(in_array($key,['open_time','close_time'],true) && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$val))fail('営業時間の形式が不正です');
            if($key==='slot_minutes' && (!ctype_digit($val)||(int)$val<5||(int)$val>120))fail('時間間隔が不正です');
            if($key==='closed_weekdays' && !preg_match('/^[0-6](,[0-6])*$/',$val) && $val!=='')fail('休業日が不正です');
            if($key==='closed_dates' && $val!=='' && (strlen($val)>3000 || !preg_match('/^\\d{4}-\\d{2}-\\d{2}(,\\d{4}-\\d{2}-\\d{2})*$/',$val))) fail('休業日の形式が不正です');
            if($key==='lead_minutes' && (!ctype_digit($val)|| (int)$val>10080)) fail('受付締切時間が不正です');
            if($key==='max_advance_days' && (!ctype_digit($val) || (int)$val<1 || (int)$val>365)) fail('予約受付期間が不正です');
            $stmt=db()->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');$stmt->execute([$key,$val]);
        }
        jsonOut(['ok'=>true]);
    }
    if($action==='setStatus' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();$status=(string)($v['status']??'');$id=(int)($v['id']??0);
        if(!in_array($status,['confirmed','cancelled'],true)||$id<1)fail('不正な更新です');
        $pdo=db();bookingLock($pdo);$pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare('SELECT * FROM bookings WHERE id=?');$stmt->execute([$id]);$b=$stmt->fetch();
            if(!$b){$pdo->rollBack();fail('予約がありません',404);}
            if($status==='confirmed'){
                $check=$pdo->prepare('SELECT state FROM stripe_payments WHERE booking_id=?');
                $check->execute([$id]);$paymentState=$check->fetchColumn();
                if($paymentState!==false && $paymentState!=='paid'){ $pdo->rollBack();fail('決済未完了のため確定できません',409); }
                $stmt=$pdo->prepare('SELECT count(*) FROM bookings b LEFT JOIN stripe_payments p ON p.booking_id=b.id WHERE b.id<>? AND (b.status IN (\'confirmed\',\'pending_review\') OR (b.status=\'pending_payment\' AND p.expires_at>?)) AND b.starts_at<? AND b.ends_at>?');
                $stmt->execute([$id,(new DateTimeImmutable())->format('Y-m-d H:i:s'),$b['ends_at'],$b['starts_at']]);
                if((int)$stmt->fetchColumn()>0){$pdo->rollBack();fail('別の予約と重複します',409);}
            }
            $stmt=$pdo->prepare('UPDATE bookings SET status=? WHERE id=?');$stmt->execute([$status,$id]);
            $pdo->commit();jsonOut(['ok'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally {bookingUnlock($pdo);}
    }
    if($action!=='') fail('APIが見つかりません',404);
}catch(Throwable $e){error_log('[reserva] '.$e->getMessage());fail('処理に失敗しました。管理者にお問い合わせください',500);}
if(in_array(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),['/instructor','/instructor/'],true)){
    require $root.'/public/instructor.php';
    exit;
}
if(in_array(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),['/tanseisha','/tanseisha/'],true)){
    require $root.'/public/corporate.php';
    exit;
}
?><!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>はなキャリ | ハナマルキャリアお仕事相談予約受付</title><link id="siteFavicon" rel="icon" href="/?action=favicon&amp;kind=<?=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)==='/admin'?'admin':'public'?>&amp;v=20261123-1"><link rel="stylesheet" href="/assets/hanacari.css?v=20261126-corporate-roster"><style>
:root{font-family:system-ui,-apple-system,"Noto Sans JP",sans-serif;color:#26323e;background:#f6f7f9}*{box-sizing:border-box}body{margin:0}header{background:#fff;border-bottom:1px solid #e5e9ef;padding:19px max(20px,calc((100vw - 1040px)/2));display:flex;align-items:center;justify-content:space-between}header strong{font-size:22px;color:#1e6663}a{color:#1f7771;text-decoration:none}main{max-width:1040px;margin:32px auto;padding:0 16px}.hero{background:linear-gradient(120deg,#f3faf7,#e9f2f8);padding:34px;border-radius:14px;margin-bottom:25px}.hero h1{margin:0 0 8px;font-size:26px}section.panel{background:#fff;border:1px solid #e5e9ef;border-radius:12px;padding:24px;margin-bottom:18px;box-shadow:0 2px 12px #22334408}h2{font-size:19px;margin:0 0 18px}label{display:block;font-size:14px;font-weight:600;margin:13px 0 6px}input,select,textarea,button{font:inherit}input,select,textarea{width:100%;border:1px solid #ced6de;border-radius:8px;padding:11px;background:#fff}textarea{min-height:88px}button{border:0;border-radius:8px;padding:12px 18px;background:#257b76;color:white;font-weight:700;cursor:pointer}button:disabled{opacity:.45;cursor:not-allowed}button.secondary{color:#335;background:#ecf0f2}button.danger{background:#ae5050}.menus{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.menu{border:1px solid #d7e0e3;background:#fff;padding:18px;text-align:left;border-radius:10px;cursor:pointer}.menu.selected{border:2px solid #257b76;background:#f0f9f7}.menu b{display:block;margin-bottom:6px}.menu small{display:block;margin-top:8px;color:#64717d}.times{display:grid;grid-template-columns:repeat(auto-fill,minmax(83px,1fr));gap:8px}.times button{background:#fff;color:#257b76;border:1px solid #91b9b6;padding:10px}.times button.chosen{background:#257b76;color:white}.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.muted{color:#687686}.hidden{display:none!important}.notice{background:#eff8f3;padding:16px;border-radius:8px;margin:16px 0}.error{background:#fff0ee;color:#a43626;padding:12px;border-radius:8px;margin:12px 0}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px 7px;text-align:left;border-bottom:1px solid #e8edf1}th{background:#f7f9fa}.scroll{overflow:auto}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}nav{display:flex;gap:15px}footer{text-align:center;color:#71808b;padding:35px}@media(max-width:620px){.row{grid-template-columns:1fr}.hero{padding:22px}section.panel{padding:17px}header{padding:16px}.hero h1{font-size:22px}}
</style></head><body>
<header><strong id="brand">はなキャリ <span class="flower-mark">✿</span></strong><span class="header-subtitle">はなキャリ - ハナマルキャリアお仕事相談予約受付</span><nav><a href="/">予約</a><a href="#contact">お問い合わせ</a><a href="/admin">ログイン</a></nav></header>
<main id="publicView"><div class="hero" id="siteHero"><p class="eyebrow">HANAMARU CAREER</p><h1 id="siteTitle">はなキャリ</h1><p>ハナマルキャリアお仕事相談予約受付</p></div><section class="corporate-entry-banner" aria-labelledby="corporateEntryHeading">
  <div><strong id="corporateEntryHeading">丹青社専用・セカンドキャリア相談</strong>
  <p>会社からご案内された方はこちらから。先生を選んで、空いている時間を予約できます。利用者の決済は不要です。</p></div>
  <a class="corporate-entry-link" href="/tanseisha">丹青社専用の予約へ</a>
</section><div class="reserva-public-grid"><div class="business-info"><div><strong>所在地</strong><p>東京都渋谷区渋谷2-10-16 池川ビル4階</p></div><div><strong>営業時間</strong><p>月曜〜日曜　07:00〜22:00</p></div></div>
<div class="reserva-menu-side"><section class="panel" id="menuSection"><h2>予約メニュー</h2><p class="muted">ご希望の相談メニューをお選びください。</p><div class="menus" id="menus"></div></section></div></div>
<section class="career-features" aria-label="はなキャリの特徴">
  <div class="feature-intro"><h2>はなキャリのキャリア相談</h2><p>起業・経営・これからの働き方まで、一人ひとりの状況に合わせてご相談いただけます。</p></div>
  <div class="feature-list">
    <div class="feature-item"><span class="feature-icon" aria-hidden="true">♧</span><strong>一人ひとりに寄り添う</strong><p>マンツーマンの個別相談</p></div>
    <div class="feature-item"><span class="feature-icon" aria-hidden="true">✧</span><strong>仕事とキャリアの相談</strong><p>起業・経営・働き方の悩み</p></div>
    <div class="feature-item"><span class="feature-icon" aria-hidden="true">◷</span><strong>予約しやすい時間帯</strong><p>7:00〜22:00の予約枠</p></div>
  </div>
</section>
<section class="career-guidance" aria-label="ご利用案内">
  <div class="guidance-card"><h2>相談の流れ</h2><ol><li>相談メニューを選択</li><li>希望日時とお客様情報を入力</li><li>予約の確定後にご相談</li></ol><p>有料メニューは決済設定が必要です。ご案内内容は予約画面で確認してください。</p><a href="#menuSection">予約メニューを見る</a></div>
  <div class="guidance-card"><h2>ご相談内容</h2><ul><li>起業・独立に向けた準備</li><li>事業運営や経営についての相談</li><li>仕事・キャリアの方向性</li><li>働き方に関する悩み</li></ul></div>
</section>
<section class="career-story" aria-label="キャリアを考える時間">
 <div class="career-story-text"><span class="story-kicker">CAREER &amp; WORK</span><h2>これからの働き方を、<br>一緒に考える。</h2><p>起業への第一歩、今の仕事の悩み、これからのキャリア。考えを整理するところからご相談いただけます。</p><a href="#menuSection">相談メニューへ戻る →</a></div>
 <div class="career-story-photo" role="img" aria-label="仕事について話し合う人々のイメージ写真"></div>
</section>
<section class="panel hidden" id="reservationStep"><h2>ご予約日時</h2><div class="reservation-date-row"><div><label for="date">希望日</label><input id="date" type="date"></div><div class="reservation-date-display" aria-live="polite"><span>選択中の日程</span><strong id="selectedDateDisplay">日付を選択してください</strong></div></div><div class="reservation-time-heading"><p class="muted">ご予約可能な時間</p><div class="time-filters" id="timeFilters" aria-label="時間帯を選択"><button type="button" class="time-filter active" data-period="morning">午前</button><button type="button" class="time-filter" data-period="afternoon">午後</button><button type="button" class="time-filter" data-period="evening">夜間</button></div></div><div class="times" id="times">先にメニューと日付を選択してください。</div><div class="chosen-slot-summary" id="chosenSlotSummary" aria-live="polite" hidden><span>ご予約日時</span><strong id="chosenSlotDisplay"></strong></div></section>
<section class="panel hidden" id="customerStep"><h2>お客様情報</h2><form id="bookingForm"><div class="row"><div><label>お名前 *</label><input name="name" maxlength="100" required autocomplete="name"></div><div><label>メールアドレス *</label><input name="email" type="email" maxlength="254" required autocomplete="email"></div></div><label>電話番号</label><input name="phone" maxlength="40" autocomplete="tel"><label>備考</label><textarea name="note" maxlength="1000"></textarea><p id="selection" class="muted"></p><div id="bookingError"></div><button id="bookBtn" disabled>予約を確定する</button></form></section><section class="panel extras"><h2>キャンセル待ち登録</h2><p class="muted">満席時に希望日時を登録できます。空きが出た場合の自動通知は準備中です。</p>
<form id="waitlistForm"><label>メニュー</label><select name="menu_id" required id="waitlistMenu"></select><label>希望日（任意）</label><input name="date" type="date"><label>お名前（任意）</label><input name="name" maxlength="100"><label>メールアドレス</label><input name="email" type="email" maxlength="254" required><button type="submit">キャンセル待ち登録</button><p id="waitlistResult"></p></form><h2>利用可能な月額プラン</h2><p class="muted">現在利用可能なものはありません。</p><h2>利用可能な回数券</h2><p class="muted">現在利用可能なものはありません。</p></section><section class="panel" id="contact"><h2>お問い合わせ</h2><form id="inquiryForm"><label>お名前</label><input name="name" maxlength="100" required><label>メールアドレス</label><input name="email" type="email" maxlength="254" required><label>お問い合わせ内容</label><textarea name="message" minlength="5" maxlength="3000" required></textarea><button type="submit">お問い合わせを送信</button><p id="inquiryResult"></p></form></section><div class="legal-links"><a href="https://reserva.be/hanamarusoudan/terms" target="_blank" rel="noopener noreferrer">現行サービスの利用規約</a><a href="https://reserva.be/hanamarusoudan/privacy" target="_blank" rel="noopener noreferrer">現行サービスのプライバシーポリシー</a><a href="https://reserva.be/hanamarusoudan/tokushohou" target="_blank" rel="noopener noreferrer">現行サービスの特商法表示</a></div></main>
<main id="adminView" class="hidden"><div class="hero"><h1>予約管理</h1><p>予約・メニュー・営業時間を管理します。</p></div>
<section id="loginPanel" class="panel"><h2>管理者ログイン</h2><form id="loginForm"><label for="adminLoginId">ログインID（マスターは空欄でも可）</label><input id="adminLoginId" name="login_id" autocomplete="username" placeholder="master または登録したID"><label for="password">パスワード</label><input type="password" id="password" autocomplete="current-password" required><p id="loginError"></p><button>ログイン</button></form></section>
<div id="dashboard" class="hidden">
<div class="hana-admin-layout">
<aside class="hana-admin-sidebar" aria-label="管理メニュー">
<div class="hana-admin-sidebar-title">管理メニュー</div>
<button type="button" class="hana-admin-nav active" data-admin-page="bookings">予約一覧</button>
<button type="button" class="hana-admin-nav" data-admin-page="manual">手動予約登録</button>
<button type="button" class="hana-admin-nav" data-admin-page="calendar">予約カレンダー・顧客</button>
<button type="button" class="hana-admin-nav" data-admin-page="teachers">先生一覧・登録</button>
<button type="button" class="hana-admin-nav" data-admin-page="qualifications">資格マスター</button>
<button type="button" class="hana-admin-nav" data-admin-page="scheduling">受付条件・先生の予定</button>
<button type="button" class="hana-admin-nav" data-admin-page="corporate">丹青社・企業向け予約</button>
<button type="button" class="hana-admin-nav" data-admin-page="menus">予約メニュー設定</button>
<button type="button" class="hana-admin-nav" data-admin-page="media">写真・画像</button>
<button type="button" class="hana-admin-nav" data-admin-page="inquiries">問い合わせ・キャンセル待ち</button>
<button type="button" class="hana-admin-nav" data-admin-page="settings">営業時間・サイト設定</button>
<button type="button" class="hana-admin-nav" data-admin-page="users" id="adminUsersNav" hidden>管理者アカウント</button>
<a class="hana-admin-site-link" href="/" target="_blank" rel="noopener">公開サイトを確認 ↗</a>
</aside>
<div class="hana-admin-content">
<section class="panel admin-pane" data-admin-section="bookings"><div class="actions"><h2 style="margin-right:auto">予約一覧（最新500件）</h2><button class="secondary" id="refresh">更新</button><button class="secondary" id="logout">ログアウト</button></div><div class="scroll"><table><thead><tr><th>ID</th><th>日時</th><th>メニュー</th><th>お名前</th><th>連絡先</th><th>状態</th><th>操作</th></tr></thead><tbody id="bookings"></tbody></table></div></section>
<section class="panel admin-pane" data-admin-section="manual"><h2>管理者による予約登録</h2><form id="adminBookingForm">
<div class="row"><div><label>メニュー</label><select name="menu_id" id="adminBookMenu" required></select></div><div><label>日付</label><input type="date" name="date" required></div></div>
<div class="row"><div><label>時間（例 10:00）</label><input type="time" name="time" required></div><div><label>お名前</label><input name="name" maxlength="100" required></div></div>
<div class="row"><div><label>メール</label><input type="email" name="email" maxlength="254" required></div><div><label>電話番号</label><input name="phone" maxlength="40"></div></div>
<label>備考</label><textarea name="note" maxlength="1000"></textarea>
<button type="submit">予約を登録</button><p id="adminBookResult"></p></form></section>
<section class="panel admin-pane" data-admin-section="calendar"><h2>予約カレンダー・顧客検索</h2><div id="operations"></div></section><section class="panel admin-pane" data-admin-section="teachers">
<h2>先生一覧・登録</h2>
<p class="muted">先生の登録・検索・停止・再開を行います。登録済みの先生は「受付条件・先生の予定」でメニューに割り当てられます。</p>
<div class="actions" style="justify-content:space-between;gap:12px;flex-wrap:wrap">
  <strong id="teacherDirectoryCount">登録済みの先生を確認中</strong>
  <button type="button" id="bulkImportTeachers" class="secondary">掲載の42名をまとめて登録</button>
  <button type="button" id="migrateTeacherProfiles" class="secondary" hidden>プロフィール・資格をDBに移行</button>
  <button type="button" id="syncTeacherDetails" class="secondary" hidden>全先生の経歴・資格・写真を取得</button>
  <button type="button" id="syncMissingTeacherDetails" class="secondary" hidden>未取得の先生だけ再補完</button>
  <button type="button" id="cancelTeacherSync" class="secondary" hidden>取得を中止</button>
</div>
<p id="teacherDirectoryResult" role="status"></p>
<div id="teacherSyncProgress" role="status" aria-live="polite"></div>
<h3>先生を個別に追加</h3>
<form id="teacherDirectoryForm" class="hana-inline-form">
  <input type="text" name="name" maxlength="100" placeholder="先生のお名前" required>
  <button type="submit">先生を登録</button>
</form>
<h3>登録済みの先生</h3>
<label for="teacherDirectorySearch">名前で検索</label>
<input type="search" id="teacherDirectorySearch" placeholder="名前の一部を入力" style="max-width:400px">
<div class="scroll" style="margin-top:14px">
  <table><thead><tr><th>ID</th><th>先生の名前</th><th>状態</th><th>操作</th></tr></thead>
  <tbody id="teacherDirectoryRows"><tr><td colspan="4">読み込み中…</td></tr></tbody></table>
</div>
<section class="hana-teacher-details" id="teacherProfileArea" aria-live="polite"><p class="muted">先生の「プロフィール」ボタンを押すと写真・経歴・資格を確認できます。</p></section>
</section>
<section class="panel admin-pane" data-admin-section="qualifications">
<h2>資格マスター・先生との紐付け</h2>
<p class="muted">資格の名称・分類を登録・編集できます。先生への資格の登録は「先生一覧・登録」→「プロフィール・写真・資格」から行ってください。</p>
<section id="qualificationMasterArea" class="hana-qualification-master"><p>資格マスターを読み込んでいます…</p></section>
</section>
<section class="panel admin-pane" data-admin-section="corporate">
<h2>丹青社・企業向け相談</h2>
<p class="muted">会社負担・決済なしの専用案件です。講師10名の選択、講師別の空き枠、実施件数の請求集計を管理します。一般向けの有料予約とは別です。</p>
<div id="corporateAdminRoot">読み込み中…</div>
<div id="instructorAdminRoot"></div>
<div id="corporateRosterAdmin"></div>
</section>
<section class="panel admin-pane" data-admin-section="scheduling">
<h2>受付条件・先生の予定</h2>
<p class="muted">店舗全体 → 先生 → メニューの順に受付条件を管理します。どこかの階層で「受付禁止」に該当すると予約不可になります。受付許可は、同じ階層の該当日に時間を限定します。</p>
<div id="schedulingAdmin"></div>
</section>
<section class="panel admin-pane" data-admin-section="menus"><h2>予約メニュー設定</h2><p class="muted">RESERVA参考画面の8メニューを登録・公開します。上田晶美に相談は7,000円、マネー相談は6,500円、その他は5,500円です。同名メニューも指定価格に更新します。既存予約は変更しません。マネー相談の時間は仮に50分です。</p><button type="button" class="secondary" id="importResevaMenus">RESERVAの8メニューを指定価格で登録・公開</button><div id="menuEditor"></div><h3>メニュー追加・編集</h3><form id="menuForm"><input type="hidden" name="id"><label>メニュー名</label><input name="name" required maxlength="100"><label>説明</label><textarea name="description" maxlength="2000"></textarea><div class="row"><div><label>所要時間（分）</label><input name="duration" type="number" min="5" max="600" value="50" required></div><div><label>価格（円）</label><input name="price" type="number" min="0" value="5500" required></div></div><label><input style="width:auto" type="checkbox" name="active" checked> 公開する</label><button>保存</button> <button type="button" class="secondary" id="resetMenu">新規入力</button></form></section>
<section class="panel admin-pane" data-admin-section="media"><h2>写真・画像管理</h2>
<p class="muted">トップ画像1枚、メニューごとに最大20枚。JPEG・PNG・WebP、各5MBまで。</p>
<form id="mediaUploadForm" enctype="multipart/form-data">
<label>配置先</label><select name="kind" id="mediaKind"><option value="hero">サイトトップ画像</option><option value="menu">メニュー画像</option></select>
<label>対象メニュー</label><select name="menu_id" id="mediaMenu"></select>
<label>画像ファイル</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
<button type="submit">画像を登録</button><p id="mediaError"></p></form>
<div id="mediaGallery" class="menus"></div></section>
<section class="panel admin-pane" data-admin-section="inquiries"><h2>お問い合わせ・キャンセル待ち管理</h2><div id="extraAdmin"></div></section>
<section class="panel admin-pane" data-admin-section="settings"><h2>サイト・営業時間設定</h2><form id="settingsForm"><label>サイト名</label><input name="site_name" required><div class="row"><div><label>開始時間</label><input type="time" name="open_time" required></div><div><label>終了時間</label><input type="time" name="close_time" required></div></div><label>予約枠間隔（分）</label><input type="number" min="5" max="120" name="slot_minutes" required><label>休業曜日（0=日曜〜6=土曜。例: 0,6）</label><input name="closed_weekdays" placeholder="例: 0,6">
<label>臨時休業日（YYYY-MM-DD、カンマ区切り）</label><input name="closed_dates" placeholder="2026-12-31,2027-01-01">
<div class="row"><div><label>予約締切（何分前）</label><input type="number" name="lead_minutes" min="0" max="10080" value="60"></div>
<div><label>何日先まで予約を受け付けるか</label><input type="number" name="max_advance_days" min="1" max="365" value="90"></div></div>
<p id="settingsError"></p><button>営業時間を保存</button></form>
<div id="faviconSettings" class="favicon-settings">
<h3>ブラウザのアイコン（Favicon）</h3>
<p class="muted">公開サイトと管理画面で別々のアイコンを設定できます。初期状態は公開用がオレンジの花、管理用が濃緑の歯車です。</p>
<div class="favicon-control-row">
<div><strong>公開サイト用</strong><div class="favicon-preview"><img id="faviconPreviewPublic" alt="公開サイトFavicon" src="/?action=favicon&amp;kind=public&amp;v=20261123-1"></div><form class="faviconUpload" data-kind="public"><input type="file" name="icon" accept="image/png,image/jpeg,image/webp" required><button type="submit">公開用を登録</button><button type="button" class="secondary faviconReset" data-kind="public">初期アイコンへ戻す</button></form></div>
<div><strong>管理画面用</strong><div class="favicon-preview"><img id="faviconPreviewAdmin" alt="管理画面Favicon" src="/?action=favicon&amp;kind=admin&amp;v=20261123-1"></div><form class="faviconUpload" data-kind="admin"><input type="file" name="icon" accept="image/png,image/jpeg,image/webp" required><button type="submit">管理用を登録</button><button type="button" class="secondary faviconReset" data-kind="admin">初期アイコンへ戻す</button></form></div>
</div>
<p id="faviconMessage" role="status"></p>
</div></section>
<section class="panel admin-pane" data-admin-section="users" id="adminUsersPane" hidden>
<h2>管理者アカウント</h2>
<p class="muted">マスターのみ追加・停止・パスワード再設定ができます。マスターのパスワードは従来の環境設定で管理します。</p>
<div id="adminUsersTable"></div>
<h3>管理者を追加</h3>
<form id="adminUserCreateForm">
<div class="row"><div><label>ログインID（半角英数字など3～50文字）</label><input name="login_id" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]+" autocomplete="off" required></div><div><label>表示名</label><input name="display_name" maxlength="80" required></div></div>
<label>初期パスワード（12文字以上）</label><input name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required>
<button type="submit">管理者を登録</button><p id="adminUserError"></p>
</form>
</section></div></div></div></main>
<footer>Hanamaru Reservation Platform</footer>
<script src="/assets/operations.js?v=20261123-explain2" defer></script>
<script src="/assets/corporate-admin.js?v=20261126-1" defer></script>
<script src="/assets/instructor-admin.js?v=20261126-1" defer></script>
<script src="/assets/corporate-roster-admin.js?v=20261126-1" defer></script>
<script src="/assets/scheduling-admin.js?v=20261123-schedule2" defer></script>
<script src="/assets/teachers-admin.js?v=20261123-sync2" defer></script>
<script src="/assets/favicon-admin.js?v=20261123-1" defer></script>
<script src="/assets/teacher-profiles-admin.js?v=20261123-profile3" defer></script>
<script src="/assets/site-extras.js" defer></script>
<script>
'use strict';
let cfg={},pickedMenu=null,pickedTime='',csrfToken='';
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(action,params={},post){const q=new URLSearchParams({action,...params});const options={};if(post!==undefined){options.method='POST';options.headers={'Content-Type':'application/json','X-CSRF-Token':csrfToken};options.body=JSON.stringify(post);}const response=await fetch('/?'+q,{...options,credentials:'same-origin'});let data=await response.json();if(!response.ok)throw Error(data.error||'通信エラー');return data;}
function error(id,e){$(id).innerHTML='<div class="error">'+esc(e.message||e)+'</div>';}
function today(){let parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tokyo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());return parts;}
function showMenus(){ $('menus').innerHTML=cfg.menus.map(m=>'<button type="button" class="menu '+(pickedMenu===Number(m.id)?'selected':'')+'" data-id="'+m.id+'">'+(cfg.media||[]).filter(x=>Number(x.menu_id)===Number(m.id)).slice(0,1).map(x=>'<img style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;margin-bottom:10px" src="/?action=media&id='+Number(x.id)+'" alt="">').join('')+'<b>'+esc(m.name)+'</b>'+esc(m.description)+'<small>'+Number(m.duration)+'分 ・ '+Number(m.price).toLocaleString('ja-JP')+'円</small></button>').join('')||'予約メニューがありません';document.querySelectorAll('.menu').forEach(el=>el.onclick=()=>{pickedMenu=Number(el.dataset.id);pickedTime='';$('reservationStep').classList.remove('hidden');showMenus();loadTimes();$('reservationStep').scrollIntoView({behavior:'smooth',block:'start'});});}
let availableTimes=[];
let currentTimePeriod='morning';
function displayJapaneseDate(value){
    if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return '日付を選択してください';
    const d=new Date(value+'T12:00:00');
    if(Number.isNaN(d.getTime()))return '日付を選択してください';
    return d.toLocaleDateString('ja-JP',{year:'numeric',month:'long',day:'numeric',weekday:'long'});
}
function timePeriod(time){const hour=Number(time.slice(0,2));return hour<12?'morning':hour<17?'afternoon':'evening';}
function updateReservationSummary(){
    const date=$('date').value;
    $('selectedDateDisplay').textContent=displayJapaneseDate(date);
    const hasSelection=Boolean(date&&pickedTime);
    $('chosenSlotSummary').hidden=!hasSelection;
    $('chosenSlotDisplay').textContent=hasSelection?displayJapaneseDate(date)+'　'+pickedTime:'';
}
function showTimePeriod(period){
    currentTimePeriod=period;
    document.querySelectorAll('#timeFilters [data-period]').forEach(el=>{
        const active=el.dataset.period===period;
        el.classList.toggle('active',active);
        el.setAttribute('aria-pressed',active?'true':'false');
        el.disabled=!availableTimes.some(t=>timePeriod(t)===el.dataset.period);
    });
    const times=availableTimes.filter(t=>timePeriod(t)===period);
    $('times').innerHTML=times.length?times.map(t=>'<button type="button" data-time="'+t+'" class="'+(t===pickedTime?'chosen':'')+'">'+t+'</button>').join(''):'<p class="muted">この時間帯の空き枠はありません。</p>';
    document.querySelectorAll('#times [data-time]').forEach(btn=>btn.onclick=()=>{
        $('customerStep').classList.remove('hidden');
        pickedTime=btn.dataset.time;
        document.querySelectorAll('#times [data-time]').forEach(x=>x.classList.toggle('chosen',x===btn));
        const menu=cfg.menus.find(m=>Number(m.id)===pickedMenu);
        const blocked=Number(menu?.price||0)>0&&!cfg.stripe_test_enabled;
        $('bookBtn').disabled=blocked;
        $('bookBtn').textContent=blocked?'カード決済の設定待ち':Number(menu?.price||0)>0?(cfg.stripe_mode==='live'?'カードで支払って予約する':'Stripeテスト決済に進む'):'無料予約を確定する';
        $('bookingError').innerHTML=blocked?'<div class="error">現在オンライン決済を設定中のため、申込みを受け付けていません。</div>':'';
        selection();
    });
}
document.querySelectorAll('#timeFilters [data-period]').forEach(btn=>btn.addEventListener('click',()=>showTimePeriod(btn.dataset.period)));
async function loadTimes(){
    pickedTime='';
    availableTimes=[];
    $('bookBtn').disabled=true;
    selection();
    if(!pickedMenu||!$('date').value){
        $('times').textContent='メニューと日付を選んでください';
        return;
    }
    $('customerStep').classList.add('hidden');
    $('times').textContent='読み込み中…';
    try{
        const date=$('date').value;
        const d=await api('availability',{menu_id:pickedMenu,date});
        if(date!==$('date').value)return;
        availableTimes=d.times||[];
        const periods=['morning','afternoon','evening'];
        const first=periods.find(p=>availableTimes.some(t=>timePeriod(t)===p));
        showTimePeriod(first||'morning');
        if(!first)$('times').innerHTML='<p class="muted">この日は空き枠がありません。</p>';
    }catch(e){error('times',e);}
}
function selection(){let menu=cfg.menus?.find(m=>Number(m.id)===pickedMenu);$('selection').textContent=menu&&pickedTime?menu.name+' / '+displayJapaneseDate($('date').value)+' '+pickedTime+' / '+Number(menu.price).toLocaleString('ja-JP')+'円':'';updateReservationSummary();}
async function init(){try{cfg=await api('config');csrfToken=cfg.csrf;$('brand').textContent=cfg.settings.site_name;$('siteTitle').textContent=cfg.settings.site_name;let adminRoute=location.pathname.replace(/\/$/,'')==='/admin';$('publicView').classList.toggle('hidden',adminRoute);$('adminView').classList.toggle('hidden',!adminRoute);if(adminRoute){if(cfg.admin)await dashboard();return;}if(window.initSiteExtras)window.initSiteExtras();if(cfg.stripe_test_enabled){$('bookBtn').textContent=cfg.stripe_mode==='live'?'カードで支払って予約する':'Stripeテスト決済に進む';}
else { $('bookBtn').textContent='カード決済の設定待ち'; }
if(new URLSearchParams(location.search).has('payment')){
    let p=new URLSearchParams(location.search);
    const msg=document.createElement('div');msg.className='notice';msg.id='paymentNotice';
    if(p.get('payment')==='success'&&p.get('session_id')){
      msg.textContent='決済結果を確認しています…';
      const session=p.get('session_id');
      const check=async()=>{try{
        const data=await api('paymentStatus',{session_id:session});
        msg.textContent=data.status==='confirmed'?'カード決済を確認しました。予約が確定しました。':data.payment_state==='needs_refund'?'決済を受け付けましたが予約確定できませんでした。運営へ連絡してください。':'決済結果を確認中です。しばらくして画面を更新してください。';
      }catch(e){msg.textContent='決済結果を取得できません。運営へお問い合わせください。';}};
      check();
    }else{msg.textContent='決済が中断されました。予約は確定していません。';}
    $('publicView').insertBefore(msg,$('publicView').children[1]);
}
$('date').min=today();$('date').value=today();showMenus();$('date').onchange=loadTimes;}catch(e){alert(e.message);}}
$('bookingForm').onsubmit=async e=>{e.preventDefault();if(!pickedMenu||!pickedTime)return;let data=Object.fromEntries(new FormData(e.target));$('bookBtn').disabled=true;$('bookingError').innerHTML='';try{const menu=cfg.menus.find(m=>Number(m.id)===pickedMenu);const paid=Number(menu?.price||0)>0;if(paid&&!cfg.stripe_test_enabled)throw new Error('カード決済がまだ設定されていないため、現在は申し込めません。');const result=await api(paid?'createCheckout':'book',{}, {...data,menu_id:pickedMenu,date:$('date').value,time:pickedTime});
if(result.checkout_url){window.location.assign(result.checkout_url);return;}
$('bookingForm').innerHTML='<div class="notice"><h2>予約が確定しました</h2><p>予約番号: '+result.booking_id+'</p><p>現在、自動確認メールは送信されません。予約番号と日時を控えてください。</p></div>';loadTimes();}catch(err){error('bookingError',err);$('bookBtn').disabled=false;}};
$('loginForm').onsubmit=async e=>{e.preventDefault();try{await api('login',{}, {login_id:$('adminLoginId').value,password:$('password').value});$('password').value='';await dashboard();}catch(err){error('loginError',err);}};
const adminPageNames=['bookings','manual','calendar','teachers','qualifications','scheduling','corporate','menus','media','inquiries','settings','users'];
function adminPageFromUrl(){
    const page=new URLSearchParams(location.search).get('page');
    return adminPageNames.includes(page)?page:'bookings';
}
let currentAdminPage=adminPageFromUrl();
function adminNavigate(page,updateUrl=false){
    const sections=document.querySelectorAll('#dashboard [data-admin-section]');
    if(!adminPageNames.includes(page)||!Array.from(sections).some(el=>el.dataset.adminSection===page))page='bookings';
    if(page==='users' && $('adminUsersNav').hidden)page='bookings';
    currentAdminPage=page;
    sections.forEach(el=>{el.hidden=el.dataset.adminSection!==page;});
    document.querySelectorAll('#dashboard [data-admin-page]').forEach(el=>{
        const selected=el.dataset.adminPage===page;
        el.classList.toggle('active',selected);
        el.setAttribute('aria-current',selected?'page':'false');
    });
    if(updateUrl){
        const url=new URL(location.href);
        url.searchParams.set('page',page);
        history.pushState({adminPage:page},'',url.pathname+url.search+url.hash);
    }
}
function loadAdminPage(page,updateUrl=false){
    adminNavigate(page,updateUrl);
    if(currentAdminPage==='users')refreshAdminUsers().catch(e=>alert(e.message));
    if(currentAdminPage==='teachers' && window.refreshTeacherDirectory)window.refreshTeacherDirectory().catch(e=>error('teacherDirectoryResult',e));
    if(currentAdminPage==='qualifications' && window.refreshTeacherQualifications)window.refreshTeacherQualifications().catch(e=>{const x=$('qualificationMasterArea');if(x)x.textContent='資格マスターを取得できません：'+e.message;});
    if(currentAdminPage==='scheduling' && window.refreshSchedulingAdmin)window.refreshSchedulingAdmin().catch(e=>alert(e.message));
    if(currentAdminPage==='corporate' && window.refreshCorporateAdmin)window.refreshCorporateAdmin().catch(e=>alert(e.message));
    if(currentAdminPage==='corporate' && window.refreshInstructorAdmin)window.refreshInstructorAdmin();
    if(currentAdminPage==='corporate' && window.refreshCorporateRoster)window.refreshCorporateRoster();
}
document.querySelectorAll('#dashboard [data-admin-page]').forEach(el=>el.addEventListener('click',()=>loadAdminPage(el.dataset.adminPage,true)));
window.addEventListener('popstate',()=>{
    if((location.pathname==='/admin'||location.pathname==='/admin/') && !$('dashboard').classList.contains('hidden'))loadAdminPage(adminPageFromUrl());
});
async function dashboard(){const d=await api('adminData');$('loginPanel').classList.add('hidden');$('dashboard').classList.remove('hidden');$('adminUsersNav').hidden=!d.master;const faviconSettings=$('faviconSettings');if(faviconSettings)faviconSettings.hidden=!d.master;$('adminUsersPane').dataset.master=d.master?'yes':'no';
$('bookings').innerHTML=d.bookings.map(b=>'<tr><td>'+b.id+'</td><td>'+esc(b.starts_at)+'</td><td>'+esc(b.menu_name)+'</td><td>'+esc(b.customer_name)+'</td><td>'+esc(b.email)+'<br>'+esc(b.phone)+'</td><td>'+esc(b.status==='pending_review'?'申込み・確認待ち':b.status)+'</td><td><button class="secondary" data-book="'+b.id+'" data-status="'+(b.status==='confirmed'?'cancelled':'confirmed')+'">'+(b.status==='confirmed'?'取消':b.status==='pending_review'?'申込みを確定':'復帰')+'</button></td></tr>').join('');
document.querySelectorAll('[data-book]').forEach(b=>b.onclick=async()=>{if(!confirm(b.dataset.status==='confirmed'?'予約を確定しますか？ 料金は当日現地払いです。':'この予約を取り消しますか？'))return;try{await api('setStatus',{}, {id:Number(b.dataset.book),status:b.dataset.status});await dashboard();}catch(e){alert(e.message);}});
$('menuEditor').innerHTML=d.menus.map(m=>'<div class="actions" style="padding:9px;border-bottom:1px solid #eee"><b>'+esc(m.name)+'</b><span>'+m.duration+'分 / '+Number(m.price).toLocaleString()+'円 / '+(m.active?'公開':'非公開')+'</span><button class="secondary" data-edit="'+m.id+'">編集</button></div>').join('');
document.querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>{let m=d.menus.find(x=>Number(x.id)===Number(b.dataset.edit));for(let key of ['id','name','description','duration','price'])$('menuForm').elements[key].value=m[key];$('menuForm').elements.active.checked=!!Number(m.active);$('menuForm').scrollIntoView({behavior:'smooth'});});
for(let key of ['site_name','open_time','close_time','slot_minutes','closed_weekdays','closed_dates','lead_minutes','max_advance_days'])$('settingsForm').elements[key].value=d.settings[key]??'';
adminNavigate(adminPageFromUrl());
// Optional sections load independently; a missing profile table cannot hide the teacher list.
if(currentAdminPage==='teachers' && window.refreshTeacherDirectory)await window.refreshTeacherDirectory();
if(currentAdminPage==='qualifications' && window.refreshTeacherQualifications)await window.refreshTeacherQualifications().catch(e=>{const x=$('qualificationMasterArea');if(x)x.textContent='資格マスターを取得できません：'+e.message;});
if(currentAdminPage==='scheduling' && window.refreshSchedulingAdmin)await window.refreshSchedulingAdmin();
if(currentAdminPage==='corporate' && window.refreshCorporateAdmin)await window.refreshCorporateAdmin();
if(currentAdminPage==='corporate' && window.refreshInstructorAdmin)await window.refreshInstructorAdmin();
if(currentAdminPage==='corporate' && window.refreshCorporateRoster)await window.refreshCorporateRoster();
if(window.refreshSiteExtras)await window.refreshSiteExtras();
}
$('refresh').onclick=()=>dashboard().catch(e=>alert(e.message));
async function refreshAdminUsers(){
    const data=await api('adminUsers');
    $('adminUsersTable').innerHTML='<div class="scroll"><table><thead><tr><th>ID</th><th>表示名</th><th>状態</th><th>最終ログイン</th><th>操作</th></tr></thead><tbody>'+
    data.users.map(u=>'<tr><td>'+esc(u.login_id)+'</td><td>'+esc(u.display_name)+'</td><td>'+(Number(u.active)?'有効':'停止')+'</td><td>'+esc(u.last_login_at||'未ログイン')+'</td><td><button type="button" class="secondary" data-user-id="'+Number(u.id)+'" data-user-action="'+(Number(u.active)?'disable':'enable')+'">'+(Number(u.active)?'停止':'有効化')+'</button> <button type="button" class="secondary" data-user-id="'+Number(u.id)+'" data-user-action="reset_password">パスワード変更</button></td></tr>').join('')+'</tbody></table></div>';
    document.querySelectorAll('#adminUsersTable [data-user-action]').forEach(b=>b.onclick=async()=>{
        const operation=b.dataset.userAction,id=Number(b.dataset.userId);
        let password='';
        if(operation==='reset_password'){
            password=prompt('新しいパスワードを入力してください（12文字以上）')||'';
            if(!password)return;
            if(password.length<12){alert('12文字以上で入力してください');return;}
        }else if(!confirm(operation==='disable'?'この管理者を停止しますか？':'この管理者を有効化しますか？'))return;
        try{await api('adminUserUpdate',{}, {id,operation,...(password?{password}:{})});await refreshAdminUsers();}catch(e){alert(e.message);}
    });
}
$('adminUserCreateForm').onsubmit=async e=>{
    e.preventDefault();
    const form=e.target;
    try{
        await api('adminUserCreate',{},Object.fromEntries(new FormData(form)));
        form.reset();$('adminUserError').textContent='登録しました';await refreshAdminUsers();
    }catch(err){error('adminUserError',err);}
};
$('logout').onclick=async()=>{await api('logout',{},{});location.reload();};
$('importResevaMenus').onclick=async()=>{if(!confirm('RESERVAの8メニューを登録・公開し、上田晶美に相談を7,000円、マネー相談を6,500円、その他を5,500円に更新します。よろしいですか？'))return;const btn=$('importResevaMenus');btn.disabled=true;try{const r=await api('importResevaMenus',{},{});await dashboard();alert(r.added+'件を追加、'+r.updated+'件を更新しました。全8メニューの指定価格を反映しました。');}catch(e){alert(e.message);}finally{btn.disabled=false;}};
$('resetMenu').onclick=()=>{$('menuForm').reset();$('menuForm').elements.id.value='';};
$('menuForm').onsubmit=async e=>{e.preventDefault();let d=Object.fromEntries(new FormData(e.target));d.active=e.target.elements.active.checked;try{await api('saveMenu',{},d);$('resetMenu').click();await dashboard();}catch(err){alert(err.message);}};
$('settingsForm').onsubmit=async e=>{e.preventDefault();try{await api('saveSettings',{},Object.fromEntries(new FormData(e.target)));await dashboard();alert('保存しました');}catch(err){error('settingsError',err);}};
init();
</script></body></html>