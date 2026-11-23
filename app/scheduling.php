<?php
declare(strict_types=1);
// Scheduling layers: store > teacher > menu. Denials at ANY matching layer win.
// All rules are interpreted in the configured Asia/Tokyo time zone.
function ensureTeacherDirectoryTable(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS teachers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
function normalizeTeacherName(string $name): string {
    return preg_replace('/[\\s\\x{3000}]+/u','',trim($name));
}
function teacherDirectoryRoute(string $action,string $method): void {
    if($action==='teacherDirectory' && $method==='GET'){
        requireAdmin();
        ensureTeacherDirectoryTable();
        $rows=db()->query('SELECT t.id,t.name,t.active,t.created_at,
           CASE WHEN p.teacher_id IS NULL THEN 0 ELSE 1 END AS profile_registered,
           CASE WHEN p.photo_path IS NULL OR p.photo_path="" THEN 0 ELSE 1 END AS has_photo,
           CASE WHEN p.career IS NULL OR p.career="" THEN 0 ELSE 1 END AS has_career,
           (SELECT COUNT(*) FROM teacher_qualifications tq WHERE tq.teacher_id=t.id) AS qualification_count
           FROM teachers t LEFT JOIN teacher_profiles p ON p.teacher_id=t.id ORDER BY t.id')->fetchAll();
        $statistics=['profiles'=>0,'photos'=>0,'careers'=>0,'qualifications'=>0];
        foreach($rows as $t){
           $statistics['profiles']+=(int)$t['profile_registered'];
           $statistics['photos']+=(int)$t['has_photo'];
           $statistics['careers']+=(int)$t['has_career'];
           $statistics['qualifications']+=(int)$t['qualification_count'];
        }
        jsonOut(['teachers'=>$rows,'statistics'=>$statistics,
          'reference_count'=>count(require __DIR__.'/teacher-directory.php'),'master'=>isMasterAdmin()]);
    }
    if($action==='teacherBulkImport' && $method==='POST'){
        requireMasterAdmin();checkCsrf();ensureTeacherDirectoryTable();
        $names=require __DIR__.'/teacher-directory.php';
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $existing=$pdo->query('SELECT name FROM teachers')->fetchAll(PDO::FETCH_COLUMN);
            $known=[];
            foreach($existing as $name) $known[normalizeTeacherName((string)$name)]=true;
            $insert=$pdo->prepare('INSERT INTO teachers(name,active) VALUES(?,1)');
            $added=0;
            foreach($names as $name){
                $key=normalizeTeacherName($name);
                if(isset($known[$key]))continue;
                $insert->execute([$name]);
                $known[$key]=true;
                $added++;
            }
            $pdo->commit();
            jsonOut(['ok'=>true,'added'=>$added,'already_registered'=>count($names)-$added,'reference_count'=>count($names)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
function scheduleTeachers(): array {
    return db()->query('SELECT id,name,active FROM teachers ORDER BY id')->fetchAll();
}
function scheduleRules(): array {
    return db()->query("SELECT id,scope,target_id,decision,label,start_date,end_date,weekdays,
       TIME_FORMAT(start_time,'%H:%i') AS start_time,TIME_FORMAT(end_time,'%H:%i') AS end_time,enabled
       FROM schedule_rules ORDER BY scope,target_id,id DESC")->fetchAll();
}
function scheduleApplies(array $rule,string $date): bool {
    if($rule['start_date']!==null && $date<$rule['start_date'])return false;
    if($rule['end_date']!==null && $date>$rule['end_date'])return false;
    $weekday=(string)(new DateTimeImmutable($date))->format('w');
    return in_array($weekday,explode(',',(string)$rule['weekdays']),true);
}
function scheduleAllows(int $menuId,?int $teacherId,string $date,string $from,string $until): bool {
    static $cache=[];
    $key=$menuId.':'.($teacherId??'none').':'.$date;
    if(!isset($cache[$key])){
        $query=db()->prepare("SELECT scope,target_id,decision,start_date,end_date,weekdays,
            TIME_FORMAT(start_time,'%H:%i') AS start_time,TIME_FORMAT(end_time,'%H:%i') AS end_time
            FROM schedule_rules
            WHERE enabled=1 AND (
                scope='store'
                OR (scope='teacher' AND target_id=?)
                OR (scope='menu' AND target_id=?)
            )");
        $query->execute([$teacherId??0,$menuId]);
        $cache[$key]=array_values(array_filter($query->fetchAll(),fn($rule)=>scheduleApplies($rule,$date)));
    }
    $rules=$cache[$key];
    // Any matching prohibition blocks the full booking interval, including partial overlaps.
    foreach($rules as $rule) {
        if($rule['decision']==='deny' && $from<$rule['end_time'] && $until>$rule['start_time'])return false;
    }
    // Per layer, if one or more allowance windows apply to the date, the interval must be
    // fully contained in at least one of them. An unconfigured layer inherits its parent.
    foreach(['store','teacher','menu'] as $scope){
        $allows=array_values(array_filter($rules,fn($r)=>$r['scope']===$scope && $r['decision']==='allow'));
        if(!$allows)continue;
        $inside=false;
        foreach($allows as $r){if($from>=$r['start_time'] && $until<=$r['end_time']){$inside=true;break;}}
        if(!$inside)return false;
    }
    return true;
}
function scheduleRoute(string $action,string $method): void {
    teacherDirectoryRoute($action,$method);
    if($action==='scheduleExplain' && $method==='GET'){
        requireAdmin();
        $date=(string)($_GET['date']??'');$menuId=(int)($_GET['menu_id']??0);
        if(!dateValid($date)||$menuId<1)fail('日付とメニューを指定してください');
        $q=db()->prepare('SELECT m.id,m.name,m.duration,m.active,m.teacher_id,t.name AS teacher_name,t.active AS teacher_active FROM menus m LEFT JOIN teachers t ON t.id=m.teacher_id WHERE m.id=?');
        $q->execute([$menuId]);$menu=$q->fetch();
        if(!$menu)fail('メニューが見つかりません',404);
        $settings=prefs();$reasons=[];
        $weekday=(string)(new DateTimeImmutable($date))->format('w');
        $closed=array_filter(explode(',',(string)($settings['closed_weekdays']??'')),fn($x)=>$x!=='');
        $dates=array_map('trim',explode(',',(string)($settings['closed_dates']??'')));
        if(in_array($weekday,$closed,true))$reasons[]=['scope'=>'store','status'=>'deny','text'=>'店舗の定休日に指定された曜日です'];
        if(in_array($date,$dates,true))$reasons[]=['scope'=>'store','status'=>'deny','text'=>'店舗の臨時休業日に指定されています'];
        if(!(int)$menu['active'])$reasons[]=['scope'=>'menu','status'=>'deny','text'=>'このメニューは非公開です'];
        if($menu['teacher_id']!==null && !(int)$menu['teacher_active'])$reasons[]=['scope'=>'teacher','status'=>'deny','text'=>'担当の先生が停止中です'];
        $tz=new DateTimeZone(date_default_timezone_get());
        $day=new DateTimeImmutable($date.' 00:00:00',$tz);$today=new DateTimeImmutable('today',$tz);
        if($day<$today)$reasons[]=['scope'=>'store','status'=>'deny','text'=>'過去の日付です'];
        if($day>$today->modify('+'.(int)($settings['max_advance_days']??90).' days'))$reasons[]=['scope'=>'store','status'=>'deny','text'=>'予約受付可能な日数を超えています'];
        $times=(int)$menu['active']?availability($menuId,$date):[];
        $rules=[];
        foreach(scheduleRules() as $rule){
            if(!(int)$rule['enabled']||!scheduleApplies($rule,$date))continue;
            if($rule['scope']==='teacher' && (int)$rule['target_id']!==(int)$menu['teacher_id'])continue;
            if($rule['scope']==='menu' && (int)$rule['target_id']!==$menuId)continue;
            $rules[]=[
              'scope'=>$rule['scope'],'decision'=>$rule['decision'],
              'label'=>$rule['label']?:($rule['decision']==='deny'?'受付禁止条件':'受付許可条件'),
              'start_time'=>$rule['start_time'],'end_time'=>$rule['end_time']
            ];
        }
        if(!$times && !$reasons){
            if(!$rules)$reasons[]=['scope'=>'bookings','status'=>'info','text'=>'受付可能枠がありません。営業時間、予約済み枠、予約締切時間をご確認ください'];
            else $reasons[]=['scope'=>'bookings','status'=>'info','text'=>'条件適用後の空き枠がありません。既存予約や受付締切時間も影響します'];
        }
        if($times)$reasons[]=['scope'=>'bookings','status'=>'allow','text'=>'受付可能な時間が'.count($times).'枠あります'];
        jsonOut(['date'=>$date,'menu'=>$menu['name'],'teacher'=>$menu['teacher_name'],'times'=>$times,'reasons'=>$reasons,'rules'=>$rules,
          'store_hours'=>($settings['open_time']??'07:00').'〜'.($settings['close_time']??'22:00')]);
    }
    if($action==='schedulingData' && $method==='GET'){
        requireAdmin();
        jsonOut(['teachers'=>scheduleTeachers(),'rules'=>scheduleRules(),
            'menus'=>db()->query('SELECT id,name,teacher_id,active FROM menus ORDER BY id')->fetchAll()]);
    }
    if($action==='teacherSave' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();
        $id=(int)($v['id']??0);$name=trim((string)($v['name']??''));
        if($name===''||mb_strlen($name)>100)fail('先生の名前を1～100文字で入力してください');
        if($id>0){
            $q=db()->prepare('UPDATE teachers SET name=? WHERE id=?');$q->execute([$name,$id]);
            if($q->rowCount()===0){$q=db()->prepare('SELECT id FROM teachers WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())fail('先生が見つかりません',404);}
        }else{
            $q=db()->prepare('INSERT INTO teachers(name) VALUES(?)');$q->execute([$name]);
        }
        jsonOut(['ok'=>true]);
    }
    if($action==='teacherStatus' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();$id=(int)($v['id']??0);$active=(int)($v['active']??-1);
        if($id<1||!in_array($active,[0,1],true))fail('先生の指定が不正です');
        $q=db()->prepare('UPDATE teachers SET active=? WHERE id=?');$q->execute([$active,$id]);
        jsonOut(['ok'=>true]);
    }
    if($action==='assignMenuTeacher' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();$id=(int)($v['menu_id']??0);$teacher=(int)($v['teacher_id']??0);
        if($id<1||$teacher<0)fail('メニューまたは先生が不正です');
        if($teacher>0){$q=db()->prepare('SELECT id FROM teachers WHERE id=? AND active=1');$q->execute([$teacher]);if(!$q->fetchColumn())fail('有効な先生を選んでください');}
        $q=db()->prepare('UPDATE menus SET teacher_id=? WHERE id=?');$q->execute([$teacher?:null,$id]);
        jsonOut(['ok'=>true]);
    }
    if($action==='scheduleRuleSave' && $method==='POST'){
        requireAdmin();checkCsrf();$v=body();
        $scope=(string)($v['scope']??'');$decision=(string)($v['decision']??'');
        $target=(int)($v['target_id']??0);$label=trim((string)($v['label']??''));
        $from=(string)($v['start_date']??'');$until=(string)($v['end_date']??'');
        $start=(string)($v['start_time']??'');$end=(string)($v['end_time']??'');
        $weekdays=$v['weekdays']??[];
        if(!in_array($scope,['store','teacher','menu'],true)||!in_array($decision,['allow','deny'],true))fail('対象または受付条件が不正です');
        if($scope==='store')$target=0;
        if($scope!=='store' && $target<1)fail('先生またはメニューを選択してください');
        if(mb_strlen($label)>120)fail('条件名は120文字以内です');
        if(($from!==''&&!dateValid($from))||($until!==''&&!dateValid($until))||($from!==''&&$until!==''&&$from>$until))fail('期間指定が不正です');
        if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$start)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$end)||$start>=$end)fail('開始時刻は終了時刻より前にしてください');
        if(!is_array($weekdays))fail('曜日の指定が不正です');
        $weekdays=array_values(array_unique(array_map('strval',$weekdays)));
        sort($weekdays);
        if(!$weekdays||count($weekdays)>7||array_diff($weekdays,['0','1','2','3','4','5','6']))fail('曜日を1つ以上選択してください');
        if($scope==='teacher'){$q=db()->prepare('SELECT id FROM teachers WHERE id=?');$q->execute([$target]);if(!$q->fetchColumn())fail('先生が見つかりません');}
        if($scope==='menu'){$q=db()->prepare('SELECT id FROM menus WHERE id=?');$q->execute([$target]);if(!$q->fetchColumn())fail('メニューが見つかりません');}
        $q=db()->prepare('INSERT INTO schedule_rules(scope,target_id,decision,label,start_date,end_date,weekdays,start_time,end_time)
            VALUES(?,?,?,?,?,?,?,?,?)');
        $q->execute([$scope,$target?:null,$decision,$label,$from?:null,$until?:null,implode(',',$weekdays),$start.':00',$end.':00']);
        jsonOut(['ok'=>true]);
    }
    if($action==='scheduleRuleDelete' && $method==='POST'){
        requireAdmin();checkCsrf();$id=(int)(body()['id']??0);if($id<1)fail('条件IDが不正です');
        $q=db()->prepare('DELETE FROM schedule_rules WHERE id=?');$q->execute([$id]);
        jsonOut(['ok'=>true]);
    }
}
