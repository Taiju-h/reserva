<?php
// Dedicated company-funded pilot page. No Stripe and no public menu shortcuts.
declare(strict_types=1);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>丹青社 セカンドキャリア相談 | はなキャリ</title>
<link rel="icon" href="/?action=favicon&amp;kind=public">
<link rel="stylesheet" href="/assets/corporate.css?v=20261126">
</head>
<body>
<header><a href="/" class="brand">はなキャリ</a><span>丹青社 セカンドキャリア相談</span></header>
<main>
<section class="intro">
<p class="eyebrow">企業向け・費用は会社負担</p>
<h1>60歳以降のセカンドキャリア相談</h1>
<p>2026年12月20日〜2027年2月28日（12月28日〜1月3日はお休み）</p>
<p>相談50分＋記録・休憩10分の、1回60分枠です。利用者の方にお支払いは発生しません。</p>
</section>
<div class="stepbar"><span id="step1label" class="active">1. お客様情報</span><span id="step2label">2. 先生を選ぶ</span><span id="step3label">3. 日時を選ぶ</span></div>
<section id="step1" class="panel">
<h2>はじめにお客様情報をご入力ください</h2>
<p>この予約は丹青社向け専用です。企業からご案内されたコードを入力してください。</p>
<form id="identity">
<label>企業専用コード <input name="access_code" type="password" autocomplete="off" minlength="12" required></label>
<label>お名前 <input name="name" autocomplete="name" maxlength="100" required></label>
<label>メールアドレス <input name="email" type="email" autocomplete="email" maxlength="254" required></label>
<label>電話番号（任意） <input name="phone" autocomplete="tel" maxlength="40"></label>
<label>社員番号など（任意） <input name="employee_reference" maxlength="80" placeholder="必要な場合のみ"></label>
<div class="privacy">入力された情報は予約の確認・管理、相談の実施連絡、会社への利用実績の集計に利用します。会社への請求は実施件数に基づきます。相談内容をこの予約画面へ入力する必要はありません。取り扱いについては<a href="https://reserva.be/hanamarusoudan/privacy" target="_blank" rel="noopener noreferrer">プライバシーポリシー</a>をご確認ください。</div>
<label class="agree"><input name="consent" type="checkbox" value="1" required> 個人情報の利用目的を確認し同意します。</label>
<button type="submit">入力内容を確認して先生を選ぶ</button>
<p id="identityError" role="alert"></p>
</form>
</section>
<section id="step2" class="panel" hidden>
<h2>相談する先生を選んでください</h2>
<p>先生の顔写真・プロフィールをご確認ください。空きがなければ別の先生も選べます。</p>
<div id="teacherCards" class="teachers"></div>
<button id="backToIdentity" type="button" class="secondary">お客様情報を編集</button>
</section>
<section id="step3" class="panel" hidden>
<h2 id="selectedTeacherLabel">相談日・時刻を選択</h2>
<label class="date">相談希望日 <input id="consultDate" type="date" min="2026-12-20" max="2027-02-28"></label>
<p class="hint">平日は8:00・18:00・19:00開始、土日祝は9:00〜19:00開始です。先生が登録した枠のみ選択できます。</p>
<div id="consultSlots" class="slots"></div>
<div id="chosenSlot" class="summary" hidden></div>
<button id="confirmBooking" type="button" disabled>無料で予約を確定する</button>
<button id="backToTeachers" class="secondary" type="button">別の先生を探す</button>
<p id="bookingMessage" role="alert"></p>
</section>
<section id="complete" class="panel" hidden>
<h2>予約を受け付けました</h2>
<p id="confirmation"></p>
<p>本ページでのカード決済はありません。予約番号をお控えください。</p>
</section>
</main>
<script src="/assets/corporate.js?v=20261126" defer></script>
</body></html>