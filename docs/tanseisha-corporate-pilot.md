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
