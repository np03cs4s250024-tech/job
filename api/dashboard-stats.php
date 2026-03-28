<?php
/**
 * Dashboard & Statistics API
 * Returns role-specific data for Admin, Employer, and Seeker views.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['error' => 'GET only.'], 405);

$user = requireLogin();
$db   = getDB();

/**
 * ── ADMIN DASHBOARD ──
 * Comprehensive overview of the entire system.
 */
if ($user['role'] === 'admin') {
    // 1. Fetch General System Stats using subqueries
    $row = $db->query("SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE role='employer') AS total_employers,
        (SELECT COUNT(*) FROM users WHERE role='seeker') AS total_seekers,
        (SELECT COUNT(*) FROM jobs) AS total_jobs,
        (SELECT COUNT(*) FROM jobs WHERE status='active') AS active_jobs,
        (SELECT COUNT(*) FROM applications) AS total_applications,
        (SELECT COUNT(*) FROM applications WHERE status='accepted') AS accepted_applications,
        (SELECT COUNT(*) FROM applications WHERE status='pending') AS pending_applications,
        (SELECT COUNT(*) FROM applications WHERE status='rejected') AS rejected_applications,
        (SELECT COUNT(*) FROM reviews) AS total_reviews
    ")->fetch(PDO::FETCH_ASSOC);

    // 2. Fetch Average Ratings per Company for Visualization (Chart.js)
    $companyStats = $db->query("SELECT 
            company, 
            ROUND(AVG(rating), 1) as avg_rating, 
            COUNT(*) as review_count 
        FROM reviews 
        GROUP BY company 
        ORDER BY avg_rating DESC 
        LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    // 3. Activity Feeds
    $recentJobs  = $db->query("SELECT j.id, j.title, j.company, j.status, j.created_at, u.name AS employer_name 
                               FROM jobs j 
                               LEFT JOIN users u ON j.employer_id = u.id 
                               ORDER BY j.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    $recentUsers = $db->query("SELECT id, name, email, role, created_at 
                               FROM users 
                               ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse([
        'success' => true, 
        'stats' => array_map('intval', $row), 
        'company_stats' => $companyStats,
        'recent_jobs' => $recentJobs, 
        'recent_users' => $recentUsers
    ]);
}

/**
 * ── EMPLOYER DASHBOARD ──
 * Stats specific to the employer's listings and applications.
 */
if ($user['role'] === 'employer') {
    $stmt = $db->prepare("SELECT 
            COUNT(DISTINCT j.id) AS total_jobs, 
            COUNT(DISTINCT a.id) AS total_applications, 
            SUM(CASE WHEN a.status='accepted' THEN 1 ELSE 0 END) AS accepted, 
            SUM(CASE WHEN a.status='pending' THEN 1 ELSE 0 END) AS pending, 
            SUM(CASE WHEN a.status='rejected' THEN 1 ELSE 0 END) AS rejected 
        FROM jobs j 
        LEFT JOIN applications a ON j.id = a.job_id 
        WHERE j.employer_id = ?");
    $stmt->execute([$user['id']]);
    
    jsonResponse(['success' => true, 'stats' => array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC))]);
}

/**
 * ── SEEKER DASHBOARD ──
 * Overview of job applications and saved items.
 */
if ($user['role'] === 'seeker') {
    $stmt = $db->prepare("SELECT 
            COUNT(*) AS total_applications, 
            SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END) AS accepted, 
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending, 
            SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected 
        FROM applications 
        WHERE seeker_id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch saved jobs count
    $saved = $db->prepare('SELECT COUNT(*) FROM saved_jobs WHERE user_id = ?');
    $saved->execute([$user['id']]);
    $row['saved_jobs'] = (int)$saved->fetchColumn();

    jsonResponse(['success' => true, 'stats' => array_map('intval', $row)]);
}

// Fallback for unrecognized roles
jsonResponse(['success' => false, 'error' => 'Role not recognized or unauthorized.'], 403);