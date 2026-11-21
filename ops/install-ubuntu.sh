#!/usr/bin/env bash
# Safe initial installer for Ubuntu + Apache. Run on UZERO VPS after reviewing.
set -Eeuo pipefail

DOMAIN="${1:-}"
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]$ ]] || [[ "$DOMAIN" != *.* ]]; then
  echo "Usage: sudo bash ops/install-ubuntu.sh reserve.your-domain.example" >&2
  exit 2
fi
if [[ "${EUID}" -ne 0 ]]; then echo "Run with sudo" >&2; exit 1; fi
if ! command -v apt-get >/dev/null; then echo "Ubuntu/Debian with apt required" >&2; exit 1; fi

REPO_DIR="/var/www/reserva"
APACHE_CONF="/etc/apache2/sites-available/reserva.conf"
if [[ -e "$APACHE_CONF" ]]; then
  echo "Existing reserva Apache config detected: $APACHE_CONF. Not overwriting." >&2; exit 1
fi
if [[ -e "$REPO_DIR" && ! -d "$REPO_DIR/.git" ]]; then
  echo "$REPO_DIR exists but is not a Git checkout. No changes made." >&2; exit 1
fi

echo "Checking installed software..."
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 git curl php-cli php-sqlite3 php-mbstring libapache2-mod-php
a2enmod rewrite
if [[ ! -d "$REPO_DIR/.git" ]]; then
  git clone https://github.com/Taiju-h/reserva.git "$REPO_DIR"
fi
if [[ -e "$REPO_DIR/.env" ]]; then
  echo "Existing .env detected. Will preserve its contents."
else
  APP_KEY="$(openssl rand -hex 32)"
  umask 027
  cat > "$REPO_DIR/.env" <<EOF
APP_KEY=$APP_KEY
ADMIN_PASSWORD_HASH=CHANGE_ME_USING_php_password_hash
APP_TIMEZONE=Asia/Tokyo
DB_PATH=storage/reservations.sqlite
EOF
fi
mkdir -p "$REPO_DIR/storage"
chown root:www-data "$REPO_DIR/.env"
chmod 640 "$REPO_DIR/.env"
chown -R www-data:www-data "$REPO_DIR/storage"
chmod 750 "$REPO_DIR/storage"
cat > "$APACHE_CONF" <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $REPO_DIR/public
    <Directory $REPO_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride FileInfo
        Require all granted
    </Directory>
    ErrorLog /var/log/apache2/reserva-error.log
    CustomLog /var/log/apache2/reserva-access.log combined
</VirtualHost>
EOF
# Refuse to take over an existing virtualhost server name.
if grep -Riq --exclude=reserva.conf "ServerName[[:space:]]\+$DOMAIN" /etc/apache2/sites-enabled/; then
  echo "Domain already used by an Apache VirtualHost. Removing unenabled config." >&2
  rm -f "$APACHE_CONF"
  exit 1
fi
apache2ctl configtest
a2ensite reserva.conf
apache2ctl configtest
systemctl reload apache2

cat <<EOF

Apache vhost created: $DOMAIN
Code: $REPO_DIR
Configuration: $REPO_DIR/.env

IMPORTANT: Live access must stay disabled until ALL steps are complete:
1. Set ADMIN_PASSWORD_HASH in .env using:
   php -r 'echo password_hash(readline("Admin password: "), PASSWORD_DEFAULT), PHP_EOL;'
2. Ensure DNS points to this VPS and issue an HTTPS certificate:
   sudo apt-get install certbot python3-certbot-apache
   sudo certbot --apache -d $DOMAIN --redirect
3. Configure web application firewall/rate limits, backups and email delivery.
4. Verify /admin, DB permissions and two simultaneous same-slot reservations.
This bootstrap does NOT activate real reservations and should not be exposed to the public before steps 1-4.
EOF
