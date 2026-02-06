<?php
// Lightweight OpCache reset (protected): requires token and localhost by default
$token = getenv('OPCACHE_RESET_TOKEN') ?: 'devtoken';
$remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// SIMPLE MODE: Accept requests from any remote IP as long as token matches.
// Be careful: keep this for local dev only. In production, re-enable IP checks.
if (!isset($_GET['token']) || $_GET['token'] !== $token) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden: invalid token', 'remote' => $remote]);
    exit;
}
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo json_encode(['success' => true, 'message' => 'opcache_reset executed']);
} else {
    echo json_encode(['success' => false, 'error' => 'opcache_reset not available']);
}
