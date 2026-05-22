<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

switch (method()) {
    case 'GET':
        if (isset($_GET['lang'])) {
            // Visitor site / SkillsPicker : retourne name+category dans la locale demandée
            $locale = in_array($_GET['lang'], ['fr', 'en']) ? $_GET['lang'] : 'fr';
            $stmt = $pdo->prepare(
                'SELECT s.id, st.name, st.category
                 FROM `skills` s
                 JOIN `skill_translations` st ON st.skill_id = s.id AND st.locale = :locale
                 ORDER BY st.category, st.name'
            );
            $stmt->execute([':locale' => $locale]);
        } else {
            // Admin CRUD : retourne les 4 champs de traduction fusionnés
            $stmt = $pdo->query(
                'SELECT s.id,
                        MAX(CASE WHEN st.locale = \'fr\' THEN st.name     END) AS name_fr,
                        MAX(CASE WHEN st.locale = \'en\' THEN st.name     END) AS name_en,
                        MAX(CASE WHEN st.locale = \'fr\' THEN st.category END) AS category_fr,
                        MAX(CASE WHEN st.locale = \'en\' THEN st.category END) AS category_en
                 FROM `skills` s
                 LEFT JOIN `skill_translations` st ON st.skill_id = s.id
                 GROUP BY s.id
                 ORDER BY name_fr'
            );
        }
        json_response($stmt->fetchAll());

    case 'POST':
        require_auth();
        $d    = body();
        $stmt = $pdo->prepare(
            'INSERT INTO `skills` (`name`, `category`) VALUES (:name, :category)'
        );
        $stmt->execute([':name' => $d['name_fr'] ?? '', ':category' => $d['category_fr'] ?? '']);
        $id = (int) $pdo->lastInsertId();
        $tr = $pdo->prepare(
            'INSERT INTO `skill_translations` (`skill_id`, `locale`, `name`, `category`)
             VALUES (:sid, :locale, :name, :category)'
        );
        foreach (['fr', 'en'] as $loc) {
            $tr->execute([
                ':sid'      => $id,
                ':locale'   => $loc,
                ':name'     => $d["name_{$loc}"]     ?? '',
                ':category' => $d["category_{$loc}"] ?? '',
            ]);
        }
        json_response(['id' => $id], 201);

    case 'PUT':
        require_auth();
        $d    = body();
        $stmt = $pdo->prepare(
            'UPDATE `skills` SET `name`=:name, `category`=:category WHERE `id`=:id'
        );
        $stmt->execute([':id' => $d['id'], ':name' => $d['name_fr'] ?? '', ':category' => $d['category_fr'] ?? '']);
        $tr = $pdo->prepare(
            'INSERT INTO `skill_translations` (`skill_id`, `locale`, `name`, `category`)
             VALUES (:sid, :locale, :name, :category)
             ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `category`=VALUES(`category`)'
        );
        foreach (['fr', 'en'] as $loc) {
            $tr->execute([
                ':sid'      => $d['id'],
                ':locale'   => $loc,
                ':name'     => $d["name_{$loc}"]     ?? '',
                ':category' => $d["category_{$loc}"] ?? '',
            ]);
        }
        json_response(['success' => true]);

    case 'DELETE':
        require_auth();
        $id   = $_GET['id'] ?? null;
        $stmt = $pdo->prepare('DELETE FROM `skills` WHERE `id` = ?');
        $stmt->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
