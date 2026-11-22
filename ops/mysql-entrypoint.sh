#!/bin/sh
# Local MySQL only: copy credentials passed through Compose raw env_file.
set -eu
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
export MYSQL_DATABASE="$DB_DATABASE"
export MYSQL_USER="$DB_USERNAME"
export MYSQL_PASSWORD="$DB_PASSWORD"
export MYSQL_RANDOM_ROOT_PASSWORD=yes
exec /usr/local/bin/docker-entrypoint.sh mysqld
