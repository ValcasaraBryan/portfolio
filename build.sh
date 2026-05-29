#!/usr/bin/env bash
# =============================================================================
# build.sh — Pipeline de build assets (JS + CSS)
#
# Ce script doit être ré-exécuté manuellement après toute modification de :
#   - site/assets/js/app.js
#   - site/assets/js/app-utils.js
#   - site/assets/js/i18n.js
#   - site/assets/css/main.css
#
# Usage :
#   bash build.sh
#
# Pré-requis :
#   Node.js >= 14 + devDependencies installées (npm install)
#   nvm disponible dans ~/.nvm/nvm.sh (ou node/npx dans le PATH)
# =============================================================================

# ---------------------------------------------------------------------------
# 0. Résolution du PATH Node (avant set -e pour éviter les faux exit codes nvm)
# ---------------------------------------------------------------------------
NVM_SCRIPT="$HOME/.nvm/nvm.sh"
if [ -s "$NVM_SCRIPT" ]; then
  # shellcheck source=/dev/null
  . "$NVM_SCRIPT" 2>/dev/null || true
  nvm use 20 --silent 2>/dev/null || true
fi

# Activer strict mode après le sourcing nvm (qui retourne des codes non-zéro bénins)
set -euo pipefail

TERSER="./node_modules/.bin/terser"
CLEANCSS="./node_modules/.bin/cleancss"
OBFUSCATOR="./node_modules/.bin/javascript-obfuscator"

for bin in "$TERSER" "$CLEANCSS" "$OBFUSCATOR"; do
  if [ ! -x "$bin" ]; then
    echo "❌  Dépendance manquante : $bin"
    echo "    → Lance : npm install"
    exit 1
  fi
done

JS_DIR="site/assets/js"
CSS_DIR="site/assets/css"
HTML="site/index.html"
TMP_CONCAT="$JS_DIR/_concat.tmp.js"
TMP_MIN="$JS_DIR/_min.tmp.js"

echo "🔨  Build assets…"

# ---------------------------------------------------------------------------
# 1. Nettoyage des anciens bundles
# ---------------------------------------------------------------------------
rm -f "$JS_DIR"/bundle.*.min.js
rm -f "$CSS_DIR/main.min.css"

# ---------------------------------------------------------------------------
# 2. Concaténation JS (ordre d'exécution impératif)
# ---------------------------------------------------------------------------
cat \
  "$JS_DIR/i18n.js" \
  "$JS_DIR/app-utils.js" \
  "$JS_DIR/app.js" \
  > "$TMP_CONCAT"

echo "   ✔  Concaténation JS ($(wc -c < "$TMP_CONCAT") octets bruts)"

# ---------------------------------------------------------------------------
# 3. Minification avec terser
# ---------------------------------------------------------------------------
"$TERSER" "$TMP_CONCAT" \
  --compress \
    drop_console=false,\
passes=2 \
  --mangle \
  --output "$TMP_MIN"

SIZE_CONCAT=$(wc -c < "$TMP_CONCAT")
SIZE_MIN=$(wc -c < "$TMP_MIN")
echo "   ✔  Minification terser ($SIZE_CONCAT → $SIZE_MIN octets)"

# ---------------------------------------------------------------------------
# 4. Obfuscation avec javascript-obfuscator
# ---------------------------------------------------------------------------
# Stratégie retenue : obfuscation agressive mais sans self-defending
# (évite les erreurs dans certains environnements strict).
# compact=true  : sortie sur une ligne
# identifier-names-generator=hexadecimal : noms de variables en _0x...
# string-array + rotate + shuffle : les chaînes littérales sont extraites,
#   encodées en base64 et réinjectées via un tableau rotatif.
# dead-code-injection=false : désactivé (augmente trop la taille du fichier)
# self-defending=false : désactivé (incompatible avec strict mode)

HASH=$(sha256sum "$TMP_MIN" | head -c 8)
BUNDLE="$JS_DIR/bundle.${HASH}.min.js"

"$OBFUSCATOR" "$TMP_MIN" \
  --output "$BUNDLE" \
  --compact true \
  --identifier-names-generator hexadecimal \
  --string-array true \
  --string-array-encoding "base64" \
  --string-array-rotate true \
  --string-array-shuffle true \
  --string-array-threshold 0.75 \
  --split-strings false \
  --dead-code-injection false \
  --self-defending false \
  --debug-protection false \
  --disable-console-output false

