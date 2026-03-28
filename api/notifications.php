<?php
/**
 * Notifications Controller
 * Handles fetching, marking as read, and clearing user alerts.
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
 * ── GET: Fetch Notifications ──
 */
if ($method === 'GET') {
    // Limits to the most recent 50 notifications to keep the response fast
    $stmt = $db->prepare('SELECT id, message, is_read, created_at 
                          FROM notifications 
                          WHERE user_id = ? 
                          ORDER BY created_at DESC 
                          LIMIT 50');
    $stmt->execute([$user['id']]);
    
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'data' => $notifications]);
}

/**
 * ── PUT: Mark Notifications as Read ──
 */
if ($method === 'PUT') {
    $data = getBody();
    $notifId = (int)($data['id'] ?? 0);

    if ($notifId > 0) {
        // Option A: Mark a specific notification as read
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$notifId, $user['id']]);
    } else {
        // Option B: Mark ALL as read (default behavior)
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$user['id']]);
    }

    jsonResponse(['success' => true, 'message' => 'Notifications updated.']);
}

/**
 * ── DELETE: Clear Notifications ──
 */
if ($method === 'DELETE') {
    $notifId = (int)($_GET['id'] ?? 0);

    if ($notifId > 0) {
        // Option A: Delete a specific notification
        $stmt = $db->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([$notifId, $user['id']]);
    } else {
        // Option B: Clear the entire inbox
        $stmt = $db->prepare('DELETE FROM notifications WHERE user_id = ?');
        $stmt->execute([$user['id']]);
    }

    jsonResponse(['success' => true, 'message' => 'Notifications cleared.']);
}

jsonResponse(['success' => false, 'error' => 'Invalid request method.'], 405);