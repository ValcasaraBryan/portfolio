<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

$ALLOWED_TABS = ['about', 'experiences', 'creations', 'formations', 'contact'];

switch (method()) {
  case 'GET':
    $routes = $pdo->query(
      'SELECT tab_name, url_path FROM tab_routes ORDER BY order_index ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $configRows = $pdo->query(
      "SELECT config_key, config_value FROM site_config
       WHERE config_key IN ('default_tab', 'home_tab')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    json_response([
      'routes' => $routes,
      'config' => [
        'default_tab' => $configRows['default_tab'] ?? 'about',
        'home_tab'    => $configRows['home_tab']    ?? 'about',
      ],
    ]);
    break;

  case 'PUT':
    require_auth();
    $body = body();
    if (!is_array($body)) json_response(['error' => 'Invalid payload'], 400);

    // Mise à jour des routes
    $items = $body['routes'] ?? [];
    $stmt  = $pdo->prepare(
      'UPDATE tab_routes SET url_path = :url_path WHERE tab_name = :tab_name'
    );
    foreach ($items as $item) {
      $tab  = $item['tab_name'] ?? '';
      $path = $item['url_path'] ?? '';
      if (!in_array($tab, $ALLOWED_TABS, true))
        json_response(['error' => "Unknown tab: $tab"], 400);
      if (!preg_match('#^/[a-z0-9][a-z0-9-]*$#', $path))
        json_response(['error' => "Invalid path for $tab: $path"], 400);
      $stmt->execute([':tab_name' => $tab, ':url_path' => $path]);
    }

    // Mise à jour de la config
    $config  = $body['config'] ?? [];
    $cfgStmt = $pdo->prepare(
      'UPDATE site_config SET config_value = :val WHERE config_key = :key'
    );
    foreach (['default_tab', 'home_tab'] as $key) {
      if (isset($config[$key])) {
        $val = $config[$key];
        if (!in_array($val, $ALLOWED_TABS, true))
          json_response(['error' => "Invalid tab for $key: $val"], 400);
        $cfgStmt->execute([':key' => $key, ':val' => $val]);
      }
    }

    json_response(['success' => true]);
    break;

  default:
    json_response(['error' => 'Method not allowed'], 405);
}
