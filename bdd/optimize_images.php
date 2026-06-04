<?php
/**
 * optimize_images.php — Migration one-shot : recompression + mise à jour BDD.
 *
 * Exécution UNIQUEMENT en CLI (protection contre l'accès HTTP).
 * Usage :
 *   php bdd/optimize_images.php [--dry-run]
 *
 * Comportement :
 *   - Parcourt uploads/project/, uploads/profile/, uploads/link/
 *   - Recompresse chaque image ≥ MIN_SIZE_KB en WebP q85 / JPEG q80, max MAX_DIM px
 *   - Si l'extension change (ex: .jpg → .webp), met à jour l'URL dans la base de données
 *   - Conserve l'original avec le suffixe .orig (supprimable après validation)
 *   - Loggue chaque fichier traité avec le gain de taille et les lignes BDD mises à jour
 *
 * Colonnes BDD mises à jour selon le dossier :
 *   uploads/project/  → projects.photo_url
 *   uploads/profile/  → profile.photo_url, profile.about_photo_url, profile.cover_url
 *   uploads/link/     → links.icon, links.icon_dark
 *
 * Après validation manuelle :
 *   find site/uploads -name "*.orig" -delete
 */

// Sécurité : refus d'exécution en contexte HTTP
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Accès refusé. Ce script est réservé à l'exécution CLI.\n");
}

require_once __DIR__ . '/../site/api/image_utils.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const MIN_SIZE_KB = 100;   // Ignorer les images < 100 Ko (icônes, petits PNG)
const MAX_DIM     = 1200;  // Dimension max en pixels
const QUALITY     = 85;    // Qualité WebP / JPEG

$dryRun  = in_array('--dry-run', $argv ?? [], true);
$baseDir = __DIR__ . '/../site/uploads';

// ---------------------------------------------------------------------------
// Connexion BDD (même logique que site/api/db.php)
// ---------------------------------------------------------------------------
$envFile = null;
foreach ([__DIR__ . '/../.env', __DIR__ . '/../../.env'] as $candidate) {
    if (file_exists($candidate)) { $envFile = $candidate; break; }
}
if ($envFile !== null) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$host   = $_ENV['DB_HOST']     ?? getenv('DB_HOST')     ?: 'localhost';
$port   = $_ENV['DB_PORT']     ?? getenv('DB_PORT')     ?: '3306';
$dbname = $_ENV['DB_NAME']     ?? getenv('DB_NAME')     ?: 'portfolio';
$user   = $_ENV['DB_USER']     ?? getenv('DB_USER')     ?: 'root';
$pass   = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    exit("❌  Connexion BDD impossible : " . $e->getMessage() . "\n");
}

// ---------------------------------------------------------------------------
// Mapping dossier → mises à jour BDD
// Chaque entrée : [ 'table', 'colonne' ]
// ---------------------------------------------------------------------------
$dbMapping = [
    'project' => [
        ['table' => 'projects', 'column' => 'photo_url'],
    ],
    'profile' => [
        ['table' => 'profile',  'column' => 'photo_url'],
        ['table' => 'profile',  'column' => 'about_photo_url'],
        ['table' => 'profile',  'column' => 'cover_url'],
    ],
    'link' => [
        ['table' => 'links', 'column' => 'icon'],
        ['table' => 'links', 'column' => 'icon_dark'],
    ],
];

// ---------------------------------------------------------------------------
// Fonction : mise à jour des URLs dans la BDD
// Retourne le nombre de lignes affectées (toutes tables/colonnes confondues).
// ---------------------------------------------------------------------------
function updateDbUrls(
    PDO $pdo,
    string $dir,
    string $oldUrl,
    string $newUrl,
    array $mapping,
    bool $dryRun
): int {
    $targets = $mapping[$dir] ?? [];
    $total   = 0;

    foreach ($targets as ['table' => $table, 'column' => $col]) {
        // Validation des identifiants (whitelist stricte)
        $allowedTables  = ['projects', 'profile', 'links'];
        $allowedColumns = ['photo_url', 'about_photo_url', 'cover_url', 'icon', 'icon_dark'];

        if (!in_array($table, $allowedTables, true) || !in_array($col, $allowedColumns, true)) {
            continue;
        }

        if ($dryRun) {
            // Compter les lignes qui seraient affectées
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?");
            $stmt->execute([$oldUrl]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                echo "         [DRY-BDD] UPDATE $table SET $col → '$newUrl' ($count ligne(s))\n";
                $total += $count;
            }
        } else {
            $stmt = $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE `$col` = ?");
            $stmt->execute([$newUrl, $oldUrl]);
            $count = $stmt->rowCount();
            if ($count > 0) {
                echo "         ↳ BDD : UPDATE $table.$col ($count ligne(s))\n";
                $total += $count;
            }
        }
    }

    return $total;
}

// ---------------------------------------------------------------------------
// MIME types à traiter
// ---------------------------------------------------------------------------
$processableMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

