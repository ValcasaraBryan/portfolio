#!/usr/bin/env bash
# =============================================================================
# hotfix.sh — Déploiement rapide d'un correctif en preprod
#
# Enchaîne : git pull → migrations SQL pendantes → rechargement Apache.
# N'exécute PAS le build JS/CSS ni Composer, sauf si demandé.
#
# Usage :
#   bash hotfix.sh                  → pull + migrations + reload Apache
#   bash hotfix.sh --with-composer  → + composer install
#   bash hotfix.sh --with-build     → + npm build
#   bash hotfix.sh --dry-run        → simule sans rien modifier
#   bash hotfix.sh --help
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"

WITH_COMPOSER=false
WITH_BUILD=false
DRY_RUN=false

for arg in "$@"; do
  case "$arg" in
    --with-composer) WITH_COMPOSER=true ;;
    --with-build)    WITH_BUILD=true ;;
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
section() { echo -e "\n${BOLD}${CYAN}══ $1 ══${RST}"; }
dry()     { echo -e "  ${YLW}[dry-run]${RST}  $*"; }

$DRY_RUN && echo -e "\n${YLW}${BOLD}Mode dry-run — aucune modification ne sera effectuée.${RST}"

# =============================================================================
# 1. Git pull
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
# 2. Migrations SQL
# =============================================================================
section "Migrations SQL"

MIGRATE_CMD="bash $ROOT/bdd/migrate.sh"
$DRY_RUN && MIGRATE_CMD="$MIGRATE_CMD --dry-run"

$MIGRATE_CMD

# =============================================================================
# 3. Composer (optionnel)
# =============================================================================
if $WITH_COMPOSER; then
  section "Dépendances PHP (Composer)"
  if $DRY_RUN; then
    dry "composer install --no-dev --optimize-autoloader"
  else
    composer install --no-dev --optimize-autoloader --no-interaction 2>&1 \
      | grep -E "^(Installing|Nothing|Generating)" | while read -r line; do info "$line"; done
    ok "Composer à jour"
  fi
fi

# =============================================================================
# 4. Build assets (optionnel)
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
# 5. Rechargement Apache
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
echo -e "\n${GRN}${BOLD}✅  Hotfix déployé.${RST}"
$DRY_RUN && echo -e "    ${YLW}(dry-run — aucun changement réel effectué)${RST}"
echo ""
