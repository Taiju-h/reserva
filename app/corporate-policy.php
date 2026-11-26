<?php
declare(strict_types=1);
/**
 * Single source of truth for 丹青社 time rules. Pure logic, no DB, safe to unit-test.
 * All times are local Asia/Tokyo, one slot = 60 minutes (50 + 10).
 */
function corporateAllowedStart(string $date,string $time): bool {
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tokyo'));
    if(!$dt || $dt->format('Y-m-d')!==$date)return false;
    if($date<'2026-12-20'||$date>'2027-02-28')return false;
    if($date>='2026-12-28'&&$date<='2027-01-03')return false;
    if(!preg_match('/^(?:0[89]|1\d):00$/D',$time))return false;
    $weekDay=(int)$dt->format('w');
    $hour=(int)substr($time,0,2);
    $holidays=['2027-01-11','2027-02-11','2027-02-23'];
    if($weekDay===0||$weekDay===6||in_array($date,$holidays,true)){
        return $hour>=9&&$hour<=19;
    }
    return $hour===8||$hour===18||$hour===19;
}
function corporateAllowedInterval(string $start,string $end): bool {
    $tz=new DateTimeZone('Asia/Tokyo');
    $begin=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$start,$tz);
    $finish=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$end,$tz);
    if(!$begin||!$finish||$begin->format('Y-m-d H:i:s')!==$start||
       $finish->format('Y-m-d H:i:s')!==$end)return false;
    return corporateAllowedStart($begin->format('Y-m-d'),$begin->format('H:i'))
       && $finish->getTimestamp()-$begin->getTimestamp()===3600;
}