// ---------------------------------------------------------------------------
// Traitement
// ---------------------------------------------------------------------------
$totalOriginalBytes  = 0;
$totalOptimizedBytes = 0;
$processedCount      = 0;
$skippedCount        = 0;
$dbUpdatesCount      = 0;

if ($dryRun) {
    echo "🔍  Mode DRY-RUN activé — aucune modification fichier ni BDD ne sera effectuée.\n\n";
}

foreach (array_keys($dbMapping) as $dir) {
    $path = $baseDir . '/' . $dir;
    if (!is_dir($path)) {
        echo "⚠️   Dossier introuvable, ignoré : $path\n";
        continue;
    }

    echo "📂  $dir/\n";

    $files = glob($path . '/*');
    if (!$files) {
        echo "    (vide)\n";
        continue;
    }

    foreach ($files as $file) {
        // Ignorer les backups .orig et les .htaccess
        if (str_ends_with($file, '.orig') || str_ends_with($file, '.htaccess')) {
            continue;
        }

        $sizeBytes = filesize($file);

        // Ignorer les fichiers sous le seuil
        if ($sizeBytes < MIN_SIZE_KB * 1024) {
            $skippedCount++;
            continue;
        }

        // Détection MIME
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file);

        if (!in_array($mime, $processableMime, true)) {
            $skippedCount++;
            continue; // SVG, GIF, ICO → ignorer
        }

        $sizeKb   = round($sizeBytes / 1024);
        $basename = basename($file);
        $oldUrl   = '/uploads/' . $dir . '/' . $basename;

        if ($dryRun) {
            $newBasename = preg_replace('/\.(jpe?g|png)$/i', '.webp', $basename);
            $newUrl      = '/uploads/' . $dir . '/' . $newBasename;
            $willRename  = ($newBasename !== $basename);

            echo "    [DRY] $basename ({$sizeKb} Ko) — serait optimisé";
            if ($willRename) echo " → $newBasename";
            echo "\n";

            if ($willRename) {
                updateDbUrls($pdo, $dir, $oldUrl, $newUrl, $dbMapping, true);
            }

            $processedCount++;
            continue;
        }

        // Sauvegarde de l'original
        $backup = $file . '.orig';
        if (!copy($file, $backup)) {
            echo "    ⚠️   Impossible de sauvegarder $basename, ignoré.\n";
            $skippedCount++;
            continue;
        }

        // Optimisation
        $result = optimizeImage($file, $mime, MAX_DIM, QUALITY);

        if ($result === false) {
            // Échec — restaurer l'original
            @unlink($file);
            rename($backup, $file);
            echo "    ❌  $basename — échec de l'optimisation (GD ?), restauré.\n";
            $skippedCount++;
            continue;
        }

        // Si le format a changé (ex: JPEG → WebP) : supprimer l'original converti,
        // la backup .orig est déjà là.
        if ($result !== $file) {
            @unlink($file);
        }

        $newBasename = basename($result);
        $newUrl      = '/uploads/' . $dir . '/' . $newBasename;
        $newSize     = filesize($result);
        $newSizeKb   = round($newSize / 1024);
        $saving      = round(($sizeBytes - $newSize) * 100 / $sizeBytes);

        $totalOriginalBytes  += $sizeBytes;
        $totalOptimizedBytes += $newSize;
        $processedCount++;

        $arrow = ($result !== $file) ? " → $newBasename" : '';
        echo "    ✔   $basename{$arrow} : {$sizeKb} Ko → {$newSizeKb} Ko (-{$saving}%)\n";

        // Mise à jour BDD si l'URL a changé
        if ($newUrl !== $oldUrl) {
            $updated = updateDbUrls($pdo, $dir, $oldUrl, $newUrl, $dbMapping, false);
            $dbUpdatesCount += $updated;
        }
    }
}

// ---------------------------------------------------------------------------
// Récapitulatif
// ---------------------------------------------------------------------------
echo "\n";
echo "═══════════════════════════════════════\n";
if ($dryRun) {
    echo "  DRY-RUN terminé.\n";
    echo "  Fichiers à optimiser  : $processedCount\n";
    echo "  Fichiers ignorés      : $skippedCount\n";
} else {
    $savedKb  = round(($totalOriginalBytes - $totalOptimizedBytes) / 1024);
    $savedPct = $totalOriginalBytes > 0
        ? round(($totalOriginalBytes - $totalOptimizedBytes) * 100 / $totalOriginalBytes)
        : 0;

    echo "  Fichiers optimisés    : $processedCount\n";
    echo "  Fichiers ignorés      : $skippedCount\n";
    echo "  Gain total            : {$savedKb} Ko (-{$savedPct}%)\n";
    echo "  Lignes BDD mises à jour : $dbUpdatesCount\n";
    echo "\n";
    echo "  Les originaux sont conservés avec le suffixe .orig.\n";
    echo "  Après validation, supprimez-les avec :\n";
    echo "    find site/uploads -name \"*.orig\" -delete\n";
}
echo "═══════════════════════════════════════\n";
