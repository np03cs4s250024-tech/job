<?php
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx']);
define('ALLOWED_MIMES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
]);

function validateUpload(array $file): bool|string {
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload error code: ' . $file['error'];
    if ($file['size'] > MAX_FILE_SIZE) return 'File too large. Max 5MB allowed.';
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) return 'Only PDF, DOC, DOCX allowed.';
    
    return true;
}