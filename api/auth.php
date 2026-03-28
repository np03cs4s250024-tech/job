<?php
/**
 * Prevent PHP warnings from breaking JSON output.
 * If mail() fails, it won't crash the frontend.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';
require_once '../config/otp.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── STEP 1: Send OTP ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'send-otp') {
    $data  = getBody();
    $name  = sanitize($data['name']  ?? '');
    $email = sanitize($data['email'] ?? '');
    $pass  = $data['password']       ?? '';
    $role  = $data['role']           ?? 'seeker';

    if (!$name || !$email || !$pass) jsonResponse(['error' => 'All fields required.'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['error' => 'Invalid email format.'], 400);
    if (strlen($pass) < 6) jsonResponse(['error' => 'Password must be at least 6 characters.'], 400);
    
    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) jsonResponse(['error' => 'This email is already registered.'], 409);

    $_SESSION['pending_reg'] = [
        'name'     => $name,
        'email'    => $email,
        'password' => password_hash($pass, PASSWORD_DEFAULT),
        'role'     => $role,
    ];

    $otp  = generateOtp($email);
    $sent = sendOtpEmail($email, $otp);

    jsonResponse([
        'success' => true,
        'message' => 'Verification code sent to ' . $email,
        'dev_otp' => $otp // REMOVE THIS LINE IN PRODUCTION
    ]);
}

// ── STEP 2: Verify OTP & Create Account ──────────────────────────────────────
if ($method === 'POST' && $action === 'verify-otp') {
    $data  = getBody();
    $email = sanitize($data['email'] ?? '');
    $otp   = trim($data['otp']       ?? '');

    if (!$email || !$otp) jsonResponse(['error' => 'Email and OTP are required.'], 400);
    
    if (!verifyOtp($email, $otp)) {
        jsonResponse(['error' => 'Invalid or expired code.'], 400);
    }

    $pending = $_SESSION['pending_reg'] ?? null;
    if (!$pending || $pending['email'] !== $email) {
        jsonResponse(['error' => 'Session expired. Please start registration again.'], 400);
    }

    $db = getDB();
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)');
        $stmt->execute([$pending['name'], $pending['email'], $pending['password'], $pending['role']]);
        $newId = (int)$db->lastInsertId();

        if ($pending['role'] === 'seeker') {
            $db->prepare('INSERT INTO seeker_profiles (user_id) VALUES (?)')->execute([$newId]);
        } else if ($pending['role'] === 'employer') {
            $db->prepare('INSERT INTO employer_profiles (user_id) VALUES (?)')->execute([$newId]);
        }

        $db->commit();
        
        clearOtp();
        unset($_SESSION['pending_reg']);
        
        jsonResponse(['success' => true, 'message' => 'Account created! You can now login.']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $data  = getBody();
    $email = sanitize($data['email']    ?? '');
    $pass  = $data['password']          ?? '';

    if (!$email || !$pass) jsonResponse(['error' => 'Email and password required.'], 400);

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pass, $user['password'])) {
        jsonResponse(['error' => 'Invalid email or password.'], 401);
    }

    unset($user['password']);
    
    $_SESSION['user'] = $user;
    session_write_close();
    jsonResponse(['success' => true, 'user' => $user]);
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    jsonResponse(['success' => true]);
}

// ── SESSION CHECK ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'me') {
    $user = $_SESSION['user'] ?? null;
    if ($user) {
        jsonResponse(['success' => true, 'user' => $user]);
    } else {
        jsonResponse(['success' => false, 'user' => null], 401);
    }
}

jsonResponse(['error' => 'Requested action not found.'], 404);