<?php
declare(strict_types=1);
/**
 * Read-only sanity report for corporate booking launch.
 * Intentionally does not echo credentials, emails or names.
 */
if(PHP_SAPI!=='cli')exit(1);
$envFile=dirname(__DIR__).'/.env';
if(!is_readable($envFile)){fwrite(STDERR,"ERROR: .env not readable\n");exit(1);}
$env=[];
foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $line=trim($line);
    if(!$line||str_starts_with($line,'#')||!str_contains($line,'='))continue;
    [$name,$value]=explode('=',$line,2);
    $env[trim($name)]=trim($value," \t\n\r\0\x0B\"'");
}
if(($env['DB_CONNECTION']??'')!=='mysql'){fwrite(STDERR,"ERROR: DB_CONNECTION=mysql required\n");exit(1);}
try{
    $db=new PDO('mysql:host='.($env['DB_HOST']??'mysql').';port='.($env['DB_PORT']??'3306').
        ';dbname='.($env['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
        $env['DB_USERNAME']??'hanamaru',$env['DB_PASSWORD']??'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $expected=['teachers','teacher_profiles','teacher_photo_assets','teacher_logins',
        'corporate_campaigns','corporate_teachers','corporate_slots',
        'corporate_customers','corporate_invitees','corporate_bookings'];
    $tables=$db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')
        ->fetchAll(PDO::FETCH_COLUMN);
    $missing=array_values(array_diff($expected,$tables));
    $report=['schema_missing'=>$missing,
        'tls_review_approved'=>($env['CORPORATE_TLS_APPROVED']??'')==='yes',
        'privacy_review_approved'=>($env['CORPORATE_PRIVACY_APPROVED']??'')==='yes',
        'email_verification_mode'=>$env['CORPORATE_EMAIL_VERIFICATION']??'off',
        'mail_delivery_configured'=>!empty($env['CORPORATE_MAILGUN_DOMAIN']) &&
            !empty($env['CORPORATE_MAILGUN_API_KEY']) &&
            filter_var($env['CORPORATE_MAIL_FROM']??'',FILTER_VALIDATE_EMAIL)!==false];
    if(!$missing){
        $campaign=$db->query("SELECT id,starts_on,ends_on,published,roster_required
             FROM corporate_campaigns WHERE code='tanseisha' LIMIT 1")->fetch();
        $report['campaign_exists']=(bool)$campaign;
        if($campaign){
            $id=(int)$campaign['id'];
            foreach([
                'assigned_teachers'=>'SELECT COUNT(*) FROM corporate_teachers WHERE campaign_id=? AND enabled=1',
                'slots_total'=>'SELECT COUNT(*) FROM corporate_slots WHERE campaign_id=?',
                'slots_open'=>'SELECT COUNT(*) FROM corporate_slots WHERE campaign_id=? AND booked_id IS NULL',
                'bookings_confirmed'=>'SELECT COUNT(*) FROM corporate_bookings WHERE campaign_id=? AND status="confirmed"',
                'bookings_completed'=>'SELECT COUNT(*) FROM corporate_bookings WHERE campaign_id=? AND status="completed"',
                'invitees_active'=>'SELECT COUNT(*) FROM corporate_invitees WHERE campaign_id=? AND active=1',
            ] as $key=>$sql){
                $q=$db->prepare($sql);$q->execute([$id]);$report[$key]=(int)$q->fetchColumn();
            }
            $report['campaign_published']=(bool)$campaign['published'];
            $report['roster_enforced']=(bool)$campaign['roster_required'];
            $q=$db->prepare('SELECT COUNT(*) FROM corporate_teachers ct
                JOIN teachers t ON t.id=ct.teacher_id LEFT JOIN teacher_profiles p ON p.teacher_id=t.id
                WHERE ct.campaign_id=? AND ct.enabled=1 AND COALESCE(p.summary,"")="" AND COALESCE(p.career,"")=""');
            $q->execute([$id]);$report['assigned_teachers_missing_bio']=(int)$q->fetchColumn();
            $q=$db->prepare('SELECT COUNT(*) FROM corporate_teachers ct
                LEFT JOIN teacher_photo_assets a ON a.teacher_id=ct.teacher_id
                LEFT JOIN teacher_profiles p ON p.teacher_id=ct.teacher_id
                WHERE ct.campaign_id=? AND ct.enabled=1 AND a.teacher_id IS NULL AND COALESCE(p.photo_path,"")=""');
            $q->execute([$id]);$report['assigned_teachers_missing_photo']=(int)$q->fetchColumn();
            $q=$db->prepare('SELECT COUNT(*) FROM corporate_teachers ct
                JOIN teacher_logins a ON a.teacher_id=ct.teacher_id AND a.active=1
                WHERE ct.campaign_id=? AND ct.enabled=1');
            $q->execute([$id]);$report['teachers_with_active_login']=(int)$q->fetchColumn();
        }
    }
    $report['can_start_technical_trial']=empty($report['schema_missing']) &&
        !empty($report['campaign_exists']);
    $report['ready_for_public_open']=empty($report['schema_missing']) &&
        !empty($report['campaign_exists']) && ($report['assigned_teachers']??0)===10 &&
        ($report['slots_total']??0)>0 && $report['tls_review_approved'] &&
        $report['privacy_review_approved'] &&
        ($report['email_verification_mode']!=='required'||$report['mail_delivery_configured']);
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    if(!$report['ready_for_public_open'])exit(2);
}catch(Throwable $e){
    fwrite(STDERR,"READINESS ERROR: ".$e->getMessage()."\n");
    exit(1);
}
