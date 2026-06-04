#!/usr/bin/env bash
# =============================================================================
# certbot-renew.sh — Renouvellement du certificat SSL Let's Encrypt
# =============================================================================
# Exécuté automatiquement par cron (voir cron-ssl.sh).
# Certbot ne renouvelle réellement que si le certificat expire dans < 30 jours.
# =============================================================================

set -euo pipefail

LOG_DIR="/var/log/letsencrypt"
LOG_FILE="$LOG_DIR/renew-cron.log"
DOMAIN="bryanvalcasara.com"
TIMESTAMP="$(date '+%Y-%m-%d %H:%M:%S')"

log() { echo "[$TIMESTAMP] $*" | sudo tee -a "$LOG_FILE" > /dev/null; }

log "=== Vérification renouvellement SSL ($DOMAIN) ==="

# Vérifier la date d'expiration avant de tenter le renouvellement
EXPIRY=$(sudo openssl x509 -enddate -noout \
    -in "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" 2>/dev/null \
    | cut -d= -f2)
EXPIRY_EPOCH=$(date -d "$EXPIRY" +%s 2>/dev/null || date -j -f "%b %d %T %Y %Z" "$EXPIRY" +%s 2>/dev/null)
NOW_EPOCH=$(date +%s)
DAYS_LEFT=$(( (EXPIRY_EPOCH - NOW_EPOCH) / 86400 ))

log "Expiration : $EXPIRY ($DAYS_LEFT jours restants)"

if [[ $DAYS_LEFT -gt 30 ]]; then
    log "Renouvellement non nécessaire (> 30 jours restants) — aucune action."
    exit 0
fi

log "Renouvellement requis ($DAYS_LEFT jours) — lancement de certbot..."

# Renouveler le certificat
if sudo certbot renew --quiet --no-random-sleep-on-renew 2>&1 | sudo tee -a "$LOG_FILE" > /dev/null; then
    log "Certificat renouvelé avec succès."
    # Recharger Apache pour prendre en compte le nouveau certificat
    sudo systemctl reload apache2
    log "Apache rechargé."
else
    log "ERREUR : le renouvellement a échoué. Vérifier $LOG_FILE"
    exit 1
fi

log "=== Fin ==="
