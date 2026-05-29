#!/usr/bin/env bash
# =============================================================================
# securite.sh — Audit et correction des droits de fichiers (production)
# =============================================================================
# Usage :
#   bash securite.sh          → audit seul, aucune modification
#   bash securite.sh --fix    → audit + correction automatique
# =============================================================================

ROOT="$(cd "$(dirname "$0")" && pwd)"
OWNER="ubuntu"
WEB_USER="www-data"
FIX=false
ISSUES=0
FIXED=0

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYAN='\033[0;36m'; RST='\033[0m'

[[ "${1:-}" == "--fix" ]] && FIX=true

# Sudo disponible sans mot de passe ?
CAN_SUDO=false
sudo -n true 2>/dev/null && CAN_SUDO=true

# ---------------------------------------------------------------------------
# check [path] [mode] [owner] [group]
#   Affiche l'état du fichier/dossier, corrige si --fix.
# ---------------------------------------------------------------------------
check() {
    local path="$1" want_mode="$2" want_owner="$3" want_group="$4"

    if [[ ! -e "$path" ]]; then
        echo -e "  ${YLW}⚠  ABSENT${RST}  $path"
        return
    fi

    local cur_mode cur_owner cur_group
    cur_mode=$(stat -c "%a" "$path")
    cur_owner=$(stat -c "%U" "$path")
    cur_group=$(stat -c "%G" "$path")

    if [[ "$cur_mode" == "$want_mode" && "$cur_owner" == "$want_owner" && "$cur_group" == "$want_group" ]]; then
        echo -e "  ${GRN}✔${RST}  ${cur_mode} ${cur_owner}:${cur_group}  ${path#"$ROOT/"}"
        return
    fi

    echo -e "  ${RED}✘${RST}  actuel=${cur_mode} ${cur_owner}:${cur_group}  →  voulu=${want_mode} ${want_owner}:${want_group}  ${path#"$ROOT/"}"
    ISSUES=$((ISSUES + 1))

    if ! $FIX; then return; fi

    # Fichiers appartenant (ou devant appartenir) à www-data → sudo requis
    local need_sudo=false
    [[ "$want_owner" == "$WEB_USER" || "$cur_owner" == "$WEB_USER" ]] && need_sudo=true

    if $need_sudo && ! $CAN_SUDO; then
        echo -e "     ${YLW}→ sudo requis — ignoré${RST}"
        return
    fi

    if $need_sudo; then
        sudo chown "${want_owner}:${want_group}" "$path" 2>/dev/null
        sudo chmod "$want_mode"                  "$path" 2>/dev/null
    else
        chown "${want_owner}:${want_group}" "$path" 2>/dev/null
        chmod "$want_mode"                  "$path" 2>/dev/null
    fi

    FIXED=$((FIXED + 1))
    echo -e "     ${GRN}→ corrigé${RST}"
}

section() { echo -e "\n${CYAN}══ $1 ══${RST}"; }

# =============================================================================
# Matrice de droits attendus
# =============================================================================

$FIX \
    && echo -e "${YLW}Mode : audit + correction${RST}\n" \
    || echo -e "${CYAN}Mode : audit seul  (bash securite.sh --fix  pour corriger)${RST}\n"

# ---------------------------------------------------------------------------
section "Racine du projet"
# ---------------------------------------------------------------------------
check "$ROOT"                 751 "$OWNER" "$OWNER"   # 751 : www-data peut traverser (x) mais pas lister (pas r)
check "$ROOT/.env"            640 "$OWNER" "$WEB_USER"   # lisible par www-data, personne d'autre
check "$ROOT/build.sh"        750 "$OWNER" "$OWNER"
check "$ROOT/securite.sh"     750 "$OWNER" "$OWNER"

for f in composer.json composer.lock package.json package-lock.json .gitignore README.md; do
    [[ -f "$ROOT/$f" ]] && check "$ROOT/$f" 640 "$OWNER" "$OWNER"
done

# ---------------------------------------------------------------------------
section "bdd/  (scripts sensibles — non exposés au web)"
# ---------------------------------------------------------------------------
check "$ROOT/bdd" 750 "$OWNER" "$OWNER"

while IFS= read -r -d '' f; do
    check "$f" 640 "$OWNER" "$OWNER"
done < <(find "$ROOT/bdd" -type f -name "*.sql" -print0 | sort -z)

while IFS= read -r -d '' f; do
    check "$f" 750 "$OWNER" "$OWNER"
done < <(find "$ROOT/bdd" -type f -name "*.sh" -print0 | sort -z)

