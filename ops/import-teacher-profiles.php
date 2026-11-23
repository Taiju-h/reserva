<?php
declare(strict_types=1);
/**
 * Import public instructor information from the original Hanamaru member directory.
 * Run inside the PHP container after migrate-teacher-profiles.php.
 * Details are retained exactly as extracted; blank source fields stay blank.
 * Photo URLs are validated against the source domain; images are saved locally.
 */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
  $line=trim($line);
  if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
  [$k,$v]=explode('=',$line,2);
  $env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
if(($env['DB_CONNECTION']??'')!=='mysql')exit("DB_CONNECTION must be mysql\n");
$pdo=new PDO('mysql:host='.($env['DB_HOST']??'mysql').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'hanamaru').';charset=utf8mb4',
  $env['DB_USERNAME']??'hanamaru',$env['DB_PASSWORD']??'',
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$base='https://hanamaru-souken.com';
$source=$base.'/member_list/';
$names=require $root.'/app/teacher-directory.php';
$normalize=static fn(string $name):string=>preg_replace('/[\\s\\x{3000}]+/u','',trim($name));
function fetchOrigin(string $url): ?string {
  $host=parse_url($url,PHP_URL_HOST);
  if(!in_array($host,['hanamaru-souken.com','www.hanamaru-souken.com'],true)||parse_url($url,PHP_URL_SCHEME)!=='https')return null;
  $curl=curl_init($url);
  curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
    CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>20,
    CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_USERAGENT=>'Hanamaru teacher profile migration (+https://hanamaru.uzero.style/)',
    CURLOPT_HTTPHEADER=>['Accept: text/html,image/*']]);
  $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);
  $effective=curl_getinfo($curl,CURLINFO_EFFECTIVE_URL);curl_close($curl);
  $effectiveHost=parse_url((string)$effective,PHP_URL_HOST);
  return $status===200 && in_array($effectiveHost,['hanamaru-souken.com','www.hanamaru-souken.com'],true)
    && is_string($body)&&strlen($body)<8000000?$body:null;
}
function originUrl(string $url,string $base): ?string {
  $url=trim(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));
  if($url===''||str_starts_with($url,'data:')||str_starts_with($url,'javascript:'))return null;
  if(str_starts_with($url,'//'))$url='https:'.$url;
  elseif(str_starts_with($url,'/'))$url='https://hanamaru-souken.com'.$url;
  elseif(!preg_match('~^https?://~i',$url))$url=rtrim($base,'/').'/'.$url;
  return parse_url($url,PHP_URL_SCHEME)==='https'&&
    in_array(parse_url($url,PHP_URL_HOST),['hanamaru-souken.com','www.hanamaru-souken.com'],true)?$url:null;
}
function parseHtml(string $html): DOMXPath {
  $doc=new DOMDocument();
  $old=libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="UTF-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
  libxml_clear_errors();libxml_use_internal_errors($old);
  return new DOMXPath($doc);
}
function textOf(?DOMNode $node): string {
  if(!$node)return '';
  $html=$node->ownerDocument->saveHTML($node);
  $html=preg_replace('~<br\\s*/?>|</(?:p|div|li|tr|h[1-6])>~i',"\n",$html);
  $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
  $text=preg_replace('/[ \t\x{00A0}\x{3000}]+/u',' ',$text);
  $text=preg_replace('/[ \t]*\n[ \t]*/u',"\n",$text);
  return trim(preg_replace('/\n{3,}/',"\n\n",$text));
}
function sections(DOMXPath $xp): array {
  $out=['career'=>'','qualifications_text'=>'','training'=>'','activity_area'=>'','message'=>''];
  foreach($xp->query('//tr[th or td]') as $row){
    $cells=[];
    foreach($row->childNodes as $node){if($node instanceof DOMElement&&in_array(strtolower($node->tagName),['td','th'],true))$cells[]=$node;}
    if(count($cells)<2)continue;
    $label=preg_replace('/\s+/u','',textOf($cells[0]));$value=textOf($cells[1]);
    if($value==='')continue;
    if(str_contains($label,'経歴')||str_contains($label,'略歴'))$out['career']=$value;
    elseif(str_contains($label,'資格'))$out['qualifications_text']=$value;
    elseif(str_contains($label,'担当研修')||str_contains($label,'研修テーマ'))$out['training']=$value;
    elseif(str_contains($label,'活動エリア')||str_contains($label,'お住まい'))$out['activity_area']=$value;
    elseif(str_contains($label,'メッセージ')||str_contains($label,'自己PR'))$out['message']=$value;
  }
  // Older member pages use titled H2/H3 blocks rather than profile tables.
  foreach($xp->query('//h2|//h3|//h4|//strong') as $node){
    $label=preg_replace('/\s+/u','',textOf($node));
    $field=null;
    if(preg_match('/^(経歴|略歴)$/u',$label))$field='career';
    elseif($label==='資格')$field='qualifications_text';
    elseif(preg_match('/^(担当研修|担当講座)$/u',$label))$field='training';
    elseif(preg_match('/^(活動エリア|活動エリア\/お住まい)$/u',$label))$field='activity_area';
    elseif(preg_match('/^メッセージ/u',$label))$field='message';
    if(!$field||$out[$field]!=='')continue;
    $parts=[];$sibling=$node->nextSibling;
    while($sibling && count($parts)<10){
      if($sibling instanceof DOMElement&&preg_match('/^h[1-4]$/i',$sibling->tagName))break;
      $text=textOf($sibling);
      if($text!=='')$parts[]=$text;
      $sibling=$sibling->nextSibling;
    }
    $out[$field]=trim(implode("\n",$parts));
  }
  return $out;
}
function photoFrom(DOMXPath $xp,string $url): ?string {
  // Avoid logos / nav icons; prefer member content images.
  foreach($xp->query('//main//img|//article//img|//*[contains(@class,"entry-content")]//img') as $img){
    if(!$img instanceof DOMElement)continue;
    $raw=$img->getAttribute('data-src')?:$img->getAttribute('src');
    $link=originUrl($raw,$url);
    if(!$link)continue;
    $lower=strtolower($link);
    if(preg_match('~logo|icon|banner|noimage|placeholder|youtube|avatar-default~',$lower))continue;
    return $link;
  }
  return null;
}
function findMemberLinks(DOMXPath $xp,array $names,string $base): array {
  $found=[];
  $norm=static fn($v)=>preg_replace('/[\\s\\x{3000}]+/u','',trim($v));
  $wanted=[];
  foreach($names as $name)$wanted[$norm($name)]=$name;
  // Prefer links whose own visible text is the exact instructor name.
  foreach($xp->query('//a[@href]') as $a){
    $key=$norm(textOf($a));
    if(!isset($wanted[$key]))continue;
    $url=originUrl($a->getAttribute('href'),$base);
    if($url && preg_match('~^https://(?:www\\.)?hanamaru-souken\\.com/member_list/[^?#]+~',$url)
       && rtrim($url,'/')!==rtrim($base,'/'))$found[$wanted[$key]]=$url;
  }
  // If headings contain no direct links, find their nearby link without crossing
  // into another instructor heading. Never use a whole page ancestor as context.
  foreach($xp->query('//h2|//h3|//h4') as $heading){
    $key=$norm(textOf($heading));
    if(!isset($wanted[$key])||isset($found[$wanted[$key]]))continue;
    $candidates=[];
    $nodes=[$heading,$heading->parentNode];
    foreach($nodes as $node){
      if(!$node)continue;
      foreach($xp->query('.//a[@href]',$node) as $a){
        $url=originUrl($a->getAttribute('href'),$base);
        if($url&&preg_match('~^https://(?:www\\.)?hanamaru-souken\\.com/member_list/[^?#]+~',$url)
           && rtrim($url,'/')!==rtrim($base,'/'))$candidates[$url]=true;
      }
    }
    if(count($candidates)===1)$found[$wanted[$key]]=array_key_first($candidates);
  }
  return $found;
}
function archivePhoto(DOMXPath $xp,string $name,string $url): ?string {
  $normalize=static fn(string $v):string=>preg_replace('/[\\s\\x{3000}]+/u','',trim($v));
  foreach($xp->query('//h2|//h3|//h4') as $heading){
    if($normalize(textOf($heading))!==$normalize($name))continue;
    $node=$heading;
    for($level=0;$level<3 && $node;$level++,$node=$node->parentNode){
      // Never use a container with multiple instructors: an image there is ambiguous.
      $namesHere=0;
      foreach($xp->query('.//h2|.//h3|.//h4',$node) as $h){
        if(preg_match('/^[\\p{Han}\\p{Hiragana}\\p{Katakana}A-Za-z\\s]{2,40}$/u',textOf($h)))$namesHere++;
      }
      if($namesHere>1)break;
      foreach($xp->query('.//img',$node) as $img){
        if(!$img instanceof DOMElement)continue;
        $raw=$img->getAttribute('data-src')?:$img->getAttribute('data-lazy-src')?:$img->getAttribute('src');
        $link=originUrl($raw,$url);
        if($link&&!preg_match('~logo|icon|banner|placeholder|youtube~i',$link))return $link;
      }
    }
  }
  return null;
}
function qualifications(string $raw): array {
  if($raw==='')return [];
  // Preserve exact documented labels; only split where the source presents distinct lines.
  $items=preg_split('/\n+|[、，,](?=\s*[^、，,]{4,})/u',$raw);
  $result=[];
  foreach($items as $item){
    $item=trim(preg_replace('/\s+/u',' ',$item));
    if($item!==''&&mb_strlen($item)<=250)$result[$item]=true;
  }
  return array_keys($result);
}
function qualificationCategory(string $label): string {
  if(preg_match('/キャリアコンサル|ＣＤＡ|CDA|GCDF|ディベロップメントアドバイザー|ディベロップメント・アドバイザー/iu',$label))return 'キャリア・就職支援';
  if(preg_match('/カウンセラ|心理|メンタルヘルス/iu',$label))return '心理・相談';
  if(preg_match('/ファイナンシャル|ファイナンス|FP技能士|ＡＦＰ|AFP/iu',$label))return '金融・生活設計';
  if(preg_match('/フード|食品衛生|料理|チーズ|ホームパーティー/iu',$label))return '食・生活';
  if(preg_match('/ビジネスマナー|秘書検定|マナープロトコール|インストラクター|講師/iu',$label))return '研修・マナー';
  if(preg_match('/MBA|修士|博士/iu',$label))return '学位';
  return '未分類';
}
function savePhoto(?string $url,int $id,string $root): ?string {
  if(!$url)return null;
  $data=fetchOrigin($url);
  if(!$data||strlen($data)>5000000)return null;
  $detected=(new finfo(FILEINFO_MIME_TYPE))->buffer($data);
  $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$detected]??null;
  if(!$ext)return null;
  $folder=$root.'/public/uploads/teacher-photos';
  if(!is_dir($folder)&&!mkdir($folder,0755,true)&&!is_dir($folder))return null;
  $filename=$id.'-'.substr(hash('sha256',$data),0,12).'.'.$ext;
  if(file_put_contents($folder.'/'.$filename,$data,LOCK_EX)===false)return null;
  chmod($folder.'/'.$filename,0644);
  return '/uploads/teacher-photos/'.$filename;
}
$archive=fetchOrigin($source);
if(!$archive){fwrite(STDERR,"Could not fetch member list. Nothing imported.\n");exit(1);}
$xp=parseHtml($archive);$links=findMemberLinks($xp,$names,$source);
// Fallback: some member listings do not link every individual detail page.
// Discover published member detail permalinks from the WordPress sitemap.
if(count($links)<count($names)){
  $sitemaps=[
    $base.'/wp-sitemap-posts-member_list-1.xml',
    $base.'/member_list-sitemap.xml',
    $base.'/wp-sitemap-posts-member-1.xml'
  ];
  foreach($sitemaps as $sitemap){
    if(count($links)>=count($names))break;
    $xml=fetchOrigin($sitemap);
    if(!$xml)continue;
    preg_match_all('~<loc>\\s*(https?://[^<]+)\\s*</loc>~i',$xml,$matches);
    foreach(array_unique($matches[1]) as $url){
      $url=originUrl($url,$base.'/');
      if(!$url||!preg_match('~^https://(?:www\\.)?hanamaru-souken\\.com/member_list/[^?#]+~',$url))continue;
      if(in_array($url,$links,true))continue;
      $page=fetchOrigin($url);
      if(!$page)continue;
      $pageXp=parseHtml($page);
      $headline=$pageXp->query('//h1')->item(0);
      if(!$headline)continue;
      $title=$normalize(textOf($headline));
      foreach($names as $name){
        if(!isset($links[$name])&&$normalize($name)===$title){$links[$name]=$url;break;}
      }
      if(count($links)>=count($names))break;
    }
  }
}

