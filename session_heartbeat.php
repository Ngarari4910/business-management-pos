<?php
require_once __DIR__ . '/security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['user_logged_in']) || !in_array($_SESSION['user_role'] ?? '', ['cashier', 'admin'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

if (isSessionExpired()) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired.']);
    exit;
}

refreshSessionActivity();

echo json_encode([
    'ok' => true,
    'last_activity_at' => (int) ($_SESSION['last_activity_at'] ?? time()),
    'role' => $_SESSION['user_role'] ?? null,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
