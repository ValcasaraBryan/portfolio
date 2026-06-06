#!/usr/bin/env bash
# =============================================================================
# migrate-stamp.sh — Initialisation du registre de migrations
#
# À exécuter UNE SEULE FOIS sur une base qui existe déjà et dont les
# migrations ont été appliquées manuellement (sans tracking).
#
# Crée la table `schema_migrations` si besoin, puis marque les fichiers
# *.sql de bdd/migrations/ comme « déjà appliqués » — sans les rejouer.
# Les fichiers après --until sont laissés à migrate.sh.
#
# Usage :
#   bash bdd/migrate-stamp.sh                              → marque toutes les migrations
#   bash bdd/migrate-stamp.sh --until 024_site_config.sql → marque jusqu'à ce fichier inclus
#   bash bdd/migrate-stamp.sh [--until <fichier>] --dry-run
# =============================================================================
set -euo pipefail

DRY_RUN=false
UNTIL=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=true ;;
    --until)   shift; UNTIL="$1" ;;
    *) echo "Option inconnue : $1" >&2; exit 1 ;;
  esac
  shift
done

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYAN='\033[0;36m'; RST='\033[0m'
BOLD='\033[1m'

ok()   { echo -e "  ${GRN}✔${RST}  $*"; }
skip() { echo -e "  ${CYAN}→${RST}  $* (déjà enregistrée)"; }
warn() { echo -e "  ${YLW}⚠${RST}  $*"; }
fail() { echo -e "  ${RED}✘${RST}  $*" >&2; exit 1; }

ENV_FILE="$(dirname "$0")/../.env"
[[ -f "$ENV_FILE" ]] || fail ".env introuvable : $ENV_FILE"
source "$ENV_FILE"

: "${DB_HOST:?}" ; : "${DB_PORT:?}" ; : "${DB_USER:?}" ; : "${DB_PASSWORD:?}" ; : "${DB_NAME:?}"

MIGRATIONS_DIR="$(dirname "$0")/migrations"
[[ -d "$MIGRATIONS_DIR" ]] || fail "Dossier migrations absent : $MIGRATIONS_DIR"

_sql() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -se "$1" 2>/dev/null; }

echo -e "\n${BOLD}${CYAN}══ Initialisation du registre schema_migrations ══${RST}"
[[ -n "$UNTIL" ]] && warn "Arrêt après : $UNTIL (les suivantes seront jouées par migrate.sh)"
$DRY_RUN        && warn "Mode dry-run — aucun enregistrement ne sera effectué"
echo ""

if ! $DRY_RUN; then
  _sql "CREATE TABLE IF NOT EXISTS schema_migrations (
    migration   VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
  ok "Table schema_migrations prête"
  echo ""
fi

shopt -s nullglob
SQL_FILES=("$MIGRATIONS_DIR"/*.sql)
shopt -u nullglob

[[ ${#SQL_FILES[@]} -eq 0 ]] && { warn "Aucun fichier *.sql trouvé."; exit 0; }

if [[ -n "$UNTIL" ]] && [[ ! -f "$MIGRATIONS_DIR/$UNTIL" ]]; then
  fail "--until '$UNTIL' : fichier introuvable dans $MIGRATIONS_DIR"
fi

STAMPED=0
SKIPPED=0

for file in "${SQL_FILES[@]}"; do
  name="$(basename "$file")"

  if $DRY_RUN; then
    echo -e "  ${YLW}[dry-run]${RST}  marquerait : $name"
    (( STAMPED++ )) || true
  else
    already=$(_sql "SELECT COUNT(*) FROM schema_migrations WHERE migration='$name';" 2>/dev/null || echo 0)
    if [[ "$already" -gt 0 ]]; then
      skip "$name"
      (( SKIPPED++ )) || true
    else
      _sql "INSERT INTO schema_migrations (migration) VALUES ('$name');"
      ok "$name"
      (( STAMPED++ )) || true
    fi
  fi

  [[ -n "$UNTIL" && "$name" == "$UNTIL" ]] && break
done

echo ""
if $DRY_RUN; then
  echo -e "${YLW}Dry-run : $STAMPED à enregistrer.${RST}"
else
  echo -e "${GRN}${BOLD}✅  $STAMPED enregistrée(s), $SKIPPED déjà présente(s).${RST}"
  echo -e "    Lancez maintenant : ${CYAN}bash bdd/migrate.sh${RST}\n"
fi
