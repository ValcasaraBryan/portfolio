<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use AltchaOrg\Altcha\V1\Altcha as AltchaV1;

function verify_altcha(string $encoded): bool
{
    $secret = $_ENV['ALTCHA_HMAC_KEY'] ?? 'change_me';
    $altcha = new AltchaV1(hmacKey: $secret);
    return $altcha->verifySolution($encoded);
}

switch (method()) {
    case 'GET':
        require_auth();
        $messages = $pdo->query(
            'SELECT `id`,`name`,`email`,`subject`,`message`,`sent_at` FROM `contact_messages` ORDER BY `sent_at` DESC'
        )->fetchAll();
        json_response($messages);

    case 'POST':
        $d = body();

        $altcha  = trim($d['altcha']  ?? '');
        if (!$altcha || !verify_altcha($altcha)) {
            json_response(['error' => 'Captcha invalide'], 400);
        }

        $name    = trim($d['name']    ?? '');
        $email   = trim($d['email']   ?? '');
        $subject = mb_substr(trim($d['subject'] ?? ''), 0, 255);
        $message = trim($d['message'] ?? '');

        if (!$name || !$email || !$message) {
            json_response(['error' => 'All fields are required'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['error' => 'Invalid email address'], 400);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO `contact_messages` (`name`,`email`,`subject`,`message`) VALUES (:name,:email,:subject,:message)'
        );
        $stmt->execute([':name' => $name, ':email' => $email, ':subject' => $subject, ':message' => $message]);

        $smtp_from = $_ENV['SMTP_FROM'] ?? '';
        if ($smtp_from) {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'localhost';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'] ?? '';
            $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
            $mail->CharSet    = 'UTF-8';

            // Notification to admin
            $mail->setFrom($smtp_from, 'Portfolio Contact');
            $mail->addAddress($smtp_from);
            $mail->addReplyTo($email, $name);
            $mail->Subject = "Nouveau message de contact de $name";
            $subjectLine   = $subject ? "Sujet : $subject\n" : '';
            $mail->Body    = "Nom : $name\nEmail : $email\n{$subjectLine}\n$message";
            try {
                $mail->send();
            } catch (Exception $e) {
                error_log('[PHPMailer admin] ' . $e->getMessage());
            }

            // Confirmation to sender
            $mail->clearAddresses();
            $mail->clearReplyTos();
            $mail->addAddress($email, $name);
            $mail->addReplyTo($smtp_from, 'Bryan Valcasara');
            $mail->Subject = "Votre message a bien été reçu";
            $mail->isHTML(true);
            $mail->Body = "
                <p>Bonjour <strong>$name</strong>,</p>
                <p>Merci pour votre message. Je l'ai bien reçu et vous répondrai dans les plus brefs délais.</p>
                <blockquote style='border-left:3px solid #ccc;padding-left:12px;color:#555;margin:16px 0'>
                    " . nl2br(htmlspecialchars($message)) . "
                </blockquote>
                <p>Cordialement,<br>Bryan Valcasara</p>
            ";
            $mail->AltBody = "Bonjour $name,\n\nMerci pour votre message. Je l'ai bien reçu et vous répondrai dans les plus brefs délais.\n\nCordialement,\nBryan Valcasara";
            try {
                $mail->send();
            } catch (Exception $e) {
                error_log('[PHPMailer confirmation] ' . $e->getMessage());
            }
        }

        json_response(['success' => true], 201);

    case 'DELETE':
        require_auth();
        $id   = $_GET['id'] ?? null;
        $stmt = $pdo->prepare('DELETE FROM `contact_messages` WHERE `id` = ?');
        $stmt->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
