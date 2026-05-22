<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/db.php';

switch (method()) {
    case 'GET':
        $locale = in_array($_GET['lang'] ?? 'fr', ['fr', 'en']) ? ($_GET['lang'] ?? 'fr') : 'fr';
        $stmt = $pdo->query('SELECT * FROM `profile` LIMIT 1');
        $profile = $stmt->fetch();
        if (!$profile) {
            json_response(['error' => 'Profile not found'], 404);
        }
        $tr = $pdo->prepare('SELECT `title`, `status` FROM `profile_translations` WHERE `locale` = ?');
        $tr->execute([$locale]);
        $translation = $tr->fetch();
        if ($translation) {
            $profile['title']  = $translation['title'];
            $profile['status'] = $translation['status'];
        }
        $links = $pdo->query('SELECT * FROM `links` ORDER BY `id`')->fetchAll();
        $profile['links'] = $links;
        json_response($profile);

    case 'PUT':
        $data = body();
        $fields = ['name', 'title', 'photo_url', 'location', 'status', 'email', 'phone'];
        $sets   = implode(', ', array_map(fn($f) => "`$f` = :$f", $fields));
        $stmt   = $pdo->prepare("UPDATE `profile` SET $sets WHERE `id` = 1");
        foreach ($fields as $f) {
            $stmt->bindValue(":$f", $data[$f] ?? null);
        }
        $stmt->execute();
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
