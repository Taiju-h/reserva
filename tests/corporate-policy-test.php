<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/corporate-policy.php';
$cases=[
  ['2026-12-20','09:00',true,'opening Sunday morning'],
  ['2026-12-20','19:00',true,'Sunday last opening'],
  ['2026-12-20','20:00',false,'Sunday after closing'],
  ['2026-12-21','08:00',true,'weekday morning'],
  ['2026-12-21','09:00',false,'weekday daytime blocked'],
  ['2026-12-21','18:00',true,'weekday evening'],
  ['2026-12-21','19:00',true,'weekday last opening'],
  ['2026-12-21','20:00',false,'weekday after closing'],
  ['2026-12-28','08:00',false,'year-end shutdown begins'],
  ['2027-01-03','19:00',false,'year-end shutdown ends'],
  ['2027-01-04','08:00',true,'first Monday after shutdown'],
  ['2027-01-11','09:00',true,'Coming-of-Age holiday'],
  ['2027-01-11','08:00',false,'holiday before 9'],
  ['2027-02-11','13:00',true,'National Foundation Day'],
  ['2027-02-23','19:00',true,'Emperor birthday'],
  ['2027-02-24','09:00',false,'weekday daylight'],
  ['2027-02-28','19:00',true,'last day'],
  ['2027-03-01','18:00',false,'after campaign'],
  ['2026-12-19','09:00',false,'before campaign'],
  ['2027-02-29','09:00',false,'invalid February date'],
  ['bad-date','09:00',false,'malformed date'],
  ['2026-12-20','09:30',false,'no half hour'],
];
$failed=0;
foreach($cases as [$date,$time,$expected,$description]){
    $value=corporateAllowedStart($date,$time);
    if($value!==$expected){
        fwrite(STDERR,"FAIL: $description ($date $time)\n");$failed++;
    }
}
$intervals=[
  ['2026-12-20 09:00:00','2026-12-20 10:00:00',true],
  ['2026-12-20 09:00:00','2026-12-20 09:50:00',false],
  ['2026-12-21 09:00:00','2026-12-21 10:00:00',false],
  ['2027-01-01 09:00:00','2027-01-01 10:00:00',false],
];
foreach($intervals as [$start,$end,$expected]){
    if(corporateAllowedInterval($start,$end)!==$expected){
        fwrite(STDERR,"FAIL: interval $start - $end\n");$failed++;
    }
}
if($failed){fwrite(STDERR,"$failed corporate schedule test failures\n");exit(1);}
echo "PASS ".(count($cases)+count($intervals))." corporate scheduling cases\n";
