# はなまる Stripe テスト決済設定（2026-11）

GitHubの実装は**テストモード限定**です。実際の決済・Stripe通信・本番公開はまだ未検証です。既存有料メニューは、テスト用秘密鍵とWebhook秘密鍵の両方が有効にならなければ予約できません。

## 1. Stripeでテストキーを取得

Stripeにログイン: https://dashboard.stripe.com/
Stripeの Sandbox / テスト環境に切り替え、開発者の **API keys** を開く。

- **Secret key**: `sk_test_...` （サーバー側のみ）
- **Publishable key**: `pk_test_...` （今回のStripeホスト型Checkoutでは不要）

※WebサイトやGitHubに秘密鍵を置かないこと。ChatGPTの会話にも貼らないこと。
キーはStripeの自分のアカウントから発行する。ドキュメントのサンプルキーは使えません。

## 2. Webhookのテスト配信先

Stripe Dashboard → Workbench / Webhooks または Event destinations → テスト環境にWebhook Endpointを作成する。

URL:

```text
https://hanamaru.uzero.style/?action=stripeWebhook
```

イベント選択:
- `checkout.session.completed`
- `checkout.session.expired`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`

Webhookの署名シークレット `whsec_...` を取得する。APIの `sk_test_` とは異なる値。

**重要:** 外部StripeからWebhookに届くよう、HTTPSのApache外部アクセス制限とWAFを確認すること。現在の初期Apache設定は `Require local` なのでStripeからの配信はできない。テスト用公開に先立って十分な保護・管理者設定・HTTPS・レート制限を検証すること。

## 3. UZEROサーバー側設定

管理者はサーバー上で実行。GitHubに入れない。実際の秘密鍵をチャットに貼らない。

```bash
cd /var/www/hanamaru
sudo apt-get install -y php-curl
git status --short
git pull --ff-only origin main
php -l public/index.php
php -l app/stripe.php
```

`/var/www/hanamaru/.env` の末尾に、Stripeアカウントで発行した値を**サーバー内のエディタで**追記・編集する。

```dotenv
STRIPE_MODE=test
STRIPE_SECRET_KEY=sk_test_YOUR_OWN_SECRET
STRIPE_WEBHOOK_SECRET=whsec_YOUR_OWN_ENDPOINT_SECRET
```

値が設定されるまで、Stripeは有効にならない。キーの例をそのまま使わないこと。

## 4. 動作確認

- サーバーに `php-curl` があり、`php -l` が全て成功する
- テスト用ページで50円以上のメニューを選び、空き枠に進む
- `Stripeテスト決済に進む` ボタンからStripe Checkoutページが開く
- Stripeのテストカード例: `4242 4242 4242 4242`、未来の有効期限・任意のCVC。テストモード画面のみで入力し、実カードは使わない
- 決済成功後、Webhookが配信成功（HTTP 200）となり、DB上の状態が `confirmed` に変わる
- 同じ日時の再予約を拒否する
- Webhook重複配信、タイムアウト、カード拒否、決済取り消し、期限超過支払い時の対応も確認する

## 未完了・要改善

- Stripe本番モード、返金APIと返金通知、予約変更、メール通知は未実装
- 35分間の仮確保後に支払い成功通知が来た場合、予約を自動確定せず手動返金確認が必要
- Stripe発行のテストキーは利用者のStripeアカウントが必要
- SQLiteマイグレーション・本番リリースとDB復旧手順の検証が必要
- 公開サイトではスパム対策・利用規約・個人情報保護方針・決済画面の注意事項が必要
