<?php
require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $current = requireLogin();
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if ($current['role'] !== 'admin' && $current['id'] != $id)
            jsonResponse(['error' => 'Forbidden.'], 403);
        $stmt = $db->prepare('SELECT id,name,email,role,created_at FROM users WHERE id=?');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) jsonResponse(['error' => 'Not found.'], 404);
        jsonResponse($u);
    }
    requireRole('admin');
    jsonResponse($db->query('SELECT id,name,email,role,created_at FROM users ORDER BY created_at DESC')->fetchAll());
}

if ($method === 'POST') {
    requireRole('admin');
    $data  = getBody();
    $name  = sanitize($data['name']  ?? '');
    $email = sanitize($data['email'] ?? '');
    $pass  = $data['password']       ?? '';
    $role  = sanitize($data['role']  ?? 'seeker');
    if (!$name || !$email || !$pass) jsonResponse(['error' => 'All fields required.'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['error' => 'Invalid email.'], 400);
    if (strlen($pass) < 6) jsonResponse(['error' => 'Password min 6 chars.'], 400);
    $chk = $db->prepare('SELECT id FROM users WHERE email=?');
    $chk->execute([$email]);
    if ($chk->fetch()) jsonResponse(['error' => 'Email already exists.'], 409);
    $db->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)')
       ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()]);
}

if ($method === 'PUT' && isset($_GET['id'])) {
    $current = requireLogin();
    $id      = (int)$_GET['id'];
    if ($current['role'] !== 'admin' && $current['id'] != $id)
        jsonResponse(['error' => 'Forbidden.'], 403);
    $data = getBody();
    $name = sanitize($data['name'] ?? '');
    if (!$name) jsonResponse(['error' => 'Name required.'], 400);
    $db->prepare('UPDATE users SET name=? WHERE id=?')->execute([$name, $id]);
    jsonResponse(['success' => true]);
}

if ($method === 'DELETE' && isset($_GET['id'])) {
    $current = requireLogin();
    $id      = (int)$_GET['id'];

    // ✅ Admins cannot delete themselves
    if ($current['role'] === 'admin' && $id === (int)$current['id'])
        jsonResponse(['error' => 'Cannot delete your own admin account.'], 403);
    if ($current['role'] !== 'admin' && $current['id'] != $id)
        jsonResponse(['error' => 'Forbidden.'], 403);

    $db->beginTransaction();
    $stmt = $db->prepare('SELECT role FROM users WHERE id=?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) { $db->rollBack(); jsonResponse(['error' => 'Not found.'], 404); }

    if ($u['role'] === 'seeker') {
        $db->prepare('DELETE FROM seeker_profiles WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM applications   WHERE seeker_id=?')->execute([$id]);
        $db->prepare('DELETE FROM saved_jobs     WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM resumes        WHERE user_id=?')->execute([$id]);
    } elseif ($u['role'] === 'employer') {
        $db->prepare('DELETE FROM employer_profiles WHERE user_id=?')->execute([$id]);
        $db->prepare("UPDATE jobs SET status='closed' WHERE employer_id=?")->execute([$id]);
    }

    // ✅ CORRECT column names: from_user, to_user (matches DB)
    $db->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$id]);
    $db->prepare('DELETE FROM messages WHERE from_user=? OR to_user=?')->execute([$id, $id]);
    $db->prepare('DELETE FROM reviews WHERE user_id=?')->execute([$id]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    $db->commit();

    if ($id === (int)$current['id']) session_destroy();
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Invalid request.'], 400);