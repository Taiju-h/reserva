<?php
declare(strict_types=1);
// Idempotent import of the public lecturer list on https://hanamaru-souken.com/member_list/
// Checked on 2026-11-23. Only creates missing teachers; never alters existing teachers,
// menu assignments, booking records, credentials, or scheduling rules.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$root=dirname(__DIR__);
$envFile=$root.'/.env';
if(!is_file($envFile)){fwrite(STDERR,"Missing .env\n");exit(1);}
$env=[];
foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $line=trim($line);
    if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
    [$k,$v]=explode('=',$line,2);
    $env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
if(($env['DB_CONNECTION']??'')!=='mysql'){fwrite(STDERR,"DB_CONNECTION=mysql required\n");exit(1);}
$names=[
    '上田 晶美',
    '川又 けい子',
    '阿隅和美',
    '大場美由紀',
    '津村 治美',
    '嶋尾かの子',
    '斉藤あゆみ',
    '葛本幸枝',
    '川崎あゆみ',
    '吉田恵子',
    '本田 貴子',
    '中尾 あずさ',
    '吉田 明美',
    '塚本智美',
    '日向 美穂',
    '鈴木誠一郎',
    '木村亮',
    '江藤隆二',
    '内村しづ子',
    'きし なおこ',
    '吉村千鶴子',
    '山口朋子',
    '小清水ゆり',
    '近藤信治',
    '桑田真理子',
    '岡田 久美子',
    '木元栄子',
    '永井 恵子',
    '梅田昌照',
    '黒田 房子',
    '村井 美月',
    '高橋 和子',
    '松岡 真理',
    '合田 菜実子',
    '酒匂 ひろ子',
    '木下芳隆',
    'パトリック',
    '金井 優貴',
    '瀬戸裕子',
    '今泉理絵',
    '武田竹美',
    '手嶋由美子',
];
$normalize=static fn(string $name):string=>preg_replace('/[\\s\\x{3000}]+/u','',trim($name));
try{
    $pdo=new PDO('mysql:host='.($env['DB_HOST']??'mysql').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
      $env['DB_USERNAME']??'hanamaru',$env['DB_PASSWORD']??'',
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->beginTransaction();
    $existing=$pdo->query('SELECT id,name FROM teachers')->fetchAll(PDO::FETCH_ASSOC);
    $found=[];
    foreach($existing as $row)$found[$normalize((string)$row['name'])]=true;
    $insert=$pdo->prepare('INSERT INTO teachers(name,active) VALUES(?,1)');
    $added=0;$skipped=0;
    foreach($names as $name){
        $key=$normalize($name);
        if(isset($found[$key])){$skipped++;continue;}
        $insert->execute([$name]);
        $found[$key]=true;$added++;
    }
    $pdo->commit();
    echo '講師名簿から'.count($names)."名を照合: 新規 {$added}名 / 登録済み {$skipped}名\n";
    echo "先生へのメニュー割当・受付条件・予約履歴は変更していません。\n";
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"講師登録に失敗しました: ".$e->getMessage()."\n");
    exit(1);
}
