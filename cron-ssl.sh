#!/usr/bin/env bash
# =============================================================================
# cron-ssl.sh — Installation / mise à jour du cron de renouvellement SSL
# =============================================================================
# Usage :
#   bash cron-ssl.sh          → installe le cron
#   bash cron-ssl.sh --status → affiche le cron actuel sans modifier
#   bash cron-ssl.sh --remove → supprime le cron
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
RENEW_SCRIPT="$ROOT/deploy/certbot-renew.sh"
CRON_FILE="/etc/cron.d/portefolio-ssl"
CRON_USER="root"

# Certbot recommande de vérifier 2x/semaine (lundi et jeudi à 3h17)
# Le script ne renouvelle que si expiration < 30 jours → zéro effet les autres fois
CRON_SCHEDULE="17 3 * * 1,4"
CRON_LINE="$CRON_SCHEDULE $CRON_USER bash $RENEW_SCRIPT"

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYAN='\033[0;36m'; RST='\033[0m'

# =============================================================================
# --status : affichage seul
# =============================================================================
if [[ "${1:-}" == "--status" ]]; then
    echo -e "${CYAN}Cron SSL actuel :${RST}"
    if [[ -f "$CRON_FILE" ]]; then
        cat "$CRON_FILE"
        echo ""
        # Calculer la prochaine exécution approximative
        echo -e "${CYAN}Prochaines vérifications : lundi et jeudi à 03h17${RST}"
        # Afficher la date d'expiration du certificat
        DOMAIN="bryanvalcasara.com"
        CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
        if sudo test -f "$CERT"; then
            EXPIRY=$(sudo openssl x509 -enddate -noout -in "$CERT" | cut -d= -f2)
            EXPIRY_EPOCH=$(date -d "$EXPIRY" +%s)
            DAYS_LEFT=$(( (EXPIRY_EPOCH - $(date +%s)) / 86400 ))
            echo -e "${CYAN}Certificat expire le : $EXPIRY ($DAYS_LEFT jours restants)${RST}"
        fi
    else
        echo -e "${YLW}Aucun cron SSL installé.${RST}"
    fi
    exit 0
fi

# =============================================================================
# --remove : suppression
# =============================================================================
if [[ "${1:-}" == "--remove" ]]; then
    if [[ -f "$CRON_FILE" ]]; then
        sudo rm -f "$CRON_FILE"
        echo -e "${GRN}✔  Cron SSL supprimé.${RST}"
    else
        echo -e "${YLW}⚠  Aucun cron SSL à supprimer.${RST}"
    fi
    exit 0
fi

# =============================================================================
# Installation
# =============================================================================
echo -e "${CYAN}══ Installation du cron SSL ══${RST}\n"

# Vérifications préalables
[[ -f "$RENEW_SCRIPT" ]] || { echo -e "${RED}✘  Script absent : $RENEW_SCRIPT${RST}"; exit 1; }
command -v certbot >/dev/null 2>&1  || { echo -e "${RED}✘  Certbot non installé${RST}"; exit 1; }

DOMAIN="bryanvalcasara.com"
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
sudo test -f "$CERT" || { echo -e "${RED}✘  Certificat absent : $CERT (lance d'abord Certbot)${RST}"; exit 1; }

# Rendre le script de renouvellement exécutable
chmod 750 "$RENEW_SCRIPT"
echo -e "  ${GRN}✔${RST}  Script de renouvellement : $RENEW_SCRIPT"

# Écrire le fichier cron dans /etc/cron.d/
sudo tee "$CRON_FILE" > /dev/null << EOF
# Renouvellement SSL Let's Encrypt — portefolio
# Vérifie 2x/semaine ; renouvelle uniquement si expiration < 30 jours
# Généré par cron-ssl.sh le $(date '+%Y-%m-%d')
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

$CRON_LINE
EOF

# /etc/cron.d/ exige des droits stricts (root, pas de group/other write)
sudo chown root:root "$CRON_FILE"
sudo chmod 644 "$CRON_FILE"

echo -e "  ${GRN}✔${RST}  Cron installé : $CRON_FILE"
echo -e "  ${GRN}✔${RST}  Planification : lundi et jeudi à 03h17 (2x/semaine)"

# Afficher l'état du certificat actuel
EXPIRY=$(sudo openssl x509 -enddate -noout -in "$CERT" | cut -d= -f2)
EXPIRY_EPOCH=$(date -d "$EXPIRY" +%s)
DAYS_LEFT=$(( (EXPIRY_EPOCH - $(date +%s)) / 86400 ))
echo -e "  ${GRN}✔${RST}  Certificat actuel expire le : ${CYAN}$EXPIRY${RST} ($DAYS_LEFT jours)"

echo ""
echo -e "  ${CYAN}ℹ${RST}  Logs : /var/log/letsencrypt/renew-cron.log"
echo -e "  ${CYAN}ℹ${RST}  Statut : bash cron-ssl.sh --status"
echo -e "  ${CYAN}ℹ${RST}  Supprimer : bash cron-ssl.sh --remove"
echo ""
echo -e "${GRN}✅  Cron SSL opérationnel.${RST}"
