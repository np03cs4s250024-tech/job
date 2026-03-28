<?php
/**
 * Reviews Controller
 * Handles fetching, creating, and deleting company reviews.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';

// Ensure basic helper functions are available locally if not in session/db config
if (!function_exists('jsonResponse')) {
    function jsonResponse($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('sanitize')) {
    function sanitize($str) {
        return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET: FETCH REVIEWS ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $company = isset($_GET['company']) ? sanitize($_GET['company']) : '';
    
    // Base SQL using a LEFT JOIN to get the author's name
    $sql = 'SELECT r.*, u.name AS author 
            FROM reviews r 
            LEFT JOIN users u ON r.user_id = u.id';
    
    $params = [];
    if (!empty($company)) { 
        $sql .= ' WHERE r.company LIKE ?'; 
        $params[] = "%$company%"; 
    }
    
    $sql .= ' ORDER BY r.created_at DESC';
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate Average Rating specifically for the searched company
        $avg = null;
        if (!empty($company)) {
            $a = $db->prepare('SELECT AVG(rating) FROM reviews WHERE company LIKE ?');
            $a->execute(["%$company%"]);
            $res = $a->fetchColumn();
            $avg = $res ? round((float)$res, 1) : null;
        }

        jsonResponse([
            'success' => true,
            'reviews' => $reviews, 
            'average_rating' => $avg, 
            'total_count' => count($reviews)
        ]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Failed to fetch reviews'], 500);
    }
}

// ── POST: CREATE REVIEW ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $user = requireLogin();
    $data = getBody(); // Assuming getBody() handles json_decode(file_get_contents('php://input'))

    $company = sanitize($data['company'] ?? '');
    $rating  = (int)($data['rating'] ?? 0);
    $review  = sanitize($data['review'] ?? '');

    if (empty($company) || $rating < 1 || $rating > 5 || empty($review)) {
        jsonResponse(['success' => false, 'error' => 'Valid company, rating (1-5), and review text are required.'], 400);
    }

    try {
        $stmt = $db->prepare('INSERT INTO reviews (user_id, company, rating, review, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$user['id'], $company, $rating, $review]);
        
        jsonResponse([
            'success' => true, 
            'message' => 'Review posted successfully!',
            'id' => (int)$db->lastInsertId()
        ]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ── DELETE: REMOVE REVIEW ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $user = requireLogin();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$id) {
        jsonResponse(['success' => false, 'error' => 'No review ID provided.'], 400);
    }

    try {
        $stmt = $db->prepare('SELECT user_id FROM reviews WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            jsonResponse(['success' => false, 'error' => 'Review not found.'], 404);
        }

        // Authorization: Only the Author or an Admin can delete
        $isAdmin = (isset($user['role']) && $user['role'] === 'admin');
        if ($r['user_id'] != $user['id'] && !$isAdmin) {
            jsonResponse(['success' => false, 'error' => 'You do not have permission to delete this review.'], 403);
        }

        $db->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Review deleted.']);
        
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Delete failed: ' . $e->getMessage()], 500);
    }
}

// 405 Method Not Allowed
jsonResponse(['success' => false, 'error' => "Method $method not allowed."], 405);