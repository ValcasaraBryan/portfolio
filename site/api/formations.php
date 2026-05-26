<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

function get_formations(PDO $pdo, string $locale): array
{
    $stmt = $pdo->prepare(
        'SELECT f.id, f.school, f.city, f.start_date, f.end_date, f.mention,
                COALESCE(ft.title,       f.title)       AS title,
                COALESCE(ft.level,       f.level)       AS level,
                COALESCE(ft.description, f.description) AS description
         FROM `formations` f
         LEFT JOIN `formation_translations` ft ON ft.formation_id = f.id AND ft.locale = :locale
         ORDER BY f.`start_date` DESC'
    );
    $stmt->execute([':locale' => $locale]);
    $formations = $stmt->fetchAll();

    foreach ($formations as &$formation) {
        // Sélection des colonnes locale-dépendantes côté PHP pour éviter le doublon :locale en PDO
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
             JOIN `formation_skills` fs ON fs.skill_id = s.id
             LEFT JOIN `skill_translations` st_en ON st_en.skill_id = s.id AND st_en.locale = 'en'
             LEFT JOIN `skill_categories` sc ON sc.key = st_en.category
             WHERE fs.formation_id = :formation_id
             ORDER BY COALESCE(sc.sort_order, 99), st.category, st.name"
        );
        $stmt->execute([':locale' => $locale, ':formation_id' => $formation['id']]);
        $formation['skills'] = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT * FROM `certifications` WHERE `formation_id` = ?'
        );
        $stmt->execute([$formation['id']]);
        $formation['certifications'] = $stmt->fetchAll();
    }
    return $formations;
}

/* ── Upsert d'une traduction formation (locale = 'fr' ou 'en') ── */
function upsert_formation_translation(PDO $pdo, int $formId, string $locale,
                                      string $title, ?string $level, ?string $description): void
{
    $pdo->prepare(
        'INSERT INTO `formation_translations`
             (`formation_id`, `locale`, `title`, `level`, `description`)
         VALUES (:id, :locale, :title, :level, :desc)
         ON DUPLICATE KEY UPDATE
             `title`       = VALUES(`title`),
             `level`       = VALUES(`level`),
             `description` = VALUES(`description`)'
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
        $locale = in_array($_GET['lang'] ?? 'fr', ['fr', 'en']) ? ($_GET['lang'] ?? 'fr') : 'fr';
        json_response(get_formations($pdo, $locale));

    case 'POST':
        require_auth();
        $d    = body();
        $stmt = $pdo->prepare(
            'INSERT INTO `formations` (`school`,`title`,`level`,`city`,`start_date`,`end_date`,`description`,`mention`)
             VALUES (:school,:title,:level,:city,:start_date,:end_date,:description,:mention)'
        );
        $stmt->execute([
            ':school'      => $d['school']      ?? '',
            ':title'       => $d['title']       ?? '',
            ':level'       => $d['level']       ?? null,
            ':city'        => $d['city']        ?? null,
            ':start_date'  => $d['start_date']  ?? null,
            ':end_date'    => $d['end_date']    ?? null,
            ':description' => $d['description'] ?? null,
            ':mention'     => $d['mention']     ?? null,
        ]);
        $formId = (int) $pdo->lastInsertId();

        /* Upsert traduction FR */
        upsert_formation_translation(
            $pdo, $formId, 'fr',
            $d['title']       ?? '',
            $d['level']       ?? null,
            $d['description'] ?? null
        );

        /* Upsert traduction EN */
        upsert_formation_translation(
            $pdo, $formId, 'en',
            $d['title_en']       ?? ($d['title']       ?? ''),
            $d['level_en']       ?? ($d['level']       ?? null),
            $d['description_en'] ?? ($d['description'] ?? null)
        );

        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `formation_skills` (`formation_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$formId, (int)$sid]);
            }
        }
        json_response(['id' => $formId], 201);

    case 'PUT':
        require_auth();
        $d    = body();
        $stmt = $pdo->prepare(
            'UPDATE `formations`
             SET `school`=:school,`title`=:title,`level`=:level,`city`=:city,
                 `start_date`=:start_date,`end_date`=:end_date,`description`=:description,`mention`=:mention
             WHERE `id`=:id'
        );
        $stmt->execute([
            ':id'          => $d['id'],
            ':school'      => $d['school']      ?? '',
            ':title'       => $d['title']       ?? '',
            ':level'       => $d['level']       ?? null,
            ':city'        => $d['city']        ?? null,
            ':start_date'  => $d['start_date']  ?? null,
            ':end_date'    => $d['end_date']    ?? null,
            ':description' => $d['description'] ?? null,
            ':mention'     => $d['mention']     ?? null,
        ]);
        /* Upsert traduction FR */
        upsert_formation_translation(
            $pdo, (int) $d['id'], 'fr',
            $d['title']       ?? '',
            $d['level']       ?? null,
            $d['description'] ?? null
        );

        /* Upsert traduction EN */
        upsert_formation_translation(
            $pdo, (int) $d['id'], 'en',
            $d['title_en']       ?? ($d['title']       ?? ''),
            $d['level_en']       ?? ($d['level']       ?? null),
            $d['description_en'] ?? ($d['description'] ?? null)
        );

        $pdo->prepare('DELETE FROM `formation_skills` WHERE `formation_id` = ?')->execute([$d['id']]);
        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `formation_skills` (`formation_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$d['id'], (int)$sid]);
            }
        }
        json_response(['success' => true]);

    case 'DELETE':
        require_auth();
        $id   = $_GET['id'] ?? null;
        $stmt = $pdo->prepare('DELETE FROM `formations` WHERE `id` = ?');
        $stmt->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