$select=$pdo->prepare('SELECT id FROM teachers WHERE name=? LIMIT 1');
$all=$pdo->query('SELECT id,name FROM teachers')->fetchAll(PDO::FETCH_ASSOC);
$existing=[];foreach($all as $row)$existing[$normalize($row['name'])]=(int)$row['id'];
$add=$pdo->prepare('INSERT INTO teachers(name,active) VALUES(?,1)');
$read=$pdo->prepare('SELECT teacher_id,photo_path,summary,career,qualifications_text,training,activity_area,message,source_url FROM teacher_profiles WHERE teacher_id=?');
$save=$pdo->prepare('INSERT INTO teacher_profiles
 (teacher_id,summary,career,qualifications_text,training,activity_area,message,photo_path,photo_source_url,source_url,source_checked_at)
 VALUES(?,?,?,?,?,?,?,?,?,?,NOW())
 ON DUPLICATE KEY UPDATE
 summary=IF(summary IS NULL OR summary="",VALUES(summary),summary),
 career=IF(career IS NULL OR career="",VALUES(career),career),
 qualifications_text=IF(qualifications_text IS NULL OR qualifications_text="",VALUES(qualifications_text),qualifications_text),
 training=IF(training IS NULL OR training="",VALUES(training),training),
 activity_area=IF(activity_area IS NULL OR activity_area="",VALUES(activity_area),activity_area),
 message=IF(message IS NULL OR message="",VALUES(message),message),
 photo_path=COALESCE(VALUES(photo_path),photo_path),
 photo_source_url=COALESCE(VALUES(photo_source_url),photo_source_url),
 source_url=COALESCE(VALUES(source_url),source_url),
 source_checked_at=NOW()');
$addQualification=$pdo->prepare('INSERT INTO qualification_master(name,category) VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
$linkQualification=$pdo->prepare('INSERT IGNORE INTO teacher_qualifications(teacher_id,qualification_id) VALUES(?,?)');
$stats=['teachers'=>0,'detail_pages'=>0,'profiles'=>0,'photos'=>0,'qualification_links'=>0,'missing_details'=>[]];
foreach($names as $name){
  $norm=$normalize($name);$id=$existing[$norm]??null;
  if(!$id){$add->execute([$name]);$id=(int)$pdo->lastInsertId();$existing[$norm]=$id;}
  $stats['teachers']++;
  $detailUrl=$links[$name]??null;
  $detail=$detailUrl?fetchOrigin($detailUrl):null;
  $fields=['career'=>'','qualifications_text'=>'','training'=>'','activity_area'=>'','message'=>''];
  if($detail){$fields=sections(parseHtml($detail));$stats['detail_pages']++;}
  else $stats['missing_details'][]=$name;
  // Directory gives useful short titles even when a member lacks a detail page.
  $summary='';
  foreach($xp->query('//h3') as $heading){
    if($normalize(textOf($heading))!==$norm)continue;
    $parent=$heading->parentNode;
    $raw=textOf($parent);
    $summary=trim(str_replace($name,'',$raw));
    if(mb_strlen($summary)>450)$summary=mb_substr($summary,0,450);
    break;
  }
  $photoUrl=$detail?photoFrom(parseHtml($detail),$detailUrl):null;
  if(!$photoUrl)$photoUrl=archivePhoto($xp,$name,$source);
  $photo=$photoUrl?savePhoto($photoUrl,$id,$root):null;
  $save->execute([$id,$summary,$fields['career'],$fields['qualifications_text'],$fields['training'],$fields['activity_area'],$fields['message'],$photo,$photo?$photoUrl:null,$detailUrl?:$source]);
  $stats['profiles']++;
  if($photo)$stats['photos']++;
  foreach(qualifications($fields['qualifications_text']) as $qualification){
    $addQualification->execute([$qualification,qualificationCategory($qualification)]);
    $qid=(int)$pdo->lastInsertId();
    $linkQualification->execute([$id,$qid]);
    $stats['qualification_links']++;
  }
  echo $name.': '.($detail?'詳細あり':'一覧のみ').' / '.($photo?'写真あり':'写真なし').' / 資格'.count(qualifications($fields['qualifications_text']))."件\n";
}
echo "\nRESULT ".json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
if($stats['missing_details'])echo "Some instructors have no discoverable detail-page link; source data for those is kept blank.\n";
