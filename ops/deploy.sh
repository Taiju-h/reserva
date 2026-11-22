#!/usr/bin/env bash
# Deployment for isolated /var/www/hanamaru. Run as the restricted deploy account.
# Usage: bash ops/deploy.sh <full-main-commit-sha>
set -Eeuo pipefail
umask 027
APP_DIR=/var/www/hanamaru
TARGET="${1:-}"
DOMAIN=hanamaru.uzero.style
if [[ ! "$TARGET" =~ ^[0-9a-f]{40}$ ]]; then echo "Expected full commit SHA" >&2; exit 2; fi
cd "$APP_DIR"
if [[ ! -d .git || ! -f public/index.php ]]; then echo "Invalid application checkout" >&2; exit 1; fi
if [[ ! -f .env ]]; then echo "Missing .env; refusing deployment" >&2; exit 1; fi
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Tracked working-tree changes detected; refusing to overwrite" >&2; exit 1
fi
PREVIOUS="$(git rev-parse HEAD)"
BACKUP_DIR="$APP_DIR/storage/deploy-backups"
mkdir -p "$BACKUP_DIR"
git fetch --no-tags origin main
if ! git cat-file -e "$TARGET^{commit}" 2>/dev/null; then
  echo "Target not fetched from origin" >&2; exit 1
fi
if ! git merge-base --is-ancestor "$TARGET" "origin/main"; then
  echo "Target SHA is not on main" >&2; exit 1
fi
TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT
git archive "$TARGET" | tar -x -C "$TMP"
find "$TMP/public" "$TMP/app" -type f -name '*.php' -print0 | xargs -0 -r -n1 php8.3 -l >/dev/null
# The MySQL backup MUST finish before updating the application code.
command -v docker >/dev/null || { echo "Docker is required" >&2; exit 1; }
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$BACKUP_DIR/mysql-$TIMESTAMP-$PREVIOUS.sql"
docker compose --env-file /dev/null exec -T mysql sh -ec \
  'MYSQL_PWD="$DB_PASSWORD" exec mysqldump --single-transaction --quick --set-gtid-purged=OFF -u "$DB_USERNAME" "$DB_DATABASE"' > "$BACKUP"
test -s "$BACKUP" || { echo "MySQL backup is empty" >&2; exit 1; }
chmod 600 "$BACKUP"
echo "MySQL snapshot: $BACKUP"

rollback() {
  code="$?"
  trap - EXIT
  if [[ "$code" -ne 0 && "${SWITCHED:-0}" == 1 ]]; then
    echo "Deployment failed, restoring previous code revision: $PREVIOUS" >&2
    git reset --hard "$PREVIOUS" || true
    docker compose --env-file /dev/null up -d --build || true
    echo "MySQL snapshot preserved at: $BACKUP" >&2
    echo "No automatic database restore: migrations require a coordinated procedure." >&2
  fi
  cleanup
  exit "$code"
}
trap rollback EXIT
git reset --hard "$TARGET"
SWITCHED=1
find public app -type f -name '*.php' -print0 | xargs -0 -r -n1 php8.3 -l >/dev/null
docker compose --env-file /dev/null config --quiet
docker compose --env-file /dev/null up -d --build
# Verify the app inside Docker, rather than host Apache (which has its own PHP runtime).
HTTP_CODE="$(curl -sS --connect-timeout 3 --max-time 15 -o "$TMP/response.json" -w '%{http_code}' "http://127.0.0.1:18081/?action=config")"
if [[ "$HTTP_CODE" != "200" ]]; then
  echo "Container HTTP health check failed: $HTTP_CODE" >&2; exit 1
fi
python3 - "$TMP/response.json" <<'PY'
import json,sys
with open(sys.argv[1],encoding="utf-8") as f: data=json.load(f)
if not isinstance(data.get("menus"),list) or not isinstance(data.get("settings"),dict):
    raise SystemExit("Unexpected API health response")
PY
echo "DEPLOY SUCCESS: $PREVIOUS -> $TARGET"
echo "MySQL backup: ${BACKUP:-none}"
