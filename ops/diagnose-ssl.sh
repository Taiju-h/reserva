#!/usr/bin/env bash
# Read-only SSL diagnosis for hanamaru.uzero.style.
set -uo pipefail
D=hanamaru.uzero.style
echo '=== Apache virtual hosts ==='
if command -v apache2ctl >/dev/null; then apache2ctl -S 2>&1 | grep -E '(:80|:443|hanamaru|default server)' || true; fi
echo '=== HTTPS response and validation ==='
curl --silent --show-error --max-time 15 --output /dev/null --write-out 'HTTPS status=%{http_code} redirect=%{redirect_url} verify=%{ssl_verify_result} remote=%{remote_ip}\n' "https://$D/" || true
echo '=== Admin HTTPS ==='
curl --silent --show-error --max-time 15 --output /dev/null --write-out 'ADMIN status=%{http_code} verify=%{ssl_verify_result}\n' "https://$D/admin" || true
echo '=== HTTP to HTTPS redirect ==='
curl --silent --show-error --max-time 12 --output /dev/null --write-out 'HTTP status=%{http_code} redirect=%{redirect_url}\n' "http://$D/" || true
echo '=== HTTPS headers ==='
curl -sSI --max-time 15 "https://$D/" | grep -Ei '^(HTTP/|location:|strict-transport-security:|content-security-policy:|x-forwarded-|server:)' || true
echo '=== Certificate presented for hostname ==='
timeout 15 openssl s_client -connect "$D:443" -servername "$D" -verify_return_error </dev/null 2>/dev/null |
  openssl x509 -noout -subject -issuer -dates -ext subjectAltName 2>&1 || true
echo '=== Certificate renewal ==='
if command -v certbot >/dev/null; then certbot certificates 2>/dev/null | grep -E '(Certificate Name|Domains:|Expiry Date:|Certificate Path:)' || true; fi
echo '=== Local Apache SSL config paths ==='
find /etc/apache2/sites-enabled -maxdepth 1 -type l -printf '%f -> %l\n' 2>/dev/null || true
echo '=== HTTPS HTML references with explicit HTTP (first 15) ==='
curl -sS --max-time 15 "https://$D/" | grep -Eo '(src|href|action)=["'\'' ]*http://[^" '\''>]+' | head -15 || true
echo '=== Apache syntax ==='
if command -v apache2ctl >/dev/null; then apache2ctl configtest 2>&1 || true; fi
echo '=== Conclusion ==='
echo 'This is read-only. If verification is 0 but Edge still warns, inspect Edge DevTools > Security/Console for mixed content or browser-specific issues.'
