<?php

$id   = $GLOBALS['_route']['id'] ?? null;
$lang = in_array($_GET['lang'] ?? 'fr', ['fr', 'en'], true) ? $_GET['lang'] : 'fr';

function upsert_education_translation(PDO $pdo, int $formId, string $locale,
                                      string $title, ?string $level, ?string $description): void
{
    $pdo->prepare(
        'INSERT INTO formation_translations (formation_id, locale, title, level, description)
         VALUES (:id, :locale, :title, :level, :desc)
         ON DUPLICATE KEY UPDATE
           title=VALUES(title), level=VALUES(level), description=VALUES(description)'
    )->execute([
        ':id'     => $formId,
        ':locale' => $locale,
        ':title'  => $title,
        ':level'  => $level,
        ':desc'   => $description,
    ]);
}

switch (method()) {

    case 'GET':
        $stmt = $pdo->prepare(
            'SELECT f.id, f.school, f.city, f.start_date, f.end_date, f.mention,
                    COALESCE(ft.title,       f.title)       AS title,
                    COALESCE(ft.level,       f.level)       AS level,
                    COALESCE(ft.description, f.description) AS description
             FROM formations f
             LEFT JOIN formation_translations ft
                    ON ft.formation_id = f.id AND ft.locale = :locale
             ORDER BY f.start_date DESC'
        );
        $stmt->execute([':locale' => $lang]);
        json_response($stmt->fetchAll());

    case 'POST':
        $user = jwt_guard();
        require_perm($user, 'educations', 'write');
        $d    = body();
        $pdo->prepare(
            'INSERT INTO formations
               (school, title, level, city, start_date, end_date, description, mention)
             VALUES
               (:school, :title, :level, :city, :start_date, :end_date, :description, :mention)'
        )->execute([
            ':school'      => $d['school']      ?? '',
            ':title'       => $d['title']       ?? '',
            ':level'       => $d['level']       ?? null,
            ':city'        => $d['city']        ?? null,
            ':start_date'  => $d['start_date']  ?? null,
            ':end_date'    => $d['end_date']    ?? null,
            ':description' => $d['description'] ?? null,
            ':mention'     => $d['mention']     ?? null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        upsert_education_translation($pdo, $newId, 'fr',
            $d['title']          ?? '',
            $d['level']          ?? null,
            $d['description']    ?? null
        );
        upsert_education_translation($pdo, $newId, 'en',
            $d['title_en']       ?? ($d['title']       ?? ''),
            $d['level_en']       ?? ($d['level']       ?? null),
            $d['description_en'] ?? ($d['description'] ?? null)
        );
        json_response(['id' => $newId], 201);

    case 'PUT':
        $user = jwt_guard();
        require_perm($user, 'educations', 'write');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $d = body();
        $pdo->prepare(
            'UPDATE formations
             SET school=:school, title=:title, level=:level, city=:city,
                 start_date=:start_date, end_date=:end_date,
                 description=:description, mention=:mention
             WHERE id=:id'
        )->execute([
            ':id'          => $id,
            ':school'      => $d['school']      ?? '',
            ':title'       => $d['title']       ?? '',
            ':level'       => $d['level']       ?? null,
            ':city'        => $d['city']        ?? null,
            ':start_date'  => $d['start_date']  ?? null,
            ':end_date'    => $d['end_date']    ?? null,
            ':description' => $d['description'] ?? null,
            ':mention'     => $d['mention']     ?? null,
        ]);
        upsert_education_translation($pdo, $id, 'fr',
            $d['title']          ?? '',
            $d['level']          ?? null,
            $d['description']    ?? null
        );
        upsert_education_translation($pdo, $id, 'en',
            $d['title_en']       ?? ($d['title']       ?? ''),
            $d['level_en']       ?? ($d['level']       ?? null),
            $d['description_en'] ?? ($d['description'] ?? null)
        );
        json_response(['success' => true]);

    case 'DELETE':
        $user = jwt_guard();
        require_perm($user, 'educations', 'delete');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $pdo->prepare('DELETE FROM formations WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
