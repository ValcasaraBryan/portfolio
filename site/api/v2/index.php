<?php
$startTime = microtime(true);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/jwt_guard.php';
require_once __DIR__ . '/logger.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// CORS — mêmes règles que l'API v1 (same-origin)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
foreach (["https://{$host}", "http://{$host}"] as $allowed) {
    if ($origin !== '' && $origin === $allowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        break;
    }
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Parsing de la route : /api/v2/{resource}/{seg3}/{seg4}
$uri      = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', $uri)));
// Index : 0=api 1=v2 2=resource 3=seg3 4=seg4
$resource = $segments[2] ?? '';
$rawSeg3  = $segments[3] ?? null;
$rawSeg4  = $segments[4] ?? null;

// Pour les routes non-auth : seg3 est un ID numérique
$id  = ($rawSeg3 !== null && ctype_digit((string) $rawSeg3)) ? (int) $rawSeg3 : null;
$sub = ($id !== null) ? $rawSeg4 : null;

// Pour la route auth : seg3 est l'action (login/logout/refresh/me)
$authAction = ($resource === 'auth' && $rawSeg3 !== null) ? $rawSeg3 : null;

$GLOBALS['_route'] = [
    'resource'   => $resource,
    'id'         => $id,
    'sub'        => $sub,
    'authAction' => $authAction,
];

// Log en fin de script (après exit de json_response)
register_shutdown_function(function () use ($startTime) {
    log_request(
        method(),
        parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
        http_response_code() ?: 200,
        isset($GLOBALS['_jwt_payload']['sub']) ? (int) $GLOBALS['_jwt_payload']['sub'] : null,
        (microtime(true) - $startTime) * 1000,
        body()
    );
});

$allowed = ['auth', 'categories', 'skills', 'educations', 'users'];
if (!in_array($resource, $allowed, true)) {
    json_response(['error' => 'Not found'], 404);
}

require_once __DIR__ . '/routes/' . $resource . '.php';
