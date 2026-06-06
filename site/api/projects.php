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

    $nameCol = ($locale === 'fr') ? 'sc.name_fr' : 'sc.name_en';
    $descCol = ($locale === 'fr') ? 'sc.description_fr' : 'sc.description_en';
    foreach ($projects as &$project) {
        $stmt = $pdo->prepare(
            "SELECT s.id, st.name,
                    COALESCE(NULLIF({$nameCol}, ''), st.category) AS category,
                    COALESCE({$descCol}, '') AS category_description,
                    COALESCE(sc.color, '#888888') AS category_color,
                    COALESCE(st.description, '') AS skill_description
             FROM `skills` s
             JOIN `skill_translations` st ON st.skill_id = s.id AND st.locale = :locale
             JOIN `project_skills` ps ON ps.skill_id = s.id
             LEFT JOIN `skill_translations` st_en ON st_en.skill_id = s.id AND st_en.locale = 'en'
             LEFT JOIN `skill_categories` sc ON sc.key = st_en.category
             WHERE ps.project_id = :project_id
             ORDER BY COALESCE(sc.sort_order, 99), st.category, st.name"
        );
        $stmt->execute([':locale' => $locale, ':project_id' => $project['id']]);
        $project['skills'] = $stmt->fetchAll();
    }
    return $projects;
}

/* ── Upsert d'une traduction projet (locale = 'fr' ou 'en') ─── */
function upsert_project_translation(PDO $pdo, int $projectId, string $locale,
                                    string $name, ?string $description): void
{
    $pdo->prepare(
        'INSERT INTO `project_translations`
             (`project_id`, `locale`, `name`, `description`)
         VALUES (:id, :locale, :name, :desc)
         ON DUPLICATE KEY UPDATE
             `name`        = VALUES(`name`),
             `description` = VALUES(`description`)'
    )->execute([
        ':id'     => $projectId,
        ':locale' => $locale,
        ':name'   => $name,
        ':desc'   => $description,
    ]);
}

switch (method()) {
    case 'GET':
        $locale = in_array($_GET['lang'] ?? 'fr', ['fr', 'en']) ? ($_GET['lang'] ?? 'fr') : 'fr';
        json_response(get_projects($pdo, $locale));

    case 'POST':
        require_min_role('editor');
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

        /* Upsert traduction FR */
        upsert_project_translation(
            $pdo, $projectId, 'fr',
            $d['name']        ?? '',
            $d['description'] ?? null
        );

        /* Upsert traduction EN */
        upsert_project_translation(
            $pdo, $projectId, 'en',
            $d['name_en']        ?? ($d['name']        ?? ''),
            $d['description_en'] ?? ($d['description'] ?? null)
        );

        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `project_skills` (`project_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$projectId, (int)$sid]);
            }
        }
        json_response(['id' => $projectId], 201);

    case 'PUT':
        require_min_role('editor');
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
        /* Upsert traduction FR */
        upsert_project_translation(
            $pdo, (int) $d['id'], 'fr',
            $d['name']        ?? '',
            $d['description'] ?? null
        );

        /* Upsert traduction EN */
        upsert_project_translation(
            $pdo, (int) $d['id'], 'en',
            $d['name_en']        ?? ($d['name']        ?? ''),
            $d['description_en'] ?? ($d['description'] ?? null)
        );

        $pdo->prepare('DELETE FROM `project_skills` WHERE `project_id` = ?')->execute([$d['id']]);
        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `project_skills` (`project_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$d['id'], (int)$sid]);
            }
        }
        json_response(['success' => true]);

    case 'PATCH':
        require_min_role('editor');
        $d = body();
        $pdo->prepare('UPDATE `projects` SET `is_favorite`=:fav WHERE `id`=:id')
            ->execute([':fav' => (int)(bool)($d['is_favorite'] ?? 0), ':id' => (int)$d['id']]);
        json_response(['success' => true]);

    case 'DELETE':
        require_min_role('admin');
        $id   = $_GET['id'] ?? null;
        $stmt = $pdo->prepare('DELETE FROM `projects` WHERE `id` = ?');
        $stmt->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
