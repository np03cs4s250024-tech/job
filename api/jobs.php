<?php
/**
 * Prevent PHP warnings from breaking JSON output.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    // 1. Ajax Autocomplete
    if ($action === 'autocomplete') {
        $q = sanitize($_GET['q'] ?? '');
        $stmt = $db->prepare("SELECT DISTINCT title FROM jobs WHERE status='active' AND title LIKE ? ORDER BY title LIMIT 8");
        $stmt->execute(["%$q%"]);
        $titles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        jsonResponse(['success' => true, 'data' => $titles]);
    }

    // 2. Advanced Search
    if ($action === 'search') {
        $keyword  = sanitize($_GET['keyword']  ?? '');
        $category = sanitize($_GET['category'] ?? '');
        $location = sanitize($_GET['location'] ?? '');
        
        $sql = "SELECT j.*, u.name AS employer_name 
                FROM jobs j 
                LEFT JOIN users u ON j.employer_id = u.id 
                WHERE j.status = 'active'";
        $params = [];

        if ($keyword !== '') {
            $sql .= " AND (j.title LIKE ? OR j.description LIKE ? OR j.company LIKE ? OR u.name LIKE ?)";
            $kw = "%$keyword%";
            $params = array_merge($params, [$kw, $kw, $kw, $kw]);
        }
        if ($category !== '' && $category !== 'All') {
            $sql .= " AND j.category = ?";
            $params[] = $category;
        }
        if ($location !== '') {
            $sql .= " AND j.location LIKE ?";
            $params[] = "%$location%";
        }

        $sql .= " ORDER BY j.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(['success' => true, 'count' => count($jobs), 'data' => $jobs]);
    }

    // 3. Single Job Details
    if (isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT j.*, u.name AS employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id WHERE j.id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) jsonResponse(['success' => false, 'error' => 'Job not found.'], 404);
        jsonResponse(['success' => true, 'data' => $job]);
    }

    // 4. Employer's Dashboard View
    if (isset($_GET['mine'])) {
        $user = requireRole('employer');
        $stmt = $db->prepare("SELECT j.*, COUNT(a.id) AS application_count 
                              FROM jobs j 
                              LEFT JOIN applications a ON j.id = a.job_id 
                              WHERE j.employer_id = ? 
                              GROUP BY j.id 
                              ORDER BY j.created_at DESC");
        $stmt->execute([$user['id']]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // 5. Admin Panel View
    if (isset($_GET['admin_view'])) {
        requireRole('admin');
        $stmt = $db->query("SELECT j.*, u.name AS employer_name, COUNT(a.id) AS application_count 
                            FROM jobs j 
                            LEFT JOIN users u ON j.employer_id = u.id 
                            LEFT JOIN applications a ON j.id = a.job_id 
                            GROUP BY j.id 
                            ORDER BY j.created_at DESC");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // 6. Default: All Active Jobs
    $stmt = $db->query("SELECT j.*, u.name AS employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id WHERE j.status = 'active' ORDER BY j.created_at DESC LIMIT 20");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST: Create Job ──
if ($method === 'POST') {
    $user = requireRole('employer');
    $data = getBody();
    
    $fields = ['title', 'company', 'salary', 'category', 'location', 'description'];
    $clean = [];
    foreach ($fields as $f) {
        $clean[$f] = sanitize($data[$f] ?? '');
    }

    if (!$clean['title'] || !$clean['company'] || !$clean['location'] || !$clean['description']) {
        jsonResponse(['success' => false, 'error' => 'Required fields missing.'], 400);
    }

    $stmt = $db->prepare("INSERT INTO jobs (title, company, salary, category, location, description, employer_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
    $success = $stmt->execute([$clean['title'], $clean['company'], $clean['salary'], $clean['category'], $clean['location'], $clean['description'], $user['id']]);
    
    jsonResponse(['success' => $success, 'id' => (int)$db->lastInsertId()]);
}

// ── PUT: Update Job ──
if ($method === 'PUT' && isset($_GET['id'])) {
    $user = requireLogin();
    $jobId = (int)$_GET['id'];
    $data = getBody();
    
    $stmt = $db->prepare('SELECT employer_id FROM jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    if (!$job) jsonResponse(['success' => false, 'error' => 'Job not found.'], 404);
    if ($user['role'] !== 'admin' && $job['employer_id'] != $user['id']) {
        jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    $status = in_array($data['status'] ?? '', ['active', 'closed']) ? $data['status'] : 'active';
    
    $stmt = $db->prepare("UPDATE jobs SET title=?, company=?, salary=?, category=?, location=?, description=?, status=? WHERE id=?");
    $stmt->execute([
        sanitize($data['title'] ?? ''), sanitize($data['company'] ?? ''), sanitize($data['salary'] ?? ''),
        sanitize($data['category'] ?? ''), sanitize($data['location'] ?? ''), sanitize($data['description'] ?? ''), 
        $status, $jobId
    ]);
    
    jsonResponse(['success' => true, 'message' => 'Job updated.']);
}

// ── DELETE: Remove Job ──
if ($method === 'DELETE' && isset($_GET['id'])) {
    $user = requireLogin();
    $jobId = (int)$_GET['id'];
    
    $stmt = $db->prepare('SELECT employer_id FROM jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    if (!$job) jsonResponse(['success' => false, 'error' => 'Job not found.'], 404);
    if ($user['role'] !== 'admin' && $job['employer_id'] != $user['id']) {
        jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    // Cascade delete related records
    $db->prepare('DELETE FROM applications WHERE job_id = ?')->execute([$jobId]);
    $db->prepare('DELETE FROM saved_jobs WHERE job_id = ?')->execute([$jobId]);
    $db->prepare('DELETE FROM jobs WHERE id = ?')->execute([$jobId]);
    
    jsonResponse(['success' => true, 'message' => 'Job deleted successfully.']);
}

jsonResponse(['success' => false, 'error' => 'Invalid request.'], 400);