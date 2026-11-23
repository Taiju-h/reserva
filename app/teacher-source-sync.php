<?php
declare(strict_types=1);

/**
 * Incremental WordPress member import (one teacher per request).
 * Profiles and photos must match the source member name before they are saved.
 */
function syncMemberTable(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS teacher_photo_assets (
        teacher_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        mime VARCHAR(32) NOT NULL,
        image_bytes MEDIUMBLOB NOT NULL,
        original_url TEXT NULL,
        imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_teacher_photo_asset FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
function syncMemberFetch(string $url,int $max=5000000): ?string {
    if(parse_url($url,PHP_URL_SCHEME)!=='https' ||
       !in_array(parse_url($url,PHP_URL_HOST),['hanamaru-souken.com','www.hanamaru-souken.com'],true))return null;
    $h=curl_init($url);
    curl_setopt_array($h,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>2,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_MAXFILESIZE=>$max,
        CURLOPT_USERAGENT=>'Hanamaru member data importer'
    ]);
    $data=curl_exec($h);
    $status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);
    $actual=(string)curl_getinfo($h,CURLINFO_EFFECTIVE_URL);
    curl_close($h);
    if($status!==200||!is_string($data)||strlen($data)<1||strlen($data)>$max)return null;
    if(parse_url($actual,PHP_URL_SCHEME)!=='https' ||
       !in_array(parse_url($actual,PHP_URL_HOST),['hanamaru-souken.com','www.hanamaru-souken.com'],true))return null;
    return $data;
}
function syncMemberUrl(string $url,string $base): ?string {
    $url=trim(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if($url===''||str_starts_with($url,'data:')||str_starts_with($url,'javascript:'))return null;
    if(str_starts_with($url,'//'))$url='https:'.$url;
    elseif(str_starts_with($url,'/'))$url='https://hanamaru-souken.com'.$url;
    elseif(!preg_match('~^https?://~i',$url))$url=rtrim(dirname($base),'/').'/'.$url;
    if(preg_match('~^http://(?:www\\.)?hanamaru-souken\\.com/~i',$url))$url='https://'.substr($url,7);
    return parse_url($url,PHP_URL_SCHEME)==='https' &&
      in_array(parse_url($url,PHP_URL_HOST),['hanamaru-souken.com','www.hanamaru-souken.com'],true)?$url:null;
}
function syncMemberDom(string $html): DOMXPath {
    $d=new DOMDocument();
    $previous=libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    libxml_clear_errors();libxml_use_internal_errors($previous);
    return new DOMXPath($d);
}
function syncMemberText(?DOMNode $n): string {
    if(!$n)return '';
    $html=$n->ownerDocument->saveHTML($n);
    $html=preg_replace('~<br\s*/?>|</(?:p|div|li|tr)>~i',"\n",$html);
    $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=preg_replace('/[ \t\x{00A0}\x{3000}]+/u',' ',$text);
    return trim(preg_replace('/\n{3,}/',"\n\n",$text));
}
function syncMemberKey(string $name): string {
    return preg_replace('/[\s\x{3000}]+/u','',trim($name));
}
function syncMemberFields(DOMXPath $xpath): array {
    $fields=['career'=>'','qualifications_text'=>'','training'=>'','activity_area'=>'','message'=>''];
    foreach($xpath->query('//tr[th or td]') as $tr){
        $cells=[];
        foreach($tr->childNodes as $child)if($child instanceof DOMElement &&
            in_array(strtolower($child->tagName),['th','td'],true))$cells[]=$child;
        if(count($cells)<2)continue;
        $title=syncMemberKey(syncMemberText($cells[0]));
        $value=syncMemberText($cells[1]);
        if($value==='')continue;
        if(str_contains($title,'経歴')||str_contains($title,'略歴'))$fields['career']=$value;
        elseif(str_contains($title,'資格'))$fields['qualifications_text']=$value;
        elseif(str_contains($title,'担当研修'))$fields['training']=$value;
        elseif(str_contains($title,'活動エリア')||str_contains($title,'お住まい'))$fields['activity_area']=$value;
        elseif(str_contains($title,'メッセージ'))$fields['message']=$value;
    }
    // Some member pages have standalone "略歴" and "資格" paragraphs.
    foreach($xpath->query('//h2|//h3|//h4|//strong|//b') as $node){
        $label=syncMemberKey(syncMemberText($node));
        $key=match(true){
            $label==='経歴'||$label==='略歴'=>'career',
            $label==='資格'=>'qualifications_text',
            $label==='担当研修'=>'training',
            str_starts_with($label,'活動エリア')=>'activity_area',
            str_starts_with($label,'メッセージ')=>'message',
            default=>null
        };
        if($key===null||$fields[$key]!=='')continue;
        $container=$node->parentNode;
        if(!$container)continue;
        $labelText=syncMemberText($node);
        $whole=syncMemberText($container);
        if($whole!==$labelText && str_starts_with($whole,$labelText)){
            $fields[$key]=trim(mb_substr($whole,mb_strlen($labelText)));
            if($fields[$key]!=='')continue;
        }
        $parts=[];
        for($next=$container->nextSibling;$next && count($parts)<10;$next=$next->nextSibling){
            if($next instanceof DOMElement && preg_match('/^h[1-5]$/i',$next->tagName))break;
            $v=syncMemberText($next);
            if($v==='')continue;
            if(in_array(syncMemberKey($v),['経歴','略歴','資格','実績','担当研修','活動エリア','メッセージ'],true))break;
            $parts[]=$v;
        }
        $fields[$key]=implode("\n",$parts);
    }
    return $fields;
}
function syncMemberPictures(DOMXPath $xpath,string $url): array {
    $candidates=[];
    // Older member templates put headshots outside article/main.
    foreach($xpath->query('//article//img|//main//img|//*[contains(@class,"entry-content")]//img|(//h1/following::img)[position()<=5]') as $img){
        if(!$img instanceof DOMElement)continue;
        $raws=[];
        foreach(['data-src','data-lazy-src','data-original','src'] as $attr){
            $v=$img->getAttribute($attr);if($v!=='')$raws[]=$v;
        }
        foreach(['data-srcset','srcset'] as $attr){
            foreach(explode(',',$img->getAttribute($attr)) as $part){
                $parts=preg_split('/\s+/',trim($part));
                if(!empty($parts[0]))$raws[]=$parts[0];
            }
        }
        foreach($raws as $raw){
            $src=syncMemberUrl($raw,$url);
            if(!$src||!preg_match('~\.(?:jpe?g|png|webp)(?:\?|$)~i',$src))continue;
            if(preg_match('~logo|icon|banner|placeholder|sprite|noimage|favicon~i',$src))continue;
            $score=1;
            if(str_contains($src,'/uploads/'))$score+=3;
            if((int)$img->getAttribute('width')>=120)$score+=2;
            if((int)$img->getAttribute('height')>=120)$score+=2;
            $alt=$img->getAttribute('alt');
            if($alt!==''&&preg_match('/先生|講師|プロフィール/u',$alt))$score+=4;
            $candidates[$src]=max($score,$candidates[$src]??0);
        }
    }
    arsort($candidates);
    return array_keys($candidates);
}
function syncMemberArchivePortrait(DOMXPath $xpath,string $name,string $listingUrl): array {
    $expected=syncMemberKey($name);
    foreach($xpath->query('//h2|//h3|//h4') as $heading){
        if(syncMemberKey(syncMemberText($heading))!==$expected)continue;
        // Find the smallest card with a single member heading and a single uploaded picture.
        $parent=$heading->parentNode;
        for($level=0;$level<3 && $parent instanceof DOMElement;$level++,$parent=$parent->parentNode){
            $headings=$xpath->query('.//h2|.//h3|.//h4',$parent);
            $matched=0;$other=0;
            foreach($headings as $h){
                if(syncMemberKey(syncMemberText($h))===$expected)$matched++;
                elseif(mb_strlen(syncMemberText($h))<=40)$other++;
            }
            if($matched!==1||$other>0)continue;
            $photos=[];
            foreach($xpath->query('.//img',$parent) as $img){
                $url=syncMemberUrl(
                    $img->getAttribute('data-src')?:$img->getAttribute('data-lazy-src')?:$img->getAttribute('src'),
                    $listingUrl
                );
                if(!$url||!preg_match('~\.(?:jpg|jpeg|png|webp)(?:\?|$)~i',$url)||
                   preg_match('~logo|icon|banner|placeholder|sprite|noimage|favicon~i',$url))continue;
                $photos[$url]=true;
            }
            // Multiple possible portraits: refuse to guess which one belongs to the person.
            if(count($photos)===1)return array_keys($photos);
        }
    }
    return [];
}
function syncMemberQualifications(string $raw): array {
    $out=[];
    foreach(preg_split('/\n+|[、，,](?=\s*[^、，,]{4,})/u',$raw) as $row){
        $value=trim(preg_replace('/\s+/u',' ',$row));
        if($value!=='' && mb_strlen($value)<=250)$out[$value]=true;
    }
    return array_keys($out);
}
function syncMemberOne(int $index): array {
    $names=require __DIR__.'/teacher-directory.php';
    if($index<0||$index>=count($names))throw new InvalidArgumentException('講師番号が範囲外です');
    $name=$names[$index];
    $q=db()->prepare('SELECT id FROM teachers WHERE name=?');
    $q->execute([$name]);
    $id=(int)$q->fetchColumn();
    if(!$id){
        // Existing imports may differ only by Japanese spaces.
        foreach(db()->query('SELECT id,name FROM teachers') as $t){
            if(syncMemberKey($t['name'])===syncMemberKey($name)){$id=(int)$t['id'];break;}
        }
    }
    if(!$id)throw new RuntimeException('先生が未登録です: '.$name);
    syncMemberTable();
    $listing='https://hanamaru-souken.com/member_list/';
    $html=syncMemberFetch($listing,4000000);
    if(!$html)throw new RuntimeException('掲載元の講師一覧に接続できません');
    $links=[];
    $page=syncMemberDom($html);
    foreach($page->query('//a[@href]') as $link){
        $key=syncMemberKey(syncMemberText($link));
        if($key!==syncMemberKey($name))continue;
        $url=syncMemberUrl($link->getAttribute('href'),$listing);
        if($url && str_contains($url,'/member_list/') && rtrim($url,'/')!==rtrim($listing,'/')){
            $links[]=$url;
        }
    }
    // Candidate permalink hints; each destination must pass the exact member-name heading check.
    $paths=[
      '上田 晶美'=>'ueda/',
      '川又 けい子'=>'k_kawamata/',
      '阿隅和美'=>'k_asumi/',
      '津村 治美'=>'h_tumura/',
      '斉藤あゆみ'=>'a_saitou/',
      '葛本幸枝'=>'s_kudumoto-2/',
      '川崎あゆみ'=>'%E5%B7%9D%E5%B4%8E%E3%81%82%E3%82%86%E3%81%BF/',
      '吉田恵子'=>'k_yoshida/',
      '鈴木誠一郎'=>'%E9%88%B4%E6%9C%A8%E8%AA%A0%E4%B8%80%E9%83%8E/',
      '本田 貴子'=>'%E6%9C%AC%E7%94%B0%E3%80%80%E8%B2%B4%E5%AD%90/',
      '中尾 あずさ'=>'nakao/',
      '吉田 明美'=>'yoshida/',
      '塚本智美'=>'tsukamoto/',
      '日向 美穂'=>'%E6%97%A5%E5%90%91-%E7%BE%8E%E7%A9%82/',
      '江藤隆二'=>'%E6%B1%9F%E8%97%A4/',
      '内村しづ子'=>'s_uchimura/',
      '山口朋子'=>'%E5%B1%B1%E5%8F%A3%E6%9C%8B%E5%AD%90/',
      '小清水ゆり'=>'%E5%B0%8F%E6%B8%85%E6%B0%B4%E3%82%86%E3%82%8A/',
      '近藤信治'=>'%E8%BF%91%E8%97%A4%E4%BF%A1%E6%B2%BB/',
      '桑田真理子'=>'m_kuwata/',
      '木元栄子'=>'e_kimoto/',
      '永井 恵子'=>'k_nagai/',
      '梅田昌照'=>'m_umeda/',
      '酒匂 ひろ子'=>'%E9%85%92%E5%8C%82-%E3%81%B2%E3%82%8D%E5%AD%90/',
      '木下芳隆'=>'kinoyoshi/',
      '松岡 真理'=>'4197-2/',
      '金井 優貴'=>'kanai/',
      '手嶋由美子'=>'yumiko-teduk/',
      '村井 美月'=>'murai/',
      '吉村千鶴子'=>'%E5%90%89%E6%9D%91%E5%8D%83%E9%B6%B4%E5%AD%90/',
      '木村亮'=>'%E6%9C%A8%E6%9D%91%E4%BA%AE/',
    ];
    if(isset($paths[$name]))$links[]=$listing.$paths[$name];
    $links=array_values(array_unique($links));
    $detail=null;$sourceUrl=null;
    foreach($links as $url){
        $html=syncMemberFetch($url,4000000);
        if(!$html)continue;
        $xpath=syncMemberDom($html);
        $title=$xpath->query('//h1')->item(0);
        if(!$title||syncMemberKey(syncMemberText($title))!==syncMemberKey($name))continue;
        $detail=$xpath;$sourceUrl=$url;break;
    }
    if(!$detail){
        // Keep the summary/qualification data migrated from the verified directory.
        // A source portrait may still be available in that instructor's dedicated card.
        $pictureUrls=syncMemberArchivePortrait($page,$name,$listing);
        $photoSaved=false;$photoReason='一覧の本人枠に写真が確認できません';
        foreach($pictureUrls as $photoUrl){
            $bytes=syncMemberFetch($photoUrl,4000000);
            if(!$bytes){$photoReason='一覧写真の取得に失敗';continue;}
            $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){$photoReason='取得した画像の形式を確認できません';continue;}
            $photo=db()->prepare('INSERT INTO teacher_photo_assets(teacher_id,mime,image_bytes,original_url)
                VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE
                mime=VALUES(mime),image_bytes=VALUES(image_bytes),original_url=VALUES(original_url),imported_at=NOW()');
            $photo->bindValue(1,$id,PDO::PARAM_INT);$photo->bindValue(2,$mime);
            $photo->bindValue(3,$bytes,PDO::PARAM_LOB);$photo->bindValue(4,$photoUrl);
            $photo->execute();
            $q=db()->prepare('INSERT INTO teacher_profiles(teacher_id,photo_path,photo_source_url)
                VALUES(?,?,?) ON DUPLICATE KEY UPDATE
                photo_path=IF(COALESCE(photo_path,"")="",VALUES(photo_path),photo_path),
                photo_source_url=IF(COALESCE(photo_source_url,"")="",VALUES(photo_source_url),photo_source_url)');
            $q->execute([$id,'/?action=teacherPhoto&id='.$id,$photoUrl]);
            $photoSaved=true;$photoReason='一覧の本人写真から取得';
            break;
        }
        return ['name'=>$name,'ok'=>false,'status'=>'詳細ページ未確認。概要・資格は名簿から補完済み',
            'career'=>false,'photo'=>$photoSaved,'photo_status'=>$photoReason,'qualifications'=>0];
    }
    $fields=syncMemberFields($detail);
    $present=0;
    foreach($fields as $value)if($value!=='')$present++;
    $photoSaved=false;$photoReason='画像URLが見つかりません';
    foreach(array_slice(syncMemberPictures($detail,$sourceUrl),0,3) as $photoUrl){
        $bytes=syncMemberFetch($photoUrl,4000000);
        if(!$bytes){$photoReason='画像ダウンロード失敗';continue;}
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){$photoReason='画像形式が不正';continue;}
        $photo=db()->prepare('INSERT INTO teacher_photo_assets(teacher_id,mime,image_bytes,original_url)
          VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE
            mime=VALUES(mime),image_bytes=VALUES(image_bytes),original_url=VALUES(original_url),imported_at=NOW()');
        $photo->bindValue(1,$id,PDO::PARAM_INT);$photo->bindValue(2,$mime);
        $photo->bindValue(3,$bytes,PDO::PARAM_LOB);$photo->bindValue(4,$photoUrl);
        $photo->execute();
        $photoSaved=true;$photoReason='写真保存成功';
        break;
    }
    // Do not replace manually written profile fields with source data.
    $save=db()->prepare('INSERT INTO teacher_profiles
      (teacher_id,career,qualifications_text,training,activity_area,message,source_url,source_checked_at,photo_path,photo_source_url)
      VALUES(?,?,?,?,?,?,?,NOW(),?,?)
      ON DUPLICATE KEY UPDATE
        career=IF(COALESCE(career,"")="",VALUES(career),career),
        qualifications_text=IF(COALESCE(qualifications_text,"")="",VALUES(qualifications_text),qualifications_text),
        training=IF(COALESCE(training,"")="",VALUES(training),training),
        activity_area=IF(COALESCE(activity_area,"")="",VALUES(activity_area),activity_area),
        message=IF(COALESCE(message,"")="",VALUES(message),message),
        source_url=VALUES(source_url),source_checked_at=NOW(),
        photo_path=IF(COALESCE(photo_path,"")="",VALUES(photo_path),photo_path),
        photo_source_url=IF(COALESCE(photo_source_url,"")="",VALUES(photo_source_url),photo_source_url)');
    $save->execute([$id,$fields['career'],$fields['qualifications_text'],$fields['training'],
      $fields['activity_area'],$fields['message'],$sourceUrl,
      $photoSaved?'/?action=teacherPhoto&id='.$id:null,null]);
    $qualCount=0;
    $create=db()->prepare('INSERT INTO qualification_master(name,category)
      VALUES(?,"未分類") ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
    $rel=db()->prepare('INSERT IGNORE INTO teacher_qualifications(teacher_id,qualification_id) VALUES(?,?)');
    foreach(syncMemberQualifications($fields['qualifications_text']) as $qual){
        $create->execute([$qual]);
        $rel->execute([$id,(int)db()->lastInsertId()]);
        $qualCount++;
    }
    return ['name'=>$name,'ok'=>true,'status'=>$present?'詳細取得':'詳細ページに対象項目なし',
      'career'=>$fields['career']!=='','photo'=>$photoSaved,'photo_status'=>$photoReason,
      'qualifications'=>$qualCount,'url'=>$sourceUrl];
}
function syncMemberRoute(string $action,string $method): void {
    if($action==='teacherSourceStatus' && $method==='GET'){
        requireMasterAdmin();
        syncMemberTable();
        $names=require __DIR__.'/teacher-directory.php';
        $rows=db()->query('SELECT t.id,t.name,
          COALESCE(p.career,"") AS career,
          COALESCE(p.photo_path,"") AS photo_path,
          (SELECT COUNT(*) FROM teacher_photo_assets a WHERE a.teacher_id=t.id) AS asset_count,
          (SELECT COUNT(*) FROM teacher_qualifications q WHERE q.teacher_id=t.id) AS qualifications
          FROM teachers t LEFT JOIN teacher_profiles p ON p.teacher_id=t.id')->fetchAll(PDO::FETCH_ASSOC);
        $byName=[];
        foreach($rows as $row)$byName[syncMemberKey($row['name'])]=$row;
        $result=[];$photos=0;$careers=0;$links=0;$missing=0;
        foreach($names as $i=>$name){
            $r=$byName[syncMemberKey($name)]??null;
            $hasPhoto=$r && ($r['photo_path']!==''||(int)$r['asset_count']>0);
            $hasCareer=$r && $r['career']!=='';
            $qualifications=$r?(int)$r['qualifications']:0;
            $needs=!$r||!$hasPhoto||!$hasCareer||$qualifications===0;
            $photos+=(int)(bool)$hasPhoto;
            $careers+=(int)(bool)$hasCareer;
            $links+=$qualifications;
            $missing+=(int)$needs;
            $result[]=['name'=>$name,'source_index'=>$i,'missing'=>$needs,
              'has_photo'=>(bool)$hasPhoto,'has_career'=>(bool)$hasCareer,
              'qualification_count'=>$qualifications];
        }
        jsonOut(['teachers'=>$result,'photos'=>$photos,'careers'=>$careers,
          'qualification_links'=>$links,'missing_count'=>$missing]);
    }
    if($action==='teacherPhoto' && $method==='GET'){
        requireAdmin();
        $id=(int)($_GET['id']??0);
        $q=db()->prepare('SELECT mime,image_bytes FROM teacher_photo_assets WHERE teacher_id=?');
        $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){http_response_code(404);exit;}
        header('Content-Type: '.$row['mime']);
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        echo $row['image_bytes'];exit;
    }
    if($action==='teacherSyncOne' && $method==='POST'){
        requireMasterAdmin();checkCsrf();
        $index=(int)(body()['index']??-1);
        $result=syncMemberOne($index);
        jsonOut(['ok'=>true,'result'=>$result]);
    }
}
