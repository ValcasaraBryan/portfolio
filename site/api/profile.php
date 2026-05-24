<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

switch (method()) {
    case 'GET':
        try {
            $locale = in_array($_GET['lang'] ?? 'fr', ['fr', 'en']) ? ($_GET['lang'] ?? 'fr') : 'fr';
            $stmt = $pdo->query('SELECT * FROM `profile` LIMIT 1');
            $profile = $stmt->fetch();
            if (!$profile) {
                json_response(['error' => 'Profile not found'], 404);
            }
            $tr = $pdo->prepare('SELECT `title`, `status` FROM `profile_translations` WHERE `locale` = ?');
            $tr->execute([$locale]);
            $translation = $tr->fetch();
            if ($translation) {
                $profile['title']  = $translation['title'];
                $profile['status'] = $translation['status'];
            }
            $links = $pdo->query('SELECT `id`, `platform`, `url`, `icon` FROM `links` ORDER BY `id`')->fetchAll();
            $profile['links'] = $links;
            json_response($profile);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    case 'PUT':
        require_auth();
        try {
            $data = body();

            // Validation photo_url
            $photo_url = isset($data['photo_url']) && $data['photo_url'] !== ''
                ? $data['photo_url']
                : null;
            if ($photo_url !== null && !filter_var($photo_url, FILTER_VALIDATE_URL)) {
                json_response(['error' => 'Invalid photo_url'], 400);
            }

            // Champs traduits FR (valeurs par défaut de la table principale)
            $title_fr  = $data['title_fr']  ?? $data['title']  ?? null;
            $status_fr = $data['status_fr'] ?? $data['status'] ?? null;

            // Mise à jour des champs non traduits + valeurs FR par défaut
            $stmt = $pdo->prepare(
                'UPDATE `profile` SET
                    `name`      = :name,
                    `title`     = :title,
                    `photo_url` = :photo_url,
                    `location`  = :location,
                    `status`    = :status,
                    `email`     = :email,
                    `phone`     = :phone
                WHERE `id` = 1'
            );
            $stmt->execute([
                ':name'      => $data['name']     ?? null,
                ':title'     => $title_fr,
                ':photo_url' => $photo_url,
                ':location'  => $data['location'] ?? null,
                ':status'    => $status_fr,
                ':email'     => $data['email']    ?? null,
                ':phone'     => $data['phone']    ?? null,
            ]);

            // UPSERT profile_translations (UNIQUE sur locale)
            $upsert = $pdo->prepare(
                'INSERT INTO `profile_translations` (`locale`, `title`, `status`)
                 VALUES (:locale, :title, :status)
                 ON DUPLICATE KEY UPDATE
                    `title`  = VALUES(`title`),
                    `status` = VALUES(`status`)'
            );

            $upsert->execute([
                ':locale' => 'fr',
                ':title'  => $title_fr,
                ':status' => $status_fr,
            ]);
            $upsert->execute([
                ':locale' => 'en',
                ':title'  => $data['title_en']  ?? $title_fr,
                ':status' => $data['status_en'] ?? $status_fr,
            ]);

            json_response(['success' => true]);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
