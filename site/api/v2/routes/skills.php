<?php

$id   = $GLOBALS['_route']['id'] ?? null;
$lang = in_array($_GET['lang'] ?? 'fr', ['fr', 'en'], true) ? $_GET['lang'] : 'fr';

switch (method()) {

    case 'GET':
        $nameCol = $lang === 'fr' ? 'sc.name_fr' : 'sc.name_en';
        $descCol = $lang === 'fr' ? 'sc.description_fr' : 'sc.description_en';
        $stmt = $pdo->prepare(
            "SELECT s.id, st.name,
                    COALESCE(NULLIF({$nameCol}, ''), st.category) AS category,
                    COALESCE({$descCol}, '')       AS category_description,
                    COALESCE(sc.color, '#888888')  AS category_color,
                    COALESCE(st.description, '')   AS skill_description
             FROM skills s
             JOIN  skill_translations st     ON st.skill_id   = s.id AND st.locale = :locale
             LEFT JOIN skill_translations st_en ON st_en.skill_id = s.id AND st_en.locale = 'en'
             LEFT JOIN skill_categories sc  ON sc.key = st_en.category
             ORDER BY COALESCE(sc.sort_order, 99), st.category, st.name"
        );
        $stmt->execute([':locale' => $lang]);
        json_response($stmt->fetchAll());

    case 'POST':
        $user   = jwt_guard();
        require_perm($user, 'skills', 'write');
        $d      = body();
        $catKey = $d['category_key'] ?? $d['category_fr'] ?? '';
        $pdo->prepare(
            'INSERT INTO skills (`name`, `category`) VALUES (:name, :cat)'
        )->execute([':name' => $d['name_fr'] ?? '', ':cat' => $catKey]);
        $newId = (int) $pdo->lastInsertId();
        $tr    = $pdo->prepare(
            'INSERT INTO skill_translations (skill_id, locale, name, category, description)
             VALUES (:sid, :locale, :name, :category, :description)'
        );
        foreach (['fr', 'en'] as $loc) {
            $tr->execute([
                ':sid'         => $newId,
                ':locale'      => $loc,
                ':name'        => $d["name_{$loc}"] ?? '',
                ':category'    => $catKey,
                ':description' => $d["description_{$loc}"] ?? null,
            ]);
        }
        json_response(['id' => $newId], 201);

    case 'PUT':
        $user   = jwt_guard();
        require_perm($user, 'skills', 'write');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $d      = body();
        $catKey = $d['category_key'] ?? $d['category_fr'] ?? '';
        $pdo->prepare(
            'UPDATE skills SET `name`=:name, `category`=:cat WHERE id=:id'
        )->execute([':id' => $id, ':name' => $d['name_fr'] ?? '', ':cat' => $catKey]);
        $tr = $pdo->prepare(
            'INSERT INTO skill_translations (skill_id, locale, name, category, description)
             VALUES (:sid, :locale, :name, :category, :description)
             ON DUPLICATE KEY UPDATE
               name=VALUES(name), category=VALUES(category), description=VALUES(description)'
        );
        foreach (['fr', 'en'] as $loc) {
            $tr->execute([
                ':sid'         => $id,
                ':locale'      => $loc,
                ':name'        => $d["name_{$loc}"] ?? '',
                ':category'    => $catKey,
                ':description' => $d["description_{$loc}"] ?? null,
            ]);
        }
        json_response(['success' => true]);

    case 'DELETE':
        $user = jwt_guard();
        require_perm($user, 'skills', 'delete');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $pdo->prepare('DELETE FROM skills WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
