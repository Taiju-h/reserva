# はなキャリ: オンラインカード決済の設定

## 現状
アプリは Stripe Checkout を使用。予約フォーム送信直後にStripeのホスト型決済ページへ遷移する。
Webhook署名を検証し、支払い成功後に `bookings.status=confirmed` へ変更する。
本番のカード決済は**まだ検証されておらず**、初期状態では停止する。

## 有効化する前の確認
1. Stripeアカウントの本人確認、決済有効化、事業者情報を確認する。
2. Stripeのテスト環境（`sk_test_`）で正常決済、失敗、キャンセル、Webhook再送、重複予約、期限切れを検証する。
3. 本番のStripe Workbench > Webhooks/Event destinationsでエンドポイントを追加する。
   - URL: `https://hanamaru.uzero.style/?action=stripeWebhook`
   - イベント: `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`
   - 本番配信先から発行された署名シークレット（`whsec_`）を利用する。
4. 本番秘密鍵（`sk_live_`）はサーバーの `.env` のみに保存し、GitHubやチャットには貼らない。
5. HTTPSの安全性、予約規約、特商法表示、返金・キャンセル、管理画面保護とバックアップを確認する。

## サーバーの /var/www/hanamaru/.env
テスト：
```dotenv
STRIPE_MODE=test
STRIPE_LIVE_ENABLED=no
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

本番（テストと審査が完了したときだけ）：
```dotenv
STRIPE_MODE=live
STRIPE_LIVE_ENABLED=yes
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```
秘密鍵・署名シークレットはStripeダッシュボードで作成し、値を直接サーバーで設定する。キーが不完全ならカード決済は停止する。
PHPアプリは `.env` を起動時ではなくリクエスト時に読み込むが、Composeの扱いによってはサービス再起動が必要な場合がある。

## 反映とAPI確認
```sh
cd /var/www/hanamaru
git pull --ff-only
docker compose --env-file /dev/null up -d --build
curl -fsS 'https://hanamaru.uzero.style/?action=config'
```
`stripe_mode` が `disabled` なら設定は不完全、`test` はテスト決済、`live` は本番決済。

**注意：** 本番決済の疎通・Stripe webhook受信・返金フロー・運用テストはGitHubコード修正だけでは保証されない。
既存の `pending_review` 予約や運用手動予約を無断で変更しない。
