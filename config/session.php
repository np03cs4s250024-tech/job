<?php
if (session_status() === PHP_SESSION_NONE) {
    // Session security settings
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => false, // Set to true if using HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// ── Auth Helpers ─────────────────────────────────────────────────────────────

function isLoggedIn(): bool { 
    return isset($_SESSION['user']); 
}

function currentUser(): ?array { 
    return $_SESSION['user'] ?? null; 
}

function requireLogin(): array {
    if (!isLoggedIn()) {
        jsonResponse(['error' => 'Unauthorized. Please login.'], 401);
    }
    return currentUser();
}

function requireRole(string $role): array {
    $user = requireLogin();
    if (($user['role'] ?? '') !== $role) {
        jsonResponse(['error' => 'Forbidden. Insufficient permissions.'], 403);
    }
    return $user;
}

// ── Shared Utility Helpers (Wrapped to prevent redeclaration) ────────────────

if (!function_exists('jsonResponse')) {
    function jsonResponse($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
}

if (!function_exists('getBody')) {
    function getBody(): array {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
}

if (!function_exists('sanitize')) {
    function sanitize($value): string {
        if ($value === null) return '';
        return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES, 'UTF-8');
    }
}

// ── Security Helpers ─────────────────────────────────────────────────────────

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (!$token) return false;
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}