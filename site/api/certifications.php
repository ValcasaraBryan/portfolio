<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

/* ── Validation helpers ─────────────────────────────────────── */

function validate_year(mixed $raw): ?int
{
    if ($raw === null || $raw === '') return null;
    $y = filter_var($raw, FILTER_VALIDATE_INT);
    if ($y === false || $y < 1970 || $y > 2099) return false;
    return $y;
}

function formation_exists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT id FROM `formations` WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetchColumn() !== false;
}

switch (method()) {

    /* ── GET ──────────────────────────────────────────────────── */
    case 'GET':
        if (isset($_GET['formation_id'])) {
            $fid = intval($_GET['formation_id']);
            if ($fid <= 0) {
                json_response(['error' => 'formation_id invalide'], 400);
            }
            $stmt = $pdo->prepare(
                'SELECT id, year, name, formation_id FROM `certifications` WHERE `formation_id` = ? ORDER BY `year` DESC, `id` ASC'
            );
            $stmt->execute([$fid]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, year, name, formation_id FROM `certifications` ORDER BY `formation_id`, `year` DESC, `id` ASC'
            );
            $stmt->execute();
        }
        json_response($stmt->fetchAll());

    /* ── POST ─────────────────────────────────────────────────── */
    case 'POST':
        require_min_role('editor');
        $d = body();

        $name = trim($d['name'] ?? '');
        if ($name === '') {
            json_response(['error' => 'Le champ name est requis'], 400);
        }
        if (mb_strlen($name) > 200) {
            json_response(['error' => 'Le champ name est trop long (max 200 caractères)'], 400);
        }

        $year = validate_year($d['year'] ?? null);
        if ($year === false) {
            json_response(['error' => 'Le champ year doit être un entier entre 1970 et 2099, ou null'], 400);
        }

        $fid = intval($d['formation_id'] ?? 0);
        if ($fid <= 0) {
            json_response(['error' => 'formation_id invalide'], 400);
        }
        try {
            if (!formation_exists($pdo, $fid)) {
                json_response(['error' => 'Formation introuvable'], 400);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO `certifications` (`year`, `name`, `formation_id`) VALUES (:year, :name, :formation_id)'
            );
            $stmt->execute([':year' => $year, ':name' => $name, ':formation_id' => $fid]);
            json_response(['id' => (int)$pdo->lastInsertId(), 'success' => true], 201);
        } catch (PDOException) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    /* ── PUT ──────────────────────────────────────────────────── */
    case 'PUT':
        require_min_role('editor');
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_response(['error' => 'id invalide'], 400);
        }

        $d    = body();
        $name = trim($d['name'] ?? '');
        if ($name === '') {
            json_response(['error' => 'Le champ name est requis'], 400);
        }
        if (mb_strlen($name) > 200) {
            json_response(['error' => 'Le champ name est trop long (max 200 caractères)'], 400);
        }

        $year = validate_year($d['year'] ?? null);
        if ($year === false) {
            json_response(['error' => 'Le champ year doit être un entier entre 1970 et 2099, ou null'], 400);
        }

        try {
            $stmt = $pdo->prepare(
                'UPDATE `certifications` SET `year` = :year, `name` = :name WHERE `id` = :id'
            );
            $stmt->execute([':year' => $year, ':name' => $name, ':id' => $id]);
            json_response(['success' => true]);
        } catch (PDOException) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    /* ── DELETE ───────────────────────────────────────────────── */
    case 'DELETE':
        require_min_role('admin');
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_response(['error' => 'id invalide'], 400);
        }
        try {
            $pdo->prepare('DELETE FROM `certifications` WHERE `id` = ?')->execute([$id]);
            json_response(['success' => true]);
        } catch (PDOException) {
            json_response(['error' => 'Erreur serveur'], 500);
        }

    default:
        json_response(['error' => 'Méthode non autorisée'], 405);
}
