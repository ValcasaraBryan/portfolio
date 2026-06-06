<?php

$id = $GLOBALS['_route']['id'] ?? null;

switch (method()) {

    case 'GET':
        $stmt = $pdo->query(
            'SELECT id, `key`, name_fr, name_en,
                    description_fr, description_en, color, sort_order
             FROM skill_categories
             ORDER BY sort_order, `key`'
        );
        json_response($stmt->fetchAll());

    case 'POST':
        $user = jwt_guard();
        require_perm($user, 'categories', 'write');
        $d   = body();
        $key = trim($d['key'] ?? '');
        if ($key === '') {
            json_response(['error' => 'key is required'], 422);
        }
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $d['color'] ?? '') ? $d['color'] : '#888888';
        $pdo->prepare(
            'INSERT INTO skill_categories (`key`, name_fr, name_en,
             description_fr, description_en, color, sort_order)
             VALUES (:key, :nfr, :nen, :dfr, :den, :color, :sort)'
        )->execute([
            ':key'   => $key,
            ':nfr'   => mb_substr(trim($d['name_fr'] ?? $key), 0, 100),
            ':nen'   => mb_substr(trim($d['name_en'] ?? $key), 0, 100),
            ':dfr'   => $d['description_fr'] ?? '',
            ':den'   => $d['description_en'] ?? '',
            ':color' => $color,
            ':sort'  => (int) ($d['sort_order'] ?? 0),
        ]);
        json_response(['id' => (int) $pdo->lastInsertId()], 201);

    case 'PUT':
        $user = jwt_guard();
        require_perm($user, 'categories', 'write');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $d     = body();
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $d['color'] ?? '') ? $d['color'] : '#888888';
        $pdo->prepare(
            'UPDATE skill_categories
             SET name_fr=:nfr, name_en=:nen,
                 description_fr=:dfr, description_en=:den,
                 color=:color, sort_order=:sort
             WHERE id=:id'
        )->execute([
            ':id'    => $id,
            ':nfr'   => mb_substr(trim($d['name_fr'] ?? ''), 0, 100),
            ':nen'   => mb_substr(trim($d['name_en'] ?? ''), 0, 100),
            ':dfr'   => $d['description_fr'] ?? '',
            ':den'   => $d['description_en'] ?? '',
            ':color' => $color,
            ':sort'  => (int) ($d['sort_order'] ?? 0),
        ]);
        json_response(['success' => true]);

    case 'DELETE':
        $user = jwt_guard();
        require_perm($user, 'categories', 'delete');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $pdo->prepare('DELETE FROM skill_categories WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
