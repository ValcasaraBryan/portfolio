<?php
header('Content-Type: application/xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Load .env — same search strategy as db.php
$env = [];
foreach ([dirname(__DIR__) . '/.env', dirname(__DIR__, 2) . '/.env'] as $candidate) {
    if (!file_exists($candidate)) continue;
    foreach (file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
    break;
}

$siteUrl = rtrim($env['SITE_URL'] ?? '', '/');

// Attempt to get the most recent content modification date from the DB
$lastmod = date('Y-m-d');
try {
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $required) {
        if (empty($env[$required])) {
            error_log("[sitemap.php] Missing required env variable: $required");
            throw new RuntimeException("Missing env: $required");
        }
    }
    $host   = $env['DB_HOST'];
    $port   = $env['DB_PORT'];
    $dbname = $env['DB_NAME'];
    $user   = $env['DB_USER'];
    $pass   = $env['DB_PASSWORD'];

    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tables with updated_at — extend this list when columns are added
    $tables = ['experiences', 'projects', 'formations', 'skills', 'profile', 'certifications'];
    $existing = [];
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $existing[] = $t;
    }
    $candidates = [];
    foreach ($tables as $t) {
        if (!in_array($t, $existing, true)) continue;
        $cols = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'updated_at'")->fetchAll();
        if ($cols) $candidates[] = "SELECT MAX(updated_at) AS d FROM `$t`";
    }
    if ($candidates) {
        $union = implode(' UNION ALL ', $candidates);
        $row = $pdo->query("SELECT MAX(d) FROM ($union) AS all_dates")->fetch(PDO::FETCH_NUM);
        if ($row && $row[0]) {
            $lastmod = (new DateTimeImmutable($row[0]))->format('Y-m-d');
        }
    }
} catch (Throwable) {
    // Fallback: use today's date — no error details exposed
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset
  xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
  xmlns:xhtml="http://www.w3.org/1999/xhtml">
  <url>
    <loc><?= htmlspecialchars($siteUrl . '/', ENT_XML1) ?></loc>
    <xhtml:link rel="alternate" hreflang="fr"      href="<?= htmlspecialchars($siteUrl . '/', ENT_XML1) ?>"/>
    <xhtml:link rel="alternate" hreflang="en"      href="<?= htmlspecialchars($siteUrl . '/?lang=en', ENT_XML1) ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($siteUrl . '/', ENT_XML1) ?>"/>
    <lastmod><?= htmlspecialchars($lastmod, ENT_XML1) ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