while IFS= read -r -d '' f; do
    check "$f" 640 "$OWNER" "$OWNER"
done < <(find "$ROOT/bdd" -type f -name "*.php" -print0 | sort -z)

# ---------------------------------------------------------------------------
section "site/  (document root Apache)"
# ---------------------------------------------------------------------------
check "$ROOT/site"           755 "$OWNER" "$OWNER"
check "$ROOT/site/.htaccess" 644 "$OWNER" "$OWNER"

# Fichiers racine de site/
while IFS= read -r -d '' f; do
    check "$f" 644 "$OWNER" "$OWNER"
done < <(find "$ROOT/site" -maxdepth 1 -type f -print0 | sort -z)

# ---------------------------------------------------------------------------
section "site/api/  (endpoints PHP)"
# ---------------------------------------------------------------------------
check "$ROOT/site/api" 755 "$OWNER" "$OWNER"

while IFS= read -r -d '' f; do
    check "$f" 644 "$OWNER" "$OWNER"
done < <(find "$ROOT/site/api" -type f -print0 | sort -z)

# ---------------------------------------------------------------------------
section "site/admin/  (panneau d'administration)"
# ---------------------------------------------------------------------------
check "$ROOT/site/admin" 755 "$OWNER" "$OWNER"

while IFS= read -r -d '' f; do
    check "$f" 644 "$OWNER" "$OWNER"
done < <(find "$ROOT/site/admin" -type f -print0 | sort -z)

# ---------------------------------------------------------------------------
section "site/assets/  (JS, CSS, images statiques)"
# ---------------------------------------------------------------------------
check "$ROOT/site/assets" 755 "$OWNER" "$OWNER"

while IFS= read -r -d '' d; do
    check "$d" 755 "$OWNER" "$OWNER"
done < <(find "$ROOT/site/assets" -type d -print0 | sort -z)

while IFS= read -r -d '' f; do
    check "$f" 644 "$OWNER" "$OWNER"
done < <(find "$ROOT/site/assets" -type f -print0 | sort -z)

# ---------------------------------------------------------------------------
section "site/i18n/  (traductions JSON)"
# ---------------------------------------------------------------------------
if [[ -d "$ROOT/site/i18n" ]]; then
    check "$ROOT/site/i18n" 755 "$OWNER" "$OWNER"
    while IFS= read -r -d '' f; do
        check "$f" 644 "$OWNER" "$OWNER"
    done < <(find "$ROOT/site/i18n" -type f -print0 | sort -z)
fi

# ---------------------------------------------------------------------------
section "site/uploads/  (fichiers uploadés — propriétaire www-data)"
# ---------------------------------------------------------------------------
check "$ROOT/site/uploads" 755 "$WEB_USER" "$WEB_USER"

while IFS= read -r -d '' d; do
    check "$d" 755 "$WEB_USER" "$WEB_USER"
done < <(find "$ROOT/site/uploads" -mindepth 1 -type d -print0 | sort -z)

while IFS= read -r -d '' f; do
    check "$f" 644 "$WEB_USER" "$WEB_USER"
done < <(find "$ROOT/site/uploads" -type f -print0 | sort -z)

# ---------------------------------------------------------------------------
section "site/vendor/  (dépendances PHP Composer)"
# ---------------------------------------------------------------------------
if [[ -d "$ROOT/site/vendor" ]]; then
    while IFS= read -r -d '' d; do
        check "$d" 755 "$OWNER" "$OWNER"
    done < <(find "$ROOT/site/vendor" -type d -print0 | sort -z)

    while IFS= read -r -d '' f; do
        check "$f" 644 "$OWNER" "$OWNER"
    done < <(find "$ROOT/site/vendor" -type f -print0 | sort -z)
fi

# ---------------------------------------------------------------------------
# Résumé
# ---------------------------------------------------------------------------
echo ""
echo "────────────────────────────────────────────────────────"

if [[ $ISSUES -eq 0 ]]; then
    echo -e "${GRN}✅  Aucun problème — droits conformes.${RST}"
elif $FIX; then
    local_remaining=$((ISSUES - FIXED))
    echo -e "${GRN}✅  $FIXED correction(s) appliquée(s).${RST}"
    if [[ $local_remaining -gt 0 ]]; then
        echo -e "${YLW}⚠   $local_remaining problème(s) non corrigé(s) (sudo requis — relance avec sudo bash securite.sh --fix).${RST}"
    fi
else
    echo -e "${RED}✘   $ISSUES problème(s) détecté(s).${RST}"
    echo -e "    Lance : ${YLW}bash securite.sh --fix${RST}"
fi

echo ""
