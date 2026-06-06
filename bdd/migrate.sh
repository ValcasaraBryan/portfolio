#!/usr/bin/env bash
# =============================================================================
# migrate.sh — Applique uniquement les migrations SQL non encore jouées.
# Chaque fichier *.sql de bdd/migrations/ est tracké dans la table
# `schema_migrations` ; il n'est jamais rejoué une fois marqué.
#
# Convention de nommage : YYYYMMDD_NNN_description.sql
#   ex. 20260606_001_add_api_tokens_table.sql
#
# Usage :
#   bash bdd/migrate.sh            → migrations pendantes seulement
#   bash bdd/migrate.sh --dry-run  → affiche ce qui serait joué, sans l'exécuter
# =============================================================================
set -euo pipefail

DRY_RUN=false
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=true

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYAN='\033[0;36m'; RST='\033[0m'
ok()   { echo -e "  ${GRN}✔${RST}  $*"; }
skip() { echo -e "  ${CYAN}→${RST}  $* (déjà appliquée)"; }
warn() { echo -e "  ${YLW}⚠${RST}  $*"; }
fail() { echo -e "  ${RED}✘${RST}  $*" >&2; exit 1; }

ENV_FILE="$(dirname "$0")/../.env"
[[ -f "$ENV_FILE" ]] || fail ".env introuvable : $ENV_FILE"
source "$ENV_FILE"

: "${DB_HOST:?DB_HOST non défini dans .env}"
: "${DB_PORT:?DB_PORT non défini dans .env}"
: "${DB_USER:?DB_USER non défini dans .env}"
: "${DB_PASSWORD:?DB_PASSWORD non défini dans .env}"
: "${DB_NAME:?DB_NAME non défini dans .env}"

MIGRATIONS_DIR="$(dirname "$0")/migrations"
[[ -d "$MIGRATIONS_DIR" ]] || fail "Dossier migrations absent : $MIGRATIONS_DIR"

# Fonction utilitaire pour exécuter une requête SQL
_sql() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -se "$1" 2>/dev/null; }
_sql_file() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$1"; }

# Créer la table de suivi si elle n'existe pas encore
_sql "CREATE TABLE IF NOT EXISTS schema_migrations (
  migration   VARCHAR(255) NOT NULL PRIMARY KEY,
  applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

# Lister les fichiers .sql triés par nom (ordre chronologique si préfixe YYYYMMDD)
shopt -s nullglob
SQL_FILES=("$MIGRATIONS_DIR"/*.sql)
shopt -u nullglob

if [[ ${#SQL_FILES[@]} -eq 0 ]]; then
  warn "Aucun fichier *.sql dans $MIGRATIONS_DIR"
  exit 0
fi

APPLIED=0
SKIPPED=0

for file in "${SQL_FILES[@]}"; do
  name="$(basename "$file")"

  already=$(_sql "SELECT COUNT(*) FROM schema_migrations WHERE migration='$name';" 2>/dev/null || echo 0)

  if [[ "$already" -gt 0 ]]; then
    skip "$name"
    (( SKIPPED++ )) || true
    continue
  fi

  if $DRY_RUN; then
    echo -e "  ${YLW}[dry-run]${RST}  jouerait : $name"
    (( APPLIED++ )) || true
    continue
  fi

  echo -e "  ${CYAN}▶${RST}  Application de $name..."
  _sql_file "$file" || fail "Échec sur $name — migration interrompue"
  _sql "INSERT INTO schema_migrations (migration) VALUES ('$name');"
  ok "$name"
  (( APPLIED++ )) || true
done

echo ""
if $DRY_RUN; then
  echo -e "${YLW}Dry-run : $APPLIED à appliquer, $SKIPPED déjà jouées.${RST}"
else
  echo -e "${GRN}Migrations : $APPLIED appliquée(s), $SKIPPED déjà jouée(s).${RST}"
fi
