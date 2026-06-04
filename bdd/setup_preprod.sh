#!/usr/bin/env bash
# =============================================================================
# setup_preprod.sh — Création de la base preprod-portefolio et de son user
# =============================================================================
# Usage : bash bdd/setup_preprod.sh
# Utilise root/root pour créer la DB et l'utilisateur preprod dédié.
# Les credentials du user sont lus dans le .env du projet.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "ERROR: .env introuvable : $ENV_FILE"
    exit 1
fi

source "$ENV_FILE"

: "${DB_HOST:?DB_HOST non défini dans .env}"
: "${DB_PORT:?DB_PORT non défini dans .env}"
: "${DB_USER:?DB_USER non défini dans .env}"
: "${DB_PASSWORD:?DB_PASSWORD non défini dans .env}"
: "${DB_NAME:?DB_NAME non défini dans .env}"

# Sur Ubuntu, root MySQL utilise auth_socket — connexion via sudo mysql
MYSQL_ROOT="sudo mysql"

echo ">>> Création de la base '$DB_NAME'..."
$MYSQL_ROOT -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
echo "    Base '$DB_NAME' — OK"

echo ">>> Création de l'utilisateur '$DB_USER'@'localhost'..."
$MYSQL_ROOT -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
echo "    Utilisateur '$DB_USER' — OK"

echo ">>> Attribution des droits sur '$DB_NAME'..."
$MYSQL_ROOT -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
$MYSQL_ROOT -e "FLUSH PRIVILEGES;"
echo "    Droits accordés — OK"

echo ""
echo "✅  Setup preprod terminé."
echo "    DB   : $DB_NAME"
echo "    User : $DB_USER @ $DB_HOST:$DB_PORT"
