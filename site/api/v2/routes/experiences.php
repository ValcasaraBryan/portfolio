<?php

$id   = $GLOBALS['_route']['id']  ?? null;
$sub  = $GLOBALS['_route']['sub'] ?? null;
$lang = in_array($_GET['lang'] ?? 'fr', ['fr', 'en'], true) ? $_GET['lang'] : 'fr';

const EXPERIENCE_TYPES = [
    'internship', 'permanent_contract', 'fixed_term_contract',
    'work_study', 'freelance', 'self_employed',
];

if ($sub === 'skills' && $id !== null) {
    switch (method()) {

        case 'GET':
            $stmt = $pdo->prepare(
                'SELECT s.id, st.name
                 FROM experience_skills es
                 JOIN skills s  ON s.id = es.skill_id
                 JOIN skill_translations st ON st.skill_id = s.id AND st.locale = :locale
                 WHERE es.experience_id = :id
                 ORDER BY st.name'
            );
            $stmt->execute([':locale' => $lang, ':id' => $id]);
            json_response($stmt->fetchAll());

        case 'PUT':
            $user = jwt_guard();
            require_perm($user, 'experiences', 'write');
            $d = body();
            if (!isset($d['skill_ids']) || !is_array($d['skill_ids'])) {
                json_response(['error' => 'skill_ids array is required'], 422);
            }
            $pdo->prepare('DELETE FROM experience_skills WHERE experience_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO experience_skills (experience_id, skill_id) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $skillId) {
                $ins->execute([$id, (int) $skillId]);
            }
            json_response(['success' => true]);

        default:
            json_response(['error' => 'Method not allowed'], 405);
    }
}

function upsert_experience_translation(PDO $pdo, int $expId, string $locale,
                                       string $role, ?string $description): void
{
    $pdo->prepare(
        'INSERT INTO experience_translations (experience_id, locale, role, description)
         VALUES (:id, :locale, :role, :desc)
         ON DUPLICATE KEY UPDATE role=VALUES(role), description=VALUES(description)'
    )->execute([
        ':id'     => $expId,
        ':locale' => $locale,
        ':role'   => $role,
        ':desc'   => $description,
    ]);
}

switch (method()) {

    case 'GET':
        $stmt = $pdo->prepare(
            'SELECT e.id, e.company, e.location, e.type, e.start_date, e.end_date,
                    COALESCE(et.role,        e.role)        AS role,
                    COALESCE(et.description, e.description) AS description
             FROM experiences e
             LEFT JOIN experience_translations et
                    ON et.experience_id = e.id AND et.locale = :locale
             ORDER BY e.start_date DESC'
        );
        $stmt->execute([':locale' => $lang]);
        json_response($stmt->fetchAll());

    case 'POST':
        $user = jwt_guard();
        require_perm($user, 'experiences', 'write');
        $d    = body();
        $type = $d['type'] ?? '';
        if (!in_array($type, EXPERIENCE_TYPES, true)) {
            json_response(['error' => 'Invalid type'], 422);
        }
        $pdo->prepare(
            'INSERT INTO experiences (company, role, type, location, start_date, end_date, description)
             VALUES (:company, :role, :type, :location, :start_date, :end_date, :description)'
        )->execute([
            ':company'     => $d['company']        ?? '',
            ':role'        => $d['role_fr']        ?? '',
            ':type'        => $type,
            ':location'    => $d['location']       ?? null,
            ':start_date'  => $d['start_date']     ?? null,
            ':end_date'    => $d['end_date']       ?? null,
            ':description' => $d['description_fr'] ?? null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        upsert_experience_translation($pdo, $newId, 'fr',
            $d['role_fr']        ?? '',
            $d['description_fr'] ?? null
        );
        upsert_experience_translation($pdo, $newId, 'en',
            $d['role_en']        ?? ($d['role_fr']        ?? ''),
            $d['description_en'] ?? ($d['description_fr'] ?? null)
        );
        json_response(['id' => $newId], 201);

    case 'PUT':
        $user = jwt_guard();
        require_perm($user, 'experiences', 'write');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $d    = body();
        $type = $d['type'] ?? '';
        if (!in_array($type, EXPERIENCE_TYPES, true)) {
            json_response(['error' => 'Invalid type'], 422);
        }
        $pdo->prepare(
            'UPDATE experiences
             SET company=:company, role=:role, type=:type, location=:location,
                 start_date=:start_date, end_date=:end_date, description=:description
             WHERE id=:id'
        )->execute([
            ':id'          => $id,
            ':company'     => $d['company']        ?? '',
            ':role'        => $d['role_fr']        ?? '',
            ':type'        => $type,
            ':location'    => $d['location']       ?? null,
            ':start_date'  => $d['start_date']     ?? null,
            ':end_date'    => $d['end_date']       ?? null,
            ':description' => $d['description_fr'] ?? null,
        ]);
        upsert_experience_translation($pdo, $id, 'fr',
            $d['role_fr']        ?? '',
            $d['description_fr'] ?? null
        );
        upsert_experience_translation($pdo, $id, 'en',
            $d['role_en']        ?? ($d['role_fr']        ?? ''),
            $d['description_en'] ?? ($d['description_fr'] ?? null)
        );
        json_response(['success' => true]);

    case 'DELETE':
        $user = jwt_guard();
        require_perm($user, 'experiences', 'delete');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $pdo->prepare('DELETE FROM experiences WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
