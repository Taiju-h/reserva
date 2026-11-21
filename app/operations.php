<?php
/**
 * Admin operations: booking calendar data, customer directory and CSV export.
 * Included from index.php INSIDE the existing try block.
 */
if ($action==='calendar' && $method==='GET') {
    requireAdmin();
    $month=(string)($_GET['month']??'');
    if(!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/',$month))fail('月の形式が不正です');
    $start=$month.'-01 00:00:00';
    $end=(new DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m-d H:i:s');
    $stmt=db()->prepare('SELECT b.id,b.starts_at,b.ends_at,b.customer_name,b.status,m.name AS menu_name
      FROM bookings b JOIN menus m ON m.id=b.menu_id
      WHERE b.starts_at>=? AND b.starts_at<? ORDER BY b.starts_at LIMIT 2000');
    $stmt->execute([$start,$end]);
    jsonOut(['bookings'=>$stmt->fetchAll()]);
}
if ($action==='customers' && $method==='GET') {
    requireAdmin();
    $search=trim((string)($_GET['q']??''));
    if(mb_strlen($search)>100)fail('検索語が長すぎます');
    $sql='SELECT lower(trim(email)) AS email, MAX(customer_name) AS name,
        MAX(phone) AS phone, COUNT(*) AS booking_count,
        SUM(CASE WHEN status="confirmed" THEN 1 ELSE 0 END) AS confirmed_count,
        MAX(starts_at) AS last_booking
      FROM bookings';
    $args=[];
    if($search!=='') {
        $sql.=' WHERE customer_name LIKE ? ESCAPE \'\\\' OR email LIKE ? ESCAPE \'\\\' OR phone LIKE ? ESCAPE \'\\\'';
        $safe=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search);
        $args=array_fill(0,3,'%'.$safe.'%');
    }
    $sql.=' GROUP BY lower(trim(email)) ORDER BY last_booking DESC LIMIT 300';
    $stmt=db()->prepare($sql);$stmt->execute($args);
    jsonOut(['customers'=>$stmt->fetchAll()]);
}
if ($action==='exportBookings' && $method==='GET') {
    requireAdmin();
    $month=(string)($_GET['month']??'');
    if(!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/',$month)) fail('月の形式が不正です');
    $start=$month.'-01 00:00:00';
    $end=(new DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m-d H:i:s');
    $stmt=db()->prepare('SELECT b.id,b.starts_at,b.ends_at,m.name AS menu_name,
        b.customer_name,b.email,b.phone,b.note,b.status,b.created_at
        FROM bookings b JOIN menus m ON m.id=b.menu_id
        WHERE b.starts_at>=? AND b.starts_at<? ORDER BY b.starts_at');
    $stmt->execute([$start,$end]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bookings-'.$month.'.csv"');
    header('Cache-Control: private, no-store');
    $out=fopen('php://output','wb');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['予約ID','開始','終了','メニュー','お名前','メール','電話','備考','状態','登録日']);
    while($row=$stmt->fetch(PDO::FETCH_NUM)) {
        // Prevent spreadsheet formula injection from untrusted customer fields.
        foreach($row as &$value) {
            $value=(string)$value;
            if(preg_match('/^[\s\x00-\x1F]*[=+@\-]/u',$value)) $value="'".$value;
        }
        unset($value);
        fputcsv($out,$row);
    }
    fclose($out);
    exit;
}
