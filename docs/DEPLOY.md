# UZERO VPS shared-host deployment (proposed, NOT executed)

Known historic host: Ubuntu + Apache + PHP 8.3, UZERO under /var/www/uzero.style. Confirm CURRENT SSH host and free RAM/disk/CPU before installation; no server changes were made.

## Isolation

- Independent checkout: /var/www/reserva
- Apache DocumentRoot: /var/www/reserva/public
- SQLite DB: /var/www/reserva/storage/reservations.sqlite
- Own VirtualHost and HTTPS certificate
- No UZERO database access, no reuse of UZERO document root, no modification to UZERO deploy helper
- Deploy by git fetch / pull from Taiju-h/reserva once authenticated access and change-management are arranged

## Prerequisites

- PHP 8.3 with pdo_sqlite and mbstring; Apache mod_rewrite
- Dedicated storage permissions, restrictive filesystem permissions
- Private .env including APP_KEY and ADMIN_PASSWORD_HASH, outside Apache DocumentRoot
- Valid TLS certificate and redirect to HTTPS
- Database backups (including WAL) with tested restoration
- Rate limiting at Apache or Cloudflare and error monitoring
- SMTP/email notification before live use
- Confirm timezone, pricing and privacy policy

## Smoke tests

1. GET / returns menu cards.
2. GET /?action=config returns active menu/settings (no secret).
3. GET /?action=availability&menu_id=1&date=YYYY-MM-DD returns open slots.
4. Booking requires CSRF, validated name/email and open slot.
5. Race two bookings for same slot: exactly one may succeed.
6. /admin rejects unauthorized access, login uses password hash.
7. Menu/setting changes only work after login.
8. Cancellation frees the slot; restoration checks for conflict.
9. Apache cannot serve .env or storage.
10. SMTP and backup restoration are verified before launch.

## Current limits

This is an initial MVP, not a complete RESERVA replacement. Payment, user self-service cancellation, staff schedules, multi-tenant onboarding, waitlists, email confirmations, calendar sync and in-app spam throttling are not implemented yet. Do not accept live bookings until required features, review and tests are completed.
