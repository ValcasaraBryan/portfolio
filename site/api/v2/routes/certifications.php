<?php

$id = $GLOBALS['_route']['id'] ?? null;

switch (method()) {

    case 'GET':
        $stmt = $pdo->prepare(
            'SELECT c.id, c.year, c.name, c.formation_id,
                    f.title AS formation_title
             FROM certifications c
             LEFT JOIN formations f ON f.id = c.formation_id
             ORDER BY c.year DESC, c.name ASC'
        );
        $stmt->execute();
        json_response($stmt->fetchAll());

    case 'POST':
        $user = jwt_guard();
        require_perm($user, 'certifications', 'write');
        $d = body();
        $pdo->prepare(
            'INSERT INTO certifications (year, name, formation_id)
             VALUES (:year, :name, :formation_id)'
        )->execute([
            ':year'         => $d['year']         ?? null,
            ':name'         => $d['name']         ?? '',
            ':formation_id' => $d['formation_id'] ?? null,
        ]);
        json_response(['id' => (int) $pdo->lastInsertId()], 201);

    case 'PUT':
        $user = jwt_guard();
        require_perm($user, 'certifications', 'write');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $d = body();
        $pdo->prepare(
            'UPDATE certifications
             SET year=:year, name=:name, formation_id=:formation_id
             WHERE id=:id'
        )->execute([
            ':id'           => $id,
            ':year'         => $d['year']         ?? null,
            ':name'         => $d['name']         ?? '',
            ':formation_id' => $d['formation_id'] ?? null,
        ]);
        json_response(['success' => true]);

    case 'DELETE':
        $user = jwt_guard();
        require_perm($user, 'certifications', 'delete');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $pdo->prepare('DELETE FROM certifications WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
