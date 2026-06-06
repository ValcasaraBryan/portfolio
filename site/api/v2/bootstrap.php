<?php
require_once __DIR__ . '/../db.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Cherche JWT_SECRET dans $_ENV (chargé par db.php) puis dans le .env
// directement depuis v2/ en remontant jusqu'à la racine du projet.
$jwtSecret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';

if ($jwtSecret === '') {
    // Fallback : lecture directe du .env depuis la racine du projet
    // __DIR__ = site/api/v2 → dirname×3 = racine du projet
    foreach ([
        __DIR__ . '/.env',              // site/api/v2/.env — accès direct garanti
        dirname(__DIR__, 3) . '/.env',  // racine projet (production via Apache)
        dirname(__DIR__, 2) . '/.env',  // site/.env
        dirname(__DIR__, 4) . '/.env',  // niveau supérieur
    ] as $envPath) {
        if (!file_exists($envPath)) continue;
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'JWT_SECRET=')) continue;
            $jwtSecret = trim(substr($line, strlen('JWT_SECRET=')), '"\'');
            break 2;
        }
    }
}

if ($jwtSecret === '') {
    json_response(['error' => 'Server misconfiguration: missing JWT_SECRET'], 500);
}

function uuid4(): string
{
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function issue_access_token(array $user): string
{
    global $jwtSecret;
    $now     = time();
    $payload = [
        'sub'         => (int) $user['id'],
        'username'    => $user['username'],
        'role'        => $user['role'],
        'permissions' => is_string($user['permissions'] ?? null)
                            ? json_decode($user['permissions'], true)
                            : ($user['permissions'] ?? null),
        'jti'         => uuid4(),
        'iat'         => $now,
        'exp'         => $now + 3600,
    ];
    return JWT::encode($payload, $jwtSecret, 'HS256');
}

function issue_refresh_token(PDO $pdo, int $userId): string
{
    $raw       = bin2hex(random_bytes(32));
    $hash      = hash('sha256', $raw);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    $pdo->prepare(
        'INSERT INTO jwt_refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
    )->execute([$userId, $hash, $expiresAt]);
    return $raw;
}
