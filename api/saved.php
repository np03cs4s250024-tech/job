<?php
/**
 * Saved Jobs Controller
 * Allows job seekers to bookmark jobs for later viewing.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Only job seekers should be able to save/unsave jobs
$user   = requireRole('seeker');

/**
 * ── GET: Fetch Saved Jobs ──
 */
if ($method === 'GET') {
    $stmt = $db->prepare("SELECT j.*, s.saved_at 
                          FROM saved_jobs s 
                          JOIN jobs j ON s.job_id = j.id 
                          WHERE s.user_id = ? 
                          ORDER BY s.saved_at DESC");
    $stmt->execute([$user['id']]);
    $savedJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse(['success' => true, 'data' => $savedJobs]);
}

/**
 * ── POST: Bookmark a Job ──
 */
if ($method === 'POST') {
    $data  = getBody(); 
    $jobId = (int)($data['job_id'] ?? 0);
    
    if (!$jobId) {
        jsonResponse(['success' => false, 'error' => 'Job ID required.'], 400);
    }

    // Prevent duplicate saves
    $chk = $db->prepare('SELECT id FROM saved_jobs WHERE user_id = ? AND job_id = ?');
    $chk->execute([$user['id'], $jobId]);
    
    if ($chk->fetch()) {
        jsonResponse(['success' => false, 'error' => 'Job is already in your saved list.'], 409);
    }

    $db->prepare('INSERT INTO saved_jobs (user_id, job_id) VALUES (?, ?)')
       ->execute([$user['id'], $jobId]);

    jsonResponse(['success' => true, 'message' => 'Job saved successfully.']);
}

/**
 * ── DELETE: Remove a Saved Job ──
 */
if ($method === 'DELETE') {
    // Expecting ?job_id=X in the URL
    $jobId = (int)($_GET['job_id'] ?? 0);
    
    if (!$jobId) {
        jsonResponse(['success' => false, 'error' => 'Job ID required.'], 400);
    }

    $stmt = $db->prepare('DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?');
    $stmt->execute([$user['id'], $jobId]);

    if ($stmt->rowCount()) {
        jsonResponse(['success' => true, 'message' => 'Job removed from saved list.']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Job not found in your saved list.'], 404);
    }
}

jsonResponse(['success' => false, 'error' => 'Invalid request method.'], 405);