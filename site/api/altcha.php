<?php
ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/../vendor/autoload.php';

foreach ([dirname(__DIR__) . '/.env', dirname(__DIR__, 2) . '/.env'] as $candidate) {
    if (file_exists($candidate)) {
        foreach (file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
        break;
    }
}

use AltchaOrg\Altcha\V1\Altcha;
use AltchaOrg\Altcha\V1\ChallengeOptions;

$secret    = $_ENV['ALTCHA_HMAC_KEY'] ?? 'change_me';
$altcha    = new Altcha(hmacKey: $secret);
$challenge = $altcha->createChallenge(new ChallengeOptions(maxNumber: 50000));

ob_end_clean();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Le widget v1 attend « maxnumber » (tout minuscule)
echo json_encode([
    'algorithm' => $challenge->algorithm,
    'challenge' => $challenge->challenge,
    'maxnumber' => $challenge->maxNumber,
    'salt'      => $challenge->salt,
    'signature' => $challenge->signature,
]);
