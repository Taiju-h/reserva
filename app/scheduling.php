<?php
declare(strict_types=1);
// Scheduling layers: store > teacher > menu. Denials at ANY matching layer win.
// All rules are interpreted in the configured Asia/Tokyo time zone.
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
