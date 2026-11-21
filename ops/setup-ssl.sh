#!/usr/bin/env bash
# Safely provisions a TLS certificate and an isolated HTTPS Apache vhost.
# Initial web access remains localhost-only; does not alter UZERO vhosts.
set -Eeuo pipefail
DOMAIN="${1:-}"
EMAIL="${2:-}"
if [[ "$DOMAIN" != "hanamaru.uzero.style" ]]; then
  echo "Only hanamaru.uzero.style is supported by this setup." >&2; exit 2
fi
if [[ "$EUID" -ne 0 ]]; then echo "Run with sudo" >&2; exit 1; fi
ROOT=/var/www/hanamaru
HTTP_CONF=/etc/apache2/sites-available/hanamaru.conf
TLS_CONF=/etc/apache2/sites-available/hanamaru-ssl.conf
CHALLENGE=/var/lib/letsencrypt
if [[ ! -f "$HTTP_CONF" || ! -f "$ROOT/public/index.php" ]]; then
  echo "Run ops/install-ubuntu.sh first." >&2; exit 1
fi
if [[ -e "$TLS_CONF" ]]; then echo "Existing TLS config; not overwriting $TLS_CONF" >&2; exit 1; fi
if [[ -z "$EMAIL" ]]; then
  if [[ ! -t 0 ]]; then echo "Provide your email as argument 2" >&2; exit 2; fi
  read -r -p "Email for Let's Encrypt renewal notices: " EMAIL
fi
if [[ ! "$EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
  echo "Valid email required." >&2; exit 2
fi
getent ahostsv4 "$DOMAIN" || { echo "DNS A record unavailable; try after DNS propagation." >&2; exit 1; }
DEBIAN_FRONTEND=noninteractive apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y certbot openssl
a2enmod ssl headers alias rewrite
mkdir -p "$CHALLENGE/.well-known/acme-challenge"
chmod 755 "$CHALLENGE" "$CHALLENGE/.well-known" "$CHALLENGE/.well-known/acme-challenge"
# Add a narrowly scoped, public ACME path while the application remains private.
# Insert just before </VirtualHost>; idempotent across retries.
if ! grep -Fq 'Alias /.well-known/acme-challenge/' "$HTTP_CONF"; then
  cp -p "$HTTP_CONF" "$HTTP_CONF.before-acme.$(date +%Y%m%d%H%M%S).bak"
  python3 - "$HTTP_CONF" "$CHALLENGE" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
needle="</VirtualHost>"
if s.count(needle)!=1: raise SystemExit("Unexpected Apache virtualhost file")
fragment=f'''    Alias /.well-known/acme-challenge/ {sys.argv[2]}/.well-known/acme-challenge/
    <Directory {sys.argv[2]}/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>
'''
p.write_text(s.replace(needle,fragment+needle))
PY
fi
apache2ctl configtest
systemctl reload apache2
certbot certonly --webroot -w "$CHALLENGE" -d "$DOMAIN" --email "$EMAIL" --agree-tos --non-interactive --keep-until-expiring
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
if [[ ! -f "$CERT" || ! -f "$KEY" ]]; then echo "Certificate not found" >&2; exit 1; fi
cat > "$TLS_CONF" <<EOF
<VirtualHost *:443>
    ServerName $DOMAIN
    DocumentRoot $ROOT/public
    SSLEngine on
    SSLCertificateFile $CERT
    SSLCertificateKeyFile $KEY
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    <Directory $ROOT/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require local
    </Directory>
    ErrorLog /var/log/apache2/hanamaru-ssl-error.log
    CustomLog /var/log/apache2/hanamaru-ssl-access.log combined
</VirtualHost>
EOF
apache2ctl configtest
a2ensite hanamaru-ssl.conf
apache2ctl configtest
systemctl reload apache2
echo "TLS certificate configured. Website remains private (Require local)."
echo "Test locally: curl --resolve $DOMAIN:443:127.0.0.1 https://$DOMAIN/ -I"
echo "Do not open to the public until login secrets, throttling, backups and testing are complete."
