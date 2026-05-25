<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

/* ── Valeurs d'enum autorisées ─────────────────────────────────── */
const VALID_TYPES = [
    'internship', 'permanent_contract', 'fixed_term_contract',
    'work_study', 'freelance', 'self_employed',
];

/* ── Labels lisibles par locale (fallback si pas de traduction) ── */
const TYPE_LABELS = [
    'en' => [
        'internship'          => 'Internship',
        'permanent_contract'  => 'Permanent contract',
        'fixed_term_contract' => 'Fixed-term contract',
        'work_study'          => 'Work-study',
        'freelance'           => 'Freelance',
        'self_employed'       => 'Self-employed',
    ],
    'fr' => [
        'internship'          => 'Stage',
        'permanent_contract'  => 'CDI',
        'fixed_term_contract' => 'CDD',
        'work_study'          => 'Alternance',
        'freelance'           => 'Freelance',
        'self_employed'       => 'Indépendant',
    ],
];

/**
 * Valide la clé enum type.
 * Retourne null si vide, la clé si valide, ou lève une 422.
 */
function validate_type(?string $type): ?string
{
    if ($type === null || $type === '') return null;
    if (!in_array($type, VALID_TYPES, true)) {
        json_response(['error' => 'Invalid experience type. Allowed: ' . implode(', ', VALID_TYPES)], 422);
    }
    return $type;
}

/**
 * Dérive le label lisible depuis la clé enum et la locale.
 * Priorité : valeur fournie → constante TYPE_LABELS → clé brute.
 */
function type_label(?string $key, string $locale, ?string $provided = null): ?string
{
    if ($key === null) return null;
    if ($provided !== null && $provided !== '') return $provided;
    return TYPE_LABELS[$locale][$key] ?? $key;
}

/* ── Lecture des expériences ────────────────────────────────────── */
function get_experiences(PDO $pdo, string $locale): array
{
    $stmt = $pdo->prepare(
        'SELECT e.id, e.company, e.location, e.start_date, e.end_date,
                e.type                        AS type_key,
                COALESCE(et.role,        e.role)        AS role,
                COALESCE(et.type,        e.type)        AS type,
                COALESCE(et.description, e.description) AS description
         FROM `experiences` e
         LEFT JOIN `experience_translations` et ON et.experience_id = e.id AND et.locale = :locale
         ORDER BY e.`start_date` DESC'
    );
    $stmt->execute([':locale' => $locale]);
    $experiences = $stmt->fetchAll();

    $nameCol = ($locale === 'fr') ? 'sc.name_fr' : 'sc.name_en';
    $descCol = ($locale === 'fr') ? 'sc.description_fr' : 'sc.description_en';
    foreach ($experiences as &$exp) {
        $stmt = $pdo->prepare(
            "SELECT s.id, st.name,
                    COALESCE(NULLIF({$nameCol}, ''), st.category) AS category,
                    COALESCE({$descCol}, '') AS category_description,
                    COALESCE(sc.color, '#888888') AS category_color,
                    COALESCE(st.description, '') AS skill_description
             FROM `skills` s
             JOIN `skill_translations` st ON st.skill_id = s.id AND st.locale = :locale
             JOIN `experience_skills` es ON es.skill_id = s.id
             LEFT JOIN `skill_translations` st_en ON st_en.skill_id = s.id AND st_en.locale = 'en'
             LEFT JOIN `skill_categories` sc ON sc.key = st_en.category
             WHERE es.experience_id = :exp_id
             ORDER BY COALESCE(sc.sort_order, 99), st.category, st.name"
        );
        $stmt->execute([':locale' => $locale, ':exp_id' => $exp['id']]);
        $exp['skills'] = $stmt->fetchAll();
    }
    return $experiences;
}

/* ── Upsert d'une traduction (locale = 'fr' ou 'en') ───────────── */
function upsert_translation(PDO $pdo, int $expId, string $locale, string $role, ?string $type, ?string $description): void
{
    $pdo->prepare(
        'INSERT INTO `experience_translations`
            (`experience_id`, `locale`, `role`, `type`, `description`)
         VALUES (:id, :locale, :role, :type, :desc)
         ON DUPLICATE KEY UPDATE
            `role`        = VALUES(`role`),
            `type`        = VALUES(`type`),
            `description` = VALUES(`description`)'
    )->execute([
        ':id'     => $expId,
        ':locale' => $locale,
        ':role'   => $role,
        ':type'   => $type,
        ':desc'   => $description,
    ]);
}

