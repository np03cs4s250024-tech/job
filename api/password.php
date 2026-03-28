<?php
/**
 * Password Controller
 * Handles password changes (authenticated) and password resets.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';

// This controller only accepts POST requests for security
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed. Use POST.'], 405);
}

$data   = getBody();
$action = $_GET['action'] ?? '';
$db     = getDB();

/**
 * ── ACTION: CHANGE PASSWORD ──
 * Used when a user is logged in and wants to update their current password.
 */
if ($action === 'change') {
    $user    = requireLogin();
    $current = $data['current_password'] ?? '';
    $new     = $data['new_password']      ?? '';
    $confirm = $data['confirm_password']  ?? '';

    // Validation
    if (!$current || !$new || !$confirm) {
        jsonResponse(['success' => false, 'error' => 'Current, new, and confirmation passwords are required.'], 400);
    }
    if ($new !== $confirm) {
        jsonResponse(['success' => false, 'error' => 'New passwords do not match.'], 400);
    }
    if (strlen($new) < 6) {
        jsonResponse(['success' => false, 'error' => 'New password must be at least 6 characters.'], 400);
    }

    // Verify current password against database
    $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($current, $row['password'])) {
        jsonResponse(['success' => false, 'error' => 'Current password incorrect.'], 401);
    }

    // Update with new hash
    $hashedPassword = password_hash($new, PASSWORD_DEFAULT);
    $update = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
    $update->execute([$hashedPassword, $user['id']]);

    jsonResponse(['success' => true, 'message' => 'Password updated successfully.']);
}

/**
 * ── ACTION: RESET PASSWORD ──
 * Used for forgotten passwords (simplified version without token).
 */
if ($action === 'reset') {
    $email = sanitize($data['email'] ?? '');
    $new   = $data['new_password']   ?? '';

    if (!$email || !$new) {
        jsonResponse(['success' => false, 'error' => 'Email and new password required.'], 400);
    }
    
    if (strlen($new) < 6) {
        jsonResponse(['success' => false, 'error' => 'New password must be at least 6 characters.'], 400);
    }

    // Check if user exists
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if (!$stmt->fetch()) {
        jsonResponse(['success' => false, 'error' => 'No account found with that email address.'], 404);
    }

    // Update password
    $hashedPassword = password_hash($new, PASSWORD_DEFAULT);
    $update = $db->prepare('UPDATE users SET password = ? WHERE email = ?');
    $update->execute([$hashedPassword, $email]);

    jsonResponse(['success' => true, 'message' => 'Password has been reset successfully.']);
}

jsonResponse(['success' => false, 'error' => 'Invalid action requested.'], 400);