#!/usr/bin/env bash
# =============================================================================
# deploy-preprod.sh — Déploiement de l'environnement preprod
# =============================================================================
# Usage :
#   bash deploy-preprod.sh              → déploiement complet
#   bash deploy-preprod.sh --skip-build → sans rebuild JS/CSS
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
SKIP_BUILD=false
[[ "${1:-}" == "--skip-build" ]] && SKIP_BUILD=true

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYAN='\033[0;36m'; RST='\033[0m'
BOLD='\033[1m'

ok()      { echo -e "  ${GRN}✔${RST}  $*"; }
fail()    { echo -e "  ${RED}✘${RST}  $*"; exit 1; }
info()    { echo -e "  ${CYAN}→${RST}  $*"; }
section() { echo -e "\n${BOLD}${CYAN}══ $1 ══${RST}"; }

DOMAIN="preprod.bryanvalcasara.com"
LIVE_DIR="/etc/letsencrypt/live/$DOMAIN"
ARCHIVE_DIR="/etc/letsencrypt/archive/$DOMAIN"
APACHE_AVAILABLE="/etc/apache2/sites-available"
APACHE_ENABLED="/etc/apache2/sites-enabled"
DEPLOY_DIR="$ROOT/deploy/apache"

# =============================================================================
# 1. Prérequis
# =============================================================================
section "Vérification des prérequis"

command -v php      >/dev/null 2>&1 && ok "PHP     $(php -r 'echo PHP_VERSION;')"         || fail "PHP non installé"
command -v mysql    >/dev/null 2>&1 && ok "MySQL   $(mysql --version | awk '{print $3}')"  || fail "MySQL non installé"
command -v apache2  >/dev/null 2>&1 && ok "Apache  $(apache2 -v 2>&1 | head -1 | awk '{print $3}')" || fail "Apache non installé"
command -v composer >/dev/null 2>&1 && ok "Composer $(composer --version 2>/dev/null | awk '{print $3}')" || fail "Composer non installé"

if ! $SKIP_BUILD; then
    command -v node >/dev/null 2>&1 && ok "Node    $(node --version)" || fail "Node.js non installé"
    command -v npm  >/dev/null 2>&1 && ok "npm     $(npm --version)"  || fail "npm non installé"
fi

# =============================================================================
# 2. Base de données — setup si nécessaire
# =============================================================================
section "Base de données preprod"

source "$ROOT/.env" 2>/dev/null || true

if mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" \
         -u "${DB_USER:-preprod_portefolio}" -p"${DB_PASSWORD:-}" \
         -e "USE \`${DB_NAME:-preprod-portefolio}\`;" 2>/dev/null; then
    ok "Base '${DB_NAME:-preprod-portefolio}' accessible"
else
    info "Base absente — exécution de setup_preprod.sh..."
    bash "$ROOT/bdd/setup_preprod.sh"
    info "Application du schéma + migrations..."
    bash "$ROOT/bdd/apply.sh"
    bash "$ROOT/bdd/migrate.sh"
    ok "Base initialisée"
fi

# =============================================================================
# 3. Configs Apache — liens symboliques
# =============================================================================
section "Configuration Apache (liens symboliques)"

for conf in preprod.conf preprod-le-ssl.conf; do
    src="$DEPLOY_DIR/$conf"
    dst="$APACHE_AVAILABLE/$conf"

    [[ -f "$src" ]] || fail "Config absente : $src"

    if [[ -e "$dst" || -L "$dst" ]]; then
        current_target="$(readlink -f "$dst" 2>/dev/null || echo "")"
        if [[ "$current_target" != "$src" ]]; then
            sudo rm -f "$dst"
            info "Ancienne config supprimée : $dst"
        fi
    fi

    if [[ ! -L "$dst" ]]; then
        sudo ln -s "$src" "$dst"
        ok "Lien créé : $dst → $src"
    else
        ok "Lien déjà en place : $conf"
    fi

    chmod 644 "$src"
done

# =============================================================================
# 4. Modules Apache
# =============================================================================
section "Modules Apache"

for mod in rewrite ssl headers expires deflate; do
    if sudo a2enmod "$mod" 2>/dev/null | grep -q "already"; then
        ok "mod_$mod déjà actif"
    else
        ok "mod_$mod activé"
    fi
done

# Réparer les liens Certbot si nécessaire
if [[ -d "$ARCHIVE_DIR" ]]; then
    for link_name in fullchain privkey chain cert; do
        link="$LIVE_DIR/${link_name}.pem"
        if [[ ! -f "$link" ]]; then
            latest=$(ls "$ARCHIVE_DIR/${link_name}"*.pem 2>/dev/null | sort -V | tail -1)
            if [[ -n "$latest" ]]; then
                sudo ln -sf "../../archive/$DOMAIN/$(basename "$latest")" "$link"
                ok "Lien SSL recréé : ${link_name}.pem"
            fi
        fi
    done
fi

# Activer les vhosts preprod
for conf in preprod.conf preprod-le-ssl.conf; do
    if [[ "$conf" == "preprod-le-ssl.conf" ]] && [[ ! -f "$LIVE_DIR/fullchain.pem" ]]; then
        echo -e "  ${YLW}⚠${RST}  Certificat SSL absent — $conf ignoré"
        echo -e "       Relance : sudo certbot --apache -d $DOMAIN && bash deploy-preprod.sh --skip-build"
        continue
    fi

    if sudo a2ensite "$conf" 2>/dev/null | grep -q "already"; then
        ok "$conf déjà activé"
    else
        ok "$conf activé"
    fi
done

# =============================================================================
# 5. Dépendances PHP
# =============================================================================
section "Dépendances PHP (Composer)"

cd "$ROOT"
composer install --no-dev --optimize-autoloader --no-interaction 2>&1 \
    | grep -E "^(Installing|Nothing|Generating)" | while read -r line; do info "$line"; done
ok "Composer à jour"

# =============================================================================
# 6. Build assets JS/CSS
# =============================================================================
if ! $SKIP_BUILD; then
    section "Build assets (JS + CSS)"
    npm install --silent 2>/dev/null
    bash "$ROOT/build.sh" 2>&1 | grep -E "✔|✅|❌"
else
    section "Build assets"
    info "Ignoré (--skip-build)"
fi

# =============================================================================
# 7. Droits de fichiers
# =============================================================================
section "Sécurité — droits de fichiers"

bash "$ROOT/securite.sh" --fix 2>&1 \
    | grep -E "✘|corrigé|✅|⚠" \
    | grep -v "^$"

# =============================================================================
# 8. Rechargement Apache
# =============================================================================
section "Rechargement Apache"

sudo apache2ctl configtest 2>&1 | grep -v "^$" | while read -r line; do info "$line"; done
sudo systemctl restart apache2
ok "Apache redémarré"

# =============================================================================
# 9. Healthcheck
# =============================================================================
section "Healthcheck"

check_url() {
    local label="$1" url="$2" expected="$3"
    local code
    code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 "$url" 2>/dev/null)
    if [[ "$code" == "$expected" ]]; then
        ok "$label → HTTP $code"
    else
        echo -e "  ${YLW}⚠${RST}  $label → HTTP $code (attendu $expected)"
    fi
}

check_url "Page d'accueil (HTTP) " "http://$DOMAIN/"           "301"
check_url "Page d'accueil (HTTPS)" "https://$DOMAIN/"          "200"
check_url "API /profile           " "https://$DOMAIN/api/profile.php" "200"
check_url "Admin /admin/          " "https://$DOMAIN/admin/"   "200"
check_url "Sitemap                " "https://$DOMAIN/sitemap.xml" "200"
check_url "PHP bloqué uploads     " "https://$DOMAIN/uploads/cv/test.php" "403"

# =============================================================================
echo -e "\n${GRN}${BOLD}✅  Déploiement preprod terminé.${RST}"
echo -e "    Site disponible sur ${CYAN}https://$DOMAIN${RST}\n"
