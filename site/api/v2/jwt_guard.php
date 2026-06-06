<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

function get_bearer_token(): ?string
{
    // Apache supprime HTTP_AUTHORIZATION lors des rewrites.
    // On cherche dans plusieurs emplacements selon la config serveur.
    $auth = $_SERVER['HTTP_AUTHORIZATION']
         ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
         ?? (function_exists('apache_request_headers')
                ? (apache_request_headers()['Authorization'] ?? '')
                : '')
         ?? '';

    return str_starts_with($auth, 'Bearer ')
        ? substr($auth, 7)
        : null;
}

function jwt_guard(): array
{
    global $pdo, $jwtSecret;

    $token = get_bearer_token();
    if ($token === null) {
        json_response(['error' => 'Missing authorization token'], 401);
    }

    try {
        $payload = (array) JWT::decode($token, new Key($jwtSecret, 'HS256'));
    } catch (ExpiredException) {
        json_response(['error' => 'Token expired'], 401);
    } catch (SignatureInvalidException) {
        json_response(['error' => 'Invalid token signature'], 401);
    } catch (\Exception) {
        json_response(['error' => 'Invalid token'], 401);
    }

    $jti  = $payload['jti'] ?? '';
    $stmt = $pdo->prepare('SELECT 1 FROM jwt_revoked_tokens WHERE jti = ?');
    $stmt->execute([$jti]);
    if ($stmt->fetchColumn() !== false) {
        json_response(['error' => 'Token has been revoked'], 401);
    }

    $GLOBALS['_jwt_payload'] = $payload;
    return $payload;
}

function require_role(array $user, string ...$roles): void
{
    if (!in_array($user['role'] ?? '', $roles, true)) {
        json_response(['error' => 'Forbidden'], 403);
    }
}

function can(array $user, string $feature, string $action): bool
{
    $role  = $user['role'] ?? 'editor';
    $perms = $user['permissions'] ?? null;

    if (is_object($perms)) {
        $perms = (array) $perms;
    }

    if (is_array($perms)
        && isset($perms[$feature])
        && is_array($perms[$feature])
        && array_key_exists($action, $perms[$feature])
    ) {
        return (bool) $perms[$feature][$action];
    }

    return match ($role) {
        'superadmin' => true,
        'admin'      => !($feature === 'users' && $action !== 'read'),
        'editor'     => in_array($feature, ['categories', 'skills', 'educations'], true)
                        && in_array($action, ['read', 'write'], true),
        default      => false,
    };
}

function require_perm(array $user, string $feature, string $action): void
{
    if (!can($user, $feature, $action)) {
        json_response(['error' => "Forbidden: missing {$feature}.{$action} permission"], 403);
    }
}
