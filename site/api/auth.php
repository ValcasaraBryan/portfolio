<?php
header('Content-Type: application/json');

// Restrict CORS to same origin only (admin panel is served from the same host)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
$allowedOrigins = [
    'https://' . $host,
    'http://' . $host,   // dev only — remove in production
];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Block pre-flight OPTIONS quickly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

if ($action === 'check') {
    json_response(['authenticated' => !empty($_SESSION['admin_id'])]);
}

if ($action === 'logout' && method() === 'POST') {
    session_destroy();
    json_response(['ok' => true]);
}

if ($action === 'login' && method() === 'POST') {
    $data     = body();
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if ($username === '' || $password === '') {
        json_response(['error' => 'Missing credentials'], 400);
    }

    // Rate limiting: max 5 attempts per IP per 15 minutes
    $ip          = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir    = sys_get_temp_dir() . '/auth_rl';
    $cacheFile   = $cacheDir . '/' . hash('sha256', $ip) . '.json';
    $maxAttempts = 5;
    $window      = 900; // 15 min

    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0700, true);
    }

    $attempts = [];
    if (file_exists($cacheFile)) {
        $attempts = json_decode(file_get_contents($cacheFile), true) ?? [];
    }
    $attempts = array_filter($attempts, fn($t) => $t > time() - $window);

    if (count($attempts) >= $maxAttempts) {
        json_response(['error' => 'Too many attempts. Try again in 15 minutes.'], 429);
    }

    $stmt = $pdo->prepare('SELECT `id`, `password_hash`, `must_change_password`, `role` FROM `admin_users` WHERE `username` = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $attempts[] = time();
        file_put_contents($cacheFile, json_encode(array_values($attempts)), LOCK_EX);
        sleep(1);
        json_response(['error' => 'Invalid credentials'], 401);
    }

    // Clear attempts on success
    @unlink($cacheFile);

    session_regenerate_id(true);
    $_SESSION['admin_id']   = $user['id'];
    $_SESSION['admin_role'] = $user['role'] ?? 'admin';
    json_response(['ok' => true, 'must_change_password' => (bool) $user['must_change_password']]);
}

if ($action === 'change_password' && method() === 'POST') {
    require_auth();
    $data        = body();
    $newPassword = $data['password'] ?? '';

    if (strlen($newPassword) < 8) {
        json_response(['error' => 'Password must be at least 8 characters'], 400);
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        'UPDATE `admin_users` SET `password_hash` = ?, `must_change_password` = 0 WHERE `id` = ?'
    );
    $stmt->execute([$hash, $_SESSION['admin_id']]);
    json_response(['ok' => true]);
}

json_response(['error' => 'Bad request'], 400);
