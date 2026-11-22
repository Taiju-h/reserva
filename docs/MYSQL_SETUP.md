# Hanamaru: MySQL 8 conversion

This repository now uses **MySQL only** (not SQLite). The host Apache and its PHP extensions are not changed.

## Existing .env

The file `/var/www/hanamaru/.env` is kept on the VPS; never push it to Git.

```dotenv
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=hanamaru
DB_USERNAME=hanamaru
DB_PASSWORD=<strong unique password>
```

Keep the existing `ADMIN_PASSWORD_HASH`, `APP_KEY`, and Stripe keys. If the database is on another host,
change `DB_HOST` and adjust Compose rather than creating an unwanted second database.

Compose 2.35+ requires `--env-file /dev/null` due to bcrypt `$` tokens in the app .env; the
MySQL service uses raw env_file input. Do not paste secrets into issue comments or chat.

## Migration preflight

1. Check for prior SQLite data before switching. **Do not delete storage/reservations.sqlite**.
2. If any bookings, payments, settings, or edits exist, export/import them in a planned migration.
   The schema bootstrap does **not** transfer prior data.
3. Make sure MySQL `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` are supplied in .env.
4. Back up any externally hosted MySQL instance before altering tables.

## First run using container MySQL 8

```bash
cd /var/www/hanamaru
git pull --ff-only
docker compose --env-file /dev/null config --quiet
docker compose --env-file /dev/null up -d --build
docker compose --env-file /dev/null ps
curl -fsS 'http://127.0.0.1:18081/?action=config'
```

Expect JSON containing `settings`, `menus`, and `stripe_test_enabled`.
MySQL tables are initialized through `ops/schema.mysql.sql` on **first creation of an empty named volume**.
The `mysql-data` named volume persists across rebuilds. NEVER run `docker compose down -v`
or delete the volume to fix a startup error.

The MySQL port is **not published** on the host. PHP container connects to service `mysql:3306`.
The site is still served externally by the existing host Apache, which needs the separate
reverse-proxy change documented in `docs/DOCKER_APACHE.md`.

## Queries (without printing credentials)

```bash
docker compose --env-file /dev/null exec -T mysql sh -ec \
  'MYSQL_PWD="$DB_PASSWORD" mysql -u "$DB_USERNAME" "$DB_DATABASE" -e "SHOW TABLES;"'
```

For troubleshooting:

```bash
docker compose --env-file /dev/null logs --tail=60 mysql
docker compose --env-file /dev/null logs --tail=60 hanamaru
```

## Security

- Do not enable real Stripe payments until webhook verification and booking conflict handling
  have been exercised with test events.
- Back up the MySQL database before code upgrades or schema migrations.
- MySQL application account should only access its own database.
- External MySQL requires appropriate firewall restrictions and credential management.