SIZE_OBF=$(wc -c < "$BUNDLE")
echo "   ✔  Obfuscation → bundle.${HASH}.min.js ($SIZE_MIN → $SIZE_OBF octets)"

# Nettoyage fichiers temporaires
rm -f "$TMP_CONCAT" "$TMP_MIN"

# ---------------------------------------------------------------------------
# 5. Minification CSS
# ---------------------------------------------------------------------------
"$CLEANCSS" \
  --output "$CSS_DIR/main.min.css" \
  "$CSS_DIR/main.css"

SIZE_CSS_SRC=$(wc -c < "$CSS_DIR/main.css")
SIZE_CSS_MIN=$(wc -c < "$CSS_DIR/main.min.css")
echo "   ✔  CSS minifié ($SIZE_CSS_SRC → $SIZE_CSS_MIN octets)"

# ---------------------------------------------------------------------------
# 6. Patch index.html — mise à jour des références
# ---------------------------------------------------------------------------
# Cas 1 : les 3 <script> sources sont encore présents (premier build)
# Cas 2 : un bundle.*.min.js existe déjà (build suivant — on met juste à jour le hash)
BUNDLE_FILENAME="bundle.${HASH}.min.js"

# Cas 1 : remplacement des 3 scripts sources (multi-lignes)
perl -i -0pe \
  "s|<script src=\"\./assets/js/i18n\.js\"></script>\s*\n\s*<script src=\"\./assets/js/app-utils\.js\"></script>\s*\n\s*<script src=\"\./assets/js/app\.js\"></script>|<script src=\"./assets/js/${BUNDLE_FILENAME}\"></script>|g" \
  "$HTML"

# Cas 2 : mise à jour du hash d'un bundle déjà présent
perl -i -pe \
  "s|assets/js/bundle\.[a-f0-9]{8}\.min\.js|assets/js/${BUNDLE_FILENAME}|g" \
  "$HTML"

# CSS : remplace main.css (non-minifié) par main.min.css si besoin
perl -i -pe \
  's|assets/css/main\.css(?!\.min)|assets/css/main.min.css|g' \
  "$HTML"

echo "   ✔  index.html patché (→ ${BUNDLE_FILENAME}, main.min.css)"

# ---------------------------------------------------------------------------
# 7. Récapitulatif
# ---------------------------------------------------------------------------

# Estimation de la taille gzip (via gzip -9 en pipe — indicateur réseau)
GZIP_SRC=$(cat "$JS_DIR/i18n.js" "$JS_DIR/app-utils.js" "$JS_DIR/app.js" | gzip -9 | wc -c)
GZIP_OBF=$(gzip -9 -c "$BUNDLE" | wc -c)
GZIP_CSS_SRC=$(gzip -9 -c "$CSS_DIR/main.css" | wc -c)
GZIP_CSS_MIN=$(gzip -9 -c "$CSS_DIR/main.min.css" | wc -c)

echo ""
echo "✅  Build terminé !"
echo ""
echo "  Fichier              Brut          gzip (réseau)"
echo "  ─────────────────────────────────────────────────"
echo "  JS sources orig.     $(( SIZE_CONCAT / 1024 )) Ko          $(( GZIP_SRC / 1024 )) Ko"
echo "  JS minifié           $(( SIZE_MIN / 1024 )) Ko          –"
echo "  JS obfusqué (final)  $(( SIZE_OBF / 1024 )) Ko          $(( GZIP_OBF / 1024 )) Ko"
echo "  CSS source           $(( SIZE_CSS_SRC / 1024 )) Ko          $(( GZIP_CSS_SRC / 1024 )) Ko"
echo "  CSS minifié (final)  $(( SIZE_CSS_MIN / 1024 )) Ko          $(( GZIP_CSS_MIN / 1024 )) Ko"
echo ""
echo "  ℹ️  L'obfuscation ajoute des tables de strings qui augmentent"
echo "     légèrement la taille gzip — trade-off sécurité / performance."
echo "  ✔  CSS : $(( (SIZE_CSS_SRC - SIZE_CSS_MIN) * 100 / SIZE_CSS_SRC ))% de réduction brute, $(( (GZIP_CSS_SRC - GZIP_CSS_MIN) * 100 / GZIP_CSS_SRC ))% gzip"
echo ""
echo "⚠️   Recharge le navigateur avec Ctrl+Shift+R pour invalider le cache."
