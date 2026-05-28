<?php
/**
 * cv.php — Gestion des CVs uploadés (FR / EN)
 *
 * GET    /api/cv.php?lang=fr   → métadonnées (exists, url, updated_at, download_name)
 * POST   /api/cv.php?lang=fr   → upload d'un PDF (protégé admin)
 * PATCH  /api/cv.php?lang=fr   → mise à jour du nom de téléchargement (protégé admin)
 * DELETE /api/cv.php?lang=fr   → suppression du PDF (protégé admin)
 */

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

// ── Headers de sécurité ──────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Content-Type: application/json');

// ── Constantes ───────────────────────────────────────────────
const VALID_LANGS = ['fr', 'en'];
const MAX_SIZE    = 5 * 1024 * 1024; // 5 Mo
const CV_DIR      = __DIR__ . '/../uploads/cv/';
const CV_URL_BASE = './uploads/cv/';

// ── Validation de lang ───────────────────────────────────────
$lang = $_GET['lang'] ?? 'fr';
if (!in_array($lang, VALID_LANGS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre lang invalide. Valeurs acceptées : fr, en.']);
    exit;
}

$filename = "cv-{$lang}.pdf";
$filepath = CV_DIR . $filename;
$fileurl  = CV_URL_BASE . $filename;

// ── Routage ──────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        handleGet($pdo, $lang, $filepath, $fileurl);
    } elseif ($method === 'POST') {
        require_auth();
        handlePost($filepath, $fileurl);
    } elseif ($method === 'PATCH') {
        require_auth();
        handlePatch($pdo, $lang);
    } elseif ($method === 'DELETE') {
        require_auth();
        handleDelete($filepath);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Méthode non autorisée.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur.']);
}

// ── Handlers ─────────────────────────────────────────────────

/**
 * GET — Retourne les métadonnées du CV pour la langue demandée.
 */
function handleGet(PDO $pdo, string $lang, string $filepath, string $fileurl): void
{
    $downloadName = getDownloadName($pdo, $lang);

    if (!file_exists($filepath)) {
        echo json_encode(['exists' => false, 'download_name' => $downloadName]);
        return;
    }

    $mtime = filemtime($filepath);
    echo json_encode([
        'exists'        => true,
        'url'           => $fileurl,
        'updated_at'    => date('c', $mtime),
        'download_name' => $downloadName,
    ]);
}

/**
 * POST — Upload d'un PDF.
 *
 * Validations :
 *   - Fichier présent ($_FILES['cv'])
 *   - Pas d'erreur d'upload PHP
 *   - Taille ≤ 5 Mo
 *   - MIME vérifié côté serveur via finfo_file (application/pdf)
 */
function handlePost(string $filepath, string $fileurl): void
{
    if (!isset($_FILES['cv'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Aucun fichier reçu. Champ attendu : cv.']);
        return;
    }

    $file = $_FILES['cv'];

    // Erreur d'upload PHP
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $code = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 413
            : 400;
        http_response_code($code);
        echo json_encode(['error' => 'Erreur lors de l\'upload (code ' . $file['error'] . ').']);
        return;
    }

    // Taille
    if ($file['size'] > MAX_SIZE) {
        http_response_code(413);
        echo json_encode(['error' => 'Fichier trop volumineux. Taille maximale : 5 Mo.']);
        return;
    }

    // MIME côté serveur
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if ($mime !== 'application/pdf') {
        http_response_code(400);
        echo json_encode(['error' => 'Type de fichier invalide. Seuls les PDFs sont acceptés.']);
        return;
    }

    // Déplacement vers la destination normalisée
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Impossible de stocker le fichier.']);
        return;
    }

    $mtime = filemtime($filepath);
    http_response_code(200);
    echo json_encode([
        'success'    => true,
        'url'        => $fileurl,
        'updated_at' => date('c', $mtime),
    ]);
}

/**
 * PATCH — Met à jour le nom de téléchargement du CV pour la langue demandée.
 *
 * Body JSON attendu : { "download_name": "Mon_CV.pdf" }
 * Validation : lettres, chiffres, tirets, underscores, 1–200 chars, extension .pdf
 */
function handlePatch(PDO $pdo, string $lang): void
{
    $body = json_decode(file_get_contents('php://input'), true);
    $name = trim($body['download_name'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9_\-]{1,200}\.pdf$/', $name)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Nom invalide. Utilisez uniquement lettres, chiffres, tirets, underscores, et terminez par .pdf (200 caractères max).',
        ]);
        return;
    }

    // Récupère le titre existant pour l'UPSERT (title est NOT NULL dans profile_translations)
    $stmt = $pdo->prepare(
        'SELECT COALESCE(t.title, p.title) AS title
         FROM profile p
         LEFT JOIN profile_translations t ON t.locale = :locale
         WHERE p.id = 1'
    );
    $stmt->execute([':locale' => $lang]);
    $row   = $stmt->fetch();
    $title = $row['title'] ?? '';

    $upsert = $pdo->prepare(
        'INSERT INTO profile_translations (locale, title, cv_download_name)
         VALUES (:locale, :title, :name)
         ON DUPLICATE KEY UPDATE cv_download_name = VALUES(cv_download_name)'
    );
    $upsert->execute([':locale' => $lang, ':title' => $title, ':name' => $name]);

    echo json_encode(['success' => true, 'download_name' => $name]);
}

/**
 * DELETE — Supprime le CV pour la langue demandée.
 */
function handleDelete(string $filepath): void
{
    if (!file_exists($filepath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Aucun CV trouvé pour cette langue.']);
        return;
    }

    if (!unlink($filepath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Impossible de supprimer le fichier.']);
        return;
    }

    echo json_encode(['success' => true]);
}

// ── Helper ───────────────────────────────────────────────────

/**
 * Retourne le nom de téléchargement pour la locale donnée.
 * Priorité : profile_translations.cv_download_name > profile.cv_download_name > 'cv.pdf'
 */
function getDownloadName(PDO $pdo, string $lang): string
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(t.cv_download_name, p.cv_download_name, \'cv.pdf\') AS download_name
         FROM profile p
         LEFT JOIN profile_translations t ON t.locale = :locale
         WHERE p.id = 1'
    );
    $stmt->execute([':locale' => $lang]);
    $row = $stmt->fetch();
    return $row['download_name'] ?? 'cv.pdf';
}
