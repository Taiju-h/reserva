<?php
declare(strict_types=1);
// Import 42 member-directory summaries first, then attempt detail-page biographies & photos.
// Run after ops/migrate-teacher-profiles.php.
// The summary import is local/deterministic and succeeds even when the source website is down.
if(PHP_SAPI!=='cli')exit(1);
echo "STEP 1/2: 講師一覧に掲載されたプロフィール概要・資格を投入します\n";
require __DIR__.'/seed-teacher-directory.php';
echo "STEP 2/2: 掲載元の個別詳細ページと顔写真を取得します\n";
require __DIR__.'/import-teacher-profiles.php';
