<?php
declare(strict_types=1);
// Stripe Checkout: test or live. Secrets stay on the server, never in the repository.
function stripeMode(): string {
    $mode=setting('STRIPE_MODE','disabled');
    return in_array($mode,['test','live'],true)?$mode:'disabled';
}
function stripeEnabled(): bool {
    $mode=stripeMode();
    if($mode==='disabled') return false;
    if($mode==='live' && setting('STRIPE_LIVE_ENABLED')!=='yes') return false;
    $secret=setting('STRIPE_SECRET_KEY');
    $webhook=setting('STRIPE_WEBHOOK_SECRET');
    return str_starts_with($secret,$mode==='live'?'sk_live_':'sk_test_')
        && strlen($secret)>16
        && str_starts_with($webhook,'whsec_')
        && strlen($webhook)>16;
}
function stripeDb(): PDO {
    return db();
}
function stripeApi(string $endpoint,array $params,string $idempotency): array {
    if(!function_exists('curl_init')) fail('サーバーにPHP cURL拡張が必要です',503);
    $ch=curl_init('https://api.stripe.com/v1/'.$endpoint);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($params,'','&',PHP_QUERY_RFC1738),
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.setting('STRIPE_SECRET_KEY'),'Idempotency-Key: '.$idempotency],
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json=is_string($body)?json_decode($body,true):null;
    if($status<200 || $status>=300 || !is_array($json)||empty($json['id'])) {
        // Log Stripe's safe error classification, never card data, keys or full response bodies.
        $stripeError=is_array($json)?($json['error']??null):null;
        $code=is_array($stripeError)?(string)($stripeError['code']??''):'';
        $type=is_array($stripeError)?(string)($stripeError['type']??''):'';
        $param=is_array($stripeError)?(string)($stripeError['param']??''):'';
        error_log('[stripe] session creation failed HTTP '.$status
            .' type='.preg_replace('/[^a-zA-Z0-9_]/','',substr($type,0,80))
            .' code='.preg_replace('/[^a-zA-Z0-9_]/','',substr($code,0,80))
            .' param='.preg_replace('/[^a-zA-Z0-9_\.\[\]]/','',substr($param,0,80))
            .' curl_error='.($body===false?'yes':'no'));
        fail('決済画面の準備に失敗しました。もう一度お試しください',502);
    }
    return $json;
}
function stripeRequestBooking(): never {
    if(!stripeEnabled()) fail('カード決済が未設定です',503);
    checkCsrf();$v=body();
    $menuId=(int)($v['menu_id']??0);$date=(string)($v['date']??'');$time=(string)($v['time']??'');
    $name=trim((string)($v['name']??''));$email=trim((string)($v['email']??''));
    $phone=trim((string)($v['phone']??''));$note=trim((string)($v['note']??''));
    if(!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$time)
       || !dateValid($date)||mb_strlen($name)<1||mb_strlen($name)>100
       || !filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254
       || strlen($phone)>40||mb_strlen($note)>1000) fail('予約内容を確認してください');
    $db=stripeDb();bookingLock($db);$db->beginTransaction();
    try{
        if(!in_array($time,availability($menuId,$date),true)) {
            $db->rollBack();fail('希望日時は予約できません',409);
        }
        $q=$db->prepare('SELECT name,price,duration FROM menus WHERE id=? AND active=1');
        $q->execute([$menuId]);$menu=$q->fetch();
        if(!$menu || (int)$menu['price']<50 || (int)$menu['price']>10000000) {
            $db->rollBack();fail('決済に対応していないメニューです');
        }
        $from=new DateTimeImmutable($date.' '.$time);
        $to=$from->modify('+'.(int)$menu['duration'].' minutes');
        // Checkout Session expiry is 30 minutes; hold is 35 minutes to allow webhook delivery.
        $expiry=(new DateTimeImmutable())->modify('+35 minutes')->format('Y-m-d H:i:s');
        $stmt=$db->prepare('INSERT INTO bookings(menu_id,starts_at,ends_at,customer_name,email,phone,note,status) VALUES(?,?,?,?,?,?,?,"pending_payment")');
        $stmt->execute([$menuId,$from->format('Y-m-d H:i:s'),$to->format('Y-m-d H:i:s'),$name,$email,$phone,$note]);
        $bookingId=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO stripe_payments (booking_id,amount_jpy,expires_at) VALUES(?,?,?)')
            ->execute([$bookingId,(int)$menu['price'],$expiry]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally {bookingUnlock($db);}
    try {
        $base='https://hanamaru.uzero.style';
        $s=stripeApi('checkout/sessions',[
            'mode'=>'payment',
            'payment_method_types'=>['card'],
            'line_items'=>[[
                'price_data'=>['currency'=>'jpy','unit_amount'=>(int)$menu['price'],
                    'product_data'=>['name'=>$menu['name']]],
                'quantity'=>1
            ]],
            'customer_email'=>$email,
            'client_reference_id'=>(string)$bookingId,
            'metadata'=>['booking_id'=>(string)$bookingId],
            'expires_at'=>time()+1800,
            'success_url'=>$base.'/?payment=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'=>$base.'/?payment=cancel',
        ],'hanamaru-'.stripeMode().'-booking-'.$bookingId);
        if(empty($s['url'])||empty($s['id'])||!str_starts_with($s['url'],'https://checkout.stripe.com/')) fail('決済URLが不正です',502);
        $stmt=$db->prepare('UPDATE stripe_payments SET session_id=?,state="checkout" WHERE booking_id=?');
        $stmt->execute([$s['id'],$bookingId]);
        jsonOut(['checkout_url'=>$s['url']]);
    }catch(Throwable $e) {
        $db->prepare('UPDATE bookings SET status="cancelled" WHERE id=? AND status="pending_payment"')->execute([$bookingId]);
        $db->prepare('UPDATE stripe_payments SET state="failed" WHERE booking_id=?')->execute([$bookingId]);
        throw $e;
    }
}
function stripeWebhook(): never {
    if(!stripeEnabled()) fail('カード決済が未設定です',503);
    $payload=file_get_contents('php://input');
    $header=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');
    if(!is_string($payload)||strlen($payload)>131072) fail('Webhook内容が不正です',400);
    $parts=[];
    foreach(explode(',',$header) as $part) {
        $p=explode('=',trim($part),2);
        if(count($p)===2)$parts[$p[0]][]=$p[1];
    }
    $timestamp=isset($parts['t'][0])?(int)$parts['t'][0]:0;
    if($timestamp<=0||abs(time()-$timestamp)>300) fail('署名の有効期限切れです',400);
    $digest=hash_hmac('sha256',$timestamp.'.'.$payload,setting('STRIPE_WEBHOOK_SECRET'));
    $matched=false;
    foreach($parts['v1']??[] as $v) if(hash_equals($digest,$v)){$matched=true;break;}
    if(!$matched) fail('署名が不正です',400);
    $event=json_decode($payload,true);
    if(!is_array($event)||empty($event['id'])||!is_string($event['id'])||!is_string($event['type']??null)) fail('イベント内容が不正です');
    if(!is_bool($event['livemode']??null) || $event['livemode']!==(stripeMode()==='live')) fail('Stripeの決済環境が一致しません',400);
    $type=$event['type'];$session=$event['data']['object']??[];
    $db=stripeDb();bookingLock($db);$db->beginTransaction();
    try {
        $q=$db->prepare('INSERT IGNORE INTO stripe_events(event_id,event_type) VALUES(?,?)');
        $q->execute([$event['id'],$type]);
        if($q->rowCount()===0){$db->commit();jsonOut(['received'=>true]);}
        if(in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired','checkout.session.async_payment_failed'],true) && is_array($session)) {
            $q=$db->prepare('SELECT p.*,b.starts_at,b.ends_at,b.status FROM stripe_payments p JOIN bookings b ON b.id=p.booking_id WHERE p.session_id=?');
            $q->execute([(string)($session['id']??'')]);$p=$q->fetch();
            if($p) {
                $paid=in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)
                    && ($session['payment_status']??'')==='paid'
                    && ($session['currency']??'')==='jpy'
                    && (int)($session['amount_total']??-1)===(int)$p['amount_jpy']
                    && (string)($session['client_reference_id']??'')===(string)$p['booking_id'];
                if($paid){
                    $q=$db->prepare('SELECT COUNT(*) FROM bookings b LEFT JOIN stripe_payments p ON p.booking_id=b.id WHERE b.id<>? AND b.starts_at<? AND b.ends_at>? AND (b.status IN (\'confirmed\',\'pending_review\') OR (b.status=\'pending_payment\' AND p.expires_at>?) )');
                    $q->execute([$p['booking_id'],$p['ends_at'],$p['starts_at'],(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
                    $conflict=(int)$q->fetchColumn()>0;
                    $expired=strtotime($p['expires_at'])<time();
                    if($p['status']==='pending_payment'&&!$conflict&&!$expired){
                        $db->prepare('UPDATE bookings SET status="confirmed" WHERE id=?')->execute([$p['booking_id']]);
                        $db->prepare('UPDATE stripe_payments SET state="paid" WHERE booking_id=?')->execute([$p['booking_id']]);
                    } elseif($p['status']!=='confirmed') {
                        $db->prepare('UPDATE stripe_payments SET state="needs_refund" WHERE booking_id=?')->execute([$p['booking_id']]);
                        error_log('[stripe] PAID NEEDS REFUND booking='.$p['booking_id']);
                    }
                } elseif(in_array($type,['checkout.session.expired','checkout.session.async_payment_failed'],true) && $p['status']==='pending_payment'){
                    $db->prepare('UPDATE bookings SET status="expired" WHERE id=?')->execute([$p['booking_id']]);
                    $db->prepare('UPDATE stripe_payments SET state="expired" WHERE booking_id=?')->execute([$p['booking_id']]);
                }
            } else { error_log('[stripe] unknown session '.(string)($session['id']??'')); }
        }
        $db->commit();jsonOut(['received'=>true]);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally {bookingUnlock($db);}
}
function stripeStatus(): never {
    $session=(string)($_GET['session_id']??'');
    if(!preg_match('/^cs_(?:test|live)_[A-Za-z0-9_]+$/',$session)) fail('決済IDが不正です');
    $q=stripeDb()->prepare('SELECT b.status,p.state FROM stripe_payments p JOIN bookings b ON b.id=p.booking_id WHERE p.session_id=?');
    $q->execute([$session]);$r=$q->fetch();
    if(!$r)fail('決済状況が見つかりません',404);
    jsonOut(['status'=>$r['status'],'payment_state'=>$r['state']]);
}
