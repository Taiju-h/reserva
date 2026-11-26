<?php
declare(strict_types=1);
?><!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>先生専用・予約枠登録 | はなキャリ</title>
<link rel="icon" href="/?action=favicon&amp;kind=admin">
<link rel="stylesheet" href="/assets/corporate.css?v=20261126-calendar3">
</head><body>
<header><a class="brand" href="/">はなキャリ</a><span>先生専用・予約枠登録</span></header>
<main>
<section class="intro"><h1>先生の予約枠</h1><p>自分の受付可能な時間だけを登録してください。既に予約が入った枠は削除できません。</p></section>
<section class="panel" id="teacherLoginArea">
<h2>先生ログイン</h2><form id="teacherLoginForm">
<label>ログインID<input name="login_id" autocomplete="username" required></label>
<label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
<button type="submit">ログイン</button><p id="teacherLoginStatus" role="alert"></p>
</form></section>
<section class="panel" id="teacherScheduleArea" hidden>
<div class="teacher-bar"><h2 id="teacherWelcome"></h2><button id="teacherLogout" type="button" class="secondary">ログアウト</button></div>
<p class="hint">企業案件の受付期間：2026/12/20〜2027/2/28（12/28〜1/3休業）。平日：8時・18時・19時開始、土日祝：9時〜19時開始。1枠60分です。</p>
<h3>月別カレンダーから日付を選択</h3>
<div class="teacher-calendar" aria-label="先生の空き枠カレンダー">
<div class="teacher-calendar-toolbar">
<button id="teacherCalendarPrev" type="button" class="secondary" aria-label="前の月">前月</button>
<strong id="teacherCalendarMonth"></strong>
<button id="teacherCalendarNext" type="button" class="secondary" aria-label="次の月">翌月</button>
</div>
<div id="teacherCalendarGrid" class="teacher-calendar-grid" aria-live="polite"></div>
<p class="hint">日付を押すと登録フォームへ反映されます。数字は登録済みの空き枠数です。予約済み枠は別に表示します。</p>
</div>
<h3>受付できる日時を登録</h3>
<form id="teacherSlotForm"><label>日付<input type="date" name="date" min="2026-12-20" max="2027-02-28" required></label>
<label>開始時間<select name="time" required><option value="">選択してください</option><option value="08:00">08:00</option><option value="09:00">09:00</option><option value="10:00">10:00</option><option value="11:00">11:00</option><option value="12:00">12:00</option><option value="13:00">13:00</option><option value="14:00">14:00</option><option value="15:00">15:00</option><option value="16:00">16:00</option><option value="17:00">17:00</option><option value="18:00">18:00</option><option value="19:00">19:00</option></select></label>
<button type="submit">この60分枠を追加</button><p id="teacherSlotStatus" role="status"></p></form>
<h3>曜日と時間を指定してまとめて登録</h3>
<form id="teacherBatchForm">
<label>期間の開始日<input type="date" name="from" min="2026-12-20" max="2027-02-28" value="2026-12-20" required></label>
<label>期間の終了日<input type="date" name="until" min="2026-12-20" max="2027-02-28" value="2027-02-28" required></label>
<fieldset><legend>受付する曜日</legend><div class="teacher-checkboxes">
<label><input type="checkbox" name="days" value="1"> 月</label><label><input type="checkbox" name="days" value="2"> 火</label><label><input type="checkbox" name="days" value="3"> 水</label><label><input type="checkbox" name="days" value="4"> 木</label><label><input type="checkbox" name="days" value="5"> 金</label><label><input type="checkbox" name="days" value="6"> 土</label><label><input type="checkbox" name="days" value="0"> 日</label>
</div></fieldset>
<fieldset><legend>受付する開始時間</legend><div class="teacher-checkboxes">
<label><input type="checkbox" name="hours" value="8"> 08:00</label><label><input type="checkbox" name="hours" value="9"> 09:00</label><label><input type="checkbox" name="hours" value="10"> 10:00</label><label><input type="checkbox" name="hours" value="11"> 11:00</label><label><input type="checkbox" name="hours" value="12"> 12:00</label><label><input type="checkbox" name="hours" value="13"> 13:00</label><label><input type="checkbox" name="hours" value="14"> 14:00</label><label><input type="checkbox" name="hours" value="15"> 15:00</label><label><input type="checkbox" name="hours" value="16"> 16:00</label><label><input type="checkbox" name="hours" value="17"> 17:00</label><label><input type="checkbox" name="hours" value="18"> 18:00</label><label><input type="checkbox" name="hours" value="19"> 19:00</label>
</div></fieldset>
<button type="submit">該当する空き枠を一括登録</button><p id="teacherBatchStatus" role="status"></p>
</form>
<h3>登録済みの枠</h3><p class="hint">「空き」の枠だけ取消できます。</p><div id="teacherSlots">読み込み中…</div>
</section>
</main><script src="/assets/instructor.js?v=20261126-calendar3" defer></script></body></html>