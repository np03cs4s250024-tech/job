<?php
/**
 * Prevent PHP warnings from breaking JSON output in XAMPP.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();
$user   = requireLogin();

/**
 * ── GET: Fetch Profile Data ──
 */
if ($method === 'GET') {
    $profileData = [];
    
    // Fetch basic user info first (Name and Email)
    $stmtUser = $db->prepare('SELECT name, email FROM users WHERE id = ?');
    $stmtUser->execute([$user['id']]);
    $basicInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($user['role'] === 'seeker') {
        $stmt = $db->prepare('SELECT phone, skills, experience, bio FROM seeker_profiles WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        $profileData = array_merge($basicInfo ?? [], $p ?: []);
    } 
    elseif ($user['role'] === 'employer') {
        $stmt = $db->prepare('SELECT company, industry, website, about FROM employer_profiles WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        $profileData = array_merge($basicInfo ?? [], $p ?: []);
    } 
    else {
        jsonResponse(['success' => false, 'error' => 'Admins do not have extended profiles.'], 400);
    }

    jsonResponse(['success' => true, 'data' => $profileData]);
}

/**
 * ── POST/PUT: Update Profile Data ──
 */
if ($method === 'POST' || $method === 'PUT') {
    $data = getBody();

    if ($user['role'] === 'seeker') {
        // 1. Update main 'users' table name if provided
        if (!empty($data['name'])) {
            $db->prepare('UPDATE users SET name = ? WHERE id = ?')
               ->execute([sanitize($data['name']), $user['id']]);
            
            // Sync session name so navbar updates immediately
            $_SESSION['user']['name'] = sanitize($data['name']);
        }

        // 2. Update or Insert into seeker_profiles
        $stmt = $db->prepare('SELECT id FROM seeker_profiles WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        
        if ($stmt->fetch()) {
            $sql = 'UPDATE seeker_profiles SET phone=?, skills=?, experience=?, bio=? WHERE user_id=?';
            $db->prepare($sql)->execute([
                sanitize($data['phone'] ?? ''), 
                sanitize($data['skills'] ?? ''), 
                sanitize($data['experience'] ?? ''), 
                sanitize($data['bio'] ?? ''), 
                $user['id']
            ]);
        } else {
            $sql = 'INSERT INTO seeker_profiles (user_id, phone, skills, experience, bio) VALUES (?, ?, ?, ?, ?)';
            $db->prepare($sql)->execute([
                $user['id'], 
                sanitize($data['phone'] ?? ''), 
                sanitize($data['skills'] ?? ''), 
                sanitize($data['experience'] ?? ''), 
                sanitize($data['bio'] ?? '')
            ]);
        }
        jsonResponse(['success' => true, 'message' => 'Profile updated successfully.']);
    }

    if ($user['role'] === 'employer') {
        $stmt = $db->prepare('SELECT id FROM employer_profiles WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        
        if ($stmt->fetch()) {
            $sql = 'UPDATE employer_profiles SET company=?, industry=?, website=?, about=? WHERE user_id=?';
            $db->prepare($sql)->execute([
                sanitize($data['company'] ?? ''), 
                sanitize($data['industry'] ?? ''), 
                sanitize($data['website'] ?? ''), 
                sanitize($data['about'] ?? ''), 
                $user['id']
            ]);
        } else {
            $sql = 'INSERT INTO employer_profiles (user_id, company, industry, website, about) VALUES (?, ?, ?, ?, ?)';
            $db->prepare($sql)->execute([
                $user['id'], 
                sanitize($data['company'] ?? ''), 
                sanitize($data['industry'] ?? ''), 
                sanitize($data['website'] ?? ''), 
                sanitize($data['about'] ?? '')
            ]);
        }
        jsonResponse(['success' => true, 'message' => 'Company profile updated.']);
    }
}

/**
 * ── PATCH: Update Account Credentials (Name/Email) ──
 */
if ($method === 'PATCH') {
    $data  = getBody();
    $name  = sanitize($data['name']  ?? '');
    $email = sanitize($data['email'] ?? '');

    if (!$name || !$email) jsonResponse(['success' => false, 'error' => 'Name and email are required.'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['success' => false, 'error' => 'Invalid email format.'], 400);

    try {
        $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $user['id']]);
        
        // Refresh session data for the updated user
        $stmt = $db->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['user'] = $updated;

        jsonResponse(['success' => true, 'user' => $updated]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Email may already be in use.'], 409);
    }
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);