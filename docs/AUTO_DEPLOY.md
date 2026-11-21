# GitHub Actions → UZERO 自動デプロイ（はなまるのみ）

GitHub の `Taiju-h/reserva` の main への push により、構文検査成功後に自動デプロイします。アシスタントが GitHub にコードをコミットした場合も対象です。

**最初の設定はサーバー管理者による一度限りの操作が必要です。** ChatGPT の GitHub 連携だけでは UZERO サーバーへの SSH 認証や Secrets の作成はできません。

## 0. 前提条件

- UZERO VPS に `/var/www/hanamaru` の Git クローン、Apache、PHP、python3、curl がある
- `/var/www/hanamaru/.env` と `storage` は Git 管理外
- ソフトウェアと DB のバックアップ先を別途確保している
- DNS/HTTPS と Apache の localhost テストが成功する
- 公開前は `Require local` を維持する

## 1. 最初に最新のデプロイスクリプトをサーバーに入れる（root）

```bash
cd /var/www/hanamaru
git status --short
git pull --ff-only origin main
php -l public/index.php
php -l app/operations.php
sudo apache2ctl configtest
```

Git の追跡対象ファイルに予期しない変更がある場合は確認してから進めること。インストーラーは再実行しません。

## 2. 専用ユーザー・権限の準備（root）

```bash
id hanamaru-deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash hanamaru-deploy
usermod -aG www-data hanamaru-deploy
chown -R hanamaru-deploy:hanamaru-deploy /var/www/hanamaru
chown root:www-data /var/www/hanamaru/.env
chmod 640 /var/www/hanamaru/.env
chown -R www-data:www-data /var/www/hanamaru/storage
find /var/www/hanamaru/storage -type d -exec chmod 2770 {} +
find /var/www/hanamaru/storage -type f -exec chmod 660 {} +
mkdir -p /home/hanamaru-deploy/.ssh
chmod 700 /home/hanamaru-deploy/.ssh
touch /home/hanamaru-deploy/.ssh/authorized_keys
chmod 600 /home/hanamaru-deploy/.ssh/authorized_keys
chown -R hanamaru-deploy:hanamaru-deploy /home/hanamaru-deploy/.ssh
```

**注意:** `storage/` のパーミッションは運用ポリシーに合わせて確認してください。既存の予約 DB への書込み権限を壊さないこと。Git Checkout 以外の UZERO のディレクトリは変更しません。

## 3. SSH キーを生成して登録

管理者の安全な端末で、専用の新しい秘密鍵/公開鍵を作成してください。

```bash
ssh-keygen -t ed25519 -C "hanamaru-actions-deploy" -f ~/.ssh/hanamaru_actions -N ''
```

公開鍵 `~/.ssh/hanamaru_actions.pub` の一行を、サーバーの
`/home/hanamaru-deploy/.ssh/authorized_keys` に追加します（他の鍵は消さない）。

秘密鍵 `~/.ssh/hanamaru_actions` の**内容はチャットに貼らず**、GitHub の Settings → Secrets and variables → Actions → Repository secrets に直接登録してください。

GitHub Secrets:

| Name | Value |
|---|---|
| `DEPLOY_HOST` | UZERO VPSのSSH接続先IPまたはFQDN |
| `DEPLOY_PORT` | SSHポート（通常22） |
| `DEPLOY_USER` | `hanamaru-deploy` |
| `DEPLOY_SSH_KEY` | 新しく生成したSSH秘密鍵の全文 |
| `DEPLOY_KNOWN_HOSTS` | サーバー側のSSHホスト公開鍵情報。信頼できる方法で指紋照合のうえ取得 |

`DEPLOY_KNOWN_HOSTS` を埋めるとき、未検証の `ssh-keyscan` 結果を盲信しないこと。

サーバー側と GitHub Actions で鍵のログインを確認します。SSH 秘密鍵は ChatGPT やリポジトリ本体に登録しないでください。

## 4. 本番環境の設定

GitHub Settings → Environments → `production` を作ることを推奨します。レビュー承認を要求する運用も設定できます。

Workflow: `.github/workflows/deploy.yml`

- Trigger: main に push、または Actions から手動実行
- Validate: PHP構文、shell構文
- Deploy: SSH接続 → `ops/deploy.sh` → DBバックアップ → Git SHA更新 → localhost ヘルスチェック
- Failure: コードを旧 SHA に戻す
- 注意: **DBの自動復元はしません**。DBスキーマを変更する更新は別途マイグレーション・バックアップ・ロールバック設計が必要です
- 注意: ApacheやSSLの再設定はしません（アプリコードのみ）

## 5. 最初のテスト

Secrets 登録後に GitHub Actions の "Deploy Hanamaru to UZERO" を手動実行し、結果のログで
`DEPLOY SUCCESS` とDBバックアップ場所を確認してください。

本番公開前に予約テスト、重複防止、管理画面の権限、管理者ログイン、障害復旧、通知などを確認してください。
