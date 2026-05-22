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
