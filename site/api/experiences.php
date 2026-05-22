<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

function get_experiences(PDO $pdo, string $locale): array
{
    $stmt = $pdo->prepare(
        'SELECT e.id, e.company, e.location, e.start_date, e.end_date,
                COALESCE(et.role,        e.role)        AS role,
                COALESCE(et.type,        e.type)        AS type,
                COALESCE(et.description, e.description) AS description
         FROM `experiences` e
         LEFT JOIN `experience_translations` et ON et.experience_id = e.id AND et.locale = :locale
         ORDER BY e.`start_date` DESC'
    );
    $stmt->execute([':locale' => $locale]);
    $experiences = $stmt->fetchAll();

    foreach ($experiences as &$exp) {
        $stmt = $pdo->prepare(
            'SELECT s.id, st.name, st.category
             FROM `skills` s
             JOIN `skill_translations` st ON st.skill_id = s.id AND st.locale = :locale
             JOIN `experience_skills` es ON es.skill_id = s.id
             WHERE es.experience_id = :exp_id'
        );
        $stmt->execute([':locale' => $locale, ':exp_id' => $exp['id']]);
        $exp['skills'] = $stmt->fetchAll();
    }
    return $experiences;
}

switch (method()) {
    case 'GET':
        $locale = in_array($_GET['lang'] ?? 'fr', ['fr', 'en']) ? ($_GET['lang'] ?? 'fr') : 'fr';
        json_response(get_experiences($pdo, $locale));

    case 'POST':
        require_auth();
        $d    = body();
        $stmt = $pdo->prepare(
            'INSERT INTO `experiences` (`company`,`role`,`type`,`location`,`start_date`,`end_date`,`description`)
             VALUES (:company,:role,:type,:location,:start_date,:end_date,:description)'
        );
        $stmt->execute([
            ':company'    => $d['company']    ?? '',
            ':role'       => $d['role']       ?? '',
            ':type'       => $d['type']       ?? null,
            ':location'   => $d['location']   ?? null,
            ':start_date' => $d['start_date'] ?? null,
            ':end_date'   => $d['end_date']   ?? null,
            ':description'=> $d['description']?? null,
        ]);
        $expId = (int) $pdo->lastInsertId();
        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `experience_skills` (`experience_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$expId, (int)$sid]);
            }
        }
        json_response(['id' => $expId], 201);

    case 'PUT':
        require_auth();
        $d    = body();
        $stmt = $pdo->prepare(
            'UPDATE `experiences`
             SET `company`=:company,`role`=:role,`type`=:type,`location`=:location,
                 `start_date`=:start_date,`end_date`=:end_date,`description`=:description
             WHERE `id`=:id'
        );
        $stmt->execute([
            ':id'         => $d['id'],
            ':company'    => $d['company']    ?? '',
            ':role'       => $d['role']       ?? '',
            ':type'       => $d['type']       ?? null,
            ':location'   => $d['location']   ?? null,
            ':start_date' => $d['start_date'] ?? null,
            ':end_date'   => $d['end_date']   ?? null,
            ':description'=> $d['description']?? null,
        ]);
        $pdo->prepare('DELETE FROM `experience_skills` WHERE `experience_id` = ?')->execute([$d['id']]);
        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `experience_skills` (`experience_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$d['id'], (int)$sid]);
            }
        }
        json_response(['success' => true]);

    case 'DELETE':
        require_auth();
        $id   = $_GET['id'] ?? null;
        $stmt = $pdo->prepare('DELETE FROM `experiences` WHERE `id` = ?');
        $stmt->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
