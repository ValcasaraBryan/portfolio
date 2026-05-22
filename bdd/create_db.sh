#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="$(dirname "$0")/../.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: .env file not found at $ENV_FILE"
  exit 1
fi

source "$ENV_FILE"

: "${DB_HOST:?DB_HOST is not set in .env}"
: "${DB_PORT:?DB_PORT is not set in .env}"
: "${DB_USER:?DB_USER is not set in .env}"
: "${DB_PASSWORD:?DB_PASSWORD is not set in .env}"
: "${DB_NAME:?DB_NAME is not set in .env}"

echo ">>> Creating database '$DB_NAME' if it does not exist..."
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" \
  -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
echo "    Database '$DB_NAME' — OK"
