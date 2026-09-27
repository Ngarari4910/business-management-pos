<?php
require_once __DIR__ . '/security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_logged_in']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'cashier'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

if (isSessionExpired()) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['error' => 'Session expired.']);
    exit;
}

refreshSessionActivity();

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid form token']);
    exit;
}

require_once __DIR__ . '/db.php';


$name = trim($_POST['name'] ?? '');
$kra = trim($_POST['kra'] ?? '');
$phone = trim($_POST['phone'] ?? '');

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Supplier name is required']);
    exit;
}

try {
    $stmt = $pdo->prepare('INSERT INTO suppliers (name, kra_pin, phone) VALUES (:name, :kra, :phone)');
    $stmt->execute(['name' => $name, 'kra' => $kra ?: null, 'phone' => $phone ?: null]);
    $id = $pdo->lastInsertId();
    echo json_encode(['id' => intval($id), 'name' => $name]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

exit;
