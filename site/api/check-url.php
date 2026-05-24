<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$url = $_GET['url'] ?? '';

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['online' => false]);
    exit;
}

/* ── Cache 24 h ──────────────────────────────────────────────── */
$cacheDir  = sys_get_temp_dir() . '/portfolio_url_cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/' . md5($url) . '.json';
$cacheTtl  = 86400; // 24 heures

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    echo file_get_contents($cacheFile);
    exit;
}

/* ── Vérification HEAD ───────────────────────────────────────── */
$online = false;
try {
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'HEAD',
            'timeout'         => 5,
            'follow_location' => true,
            'ignore_errors'   => true,
            'user_agent'      => 'Mozilla/5.0 Portfolio-URL-Checker/1.0',
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $headers = @get_headers($url, false, $ctx);
    if ($headers) {
        // Premier header : "HTTP/1.x 200 OK" — on extrait le code
        preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $m);
        $code   = (int)($m[1] ?? 0);
        $online = $code > 0 && $code < 400;
    }
} catch (Throwable $e) {
    $online = false;
}

$result = json_encode(['online' => $online]);
file_put_contents($cacheFile, $result);
echo $result;
