<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

switch (method()) {
    case 'GET':
        try {
            $rows = $pdo->query('SELECT `id`, `platform`, `url`, `icon`, `icon_dark` FROM `links` ORDER BY `id`')->fetchAll();
            json_response($rows);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    case 'POST':
        require_min_role('admin');
        try {
            $data = body();

            $url = $data['url'] ?? '';
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                json_response(['error' => 'URL invalide'], 400);
            }

            $platform = $data['platform'] ?? '';
            if ($platform === '') {
                json_response(['error' => 'Platform requis'], 400);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO `links` (`platform`, `url`, `icon`, `icon_dark`) VALUES (:platform, :url, :icon, :icon_dark)'
            );
            $stmt->execute([
                ':platform'  => $platform,
                ':url'       => $url,
                ':icon'      => $data['icon']      ?? null,
                ':icon_dark' => $data['icon_dark'] ?? null,
            ]);
            json_response(['success' => true, 'id' => (int) $pdo->lastInsertId()], 201);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    case 'PUT':
        require_min_role('admin');
        try {
            $data = body();

            // L'ID est dans le body JSON (cohérent avec projects.php)
            $id = intval($data['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                json_response(['error' => 'ID invalide'], 400);
            }

            $url = $data['url'] ?? '';
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                json_response(['error' => 'URL invalide'], 400);
            }

            $platform = $data['platform'] ?? '';
            if ($platform === '') {
                json_response(['error' => 'Platform requis'], 400);
            }

            $stmt = $pdo->prepare(
                'UPDATE `links` SET `platform` = :platform, `url` = :url, `icon` = :icon, `icon_dark` = :icon_dark WHERE `id` = :id'
            );
            $stmt->execute([
                ':platform'  => $platform,
                ':url'       => $url,
                ':icon'      => $data['icon']      ?? null,
                ':icon_dark' => $data['icon_dark'] ?? null,
                ':id'        => $id,
            ]);

            if ($stmt->rowCount() === 0) {
                json_response(['error' => 'Lien non trouvé'], 404);
            }
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    case 'DELETE':
        require_min_role('admin');
        try {
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) {
                json_response(['error' => 'ID invalide'], 400);
            }

            $stmt = $pdo->prepare('DELETE FROM `links` WHERE `id` = ?');
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                json_response(['error' => 'Lien non trouvé'], 404);
            }
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
