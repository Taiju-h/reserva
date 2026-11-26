# 丹青社 セカンドキャリア相談：専用予約（2026/12/20〜2027/02/28）

## 契約・運用前提（2026/11/25 の依頼）
- 2025年研修受講者260人から、2026年度のフォロー相談を提供。利用見込み約30名。
- **利用者課金なし**。実施を確認した相談だけを **8,000円/件** で会社へ請求。30件の参考見積 **240,000円（構築費別）**。
- 相談50分＋記録・休憩10分 = **1枠60分**。
- 対象期間：2026-12-20〜2027-02-28。2026-12-28〜2027-01-03は予約禁止。
- 平日：08:00、18:00、19:00開始。土日祝：09:00〜19:00の毎時開始（11枠）。2027年の該当祝日は1/11、2/11、2/23。
- **個人情報を先に入力** → 参加講師10名の顔写真・プロフィールから選択 → その講師の空き枠選択 → **Stripeなしで予約確定**。空き枠なしなら他の先生を選び直せる。
- 既存の一般向け有料メニューと料金は変更しない。

## 実装位置
- 公開：`/tanseisha`（企業専用コード必須。未公開なら予約受付不可）
- 管理：`/admin?page=corporate`（案件設定・担当講師・枠一括追加・取消・実施確認）
- DB：`corporate_campaigns` / `corporate_teachers` / `corporate_slots` / `corporate_bookings`。
- DB migration：`ops/migrate-corporate-bookings.php`。
- 会社向け実績集計：`completed` のみ件数と8,000円の積、見積と区別する。
- 祝日：該当期間分のみ固定判定。期間変更時には公式暦との再照合が必要。

## 公開までに必須
- [ ] 10名の担当講師の確定（42名から管理画面で選択）
- [ ] 講師10名の写真・プロフィール・案内文の確認
- [ ] 各先生の空き枠登録・確認（休日・年末年始・時間条件）
- [ ] 実際の個人情報取扱い（利用目的、保管期間、削除、企業に共有する項目、連絡窓口）の承認
- [ ] HTTPS/SSLのブラウザ警告の原因究明と修復
- [ ] TLS及び個人情報保護の確認後にサーバー .env で `CORPORATE_TLS_APPROVED=yes` / `CORPORATE_PRIVACY_APPROVED=yes`。管理画面で明示的に公開
- [ ] 予約・重複防止・取り消し・実施完了・請求集計のテスト
- [ ] 予約確認メール、変更・取消依頼の導線を本番運用に合わせて整備（現段階では自動送信なし）
- [ ] 講師本人に枠を入力させる場合は「講師専用権限」のログインを追加（管理者アカウントの配布は不可）

## 反映（管理者が VPS で実行）
```sh
cd /var/www/hanamaru
git pull --ff-only
docker compose --env-file /dev/null exec -T hanamaru php /var/www/html/ops/migrate-corporate-bookings.php
docker compose --env-file /dev/null exec -T hanamaru php -l /var/www/html/app/corporate-bookings.php
docker compose --env-file /dev/null exec -T hanamaru php -l /var/www/html/public/index.php
sudo bash ops/diagnose-ssl.sh
```
**注意:** migrationはテーブル作成のみ。企業案件は非公開のまま。実データの投入は管理画面で10名・予約枠・企業コードを設定してから。既存予約・Stripe決済は変更しない。

## 講師本人のスケジュール入力（2026/11/26追加）
- 先生専用URL：`/instructor`。管理画面とはログインを分離し、先生自身の枠のみ閲覧・変更
- 管理者（マスター）が `/admin?page=corporate` から先生のログインIDと12文字以上の初期パスワードを発行
- 先生は日付・開始時間を選んで1枠登録、または期間・曜日・時間を選んでまとめて登録
- 企業向け期間外、休業日、許可外の時刻、過去日時は自動で拒否
- 予約済みの時間は先生が削除不可。空き枠のみ削除可能
- 先生に管理者アカウントを渡さず、権限を自分の `teacher_id` に限定

## 予約者のメールアドレスによる登録
- `/tanseisha` の最初の画面で氏名、メールアドレス、電話番号（任意）、社員番号（任意）、企業コードと同意を取得
- `corporate_customers` を `(campaign_id,email)` で一意管理し、予約と `customer_id` を紐付け
- その後は先生10名から希望を選び、先生本人が登録した空き枠だけを予約する
- **重要：現状はメール送信・本人確認が未実装**。`email_verified_at` は NULL のまま。本人確認済みと表示したり、メールアドレスだけで本人の予約履歴を開かせたりしない
- 確認メール・本人確認コードを本番で使用する場合はSMTP/Mailgun等の送信基盤の設定とテストが必要

## マイグレーション
更新済み `ops/migrate-corporate-bookings.php` は `teacher_logins`、`corporate_customers` を作成し、既存 `corporate_bookings` へ `customer_id` を追加する。繰り返し実行可能。
```sh
cd /var/www/hanamaru && git pull --ff-only
docker compose --env-file /dev/null exec -T hanamaru php /var/www/html/ops/migrate-corporate-bookings.php
docker compose --env-file /dev/null exec -T hanamaru php -l /var/www/html/app/instructor-portal.php
docker compose --env-file /dev/null exec -T hanamaru php -l /var/www/html/app/corporate-bookings.php
docker compose --env-file /dev/null exec -T hanamaru php -l /var/www/html/public/index.php
```

