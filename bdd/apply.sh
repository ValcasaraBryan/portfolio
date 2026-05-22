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

SCRIPT_DIR="$(dirname "$0")"
CMD="mysql -h \"$DB_HOST\" -P \"$DB_PORT\" -u \"$DB_USER\" -p\"$DB_PASSWORD\" \"$DB_NAME\""

echo ">>> Applying schema.sql..."
eval "$CMD" < "$SCRIPT_DIR/schema.sql"
echo "    schema.sql — OK"

echo ">>> Applying seed.sql..."
eval "$CMD" < "$SCRIPT_DIR/seed.sql"
echo "    seed.sql   — OK"

echo ""
echo "Done. Database '$DB_NAME' is ready."
