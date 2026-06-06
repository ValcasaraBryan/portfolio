<?php
function log_request(
    string $method,
    string $path,
    int    $status,
    ?int   $userId,
    float  $durationMs,
    array  $body = []
): void {
    static $sensitive = ['password', 'password_hash', 'token', 'refresh_token', 'new_password'];
    foreach ($sensitive as $field) {
        if (array_key_exists($field, $body)) {
            $body[$field] = '***';
        }
    }

    // Remonte de site/api/v2 vers la racine du projet, puis logs/
    $logDir  = dirname(__DIR__, 3) . '/logs';
    $logFile = $logDir . '/api-v2-' . date('Y-m-d') . '.log';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0750, true);
    }

    $entry = json_encode([
        'ts'          => date('c'),
        'method'      => $method,
        'path'        => $path,
        'status'      => $status,
        'user_id'     => $userId,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'duration_ms' => (int) round($durationMs),
        'user_agent'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'body'        => empty($body) ? null : $body,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}
