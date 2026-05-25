<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

function get_projects(PDO $pdo, string $locale): array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.photo_url, p.date, p.url, p.github_url, p.is_favorite,
                COALESCE(pt.name,        p.name)        AS name,
                COALESCE(pt.description, p.description) AS description,
                p.category                              AS category
         FROM `projects` p
         LEFT JOIN `project_translations` pt ON pt.project_id = p.id AND pt.locale = :locale
         ORDER BY p.is_favorite DESC, p.`id` ASC'
    );
    $stmt->execute([':locale' => $locale]);
    $projects = $stmt->fetchAll();

    foreach ($projects as &$project) {
        $stmt = $pdo->prepare(
            'SELECT s.id, st.name, st.category
             FROM `skills` s
             JOIN `skill_translations` st ON st.skill_id = s.id AND st.locale = :locale
             JOIN `project_skills` ps ON ps.skill_id = s.id
             WHERE ps.project_id = :project_id'
        );
        $stmt->execute([':locale' => $locale, ':project_id' => $project['id']]);
        $project['skills'] = $stmt->fetchAll();
    }
    return $projects;
}

switch (method()) {
    case 'GET':
        $locale = in_array($_GET['lang'] ?? 'fr', ['fr', 'en']) ? ($_GET['lang'] ?? 'fr') : 'fr';
        json_response(get_projects($pdo, $locale));

    case 'POST':
        require_auth();
        $d        = body();
        $category = $d['category'] ?? null;
        if ($category !== null && !in_array($category, ['web', 'opensource', 'side'], true)) {
            json_response(['error' => 'Invalid category'], 400);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO `projects` (`name`,`photo_url`,`description`,`date`,`url`,`github_url`,`category`)
             VALUES (:name,:photo_url,:description,:date,:url,:github_url,:category)'
        );
        $stmt->execute([
            ':name'       => $d['name']       ?? '',
            ':photo_url'  => $d['photo_url']  ?? null,
            ':description'=> $d['description']?? null,
            ':date'       => $d['date']       ?? null,
            ':url'        => $d['url']        ?? null,
            ':github_url' => $d['github_url'] ?? null,
            ':category'   => $category,
        ]);
        $projectId = (int) $pdo->lastInsertId();
        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `project_skills` (`project_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$projectId, (int)$sid]);
            }
        }
        json_response(['id' => $projectId], 201);

    case 'PUT':
        require_auth();
        $d        = body();
        $category = $d['category'] ?? null;
        if ($category !== null && !in_array($category, ['web', 'opensource', 'side'], true)) {
            json_response(['error' => 'Invalid category'], 400);
        }
        $stmt = $pdo->prepare(
            'UPDATE `projects`
             SET `name`=:name,`photo_url`=:photo_url,`description`=:description,
                 `date`=:date,`url`=:url,`github_url`=:github_url,`category`=:category
             WHERE `id`=:id'
        );
        $stmt->execute([
            ':id'         => $d['id'],
            ':name'       => $d['name']       ?? '',
            ':photo_url'  => $d['photo_url']  ?? null,
            ':description'=> $d['description']?? null,
            ':date'       => $d['date']       ?? null,
            ':url'        => $d['url']        ?? null,
            ':github_url' => $d['github_url'] ?? null,
            ':category'   => $category,
        ]);
        $pdo->prepare(
            'UPDATE `project_translations`
             SET `name`=:name,`description`=:description
             WHERE `project_id`=:id'
        )->execute([
            ':id'         => $d['id'],
            ':name'       => $d['name']       ?? '',
            ':description'=> $d['description']?? null,
        ]);
        $pdo->prepare('DELETE FROM `project_skills` WHERE `project_id` = ?')->execute([$d['id']]);
        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `project_skills` (`project_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$d['id'], (int)$sid]);
            }
        }
        json_response(['success' => true]);

    case 'PATCH':
        require_auth();
        $d = body();
        $pdo->prepare('UPDATE `projects` SET `is_favorite`=:fav WHERE `id`=:id')
            ->execute([':fav' => (int)(bool)($d['is_favorite'] ?? 0), ':id' => (int)$d['id']]);
        json_response(['success' => true]);

    case 'DELETE':
        require_auth();
        $id   = $_GET['id'] ?? null;
        $stmt = $pdo->prepare('DELETE FROM `projects` WHERE `id` = ?');
        $stmt->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
