<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

switch (method()) {
    case 'GET':
        // Public — liste toutes les catégories triées par sort_order
        $stmt = $pdo->query(
            'SELECT id, `key`, name_fr, name_en, description_fr, description_en, color, sort_order
             FROM `skill_categories`
             ORDER BY sort_order, `key`'
        );
        json_response($stmt->fetchAll());

    case 'POST':
        require_min_role('admin');
        $d = body();
        $key = trim($d['key'] ?? '');
        if ($key === '') {
            json_response(['error' => 'key is required'], 422);
        }
        $color  = preg_match('/^#[0-9a-fA-F]{6}$/', $d['color'] ?? '') ? $d['color'] : '#888888';
        $nameFr = mb_substr(trim($d['name_fr'] ?? $key), 0, 100);
        $nameEn = mb_substr(trim($d['name_en'] ?? $key), 0, 100);
        $stmt = $pdo->prepare(
            'INSERT INTO `skill_categories` (`key`, name_fr, name_en, description_fr, description_en, color, sort_order)
             VALUES (:key, :nfr, :nen, :dfr, :den, :color, :sort)'
        );
        $stmt->execute([
            ':key'   => $key,
            ':nfr'   => $nameFr,
            ':nen'   => $nameEn,
            ':dfr'   => $d['description_fr'] ?? '',
            ':den'   => $d['description_en'] ?? '',
            ':color' => $color,
            ':sort'  => (int)($d['sort_order'] ?? 0),
        ]);
        json_response(['id' => (int)$pdo->lastInsertId()], 201);

    case 'PUT':
        require_min_role('admin');
        $d  = body();
        $id = (int)($d['id'] ?? 0);
        if ($id === 0) {
            json_response(['error' => 'id is required'], 422);
        }
        $color  = preg_match('/^#[0-9a-fA-F]{6}$/', $d['color'] ?? '') ? $d['color'] : '#888888';
        $nameFr = mb_substr(trim($d['name_fr'] ?? ''), 0, 100);
        $nameEn = mb_substr(trim($d['name_en'] ?? ''), 0, 100);
        // key est en lecture seule en PUT (clé de JOIN immuable)
        $stmt = $pdo->prepare(
            'UPDATE `skill_categories`
             SET name_fr=:nfr, name_en=:nen,
                 description_fr=:dfr, description_en=:den,
                 color=:color, sort_order=:sort
             WHERE id=:id'
        );
        $stmt->execute([
            ':id'    => $id,
            ':nfr'   => $nameFr,
            ':nen'   => $nameEn,
            ':dfr'   => $d['description_fr'] ?? '',
            ':den'   => $d['description_en'] ?? '',
            ':color' => $color,
            ':sort'  => (int)($d['sort_order'] ?? 0),
        ]);
        json_response(['success' => true]);

    case 'DELETE':
        require_min_role('admin');
        $id = (int)($_GET['id'] ?? 0);
        if ($id === 0) {
            json_response(['error' => 'id is required'], 422);
        }
        // Pas de FK entre skill_categories et skills — suppression safe (skills deviennent orphelins)
        $pdo->prepare('DELETE FROM `skill_categories` WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
