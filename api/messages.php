<?php
/**
 * Messages Controller
 * Handles conversation listing, message history, and sending/deleting messages.
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
 * ── GET: Fetch Conversations or Message History ──
 */
if ($method === 'GET') {
    // 1. Fetch Conversation List (Inbox)
    // Gets all unique users the current user has chatted with.
    if (!isset($_GET['with'])) {
        $stmt = $db->prepare("SELECT DISTINCT CASE WHEN from_user=? THEN to_user ELSE from_user END AS partner_id 
                              FROM messages WHERE from_user=? OR to_user=?");
        $stmt->execute([$user['id'], $user['id'], $user['id']]);
        $partners = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $convs = [];
        foreach ($partners as $pid) {
            // Fetch partner details
            $p = $db->prepare('SELECT id, name, role FROM users WHERE id=?');
            $p->execute([$pid]);
            $partner = $p->fetch(PDO::FETCH_ASSOC);

            // Fetch the most recent message for the preview
            $l = $db->prepare("SELECT message, sent_at FROM messages 
                               WHERE (from_user=? AND to_user=?) OR (from_user=? AND to_user=?) 
                               ORDER BY sent_at DESC LIMIT 1");
            $l->execute([$user['id'], $pid, $pid, $user['id']]);
            $lastMsg = $l->fetch(PDO::FETCH_ASSOC);

            $convs[] = [
                'partner' => $partner, 
                'last_message' => $lastMsg
            ];
        }
        jsonResponse(['success' => true, 'data' => $convs]);
    }

    // 2. Fetch Full Message History with a specific user
    if (isset($_GET['with'])) {
        $pid = (int)$_GET['with'];
        $stmt = $db->prepare("SELECT m.*, u.name AS sender_name 
                              FROM messages m 
                              JOIN users u ON m.from_user = u.id 
                              WHERE (m.from_user=? AND m.to_user=?) OR (m.from_user=? AND m.to_user=?) 
                              ORDER BY m.sent_at ASC");
        $stmt->execute([$user['id'], $pid, $pid, $user['id']]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
}

/**
 * ── POST: Send a Message ──
 */
if ($method === 'POST') {
    $data    = getBody();
    $toUser  = (int)($data['to_user']  ?? 0);
    $message = sanitize($data['message'] ?? '');

    if (!$toUser || !$message) {
        jsonResponse(['success' => false, 'error' => 'Recipient and message required.'], 400);
    }
    if ($toUser === (int)$user['id']) {
        jsonResponse(['success' => false, 'error' => 'You cannot message yourself.'], 400);
    }

    // Verify recipient exists
    $chk = $db->prepare('SELECT id FROM users WHERE id=?');
    $chk->execute([$toUser]);
    if (!$chk->fetch()) {
        jsonResponse(['success' => false, 'error' => 'Recipient not found.'], 404);
    }

    // Insert message
    $db->prepare('INSERT INTO messages (from_user, to_user, message) VALUES (?, ?, ?)')
       ->execute([$user['id'], $toUser, $message]);
    
    $msgId = (int)$db->lastInsertId();

    // Notify recipient
    $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")
       ->execute([$toUser, "New message from " . htmlspecialchars($user['name'])]);

    jsonResponse(['success' => true, 'id' => $msgId, 'message' => 'Message sent.']);
}

/**
 * ── DELETE: Remove a Message ──
 */
if ($method === 'DELETE' && isset($_GET['id'])) {
    $msgId = (int)$_GET['id'];
    
    // Check ownership
    $stmt = $db->prepare('SELECT from_user FROM messages WHERE id=?');
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$msg) {
        jsonResponse(['success' => false, 'error' => 'Message not found.'], 404);
    }
    if ($msg['from_user'] != $user['id']) {
        jsonResponse(['success' => false, 'error' => 'You can only delete your own messages.'], 403);
    }

    $db->prepare('DELETE FROM messages WHERE id=?')->execute([$msgId]);
    jsonResponse(['success' => true, 'message' => 'Message deleted.']);
}

jsonResponse(['success' => false, 'error' => 'Invalid request method.'], 400);