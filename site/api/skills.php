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
            // Visitor site / SkillsPicker : retourne name+category+category_color dans la locale demandée
            $locale  = in_array($_GET['lang'], ['fr', 'en']) ? $_GET['lang'] : 'fr';
            $nameCol = ($locale === 'fr') ? 'sc.name_fr' : 'sc.name_en';
            $descCol = ($locale === 'fr') ? 'sc.description_fr' : 'sc.description_en';
            $stmt = $pdo->prepare(
                "SELECT s.id, st.name,
                        COALESCE(NULLIF({$nameCol}, ''), st.category) AS category,
                        COALESCE({$descCol}, '') AS category_description,
                        COALESCE(sc.color, '#888888') AS category_color,
                        COALESCE(st.description, '') AS skill_description
                 FROM `skills` s
                 JOIN `skill_translations` st ON st.skill_id = s.id AND st.locale = :locale
                 LEFT JOIN `skill_translations` st_en ON st_en.skill_id = s.id AND st_en.locale = 'en'
                 LEFT JOIN `skill_categories` sc ON sc.key = st_en.category
                 ORDER BY COALESCE(sc.sort_order, 99), st.category, st.name"
            );
            $stmt->execute([':locale' => $locale]);
        } else {
            // Admin CRUD : retourne les champs de traduction fusionnés
            $stmt = $pdo->query(
                'SELECT s.id,
                        MAX(CASE WHEN st.locale = \'fr\' THEN st.name        END) AS name_fr,
                        MAX(CASE WHEN st.locale = \'en\' THEN st.name        END) AS name_en,
                        MAX(CASE WHEN st.locale = \'fr\' THEN st.category    END) AS category_fr,
                        MAX(CASE WHEN st.locale = \'en\' THEN st.category    END) AS category_en,
                        MAX(CASE WHEN st.locale = \'fr\' THEN st.description END) AS description_fr,
                        MAX(CASE WHEN st.locale = \'en\' THEN st.description END) AS description_en
                 FROM `skills` s
                 LEFT JOIN `skill_translations` st ON st.skill_id = s.id
                 GROUP BY s.id
                 ORDER BY name_fr'
            );
        }
        json_response($stmt->fetchAll());

    case 'POST':
        require_min_role('editor');
        $d      = body();
        // category_key (nouveau form admin groupé) ou category_fr (ancien form) — fallback gracieux
        $catKey = $d['category_key'] ?? $d['category_fr'] ?? '';
        $stmt = $pdo->prepare(
            'INSERT INTO `skills` (`name`, `category`) VALUES (:name, :category)'
        );
        $stmt->execute([':name' => $d['name_fr'] ?? '', ':category' => $catKey]);
        $id = (int) $pdo->lastInsertId();
        $tr = $pdo->prepare(
            'INSERT INTO `skill_translations` (`skill_id`, `locale`, `name`, `category`, `description`)
             VALUES (:sid, :locale, :name, :category, :description)'
        );
        foreach (['fr', 'en'] as $loc) {
            $tr->execute([
                ':sid'         => $id,
                ':locale'      => $loc,
                ':name'        => $d["name_{$loc}"] ?? '',
                ':category'    => isset($d['category_key']) ? $catKey : ($d["category_{$loc}"] ?? ''),
                ':description' => $d["description_{$loc}"] ?? null,
            ]);
        }
        json_response(['id' => $id], 201);

    case 'PUT':
        require_min_role('editor');
        $d      = body();
        $catKey = $d['category_key'] ?? $d['category_fr'] ?? '';
        $stmt = $pdo->prepare(
            'UPDATE `skills` SET `name`=:name, `category`=:category WHERE `id`=:id'
        );
        $stmt->execute([':id' => $d['id'], ':name' => $d['name_fr'] ?? '', ':category' => $catKey]);
        $tr = $pdo->prepare(
            'INSERT INTO `skill_translations` (`skill_id`, `locale`, `name`, `category`, `description`)
             VALUES (:sid, :locale, :name, :category, :description)
             ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `category`=VALUES(`category`), `description`=VALUES(`description`)'
        );
        foreach (['fr', 'en'] as $loc) {
            $tr->execute([
                ':sid'         => $d['id'],
                ':locale'      => $loc,
                ':name'        => $d["name_{$loc}"] ?? '',
                ':category'    => isset($d['category_key']) ? $catKey : ($d["category_{$loc}"] ?? ''),
                ':description' => $d["description_{$loc}"] ?? null,
            ]);
        }
        json_response(['success' => true]);

    case 'DELETE':
        require_min_role('admin');
        $id   = $_GET['id'] ?? null;
        $stmt = $pdo->prepare('DELETE FROM `skills` WHERE `id` = ?');
        $stmt->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