/* ── Router ─────────────────────────────────────────────────────── */
switch (method()) {

    /* ── GET ─────────────────────────────────────────────────────── */
    case 'GET':
        $locale = in_array($_GET['lang'] ?? 'fr', ['fr', 'en']) ? ($_GET['lang'] ?? 'fr') : 'fr';
        json_response(get_experiences($pdo, $locale));
        break;

    /* ── POST (création) ─────────────────────────────────────────── */
    case 'POST':
        require_auth();
        $d    = body();
        $type = validate_type($d['type'] ?? null);

        $stmt = $pdo->prepare(
            'INSERT INTO `experiences`
                (`company`, `role`, `type`, `location`, `start_date`, `end_date`, `description`)
             VALUES
                (:company, :role, :type, :location, :start_date, :end_date, :description)'
        );
        $stmt->execute([
            ':company'     => $d['company']     ?? '',
            ':role'        => $d['role']        ?? '',
            ':type'        => $type,
            ':location'    => $d['location']    ?? null,
            ':start_date'  => $d['start_date']  ?? null,
            ':end_date'    => $d['end_date']    ?? null,
            ':description' => $d['description'] ?? null,
        ]);
        $expId = (int) $pdo->lastInsertId();

        /* Upsert traduction FR */
        upsert_translation(
            $pdo, $expId, 'fr',
            $d['role']        ?? '',
            type_label($type, 'fr', $d['type_fr'] ?? null),
            $d['description'] ?? null
        );

        /* Upsert traduction EN */
        upsert_translation(
            $pdo, $expId, 'en',
            $d['role_en']        ?? ($d['role']        ?? ''),
            type_label($type, 'en', $d['type_en'] ?? null),
            $d['description_en'] ?? ($d['description'] ?? null)
        );

        /* Skills */
        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `experience_skills` (`experience_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$expId, (int) $sid]);
            }
        }
        json_response(['id' => $expId], 201);
        break;

    /* ── PUT (modification) ──────────────────────────────────────── */
    case 'PUT':
        require_auth();
        $d    = body();
        $type = validate_type($d['type'] ?? null);

        /* Mise à jour table principale */
        $pdo->prepare(
            'UPDATE `experiences`
             SET `company`=:company, `role`=:role, `type`=:type, `location`=:location,
                 `start_date`=:start_date, `end_date`=:end_date, `description`=:description
             WHERE `id`=:id'
        )->execute([
            ':id'          => $d['id'],
            ':company'     => $d['company']     ?? '',
            ':role'        => $d['role']        ?? '',
            ':type'        => $type,
            ':location'    => $d['location']    ?? null,
            ':start_date'  => $d['start_date']  ?? null,
            ':end_date'    => $d['end_date']    ?? null,
            ':description' => $d['description'] ?? null,
        ]);

        /* Upsert traduction FR */
        upsert_translation(
            $pdo, (int) $d['id'], 'fr',
            $d['role']        ?? '',
            type_label($type, 'fr', $d['type_fr'] ?? null),
            $d['description'] ?? null
        );

        /* Upsert traduction EN */
        upsert_translation(
            $pdo, (int) $d['id'], 'en',
            $d['role_en']        ?? ($d['role']        ?? ''),
            type_label($type, 'en', $d['type_en'] ?? null),
            $d['description_en'] ?? ($d['description'] ?? null)
        );

        /* Skills : remplacer entièrement */
        $pdo->prepare('DELETE FROM `experience_skills` WHERE `experience_id` = ?')->execute([$d['id']]);
        if (!empty($d['skill_ids']) && is_array($d['skill_ids'])) {
            $sk = $pdo->prepare('INSERT INTO `experience_skills` (`experience_id`, `skill_id`) VALUES (?, ?)');
            foreach ($d['skill_ids'] as $sid) {
                $sk->execute([$d['id'], (int) $sid]);
            }
        }
        json_response(['success' => true]);
        break;

    /* ── DELETE ──────────────────────────────────────────────────── */
    case 'DELETE':
        require_auth();
        $id = $_GET['id'] ?? null;
        $pdo->prepare('DELETE FROM `experiences` WHERE `id` = ?')->execute([$id]);
        json_response(['success' => true]);
        break;

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
