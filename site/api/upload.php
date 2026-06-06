<?php
/**
 * upload.php — Upload d'images pour les projets et les liens sociaux.
 *
 * POST ?type=project|link
 *   Champ multipart : image
 *   Réponse : { "url": "/uploads/{type}/{filename}" }
 *
 * Sécurité :
 *   - Auth obligatoire (require_auth)
 *   - Paramètre type whitelisté
 *   - MIME validé côté serveur via finfo_file
 *   - Nom de fichier généré (UUID) — jamais le nom original du client
 *   - Taille ≤ 2 Mo
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/image_utils.php';

const MAX_SIZE   = 2 * 1024 * 1024; // 2 Mo
const ALLOWED_MIME = [
    'image/jpeg'   => 'jpg',
    'image/jpg'    => 'jpg',   // variante Windows
    'image/png'    => 'png',
    'image/x-png'  => 'png',   // variante Windows/XAMPP
    'image/webp'   => 'webp',
    'image/gif'    => 'gif',
    'image/svg+xml'              => 'svg',
    'text/xml'                   => 'svg',   // SVG parfois détecté comme XML
    'application/xml'            => 'svg',
    'image/x-icon'               => 'ico',   // ICO (favicon)
    'image/vnd.microsoft.icon'   => 'ico',   // ICO variante IANA
];

// Seule méthode acceptée : POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_min_role('editor');

// Validation du paramètre type
$type = $_GET['type'] ?? '';
if (!in_array($type, ['project', 'link', 'profile'], true)) {
    json_response(['error' => 'Paramètre type invalide. Valeurs acceptées : project, link, profile.'], 400);
}

// Vérification présence du fichier
if (!isset($_FILES['image'])) {
    json_response(['error' => 'Aucun fichier reçu. Champ attendu : image.'], 400);
}

$file = $_FILES['image'];

// Erreur PHP d'upload
if ($file['error'] !== UPLOAD_ERR_OK) {
    $code = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE]) ? 413 : 400;
    json_response(['error' => 'Erreur lors de l\'upload (code ' . $file['error'] . ').'], $code);
}

// Taille
if ($file['size'] > MAX_SIZE) {
    json_response(['error' => 'Fichier trop volumineux. Taille maximale : 2 Mo.'], 413);
}

// MIME côté serveur (jamais l'extension ou le Content-Type client)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);

if (!array_key_exists($mime, ALLOWED_MIME)) {
    json_response(['error' => 'Type de fichier invalide. Formats acceptés : JPEG, PNG, WebP, GIF, SVG.'], 400);
}

$ext    = ALLOWED_MIME[$mime];
$folder = dirname(__DIR__) . '/uploads/' . $type . '/';

// Créer le dossier s'il n'existe pas encore (première utilisation)
if (!is_dir($folder)) {
    if (!mkdir($folder, 0755, true)) {
        json_response(['error' => 'Impossible de créer le dossier de destination.'], 500);
    }
}

// Nom de fichier généré côté serveur — jamais le nom original du client
$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$filepath = $folder . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    json_response(['error' => 'Impossible de stocker le fichier.'], 500);
}

// Optimisation : redimensionnement + conversion WebP (si GD le supporte)
// SVG / GIF / ICO sont ignorés par optimizeImage (retourne false silencieusement).
if (in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/x-png', 'image/webp'], true)) {
    $maxDim    = ($type === 'profile') ? 800 : 1200;
    $mimeFinal = ($mime === 'image/x-png') ? 'image/png' : $mime;
    $result    = optimizeImage($filepath, $mimeFinal, $maxDim, 85);

    if ($result !== false && $result !== $filepath) {
        // Le format a changé (ex: JPEG → WebP) — supprimer l'original
        @unlink($filepath);
        $filename = basename($result);
        $filepath = $result;
    }
}

json_response([
    'success' => true,
    'url'     => '/uploads/' . $type . '/' . $filename,
]);
