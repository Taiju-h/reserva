<?php
declare(strict_types=1);
// One-time/idempotent CLI initializer for the eight requested RESERVA menus.
// Run inside the hanamaru PHP container. Never changes bookings or payment data.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$vars=[];
$filename=dirname(__DIR__).'/.env';
if(!is_file($filename)){fwrite(STDERR,"Missing .env\n");exit(1);}
foreach(file($filename,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $line=trim($line);
    if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
    [$key,$val]=explode('=',$line,2);
    $vars[trim($key)]=trim($val," \t\n\r\0\x0B\"'");
}
if(($vars['DB_CONNECTION']??'')!=='mysql'){fwrite(STDERR,"MySQL configuration required\n");exit(1);}
$pdo=new PDO(
    'mysql:host='.($vars['DB_HOST']??'mysql').';port='.($vars['DB_PORT']??'3306').';dbname='.($vars['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
    $vars['DB_USERNAME']??'hanamaru',
    $vars['DB_PASSWORD']??'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$menus=[
    ['初めての転職コース',40,5500],
    ['仕事もやもや相談',40,5500],
    ['学生就活コース',30,5500],
    ['女性のキャリア相談コース',40,5500],
    ['独立・経営相談',50,5500],
    ['上田晶美に相談',50,7000],
    ['ビジネス英語力チェック',50,5500],
    ['マネー相談',50,6500], // Duration is provisional.
];
$pdo->beginTransaction();
try {
    $select=$pdo->prepare('SELECT id FROM menus WHERE name=? ORDER BY id ASC LIMIT 1');
    $create=$pdo->prepare('INSERT INTO menus (name,description,duration,price,active) VALUES(?,?,?,?,1)');
    $update=$pdo->prepare('UPDATE menus SET price=?,active=1 WHERE id=?');
    $added=0;$changed=0;
    foreach($menus as [$name,$minutes,$price]){
        $select->execute([$name]);
        $id=$select->fetchColumn();
        if($id===false){
            $description=$minutes.'分の個別相談';
            if($name==='マネー相談')$description.='（所要時間は仮設定）';
            $create->execute([$name,$description,$minutes,$price]);
            $added++;
        }else{
            // Keep existing ID, duration and description; preserve booking references.
            $update->execute([$price,$id]);
            $changed++;
        }
    }
    $pdo->commit();
    echo "Completed: created={$added}, updated={$changed}\n";
    $q=$pdo->prepare('SELECT name,duration,price,active FROM menus WHERE name=? ORDER BY id ASC LIMIT 1');
    foreach($menus as [$name]){
        $q->execute([$name]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        echo $row['name'].' / '.$row['duration'].'分 / '.$row['price'].'円 / '.((int)$row['active']?'公開':'非公開')."\n";
    }
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"Failed: ".$e->getMessage()."\n");
    exit(1);
}
