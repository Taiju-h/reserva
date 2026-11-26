<?php
declare(strict_types=1);
// Unit checks for email verification state. No external email sent.
$env=['CORPORATE_EMAIL_VERIFICATION'=>'off'];
function setting(string $name,string $default=''):string {global $env;return $env[$name]??$default;}
require_once dirname(__DIR__).'/app/corporate-email.php';
$failures=0;
function expectEmail(bool $actual,bool $expected,string $description):void {
    global $failures;
    if($actual!==$expected){fwrite(STDERR,"FAIL: $description\n");$failures++;}
}
$campaign=['id'=>42];
$_SESSION=[];
expectEmail(corporateEmailVerified($campaign,'person@example.com'),true,'disabled verification');
$env['CORPORATE_EMAIL_VERIFICATION']='required';
expectEmail(corporateEmailVerified($campaign,'person@example.com'),false,'unverified email rejected');
$_SESSION['corporate_email_verified_campaign']=42;
$_SESSION['corporate_email_verified']='person@example.com';
$_SESSION['corporate_email_verified_until']=time()+600;
expectEmail(corporateEmailVerified($campaign,'person@example.com'),true,'verified email accepted');
expectEmail(corporateEmailVerified($campaign,'other@example.com'),false,'mismatched email rejected');
expectEmail(corporateEmailVerified(['id'=>43],'person@example.com'),false,'wrong company rejected');
$_SESSION['corporate_email_verified_until']=time()-1;
expectEmail(corporateEmailVerified($campaign,'person@example.com'),false,'expired verification rejected');
expectEmail(corporateEmailReady(),false,'mail provider must be configured');
if($failures)exit(1);
echo "PASS corporate email verification session checks\n";
