<?php

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'samesite' => 'Strict',
        'httponly' => true,
        'secure'   => $secure,
    ]);

    // Idle timeout: 8 hours
    ini_set('session.gc_maxlifetime', 28800);
    session_start();

    // Invalidate session after 8 hours of inactivity
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > 28800) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['_last_activity'] = time();
}

function require_auth(): void
{
    if (empty($_SESSION['admin_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function get_session_role(): string
{
    // Fallback 'admin' pour les sessions ouvertes avant la migration 025 (pas de rôle en session)
    return $_SESSION['admin_role'] ?? 'admin';
}

function require_min_role(string $minRole): void
{
    require_auth();
    static $hierarchy = ['editor' => 0, 'admin' => 1, 'superadmin' => 2];
    $userLevel = $hierarchy[get_session_role()] ?? 0;
    $minLevel  = $hierarchy[$minRole]           ?? 99;
    if ($userLevel < $minLevel) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden: insufficient role']);
        exit;
    }
}
