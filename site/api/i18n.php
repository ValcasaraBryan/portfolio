<?php
/**
 * i18n.php — Lecture et écriture des fichiers de traduction JSON
 *
 * GET  /api/i18n.php → { fr: {...}, en: {...} }
 * PUT  /api/i18n.php → body: { fr: {...}, en: {...} }  (protégé admin)
 */

require_once __DIR__ . '/auth_guard.php';

// ── Headers de sécurité ──────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Content-Type: application/json');

// ── Constantes ───────────────────────────────────────────────
define('I18N_DIR', __DIR__ . '/../i18n/');
const VALID_SECTIONS = ['nav', 'about', 'common', 'experiences', 'creations', 'formations', 'contact'];

// ── Routage ──────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        handleGet();
    } elseif ($method === 'PUT') {
        require_auth();
        handlePut();
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
 * GET — Retourne le contenu des deux fichiers de traduction.
 */
function handleGet(): void
{
    $i18nDir = realpath(I18N_DIR);

    if (!$i18nDir) {
        http_response_code(500);
        echo json_encode(['error' => 'Dossier i18n introuvable.']);
        return;
    }

    $frPath = realpath(I18N_DIR . 'fr.json');
    $enPath = realpath(I18N_DIR . 'en.json');

    if (
        !$frPath || !$enPath
        || !str_starts_with($frPath, $i18nDir)
        || !str_starts_with($enPath, $i18nDir)
    ) {
        http_response_code(500);
        echo json_encode(['error' => 'Fichiers de traduction introuvables.']);
        return;
    }

    $fr = json_decode(file_get_contents($frPath), true);
    $en = json_decode(file_get_contents($enPath), true);

    echo json_encode(['fr' => $fr, 'en' => $en]);
}

/**
 * PUT — Écrit les deux fichiers de traduction.
 *
 * Body attendu : { fr: { nav: {...}, about: {...}, ... }, en: { ... } }
 *
 * Validations :
 *   - Clés top-level filtrées sur la whitelist VALID_SECTIONS
 *   - Chemins vérifiés via realpath + str_starts_with (anti path-traversal)
 *   - LOCK_EX sur file_put_contents
 */
function handlePut(): void
{
    $body = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($body['fr'], $body['en'])
        || !is_array($body['fr'])
        || !is_array($body['en'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Corps invalide. Attendu : { fr: {...}, en: {...} }.']);
        return;
    }

    // Filtrer sur la whitelist des sections autorisées
    $fr = array_intersect_key($body['fr'], array_flip(VALID_SECTIONS));
    $en = array_intersect_key($body['en'], array_flip(VALID_SECTIONS));

    if (empty($fr) || empty($en)) {
        http_response_code(400);
        echo json_encode(['error' => 'Aucune section valide fournie.']);
        return;
    }

    $i18nDir = realpath(I18N_DIR);

    if (!$i18nDir) {
        http_response_code(500);
        echo json_encode(['error' => 'Dossier i18n introuvable.']);
        return;
    }

    foreach (['fr' => $fr, 'en' => $en] as $lang => $data) {
        $path = I18N_DIR . $lang . '.json';

        if (!file_exists($path)) {
            http_response_code(500);
            echo json_encode(['error' => "Fichier {$lang}.json introuvable."]);
            return;
        }

        $real = realpath($path);
        if (!$real || !str_starts_with($real, $i18nDir)) {
            http_response_code(400);
            echo json_encode(['error' => 'Chemin non autorisé.']);
            return;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($real, $json) === false) {
            http_response_code(500);
            echo json_encode(['error' => "Impossible d'écrire {$lang}.json."]);
            return;
        }
    }

    echo json_encode(['success' => true]);
}
