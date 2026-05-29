<?php
/**
 * Usage: php bdd/create_admin.php <username> <password>
 * Creates or updates the admin account.
 */
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php create_admin.php <username> <password>\n");
    exit(1);
}

[, $username, $password] = $argv;
$username = trim($username);

if ($username === '' || strlen($password) < 8) {
    fwrite(STDERR, "Error: username cannot be empty and password must be at least 8 characters.\n");
    exit(1);
}

require_once __DIR__ . '/../site/api/db.php';

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $pdo->prepare(
    'INSERT INTO `admin_users` (`username`, `password_hash`) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`)'
);
$stmt->execute([$username, $hash]);

echo "Admin '$username' created/updated successfully.\n";
