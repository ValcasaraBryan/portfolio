#!/usr/bin/env bash
# Configure le système de sauvegarde automatisée du portfolio.
# Usage : ./setup-backup.sh [--retention N] [--schedule "cron-expr"] [--run-now] [--uninstall]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_NAME="$(basename "$SCRIPT_DIR")"
BACKUP_SCRIPT="${SCRIPT_DIR}/backup.sh"
BACKUP_ROOT="/home/ubuntu/backup/${PROJECT_NAME}"
CRON_TAG="# backup-${PROJECT_NAME}"

# ── Valeurs par défaut ───────────────────────────────────────────────────────
RETENTION=7
SCHEDULE="0 3 * * *"   # tous les jours à 3h du matin
RUN_NOW=false
UNINSTALL=false

# ── Parsing des arguments ────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
  case "$1" in
    --retention)  RETENTION="$2";   shift 2 ;;
    --schedule)   SCHEDULE="$2";    shift 2 ;;
    --run-now)    RUN_NOW=true;     shift   ;;
    --uninstall)  UNINSTALL=true;   shift   ;;
    -h|--help)
      cat <<EOF
Usage : ./setup-backup.sh [OPTIONS]

Options :
  --retention N          Nombre de sauvegardes à conserver (défaut : 7)
  --schedule "EXPR"      Expression cron (défaut : "0 3 * * *" = chaque jour à 3h)
  --run-now              Lance une sauvegarde immédiatement après la configuration
  --uninstall            Supprime la tâche cron (ne supprime pas les sauvegardes)
  -h, --help             Affiche cette aide

Exemples :
  ./setup-backup.sh                           # config par défaut (daily @ 3h, keep 7)
  ./setup-backup.sh --retention 14            # garder 14 sauvegardes
  ./setup-backup.sh --schedule "0 */6 * * *"  # toutes les 6 heures
  ./setup-backup.sh --run-now                 # configure ET lance tout de suite
  ./setup-backup.sh --uninstall               # retire la tâche cron
EOF
      exit 0 ;;
    *) echo "Option inconnue : $1" >&2; exit 1 ;;
  esac
done

# ── Utilitaires ──────────────────────────────────────────────────────────────
info()    { echo "  [INFO]  $*"; }
success() { echo "  [OK]    $*"; }
warn()    { echo "  [WARN]  $*"; }
section() { echo; echo "▶ $*"; }

# ── Désinstallation ──────────────────────────────────────────────────────────
if [[ "$UNINSTALL" == true ]]; then
  section "Désinstallation de la tâche cron"
  CURRENT_CRON="$(crontab -l 2>/dev/null || true)"
  if echo "$CURRENT_CRON" | grep -qF "$CRON_TAG"; then
    echo "$CURRENT_CRON" | grep -v "$CRON_TAG" | crontab -
    success "Tâche cron supprimée."
  else
    warn "Aucune tâche cron trouvée pour ce projet."
  fi
  echo
  echo "Les sauvegardes existantes dans ${BACKUP_ROOT} sont conservées."
  exit 0
fi

echo
echo "╔══════════════════════════════════════════════════════════╗"
echo "║       Configuration de la sauvegarde automatisée        ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo "  Projet      : ${PROJECT_NAME}"
echo "  Destination : ${BACKUP_ROOT}"
echo "  Planification : ${SCHEDULE}"
echo "  Rétention   : ${RETENTION} sauvegardes"

# ── 1. Création des dossiers ─────────────────────────────────────────────────
section "Création des dossiers"
mkdir -p "$BACKUP_ROOT"
success "Dossier créé/vérifié : ${BACKUP_ROOT}"

# ── 2. Vérification du script de sauvegarde ──────────────────────────────────
section "Vérification du script backup.sh"
[[ -f "$BACKUP_SCRIPT" ]] || { echo "  ERREUR : backup.sh introuvable à ${BACKUP_SCRIPT}"; exit 1; }
chmod +x "$BACKUP_SCRIPT"
success "backup.sh prêt : ${BACKUP_SCRIPT}"

# ── 3. Écriture de la configuration de rétention ────────────────────────────
section "Configuration de la rétention"
RETENTION_FILE="${BACKUP_ROOT}/.config"
cat > "$RETENTION_FILE" <<EOF
# Configuration de la sauvegarde - généré par setup-backup.sh le $(date '+%Y-%m-%d %H:%M:%S')
BACKUP_RETENTION=${RETENTION}
EOF
success "Rétention configurée : ${RETENTION} sauvegardes"

# ── 4. Installation de la tâche cron ────────────────────────────────────────
section "Installation de la tâche cron"

# Charger BACKUP_RETENTION depuis le fichier de config dans l'environnement cron
CRON_LINE="${SCHEDULE} BACKUP_RETENTION=${RETENTION} ${BACKUP_SCRIPT} >> ${BACKUP_ROOT}/backup.log 2>&1 ${CRON_TAG}"

CURRENT_CRON="$(crontab -l 2>/dev/null || true)"

# Supprimer l'ancienne entrée si elle existe
FILTERED_CRON="$(echo "$CURRENT_CRON" | grep -vF "$CRON_TAG" || true)"

# Ajouter la nouvelle
NEW_CRON="${FILTERED_CRON}
${CRON_LINE}"

echo "$NEW_CRON" | crontab -
success "Tâche cron installée : ${SCHEDULE}"

# ── 5. Vérification de la tâche installée ───────────────────────────────────
section "Cron actuel"
crontab -l | grep -F "$CRON_TAG" | sed 's/^/  /'

# ── 6. Vérification mysqldump ────────────────────────────────────────────────
section "Vérification des dépendances"
if command -v mysqldump &>/dev/null; then
  success "mysqldump disponible : $(mysqldump --version | head -1)"
else
  warn "mysqldump non trouvé — installez mysql-client avant la première sauvegarde"
fi

# ── 7. Lancement immédiat ────────────────────────────────────────────────────
if [[ "$RUN_NOW" == true ]]; then
  section "Lancement immédiat de la sauvegarde"
  BACKUP_RETENTION="$RETENTION" bash "$BACKUP_SCRIPT"
fi

# ── Résumé ───────────────────────────────────────────────────────────────────
echo
echo "╔══════════════════════════════════════════════════════════╗"
echo "║                  Configuration terminée                 ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo "  Sauvegardes dans  : ${BACKUP_ROOT}"
echo "  Logs              : ${BACKUP_ROOT}/backup.log"
echo "  Planification     : ${SCHEDULE}"
echo "  Rétention         : ${RETENTION} sauvegardes"
echo
echo "  Pour lancer manuellement :"
echo "    bash ${BACKUP_SCRIPT}"
echo
echo "  Pour modifier la configuration :"
echo "    bash setup-backup.sh --retention 14 --schedule \"0 */6 * * *\""
echo
echo "  Pour désinstaller :"
echo "    bash setup-backup.sh --uninstall"
echo