## 2026/11/26 打ち合わせ前の暫定仕様：専用入口＋名簿
- 公開サイトのトップに **「丹青社専用の予約へ」** を追加し、`/tanseisha` に誘導。
- 予約対象の**先生**は、既存講師42名から管理者が選ぶ参加講師10名。利用者には、その10名だけ顔写真・プロフィール・空き枠を表示する。
- **受講者の事前名簿**を使うかは上田さんと協議中。仮実装として管理者だけが事前名簿（メールアドレス＋任意の氏名）を登録できる `corporate_invitees` を追加。
- 名簿照合 `corporate_campaigns.roster_required` は **初期値0（無効）**。会議で決定後にマスター管理画面で任意に有効化。名簿を一般公開したり、受講者に別人の氏名を選ばせたりしない。
- 予約者は先に氏名・メールアドレス・企業専用コードを入力。名簿照合を有効化した場合は登録済みのメールアドレスだけ受付。予約確定時にも再照合。
- **メールアドレスを知っているだけでは本人確認にはならない**。運用開始前にメール確認・社内ID照合・個別招待リンク等の方式を決定する。
- **議事確認事項**：名簿提供元、260名全員が対象か、人数上限、社員番号の必要性、メール本人確認方法、受講者への送信内容、講師10名の確定、予約変更の運用、プライバシー案内。
- SSL警告の原因が未解決なのでキャンペーン公開は引き続き保留。

### 反映
```sh
cd /var/www/hanamaru
git pull --ff-only
docker compose --env-file /dev/null exec -T hanamaru php /var/www/html/ops/migrate-corporate-bookings.php
docker compose --env-file /dev/null exec -T hanamaru php -l /var/www/html/app/corporate-bookings.php
docker compose --env-file /dev/null exec -T hanamaru php -l /var/www/html/public/index.php
```

## 2026/11/26 予約フローの品質改善
### 予約処理
- 60分枠と営業日・祝日・年末年始の純粋な検証関数：`app/corporate-policy.php`。
- 回帰試験：`php tests/corporate-policy-test.php`（26ケース）。
- 会社予約と一般予約の競合チェック、予約失敗時のDBトランザクションロールバックとロック解放処理を明示化。
- **本番MySQLに対する複数接続の競合試験は未実施**。テスト枠で動作確認すること。

### 先生用画面
- `/instructor` に月別カレンダーを追加。日付の選択、空き・予約済み件数を表示。
- 期間・曜日・時間帯による一括登録は従来の先生専用権限で利用可能。
- 先生用ログインID・パスワードはマスター管理画面から発行する。公開・企業参加講師は管理者が確定する。

### 利用者のメールアドレス確認
- `CORPORATE_EMAIL_VERIFICATION=off` が既定。**この状態ではメールアドレスは本人認証ではない**。
- `required` にすると、個人情報入力後、先生選択前に6桁のメール確認コードの送信・照合が必要。
- 送信には `CORPORATE_MAILGUN_DOMAIN`、`CORPORATE_MAILGUN_API_KEY`、`CORPORATE_MAIL_FROM` が必要。
- EUリージョンの場合 `CORPORATE_MAILGUN_REGION=eu`。コードは10分有効、試行5回、同一セッションで再送60秒制限。
- 確認コードの平文はDBに保存しない。予約確定時にも検証済みメールアドレスを再照合。
- **本番のメール送信と到着は未確認**。初期状態のままユーザーに「本人確認済み」と案内しない。大量アクセスへの対策としてプロキシ側のIPレート制限も追加確認が必要。

### CSV出力と公開前診断
- 管理画面 `/admin?page=corporate` から対象月を選択して、`completed`（実施済み）のみの請求CSVを出力。氏名・メールアドレスは含まない。
- `php ops/check-corporate-readiness.php`：DBテーブル、担当講師10名、予約枠、プロフィール/画像未登録人数、メール設定、TLS・個人情報保護承認状態を確認する。**読み取り専用**。
- 公開前のSSL診断は `sudo bash ops/diagnose-ssl.sh`。証明書が有効でもブラウザ警告の解消は別途確認する。

### SQLをユーザーが適用した後の受入試験
```sh
cd /var/www/hanamaru
git pull --ff-only
docker compose --env-file /dev/null exec -T hanamaru php /var/www/html/ops/migrate-corporate-bookings.php
docker compose --env-file /dev/null exec -T hanamaru php tests/corporate-policy-test.php
docker compose --env-file /dev/null exec -T hanamaru php ops/check-corporate-readiness.php
docker compose --env-file /dev/null exec -T hanamaru php -l app/corporate-email.php
docker compose --env-file /dev/null exec -T hanamaru php -l app/corporate-bookings.php
docker compose --env-file /dev/null exec -T hanamaru php -l app/instructor-portal.php
```
`check-corporate-readiness.php` が終了コード2の場合は公開条件が未整備（必ずしも移行失敗ではない）。
