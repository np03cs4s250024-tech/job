<?php
/**
 * Resume Controller
 * Handles uploading, listing, and deleting PDF/Doc resumes for Job Seekers.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';
require_once '../config/upload.php'; 

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Only seekers are allowed to manage resumes
$user   = requireRole('seeker');

/**
 * ── GET: Fetch Saved Resumes ──
 */
if ($method === 'GET') {
    $stmt = $db->prepare('SELECT id, filename, filepath, uploaded_at 
                          FROM resumes 
                          WHERE user_id = ? 
                          ORDER BY uploaded_at DESC');
    $stmt->execute([$user['id']]);
    $resumes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse(['success' => true, 'data' => $resumes]);
}

/**
 * ── POST: Upload Resume ──
 */
if ($method === 'POST') {
    if (!isset($_FILES['resume'])) {
        jsonResponse(['success' => false, 'error' => 'No file selected for upload.'], 400);
    }

    $file = $_FILES['resume'];
    
    // 1. Validate File (Size/Extension) using config/upload.php helpers
    $validationResult = validateUpload($file);
    if ($validationResult !== true) {
        jsonResponse(['success' => false, 'error' => $validationResult], 400);
    }

    // 2. Strict MIME-type verification (Server-side)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    // Check against allowed mimes defined in config/upload.php
    if (!defined('ALLOWED_MIMES') || !in_array($mime, ALLOWED_MIMES)) {
        jsonResponse(['success' => false, 'error' => 'Invalid file content. Only PDF and Word docs are allowed.'], 400);
    }

    // 3. Prepare Directory
    $subDir = 'resumes/';
    $targetDir = UPLOAD_DIR . $subDir;
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

    // 4. Secure Naming (Prevents overwriting and hides original user filenames)
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $newFilename = 'res_' . $user['id'] . '_' . time() . '.' . $ext;
    $savePath = $targetDir . $newFilename;
    $dbPath = 'uploads/' . $subDir . $newFilename;

    // 5. Move and Record
    if (move_uploaded_file($file['tmp_name'], $savePath)) {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare('INSERT INTO resumes (user_id, filename, filepath) VALUES (?, ?, ?)');
            $stmt->execute([$user['id'], htmlspecialchars($file['name']), $dbPath]);
            $newId = $db->lastInsertId();

            $db->commit();

            jsonResponse([
                'success' => true, 
                'message' => 'Resume uploaded successfully.',
                'data' => [
                    'id' => $newId,
                    'filename' => $file['name'],
                    'filepath' => $dbPath,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ]
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            // Critical: If DB fails, delete the file we just uploaded
            if (file_exists($savePath)) unlink($savePath); 
            jsonResponse(['success' => false, 'error' => 'Database error during upload.'], 500);
        }
    } else {
        jsonResponse(['success' => false, 'error' => 'Failed to write file to disk.'], 500);
    }
}

/**
 * ── DELETE: Remove Resume ──
 */
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    
    // Authorization: Ensure the resume belongs to the logged-in seeker
    $stmt = $db->prepare('SELECT filepath FROM resumes WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    $resume = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resume) {
        jsonResponse(['success' => false, 'error' => 'Resume not found or access denied.'], 404);
    }

    // Delete physical file
    $fullPath = __DIR__ . '/../' . $resume['filepath'];
    if (file_exists($fullPath)) unlink($fullPath);

    // Delete database record
    $db->prepare('DELETE FROM resumes WHERE id = ?')->execute([$id]);
    
    jsonResponse(['success' => true, 'message' => 'Resume deleted successfully.']);
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);