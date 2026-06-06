<?php

$currentUser = jwt_guard();
require_role($currentUser, 'superadmin');

$id     = $GLOBALS['_route']['id'] ?? null;
$sub    = $GLOBALS['_route']['sub'] ?? null;
$method = method();

switch (true) {

    // GET /api/v2/users — liste tous les utilisateurs
    case $method === 'GET' && $id === null:
        $rows = $pdo->query(
            'SELECT id, username, email, role, permissions, last_login_at, created_at
             FROM admin_users ORDER BY created_at'
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['id']          = (int) $row['id'];
            $row['permissions'] = $row['permissions']
                                    ? json_decode($row['permissions'], true)
                                    : null;
        }
        json_response($rows);

    // POST /api/v2/users — créer un utilisateur
    case $method === 'POST':
        $d        = body();
        $username = trim($d['username'] ?? '');
        $password = $d['password']     ?? '';
        if ($username === '' || $password === '') {
            json_response(['error' => 'username and password are required'], 422);
        }
        if (strlen($password) < 8) {
            json_response(['error' => 'Password must be at least 8 characters'], 422);
        }
        $role  = in_array($d['role'] ?? '', ['superadmin', 'admin', 'editor'], true)
                    ? $d['role'] : 'editor';
        $perms = isset($d['permissions']) && is_array($d['permissions'])
                    ? json_encode($d['permissions']) : null;
        $pdo->prepare(
            'INSERT INTO admin_users (username, email, password_hash, role, permissions)
             VALUES (:username, :email, :hash, :role, :perms)'
        )->execute([
            ':username' => $username,
            ':email'    => trim($d['email'] ?? '') ?: null,
            ':hash'     => password_hash($password, PASSWORD_BCRYPT),
            ':role'     => $role,
            ':perms'    => $perms,
        ]);
        json_response(['id' => (int) $pdo->lastInsertId()], 201);

    // PUT /api/v2/users/:id — modifier un utilisateur
    case $method === 'PUT' && $id !== null:
        $d    = body();
        $role = in_array($d['role'] ?? '', ['superadmin', 'admin', 'editor'], true)
                    ? $d['role'] : null;

        if ($role !== null && $role !== 'superadmin') {
            $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $targetRole = $stmt->fetchColumn();
            if ($targetRole === 'superadmin') {
                $stmt2 = $pdo->prepare(
                    "SELECT COUNT(*) FROM admin_users WHERE role = 'superadmin' AND id != ?"
                );
                $stmt2->execute([$id]);
                if ((int) $stmt2->fetchColumn() === 0) {
                    json_response(['error' => 'Cannot demote the last superadmin'], 422);
                }
            }
        }

        $fields = [];
        $params = [':id' => $id];
        if (array_key_exists('email', $d)) {
            $fields[]        = 'email = :email';
            $params[':email'] = trim($d['email'] ?? '') ?: null;
        }
        if ($role !== null) {
            $fields[]       = 'role = :role';
            $params[':role'] = $role;
        }
        if (isset($d['permissions']) && is_array($d['permissions'])) {
            $fields[]        = 'permissions = :perms';
            $params[':perms'] = json_encode($d['permissions']);
        }
        if (!empty($d['password'])) {
            if (strlen($d['password']) < 8) {
                json_response(['error' => 'Password must be at least 8 characters'], 422);
            }
            $fields[]       = 'password_hash = :hash';
            $params[':hash'] = password_hash($d['password'], PASSWORD_BCRYPT);
            $fields[]       = 'must_change_password = 0';
        }
        if (empty($fields)) {
            json_response(['error' => 'Nothing to update'], 422);
        }
        $pdo->prepare('UPDATE admin_users SET ' . implode(', ', $fields) . ' WHERE id = :id')
            ->execute($params);
        json_response(['success' => true]);

    // PATCH /api/v2/users/:id/permissions — remplacer les permissions
    case $method === 'PATCH' && $id !== null && $sub === 'permissions':
        $d = body();
        if (!isset($d['permissions']) || !is_array($d['permissions'])) {
            json_response(['error' => 'permissions object is required'], 422);
        }
        $pdo->prepare('UPDATE admin_users SET permissions = ? WHERE id = ?')
            ->execute([json_encode($d['permissions']), $id]);
        json_response(['success' => true]);

    // DELETE /api/v2/users/:id — supprimer un utilisateur
    case $method === 'DELETE' && $id !== null:
        if ((int) $currentUser['sub'] === $id) {
            json_response(['error' => 'Cannot delete your own account'], 422);
        }
        $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $targetRole = $stmt->fetchColumn();
        if ($targetRole === 'superadmin') {
            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'superadmin'");
            $stmt2->execute();
            if ((int) $stmt2->fetchColumn() <= 1) {
                json_response(['error' => 'Cannot delete the last superadmin'], 422);
            }
        }
        $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Method not allowed'], 405);
}
