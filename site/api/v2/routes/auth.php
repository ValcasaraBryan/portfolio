<?php

$action = $GLOBALS['_route']['authAction'] ?? null;

switch (true) {

    // POST /api/v2/auth/login
    case $action === 'login' && method() === 'POST':
        $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $cacheDir  = sys_get_temp_dir() . '/auth_v2_rl';
        $cacheFile = $cacheDir . '/' . hash('sha256', $ip) . '.json';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }
        $attempts = file_exists($cacheFile)
            ? (json_decode(file_get_contents($cacheFile), true) ?? [])
            : [];
        $attempts = array_values(array_filter($attempts, fn($t) => $t > time() - 900));
        if (count($attempts) >= 5) {
            json_response(['error' => 'Too many attempts. Try again in 15 minutes.'], 429);
        }

        $d        = body();
        $username = trim($d['username'] ?? '');
        $password = $d['password'] ?? '';
        if ($username === '' || $password === '') {
            json_response(['error' => 'Missing credentials'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, role, permissions
             FROM admin_users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $attempts[] = time();
            file_put_contents($cacheFile, json_encode(array_values($attempts)), LOCK_EX);
            sleep(1);
            json_response(['error' => 'Invalid credentials'], 401);
        }

        @unlink($cacheFile);

        $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')
            ->execute([$user['id']]);

        $accessToken  = issue_access_token($user);
        $refreshToken = issue_refresh_token($pdo, (int) $user['id']);

        json_response([
            'token'         => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => 3600,
            'user'          => [
                'id'          => (int) $user['id'],
                'username'    => $user['username'],
                'role'        => $user['role'],
                'permissions' => $user['permissions']
                                    ? json_decode($user['permissions'], true)
                                    : null,
            ],
        ]);

    // POST /api/v2/auth/logout
    case $action === 'logout' && method() === 'POST':
        $payload = jwt_guard();
        $jti     = $payload['jti'] ?? '';
        $exp     = isset($payload['exp'])
                    ? date('Y-m-d H:i:s', (int) $payload['exp'])
                    : date('Y-m-d H:i:s', time() + 3600);
        $pdo->prepare(
            'INSERT IGNORE INTO jwt_revoked_tokens (jti, expires_at) VALUES (?, ?)'
        )->execute([$jti, $exp]);
        json_response(['ok' => true]);

    // POST /api/v2/auth/refresh
    case $action === 'refresh' && method() === 'POST':
        $d   = body();
        $raw = $d['refresh_token'] ?? '';
        if ($raw === '') {
            json_response(['error' => 'Missing refresh_token'], 400);
        }
        $hash = hash('sha256', $raw);
        $stmt = $pdo->prepare(
            'SELECT rt.id, rt.user_id,
                    u.username, u.role, u.permissions
             FROM jwt_refresh_tokens rt
             JOIN admin_users u ON u.id = rt.user_id
             WHERE rt.token_hash = ?
               AND rt.revoked    = 0
               AND rt.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(['error' => 'Invalid or expired refresh token'], 401);
        }

        // Rotation : révoque l'ancien token
        $pdo->prepare('UPDATE jwt_refresh_tokens SET revoked = 1 WHERE id = ?')
            ->execute([$row['id']]);

        $user         = ['id' => $row['user_id'], 'username' => $row['username'],
                          'role' => $row['role'],   'permissions' => $row['permissions']];
        $accessToken  = issue_access_token($user);
        $refreshToken = issue_refresh_token($pdo, (int) $row['user_id']);

        json_response([
            'token'         => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => 3600,
        ]);

    // GET /api/v2/auth/me
    case $action === 'me' && method() === 'GET':
        $payload = jwt_guard();
        $stmt    = $pdo->prepare(
            'SELECT id, username, email, role, permissions, last_login_at
             FROM admin_users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            json_response(['error' => 'User not found'], 404);
        }
        $user['id']          = (int) $user['id'];
        $user['permissions'] = $user['permissions']
                                ? json_decode($user['permissions'], true)
                                : null;
        json_response($user);

    // GET /api/v2/auth/exchange — échange une session PHP admin active contre un JWT
    case $action === 'exchange' && method() === 'GET':
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $adminId = $_SESSION['admin_id'] ?? null;
        if (!$adminId) {
            json_response(['error' => 'No active session'], 401);
        }
        $stmt = $pdo->prepare(
            'SELECT id, username, role, permissions
             FROM admin_users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$adminId]);
        $user = $stmt->fetch();
        if (!$user || $user['role'] !== 'superadmin') {
            json_response(['error' => 'Forbidden'], 403);
        }
        $accessToken  = issue_access_token($user);
        $refreshToken = issue_refresh_token($pdo, (int) $user['id']);
        json_response([
            'token'         => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => 3600,
            'user'          => [
                'id'          => (int) $user['id'],
                'username'    => $user['username'],
                'role'        => $user['role'],
                'permissions' => $user['permissions']
                                    ? json_decode($user['permissions'], true)
                                    : null,
            ],
        ]);

    default:
        json_response(['error' => 'Not found'], 404);
}
