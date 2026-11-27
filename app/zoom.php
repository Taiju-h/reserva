<?php
declare(strict_types=1);
/**
 * Zoom Server-to-Server OAuth, one company-owned host.
 * Never expose credentials, OAuth tokens, start_url, or participant join URLs in logs.
 * This module is inert unless explicitly called; it does not change bookings.
 */
function zoomConfigured(array $env): bool {
    foreach(['ZOOM_ACCOUNT_ID','ZOOM_CLIENT_ID','ZOOM_CLIENT_SECRET','ZOOM_HOST_EMAIL'] as $key){
        if(trim((string)($env[$key]??''))==='')return false;
    }
    return filter_var($env['ZOOM_HOST_EMAIL'],FILTER_VALIDATE_EMAIL)!==false;
}
function zoomMeetingTopic(string $teacher,string $startsAt): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$startsAt,new DateTimeZone('Asia/Tokyo'));
    if(!$d || $d->format('Y-m-d H:i:s')!==$startsAt)throw new InvalidArgumentException('Invalid meeting start');
    $teacher=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ', $teacher)??'');
    if($teacher==='')$teacher='担当講師';
    return '丹青社｜'.mb_substr($teacher,0,70).'｜'.$d->format('m月d日 H:i');
}
function zoomAccessToken(array $env): string {
    if(!zoomConfigured($env))throw new RuntimeException('Zoom API configuration missing');
    $url='https://zoom.us/oauth/token?grant_type=account_credentials&account_id='.rawurlencode($env['ZOOM_ACCOUNT_ID']);
    $ch=curl_init($url);
    if($ch===false)throw new RuntimeException('Zoom OAuth connection failed');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',
        CURLOPT_USERPWD=>$env['ZOOM_CLIENT_ID'].':'.$env['ZOOM_CLIENT_SECRET'],
        CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json=is_string($body)?json_decode($body,true):null;
    if($status!==200||!is_array($json)||empty($json['access_token']))
        throw new RuntimeException('Zoom OAuth failed (HTTP '.$status.')');
    return (string)$json['access_token'];
}
function zoomApi(array $env,string $method,string $path,?array $data=null): array {
    if(!in_array($method,['GET','POST','PATCH','DELETE'],true) ||
       !preg_match('~^/users/[^/?]+/meetings$|^/meetings/[0-9]+$~D',$path))
        throw new InvalidArgumentException('Unsupported Zoom endpoint');
    $token=zoomAccessToken($env);
    $ch=curl_init('https://api.zoom.us/v2'.$path);
    if($ch===false)throw new RuntimeException('Zoom API connection failed');
    $opts=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json']];
    if($data!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch,$opts);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($status<200||$status>=300)
        throw new RuntimeException('Zoom meeting API failed (HTTP '.$status.')');
    if($status===204||$body===''||$body===false)return [];
    $json=json_decode($body,true);
    if(!is_array($json))throw new RuntimeException('Zoom meeting API returned invalid JSON');
    return $json;
}
function zoomCreateMeeting(array $env,string $teacher,string $startsAt): array {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$startsAt,new DateTimeZone('Asia/Tokyo'));
    if(!$d||$d->format('Y-m-d H:i:s')!==$startsAt)throw new InvalidArgumentException('Invalid meeting start');
    $data=[
        'topic'=>zoomMeetingTopic($teacher,$startsAt),
        'type'=>2,
        'start_time'=>$d->format('Y-m-d\TH:i:s'),
        'timezone'=>'Asia/Tokyo',
        'duration'=>50,
        'settings'=>[
            'waiting_room'=>true,
            'join_before_host'=>false,
            'approval_type'=>2,
            'meeting_authentication'=>false
        ],
    ];
    $result=zoomApi($env,'POST','/users/'.rawurlencode($env['ZOOM_HOST_EMAIL']).'/meetings',$data);
    if(empty($result['id'])||empty($result['join_url']))
        throw new RuntimeException('Zoom meeting creation response incomplete');
    return [
        'meeting_id'=>(string)$result['id'],
        'join_url'=>(string)$result['join_url'],
        'start_url'=>(string)($result['start_url']??''),
    ];
}
function zoomDeleteMeeting(array $env,string $meetingId): void {
    if(!preg_match('/^[0-9]{8,20}$/D',$meetingId))throw new InvalidArgumentException('Invalid Zoom meeting ID');
    zoomApi($env,'DELETE','/meetings/'.$meetingId);
}

/**
 * Admin-only diagnostic; never returns secrets, token, meeting links or start_url.
 * Credentials remain in the server-side .env.
 */
function zoomAdminRoute(string $action,string $method): void {
    if($action!=='zoomConfigStatus' || $method!=='GET')return;
    requireMasterAdmin();
    global $env;
    $configured=zoomConfigured($env);
    $host=(string)($env['ZOOM_HOST_EMAIL']??'');
    $max=max(1,(int)($env['ZOOM_MAX_CONCURRENT_MEETINGS']??1));
    $result=['configured'=>$configured,'host_email'=>$host,
        'max_concurrent_meetings'=>$max,'api_implemented'=>true,
        'booking_integration_enabled'=>false];
    if(($_GET['probe']??'')==='1'){
        if(!$configured){$result['connection']='not_configured';}
        else {
            try{
                zoomAccessToken($env);
                $result['connection']='authenticated';
            }catch(Throwable $e){
                error_log('[zoom] admin connection check failed');
                $result['connection']='failed';
            }
        }
    }
    jsonOut($result);
}
