<?php
/**
 * cv.php — Gestion des CVs uploadés (FR / EN)
 *
 * GET    /api/cv.php?lang=fr   → métadonnées (exists, url, updated_at, download_name, external_url)
 * POST   /api/cv.php?lang=fr   → upload d'un PDF (protégé admin)
 * PATCH  /api/cv.php?lang=fr   → mise à jour du nom de téléchargement et/ou de l'URL externe (protégé admin)
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
const CV_URL_BASE = '/uploads/cv/';

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
 * Priorité : URL externe > fichier uploadé.
 */
function handleGet(PDO $pdo, string $lang, string $filepath, string $fileurl): void
{
    $meta = getCvMeta($pdo, $lang);

    // URL externe définie → elle prend la priorité
    if (!empty($meta['external_url'])) {
        echo json_encode([
            'exists'        => true,
            'url'           => $meta['external_url'],
            'updated_at'    => null,
            'download_name' => $meta['download_name'],
            'external_url'  => $meta['external_url'],
        ]);
        return;
    }

    // Fichier uploadé
    if (!file_exists($filepath)) {
        echo json_encode([
            'exists'        => false,
            'download_name' => $meta['download_name'],
            'external_url'  => null,
        ]);
        return;
    }

    $mtime = filemtime($filepath);
    echo json_encode([
        'exists'        => true,
        'url'           => $fileurl,
        'updated_at'    => date('c', $mtime),
        'download_name' => $meta['download_name'],
        'external_url'  => null,
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

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $code = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 413
            : 400;
        http_response_code($code);
        echo json_encode(['error' => 'Erreur lors de l\'upload (code ' . $file['error'] . ').']);
        return;
    }

    if ($file['size'] > MAX_SIZE) {
        http_response_code(413);
        echo json_encode(['error' => 'Fichier trop volumineux. Taille maximale : 5 Mo.']);
        return;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if ($mime !== 'application/pdf') {
        http_response_code(400);
        echo json_encode(['error' => 'Type de fichier invalide. Seuls les PDFs sont acceptés.']);
        return;
    }

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
 * PATCH — Met à jour download_name et/ou external_url pour la langue demandée.
 *
 * Body JSON : { "download_name": "Mon_CV.pdf", "external_url": "https://…" }
 * Les deux champs sont optionnels ; seuls les champs présents sont traités.
 */
function handlePatch(PDO $pdo, string $lang): void
{
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $updates = [];

    // ── download_name ────────────────────────────────────────
    if (array_key_exists('download_name', $body)) {
        $name = trim($body['download_name']);
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,200}\.pdf$/', $name)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Nom invalide. Utilisez uniquement lettres, chiffres, tirets, underscores, et terminez par .pdf (200 caractères max).',
            ]);
            return;
        }
        $updates['download_name'] = $name;
    }

    // ── external_url ─────────────────────────────────────────
    if (array_key_exists('external_url', $body)) {
        $url = trim($body['external_url']);
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
            http_response_code(400);
            echo json_encode(['error' => 'URL externe invalide.']);
            return;
        }
        // Vérification scheme HTTP/HTTPS uniquement
        if ($url !== '') {
            $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
            if (!in_array($scheme, ['http', 'https'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'URL externe invalide. Seuls les schémas http et https sont acceptés.']);
                return;
            }
        }
        $updates['external_url'] = $url === '' ? null : $url;
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'Aucun champ à mettre à jour (download_name, external_url).']);
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

    // Construction dynamique de l'UPSERT selon les champs à mettre à jour
    $setClauses = [];
    $params     = [':locale' => $lang, ':title' => $title];

    if (isset($updates['download_name'])) {
        $setClauses[] = 'cv_download_name = VALUES(cv_download_name)';
        $params[':download_name'] = $updates['download_name'];
    }
    if (array_key_exists('external_url', $updates)) {
        $setClauses[] = 'cv_external_url = VALUES(cv_external_url)';
        $params[':external_url'] = $updates['external_url'];
    }

    $dlCol  = isset($updates['download_name'])              ? ', cv_download_name' : '';
    $dlVal  = isset($updates['download_name'])              ? ', :download_name'   : '';
    $extCol = array_key_exists('external_url', $updates)   ? ', cv_external_url'  : '';
    $extVal = array_key_exists('external_url', $updates)   ? ', :external_url'    : '';

    $sql = "INSERT INTO profile_translations (locale, title{$dlCol}{$extCol})
            VALUES (:locale, :title{$dlVal}{$extVal})
            ON DUPLICATE KEY UPDATE " . implode(', ', $setClauses);

    $pdo->prepare($sql)->execute($params);

    echo json_encode(array_merge(['success' => true], $updates));
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
 * Retourne download_name et external_url pour la locale donnée.
 */
function getCvMeta(PDO $pdo, string $lang): array
{
    $stmt = $pdo->prepare(
        'SELECT
           COALESCE(t.cv_download_name, p.cv_download_name, \'cv.pdf\') AS download_name,
           t.cv_external_url AS external_url
         FROM profile p
         LEFT JOIN profile_translations t ON t.locale = :locale
         WHERE p.id = 1'
    );
    $stmt->execute([':locale' => $lang]);
    $row = $stmt->fetch();
    return [
        'download_name' => $row['download_name'] ?? 'cv.pdf',
        'external_url'  => $row['external_url']  ?? null,
    ];
}
