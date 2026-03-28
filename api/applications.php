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

/**
 * ── POST: Apply for a Job OR Update Status ──
 */
if ($method === 'POST') {
    $data = getBody();
    $action = $_GET['action'] ?? '';

    // ACTION: Apply for a job (Job Seeker)
    if ($action === 'apply' || empty($action)) {
        $user  = requireRole('seeker');
        $jobId = (int)($data['job_id'] ?? 0);
        $note  = isset($data['resume_note']) ? sanitize($data['resume_note']) : '';
        $resumeId = (int)($data['resume_id'] ?? 0); 

        if (!$jobId) jsonResponse(['success' => false, 'error' => 'Job ID required.'], 400);

        // 1. Check if job exists and is active
        $stmt = $db->prepare("SELECT id, employer_id, title FROM jobs WHERE id = ? AND status = 'active'");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) jsonResponse(['success' => false, 'error' => 'This job is no longer accepting applications.'], 404);

        // 2. Prevent double application
        $stmt = $db->prepare('SELECT id FROM applications WHERE job_id = ? AND seeker_id = ?');
        $stmt->execute([$jobId, $user['id']]);
        if ($stmt->fetch()) jsonResponse(['success' => false, 'error' => 'You have already applied for this position.'], 409);

        // 3. Insert Application
        $sql = "INSERT INTO applications (job_id, seeker_id, resume_id, resume_note, status) VALUES (?, ?, ?, ?, 'pending')";
        $db->prepare($sql)->execute([$jobId, $user['id'], $resumeId, $note]);

        // 4. Notify Employer
        $notifMsg = "New applicant for '{$job['title']}' from " . $user['name'];
        $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")
           ->execute([$job['employer_id'], $notifMsg]);

        jsonResponse(['success' => true, 'message' => 'Application submitted successfully!']);
    }

    // ACTION: Update Status (Employer/Admin)
    if ($action === 'update-status') {
        $user   = requireLogin();
        $appId  = (int)($data['id'] ?? 0);
        $status = strtolower($data['status'] ?? '');

        if (!in_array($status, ['pending', 'accepted', 'rejected'])) {
            jsonResponse(['success' => false, 'error' => 'Invalid status provided.'], 400);
        }

        $stmt = $db->prepare("SELECT a.seeker_id, j.title, j.employer_id FROM applications a JOIN jobs j ON a.job_id = j.id WHERE a.id = ?");
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app) jsonResponse(['success' => false, 'error' => 'Application record not found.'], 404);
        
        if ($user['role'] !== 'admin' && $app['employer_id'] != $user['id']) {
            jsonResponse(['success' => false, 'error' => 'Unauthorized action.'], 403);
        }

        $db->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute([$status, $appId]);

        $statusLabel = ($status === 'accepted') ? "shortlisted/accepted" : "rejected";
        $msg = "Update: Your application for '{$app['title']}' has been {$statusLabel}.";
        
        $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")
           ->execute([$app['seeker_id'], $msg]);

        jsonResponse(['success' => true, 'message' => "Application marked as " . ucfirst($status)]);
    }
}

/**
 * ── GET: List Applications ──
 */
if ($method === 'GET') {
    $user = requireLogin();

    // 1. Employer View
    if ($user['role'] === 'employer') {
        $sql = "SELECT a.*, u.name AS seeker_name, u.email AS seeker_email, 
                       r.filepath AS resume_path, j.title AS job_title 
                FROM applications a 
                JOIN users u ON a.seeker_id = u.id 
                JOIN jobs j ON a.job_id = j.id 
                LEFT JOIN resumes r ON a.resume_id = r.id
                WHERE j.employer_id = ? 
                ORDER BY a.applied_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user['id']]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // 2. Seeker View
    if ($user['role'] === 'seeker') {
        $stmt = $db->prepare("SELECT a.*, j.title AS job_title, j.company, j.location 
                              FROM applications a 
                              JOIN jobs j ON a.job_id = j.id 
                              WHERE a.seeker_id = ? 
                              ORDER BY a.applied_at DESC");
        $stmt->execute([$user['id']]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // 3. Admin View
    if ($user['role'] === 'admin') {
        $stmt = $db->query("SELECT a.*, u.name AS seeker_name, j.title AS job_title, e.name AS employer_name 
                            FROM applications a 
                            JOIN users u ON a.seeker_id = u.id 
                            JOIN jobs j ON a.job_id = j.id 
                            JOIN users e ON j.employer_id = e.id 
                            ORDER BY a.applied_at DESC");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
}

jsonResponse(['success' => false, 'error' => 'Forbidden method or invalid request.'], 405);