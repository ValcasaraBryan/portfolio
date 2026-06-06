#!/usr/bin/env bash
# =============================================================================
# hotfix-prod.sh — Déploiement rapide d'un correctif en PRODUCTION
#
# Enchaîne : confirmation → backup BDD → git pull → migrations SQL → reload Apache.
# N'exécute PAS le build JS/CSS ni Composer, sauf si demandé.
#
# Usage :
#   bash hotfix-prod.sh                  → pull + backup + migrations + reload
#   bash hotfix-prod.sh --with-composer  → + composer install
#   bash hotfix-prod.sh --with-build     → + npm build
#   bash hotfix-prod.sh --skip-backup    → sans backup BDD (déconseillé)
#   bash hotfix-prod.sh --dry-run        → simule sans rien modifier
#   bash hotfix-prod.sh --help
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"

WITH_COMPOSER=false
WITH_BUILD=false
SKIP_BACKUP=false
DRY_RUN=false

for arg in "$@"; do
  case "$arg" in
    --with-composer) WITH_COMPOSER=true ;;
    --with-build)    WITH_BUILD=true ;;
    --skip-backup)   SKIP_BACKUP=true ;;
    --dry-run)       DRY_RUN=true ;;
    --help)
      grep '^#' "$0" | sed 's/^# \?//'
      exit 0 ;;
  esac
done

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYAN='\033[0;36m'; RST='\033[0m'
BOLD='\033[1m'

ok()      { echo -e "  ${GRN}✔${RST}  $*"; }
fail()    { echo -e "  ${RED}✘${RST}  $*" >&2; exit 1; }
info()    { echo -e "  ${CYAN}→${RST}  $*"; }
warn()    { echo -e "  ${YLW}⚠${RST}  $*"; }
section() { echo -e "\n${BOLD}${CYAN}══ $1 ══${RST}"; }
dry()     { echo -e "  ${YLW}[dry-run]${RST}  $*"; }

DOMAIN="bryanvalcasara.com"

# =============================================================================
# Bannière production
# =============================================================================
echo -e "\n${RED}${BOLD}╔══════════════════════════════════════════╗"
echo -e "║        ⚠  DÉPLOIEMENT PRODUCTION  ⚠       ║"
echo -e "║  ${DOMAIN}  ║"
echo -e "╚══════════════════════════════════════════╝${RST}\n"

if $DRY_RUN; then
  echo -e "${YLW}${BOLD}Mode dry-run — aucune modification ne sera effectuée.${RST}\n"
fi

# =============================================================================
# Confirmation obligatoire (sauf dry-run)
# =============================================================================
if ! $DRY_RUN; then
  cd "$ROOT"
  BRANCH="$(git rev-parse --abbrev-ref HEAD)"

  echo -e "  Branche courante : ${BOLD}$BRANCH${RST}"
  echo -e "  Cible            : ${BOLD}https://$DOMAIN${RST}\n"
  echo -en "  Tapez le nom de la branche pour confirmer le déploiement : "
  read -r CONFIRM

  if [[ "$CONFIRM" != "$BRANCH" ]]; then
    echo -e "\n  ${RED}Annulé${RST} — '$CONFIRM' ≠ '$BRANCH'"
    exit 1
  fi
  echo ""
fi

# =============================================================================
# Charger .env
# =============================================================================
source "$ROOT/.env" 2>/dev/null || true
: "${DB_HOST:?DB_HOST non défini dans .env}"
: "${DB_PORT:?DB_PORT non défini dans .env}"
: "${DB_USER:?DB_USER non défini dans .env}"
: "${DB_PASSWORD:?DB_PASSWORD non défini dans .env}"
: "${DB_NAME:?DB_NAME non défini dans .env}"

# =============================================================================
# 1. Backup BDD (avant toute modification)
# =============================================================================
section "Backup base de données"

BACKUP_ROOT="/home/ubuntu/backup/preprod-portfolio"
TIMESTAMP="$(date '+%Y-%m-%d_%H-%M-%S')"
BACKUP_DIR="${BACKUP_ROOT}/hotfix_${TIMESTAMP}"

if $SKIP_BACKUP; then
  warn "Backup ignoré (--skip-backup)"
elif $DRY_RUN; then
  dry "mysqldump → ${BACKUP_DIR}/db_${DB_NAME}_${TIMESTAMP}.sql.gz"
else
  mkdir -p "$BACKUP_DIR"
  DB_DUMP="${BACKUP_DIR}/db_${DB_NAME}_${TIMESTAMP}.sql.gz"

  mysqldump \
    -h "$DB_HOST" -P "$DB_PORT" \
    -u "$DB_USER" -p"$DB_PASSWORD" \
    --single-transaction --no-tablespaces --routines --triggers \
    "$DB_NAME" | gzip > "$DB_DUMP"

  ok "Backup : $DB_DUMP ($(du -sh "$DB_DUMP" | cut -f1))"
