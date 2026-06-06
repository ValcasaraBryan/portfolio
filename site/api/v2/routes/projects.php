<?php

$id   = $GLOBALS['_route']['id']  ?? null;
$sub  = $GLOBALS['_route']['sub'] ?? null;
$lang = in_array($_GET['lang'] ?? 'fr', ['fr', 'en'], true) ? $_GET['lang'] : 'fr';

const PROJECT_CATEGORIES = ['web', 'opensource', 'side'];

if ($sub === 'skills' && $id !== null) {
    switch (method()) {

        case 'GET':
            $stmt = $pdo->prepare(
                'SELECT s.id, st.name
                 FROM project_skills ps
                 JOIN skills s  ON s.id = ps.skill_id
                 JOIN skill_translations st ON st.skill_id = s.id AND st.locale = :locale
                 WHERE ps.project_id = :id
                 ORDER BY st.name'
            );
            $stmt->execute([':locale' => $lang, ':id' => $id]);
            json_response($stmt->fetchAll());

        case 'PUT':
            $user = jwt_guard();
            require_perm($user, 'projects', 'write');
            $d = body();
            if (!isset($d['skill_ids']) || !is_array($d['skill_ids'])) {
                json_response(['error' => 'skill_ids array is required'], 422);
            }
            $pdo->prepare('DELETE FROM project_skills WHERE project_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO project_skills (project_id, skill_id) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $skillId) {
                $ins->execute([$id, (int) $skillId]);
            }
            json_response(['success' => true]);

        default:
            json_response(['error' => 'Method not allowed'], 405);
    }
}

function upsert_project_translation(PDO $pdo, int $projectId, string $locale,
                                    string $name, ?string $description): void
{
    $pdo->prepare(
        'INSERT INTO project_translations (project_id, locale, name, description)
         VALUES (:id, :locale, :name, :desc)
         ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description)'
    )->execute([
        ':id'     => $projectId,
        ':locale' => $locale,
        ':name'   => $name,
        ':desc'   => $description,
    ]);
}

switch (method()) {

    case 'GET':
        $stmt = $pdo->prepare(
            'SELECT p.id, p.photo_url, p.date, p.url, p.github_url, p.category, p.is_favorite,
                    COALESCE(pt.name,        p.name)        AS name,
                    COALESCE(pt.description, p.description) AS description
             FROM projects p
             LEFT JOIN project_translations pt
                    ON pt.project_id = p.id AND pt.locale = :locale
             ORDER BY p.is_favorite DESC, p.id ASC'
        );
        $stmt->execute([':locale' => $lang]);
        json_response($stmt->fetchAll());

    case 'POST':
        $user     = jwt_guard();
        require_perm($user, 'projects', 'write');
        $d        = body();
        $category = $d['category'] ?? null;
        if ($category !== null && !in_array($category, PROJECT_CATEGORIES, true)) {
            json_response(['error' => 'Invalid category'], 422);
        }
        $pdo->prepare(
            'INSERT INTO projects (name, photo_url, description, date, url, github_url, category, is_favorite)
             VALUES (:name, :photo_url, :description, :date, :url, :github_url, :category, :is_favorite)'
        )->execute([
            ':name'        => $d['name_fr']        ?? '',
            ':photo_url'   => $d['photo_url']      ?? null,
            ':description' => $d['description_fr'] ?? null,
            ':date'        => $d['date']            ?? null,
            ':url'         => $d['url']             ?? null,
            ':github_url'  => $d['github_url']      ?? null,
            ':category'    => $category,
            ':is_favorite' => (int) ($d['is_favorite'] ?? 0),
        ]);
        $newId = (int) $pdo->lastInsertId();
        upsert_project_translation($pdo, $newId, 'fr',
            $d['name_fr']        ?? '',
            $d['description_fr'] ?? null
        );
        upsert_project_translation($pdo, $newId, 'en',
            $d['name_en']        ?? ($d['name_fr']        ?? ''),
            $d['description_en'] ?? ($d['description_fr'] ?? null)
        );
        json_response(['id' => $newId], 201);

    case 'PUT':
        $user = jwt_guard();
        require_perm($user, 'projects', 'write');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $d        = body();
        $category = $d['category'] ?? null;
        if ($category !== null && !in_array($category, PROJECT_CATEGORIES, true)) {
            json_response(['error' => 'Invalid category'], 422);
        }
        $pdo->prepare(
            'UPDATE projects
             SET name=:name, photo_url=:photo_url, description=:description,
                 date=:date, url=:url, github_url=:github_url,
                 category=:category, is_favorite=:is_favorite
             WHERE id=:id'
        )->execute([
            ':id'          => $id,
            ':name'        => $d['name_fr']        ?? '',
            ':photo_url'   => $d['photo_url']      ?? null,
            ':description' => $d['description_fr'] ?? null,
            ':date'        => $d['date']            ?? null,
            ':url'         => $d['url']             ?? null,
            ':github_url'  => $d['github_url']      ?? null,
            ':category'    => $category,
            ':is_favorite' => (int) ($d['is_favorite'] ?? 0),
        ]);
        upsert_project_translation($pdo, $id, 'fr',
            $d['name_fr']        ?? '',
            $d['description_fr'] ?? null
        );
        upsert_project_translation($pdo, $id, 'en',
            $d['name_en']        ?? ($d['name_fr']        ?? ''),
            $d['description_en'] ?? ($d['description_fr'] ?? null)
        );
        json_response(['success' => true]);

    case 'DELETE':
        $user = jwt_guard();
        require_perm($user, 'projects', 'delete');
        if ($id === null) {
            json_response(['error' => 'id is required'], 422);
        }
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
