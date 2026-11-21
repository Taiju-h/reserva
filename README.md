# はなまる予約システム (Hanamaru Reservation)

独立開発の予約サイトです。RESERVA の公開UI・一般的な操作フローを参考にしていますが、RESERVA の公式プログラムやコピーではありません。

- GitHub: https://github.com/Taiju-h/reserva
- サーバー: `/var/www/hanamaru`
- URL候補: https://hanamaru.uzero.style
- Apache DocumentRoot: `/var/www/hanamaru/public`
- DB: `/var/www/hanamaru/storage/reservations.sqlite`（Git管理外）
- PHP 8.2以上・PDO SQLite・mbstring が必要です。

## 現在の実装

予約者: メニュー選択、日付・空き時間表示、お名前・メール・電話・備考入力、予約確定と重複防止。

管理者: ログイン、予約一覧、取消・復帰、メニュー登録・公開設定、営業時間・曜日休業・臨時休業日、予約締切時間、予約受付期間、月別予約カレンダー、顧客検索、月別CSV。

## 既にサーバーに設置済みの場合の更新

**Apacheインストーラーを再実行しないでください。** 既存DB・.envを維持してGitだけ更新します。

```bash
cd /var/www/hanamaru
git status --short
git remote -v
git pull --ff-only origin main
php -l public/index.php
php -l app/operations.php
sudo apache2ctl configtest
```

`git status --short` に予期しない変更があれば、そのまま pull せず原因を確認します。稼働中なら更新前にDBバックアップと復元手順を準備してください。

## 初回セットアップ

`ops/install-ubuntu.sh` および `ops/setup-ssl.sh` は初回セットアップ用です。HTTP/HTTPS VirtualHost は初期状態 `Require local` で外部非公開です。

`.env` はGit管理外です。管理者パスワードは `ADMIN_PASSWORD_HASH` にPHP `password_hash` の結果を設定し、秘密値をチャットに貼らないでください。

## 重要な制限

**現状は開発・テスト段階であり、公開して本番予約を受け付ける準備はできていません。** メール通知、決済、予約変更、スタッフ別空き枠、CSRF以外のスパム対策、IP単位のログイン試行制限、バックアップ、権限管理、各種セキュリティテストなどが必要です。

管理画面のCSVには顧客の個人情報が含まれるため、安全な保管・アクセス制御が必要です。

## 確認項目

1. `php -l` の構文チェック
2. 管理者でメニューを保存し、一般予約画面に反映されること
3. 日曜休業（0）、臨時休業日、受付締切、受付期限が反映されること
4. 同一時間帯に同時予約を試し、1件だけ確定されること
5. 月間カレンダー、顧客検索、CSV出力を管理者だけが使えること
6. 予約取消後に空き枠が戻り、復帰時には重複判定されること
7. 本番利用前にSSL、バックアップ、メール通知、レート制限を検証すること
