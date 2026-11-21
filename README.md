# Hanamaru Reservation Platform

独自開発の予約・メニュー管理システム。RESERVA の一般的な予約操作フローを参考にしたもので、RESERVA の公式製品や複製ではありません。

## Environment

PHP 8.2+ / PDO SQLite / Apache mod_rewrite. Apache DocumentRoot must point to `public/` (not the repository root). SQLite database and application secrets live outside the public web root in `storage/`, and must never be committed.

## Setup

1. Copy `.env.example` to `.env` (never commit `.env`).
2. Set `APP_KEY` to 64 random hex characters (`php -r 'echo bin2hex(random_bytes(32)),PHP_EOL;'`).
3. Set `ADMIN_PASSWORD_HASH` using `php -r 'echo password_hash("YOUR-STRONG-PASSWORD", PASSWORD_DEFAULT),PHP_EOL;'`.
4. Ensure PHP `pdo_sqlite` is installed, and grant the Apache user write access to `storage/`.
5. Set Apache DocumentRoot to `/var/www/reserva/public`; enable rewrite and HTTPS.
6. Visit `/admin` to configure menus and opening hours. Defaults are illustrative, not imported booking or customer data.

## Security / deployment

Public GitHub repository: never commit personal data, passwords, API keys, SQLite files or server configuration secrets. Confirm HTTPS, backup, mail delivery, access controls, rate limiting and CSRF before accepting live bookings. Deployment to UZERO VPS is **not configured**.
