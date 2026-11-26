<?php
declare(strict_types=1);
/**
 * Optional email ownership check for corporate attendees.
 * Enable with CORPORATE_EMAIL_VERIFICATION=required plus Mailgun env settings.
 * Does not activate any campaign and does not send mail unless configured.
 */
function corporateEmailVerificationRequired(): bool {
    return setting('CORPORATE_EMAIL_VERIFICATION','off')==='required';
}
function corporateEmailReady(): bool {
    return setting('CORPORATE_MAILGUN_API_KEY')!=='' &&
      setting('CORPORATE_MAILGUN_DOMAIN')!=='' &&
      filter_var(setting('CORPORATE_MAIL_FROM'),FILTER_VALIDATE_EMAIL)!==false;
}
function corporateEmailVerified(array $campaign,string $email): bool {
    return !corporateEmailVerificationRequired() ||
      (!empty($campaign['id']) &&
       (int)($_SESSION['corporate_email_verified_campaign']??0)===(int)$campaign['id'] &&
       (string)($_SESSION['corporate_email_verified']??'')===$email &&
       (int)($_SESSION['corporate_email_verified_until']??0)>=time());
}
function corporateEmailSend(string $address,string $code): bool {
    if(!corporateEmailReady())return false;
    $domain=setting('CORPORATE_MAILGUN_DOMAIN');
    if(!preg_match('/^[a-z0-9.-]{3,253}$/iD',$domain))return false;
    $region=setting('CORPORATE_MAILGUN_REGION','us')==='eu'?'api.eu.mailgun.net':'api.mailgun.net';
    $ch=curl_init('https://'.$region.'/v3/'.$domain.'/messages');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_USERPWD=>'api:'.setting('CORPORATE_MAILGUN_API_KEY'),
        CURLOPT_POSTFIELDS=>http_build_query([
            'from'=>setting('CORPORATE_MAIL_FROM'),
            'to'=>$address,
            'subject'=>'【はなキャリ】メールアドレス確認コード',
            'text'=>"丹青社向けセカンドキャリア相談の確認コード：{$code}\n\n有効期限：10分\n\nお心当たりがない場合は、このメールを破棄してください。\n"
        ]),
    ]);
    curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status>=200&&$status<300;
}
function corporateEmailRoute(string $action,string $method): void {
    if($action==='corporateEmailPolicy' && $method==='GET'){
        jsonOut(['verification_required'=>corporateEmailVerificationRequired()]);
    }
    if($action==='corporateEmailStatus' && $method==='GET'){
        requireMasterAdmin();
        jsonOut(['verification_required'=>corporateEmailVerificationRequired(),
            'delivery_ready'=>corporateEmailReady()]);
    }
    if($action==='corporateEmailSendCode' && $method==='POST'){
        checkCsrf();
        if(!corporateEmailVerificationRequired())fail('メール確認は現在不要です',409);
        if(!corporateEmailReady())fail('確認メールの送信設定が未完了です。管理者にご連絡ください',503);
        $v=body();
        $campaign=corporateCampaign();
        if(!corporateAuthorized($campaign,(string)($v['access_code']??'')))fail('企業専用コードが正しくありません',403);
        $email=strtolower(trim((string)($v['email']??'')));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)fail('メールアドレスをご確認ください');
        if((int)($campaign['roster_required']??0)===1){
            $q=db()->prepare('SELECT COUNT(*) FROM corporate_invitees WHERE campaign_id=? AND email=? AND active=1');
            $q->execute([(int)$campaign['id'],$email]);
            if(!$q->fetchColumn())fail('このメールアドレスは対象者名簿に登録されていません',403);
        }
        if(time()-(int)($_SESSION['corporate_email_last_sent']??0)<60)
            fail('確認コードの再送は1分後に行ってください',429);
        $code=(string)random_int(100000,999999);
        $nonce=bin2hex(random_bytes(16));
        $hash=hash_hmac('sha256',$code,$nonce);
        // Do not store the plaintext OTP in the database or client browser.
        if(!corporateEmailSend($email,$code))fail('確認メールを送信できません。管理者にご連絡ください',503);
        $_SESSION['corporate_email_pending']=[
            'campaign_id'=>(int)$campaign['id'],'email'=>$email,
            'nonce'=>$nonce,'hash'=>$hash,'expires'=>time()+600,'attempts'=>0
        ];
        $_SESSION['corporate_email_last_sent']=time();
        unset($_SESSION['corporate_email_verified'],$_SESSION['corporate_email_verified_campaign'],
            $_SESSION['corporate_email_verified_until']);
        jsonOut(['ok'=>true,'expires_in_seconds'=>600]);
    }
    if($action==='corporateEmailVerifyCode' && $method==='POST'){
        checkCsrf();$v=body();
        $code=trim((string)($v['code']??''));
        $pending=$_SESSION['corporate_email_pending']??null;
        if(!is_array($pending)||(int)($pending['expires']??0)<time())fail('確認コードの有効期限が切れています。再送してください',410);
        if((int)($pending['attempts']??0)>=5)fail('確認回数の上限です。確認コードを再送してください',429);
        $_SESSION['corporate_email_pending']['attempts']++;
        if(!preg_match('/^\d{6}$/D',$code) ||
           !hash_equals((string)$pending['hash'],hash_hmac('sha256',$code,(string)$pending['nonce'])))
            fail('確認コードが正しくありません',401);
        session_regenerate_id(true);
        $_SESSION['corporate_email_verified']=$pending['email'];
        $_SESSION['corporate_email_verified_campaign']=$pending['campaign_id'];
        $_SESSION['corporate_email_verified_until']=time()+1800;
        unset($_SESSION['corporate_email_pending']);
        jsonOut(['ok'=>true]);
    }
}