fi

# =============================================================================
# 2. Git pull
# =============================================================================
section "Récupération des modifications (git pull)"

cd "$ROOT"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
info "Branche : $BRANCH"

BEFORE="$(git rev-parse HEAD)"

if $DRY_RUN; then
  git fetch origin "$BRANCH" --quiet
  AFTER="$(git rev-parse "origin/$BRANCH")"
  if [[ "$BEFORE" == "$AFTER" ]]; then
    dry "Déjà à jour — rien à tirer"
  else
    dry "Commits à récupérer :"
    git log --oneline "$BEFORE..origin/$BRANCH"
  fi
else
  git pull --ff-only origin "$BRANCH" || fail "git pull échoué (merge conflicts ?)"
  AFTER="$(git rev-parse HEAD)"
  if [[ "$BEFORE" == "$AFTER" ]]; then
    ok "Déjà à jour"
  else
    ok "$(git log --oneline "$BEFORE..$AFTER" | wc -l) commit(s) récupéré(s)"
    git log --oneline "$BEFORE..$AFTER" | while read -r line; do info "  $line"; done
  fi
fi

# =============================================================================
# 3. Migrations SQL
# =============================================================================
section "Migrations SQL"

MIGRATE_CMD="bash $ROOT/bdd/migrate.sh"
$DRY_RUN && MIGRATE_CMD="$MIGRATE_CMD --dry-run"

$MIGRATE_CMD

# =============================================================================
# 4. Composer (optionnel — forcé si composer.lock a changé dans ce pull)
# =============================================================================
COMPOSER_LOCK_CHANGED=false
if [[ "$BEFORE" != "$AFTER" ]] && \
   git diff "${BEFORE}..${AFTER}" --name-only 2>/dev/null | grep -q "composer.lock"; then
  COMPOSER_LOCK_CHANGED=true
fi

if $WITH_COMPOSER || $COMPOSER_LOCK_CHANGED; then
  section "Dépendances PHP (Composer)"
  $COMPOSER_LOCK_CHANGED && ! $WITH_COMPOSER && \
    warn "composer.lock modifié — installation automatique des dépendances"
  if $DRY_RUN; then
    dry "composer install --no-dev --optimize-autoloader"
  else
    composer install --no-dev --optimize-autoloader --no-interaction 2>&1 \
      | grep -E "^(Installing|Nothing|Generating)" | while read -r line; do info "$line"; done
    ok "Composer à jour"
  fi
fi

# =============================================================================
# 5. Build assets (optionnel)
# =============================================================================
if $WITH_BUILD; then
  section "Build assets (JS + CSS)"
  if $DRY_RUN; then
    dry "bash build.sh"
  else
    bash "$ROOT/build.sh" 2>&1 | grep -E "✔|✅|❌"
    ok "Assets reconstruits"
  fi
fi

# =============================================================================
# 6. Rechargement Apache
# =============================================================================
section "Rechargement Apache"

if $DRY_RUN; then
  dry "sudo systemctl reload apache2"
else
  sudo apache2ctl configtest 2>&1 | grep -Ev "^$|Syntax OK" | while read -r line; do info "$line"; done
  sudo systemctl reload apache2
  ok "Apache rechargé"
fi

# =============================================================================
# 7. Healthcheck
# =============================================================================
section "Healthcheck"

check_url() {
  local label="$1" url="$2" expected="$3"
  local code
  code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 "$url" 2>/dev/null)
  if [[ "$code" == "$expected" ]]; then
    ok "$label → HTTP $code"
  else
    warn "$label → HTTP $code (attendu $expected)"
  fi
}

if $DRY_RUN; then
  dry "healthcheck ignoré en dry-run"
else
  check_url "Accueil (HTTP) " "http://$DOMAIN/"                              "301"
  check_url "Accueil (HTTPS)" "https://$DOMAIN/"                             "200"
  check_url "API /profile    " "https://$DOMAIN/api/profile.php"             "200"
  check_url "Admin /admin/   " "https://$DOMAIN/admin/"                      "200"
  check_url "Sitemap         " "https://$DOMAIN/sitemap.xml"                 "200"
  check_url "PHP bloqué      " "https://$DOMAIN/uploads/cv/test.php"         "403"
fi

# =============================================================================
echo -e "\n${GRN}${BOLD}✅  Hotfix production déployé.${RST}"
$DRY_RUN && echo -e "    ${YLW}(dry-run — aucun changement réel effectué)${RST}"
! $DRY_RUN && ! $SKIP_BACKUP && echo -e "    Backup disponible : ${CYAN}${BACKUP_DIR}${RST}"
echo ""
