<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Look for .env one level up (htdocs deployment) then two levels up (local dev)
$env_file = null;
foreach ([dirname(__DIR__) . '/.env', dirname(__DIR__, 2) . '/.env'] as $candidate) {
    if (file_exists($candidate)) { $env_file = $candidate; break; }
}
if ($env_file !== null) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim(trim($value), '"\'');
    }
}

$host   = $_ENV['DB_HOST']     ?? getenv('DB_HOST')     ?: 'localhost';
$port   = $_ENV['DB_PORT']     ?? getenv('DB_PORT')     ?: '3306';
$dbname = $_ENV['DB_NAME']     ?? getenv('DB_NAME')     ?: 'portfolio';
$user   = $_ENV['DB_USER']     ?? getenv('DB_USER')     ?: 'root';
$pass   = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

function json_response(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function method(): string
{
    return $_SERVER['REQUEST_METHOD'];
}

function body(): array
{
    return json_decode(file_get_contents('php://input'), true) ?? [];
}
