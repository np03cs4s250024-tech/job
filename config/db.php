<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'jstack_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database connection failed. Check XAMPP MySQL.']);
            exit;
        }
    }
    return $pdo;
}

/**
 * Global helper to send JSON responses easily
 */
if (!function_exists('jsonResponse')) {
    function jsonResponse($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

/**
 * Sanitize user input to prevent XSS
 */
if (!function_exists('sanitize')) {
    function sanitize($data) {
        if ($data === null) return '';
        if (is_array($data)) {
            return array_map('sanitize', $data);
        }
        return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Get the JSON body sent from JavaScript
 */
if (!function_exists('getBody')) {
    function getBody() {
        $json = file_get_contents('php://input');
        return json_decode($json, true) ?? [];
    }
}