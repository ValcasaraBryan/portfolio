#!/usr/bin/env bash
# Sauvegarde automatisée du portfolio : base de données + fichiers critiques
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_NAME="$(basename "$SCRIPT_DIR")"
BACKUP_ROOT="/home/ubuntu/backup/${PROJECT_NAME}"
TIMESTAMP="$(date '+%Y-%m-%d_%H-%M-%S')"
BACKUP_DIR="${BACKUP_ROOT}/${TIMESTAMP}"
LOG_FILE="${BACKUP_ROOT}/backup.log"
ENV_FILE="${SCRIPT_DIR}/.env"

# Nombre de sauvegardes à conserver (configurable via setup-backup.sh)
RETENTION="${BACKUP_RETENTION:-10}"

# ── Utilitaires ──────────────────────────────────────────────────────────────
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
die() { log "ERREUR: $*"; exit 1; }

# ── Vérifications préalables ─────────────────────────────────────────────────
[[ -f "$ENV_FILE" ]] || die ".env introuvable à $ENV_FILE"

# Charger les variables d'environnement
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${DB_HOST:?DB_HOST absent du .env}"
: "${DB_PORT:?DB_PORT absent du .env}"
: "${DB_USER:?DB_USER absent du .env}"
: "${DB_PASSWORD:?DB_PASSWORD absent du .env}"
: "${DB_NAME:?DB_NAME absent du .env}"

# ── Création du dossier de sauvegarde ────────────────────────────────────────
mkdir -p "$BACKUP_DIR"
log "=== Sauvegarde démarrée → ${BACKUP_DIR}"

# ── 1. Dump de la base de données ────────────────────────────────────────────
DB_DUMP="${BACKUP_DIR}/db_${DB_NAME}_${TIMESTAMP}.sql.gz"
log "Dump BDD : ${DB_NAME}..."

mysqldump \
  -h "$DB_HOST" \
  -P "$DB_PORT" \
  -u "$DB_USER" \
  -p"$DB_PASSWORD" \
  --single-transaction \
  --no-tablespaces \
  --routines \
  --triggers \
  "$DB_NAME" | gzip > "$DB_DUMP"

log "Dump BDD terminé : $(du -sh "$DB_DUMP" | cut -f1)"

# ── 2. Sauvegarde des fichiers critiques ─────────────────────────────────────
FILES_ARCHIVE="${BACKUP_DIR}/files_${TIMESTAMP}.tar.gz"
log "Archivage des fichiers..."

# Inclure : uploads, .env, deploy, bdd (hors dumps déjà archivés)
# Exclure : node_modules, vendor, .git, les sauvegardes elles-mêmes
tar -czf "$FILES_ARCHIVE" \
  --exclude="$SCRIPT_DIR/node_modules" \
  --exclude="$SCRIPT_DIR/site/vendor" \
  --exclude="$SCRIPT_DIR/.git" \
  --exclude="$SCRIPT_DIR/bdd/dumps" \
  -C "$(dirname "$SCRIPT_DIR")" \
  "$PROJECT_NAME"

log "Archivage terminé : $(du -sh "$FILES_ARCHIVE" | cut -f1)"

# ── 3. Manifest de la sauvegarde ─────────────────────────────────────────────
MANIFEST="${BACKUP_DIR}/MANIFEST.txt"
{
  echo "Date        : $(date '+%Y-%m-%d %H:%M:%S')"
  echo "Projet      : ${PROJECT_NAME}"
  echo "Base de données : ${DB_NAME}@${DB_HOST}:${DB_PORT}"
  echo "Dump BDD    : $(basename "$DB_DUMP") ($(du -sh "$DB_DUMP" | cut -f1))"
  echo "Archive     : $(basename "$FILES_ARCHIVE") ($(du -sh "$FILES_ARCHIVE" | cut -f1))"
  echo "Taille totale : $(du -sh "$BACKUP_DIR" | cut -f1)"
} > "$MANIFEST"

log "Manifest créé."

# ── 4. Rotation des anciennes sauvegardes ────────────────────────────────────
log "Rotation : conservation des ${RETENTION} dernières sauvegardes..."

mapfile -t OLD_BACKUPS < <(
  find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d | sort | head -n "-${RETENTION}"
)

for OLD in "${OLD_BACKUPS[@]}"; do
  log "Suppression ancienne sauvegarde : $(basename "$OLD")"
  rm -rf "$OLD"
done

# ── 5. Résumé ────────────────────────────────────────────────────────────────
TOTAL=$(du -sh "$BACKUP_ROOT" | cut -f1)
COUNT=$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d | wc -l)
log "=== Sauvegarde terminée — ${COUNT} sauvegarde(s) conservée(s), espace total : ${TOTAL}"
