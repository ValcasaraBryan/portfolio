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
            $tr = $pdo->prepare('SELECT `title`, `status`, `bio` FROM `profile_translations` WHERE `locale` = ?');
            $tr->execute([$locale]);
            $translation = $tr->fetch();
            if ($translation) {
                $profile['title']  = $translation['title'];
                $profile['status'] = $translation['status'];
                if ($translation['bio'] !== null) {
                    $profile['bio'] = $translation['bio'];
                }
            }
            // Cast booléen pour le toggle (évite d'exposer "0"/"1" en JSON)
            $profile['about_use_drawer_photo'] = (bool) $profile['about_use_drawer_photo'];
            $links = $pdo->query('SELECT `id`, `platform`, `url`, `icon`, `icon_dark` FROM `links` ORDER BY `id`')->fetchAll();
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
            // Accepte les URL absolues (https://…) ET les chemins d'upload locaux (/uploads/…)
            if ($photo_url !== null
                && !filter_var($photo_url, FILTER_VALIDATE_URL)
                && !str_starts_with($photo_url, '/uploads/')) {
                json_response(['error' => 'Invalid photo_url'], 400);
            }

            // Validation about_photo_url
            $about_photo_url = isset($data['about_photo_url']) && $data['about_photo_url'] !== ''
                ? $data['about_photo_url'] : null;
            if ($about_photo_url !== null
                && !filter_var($about_photo_url, FILTER_VALIDATE_URL)
                && !str_starts_with($about_photo_url, '/uploads/')) {
                json_response(['error' => 'Invalid about_photo_url'], 400);
            }

            // Validation cover_url
            $cover_url = isset($data['cover_url']) && $data['cover_url'] !== ''
                ? $data['cover_url'] : null;
            if ($cover_url !== null
                && !filter_var($cover_url, FILTER_VALIDATE_URL)
                && !str_starts_with($cover_url, '/uploads/')) {
                json_response(['error' => 'Invalid cover_url'], 400);
            }

            // about_use_drawer_photo — whitelist stricte : 0 ou 1
            $about_use_drawer_photo = isset($data['about_use_drawer_photo'])
                ? (int)(bool)$data['about_use_drawer_photo'] : 1;

            // Champs traduits FR (valeurs par défaut de la table principale)
            $title_fr  = $data['title_fr']  ?? $data['title']  ?? null;
            $status_fr = $data['status_fr'] ?? $data['status'] ?? null;

            // Validation et limite bio (max 2000 caractères)
            $bio_fr = $data['bio_fr'] ?? null;
            $bio_en = $data['bio_en'] ?? null;
            if ($bio_fr !== null && mb_strlen($bio_fr) > 2000) {
                json_response(['error' => 'bio_fr exceeds 2000 characters'], 400);
            }
            if ($bio_en !== null && mb_strlen($bio_en) > 2000) {
                json_response(['error' => 'bio_en exceeds 2000 characters'], 400);
            }

            // Mise à jour des champs non traduits + valeurs FR par défaut
            $stmt = $pdo->prepare(
                'UPDATE `profile` SET
                    `name`                   = :name,
                    `title`                  = :title,
                    `photo_url`              = :photo_url,
                    `about_photo_url`        = :about_photo_url,
                    `cover_url`              = :cover_url,
                    `about_use_drawer_photo` = :about_use_drawer_photo,
                    `location`               = :location,
                    `status`                 = :status,
                    `bio`                    = :bio,
                    `email`                  = :email,
                    `phone`                  = :phone
                WHERE `id` = 1'
            );
            $stmt->execute([
                ':name'                   => $data['name']     ?? null,
                ':title'                  => $title_fr,
                ':photo_url'              => $photo_url,
                ':about_photo_url'        => $about_photo_url,
                ':cover_url'              => $cover_url,
                ':about_use_drawer_photo' => $about_use_drawer_photo,
                ':location'               => $data['location'] ?? null,
                ':status'                 => $status_fr,
                ':bio'                    => $bio_fr,
                ':email'                  => $data['email']    ?? null,
                ':phone'                  => $data['phone']    ?? null,
            ]);

            // UPSERT profile_translations (UNIQUE sur locale)
            $upsert = $pdo->prepare(
                'INSERT INTO `profile_translations` (`locale`, `title`, `status`, `bio`)
                 VALUES (:locale, :title, :status, :bio)
                 ON DUPLICATE KEY UPDATE
                    `title`  = VALUES(`title`),
                    `status` = VALUES(`status`),
                    `bio`    = VALUES(`bio`)'
            );

            foreach (['fr', 'en'] as $locale) {
                if (!in_array($locale, ['fr', 'en'])) continue; // whitelist
                $upsert->execute([
                    ':locale' => $locale,
                    ':title'  => $locale === 'fr' ? $title_fr : ($data['title_en']  ?? $title_fr),
                    ':status' => $locale === 'fr' ? $status_fr : ($data['status_en'] ?? $status_fr),
                    ':bio'    => $locale === 'fr' ? $bio_fr    : $bio_en,
                ]);
            }

            json_response(['success' => true]);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
