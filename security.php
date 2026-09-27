<?php

date_default_timezone_set('Africa/Nairobi');

function refreshSessionActivity(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!empty($_SESSION['user_logged_in'])) {
        $_SESSION['last_activity_at'] = time();
    }
}

function isSessionExpired(int $idleTimeout = 30 * 60): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['user_logged_in'])) {
        return true;
    }

    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? 0);
    return $lastActivity > 0 && (time() - $lastActivity) >= $idleTimeout;
}

function requireAdminPage() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $idleTimeout = 30 * 60;
    if (isSessionExpired($idleTimeout)) {
        destroySessionAndRedirect('login.php');
    }

    if (empty($_SESSION['user_logged_in']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
        header('Location: login.php');
        exit;
    }

    refreshSessionActivity();
}

function destroySessionAndRedirect(string $redirectTarget): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ' . $redirectTarget);
    exit;
}

function requireCashierPage(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $idleTimeout = 30 * 60;
    if (isSessionExpired($idleTimeout)) {
        destroySessionAndRedirect('cashier_login.php');
    }

    if (empty($_SESSION['user_logged_in']) || ($_SESSION['user_role'] ?? '') !== 'cashier') {
        header('Location: cashier_login.php');
        exit;
    }

    refreshSessionActivity();
}

function requireCashierOrAdminPage(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $idleTimeout = 30 * 60;
    $role = (string) ($_SESSION['user_role'] ?? '');
    $loginPage = $role === 'admin' ? 'login.php' : 'cashier_login.php';

    if (isSessionExpired($idleTimeout)) {
        destroySessionAndRedirect($loginPage);
    }

    if (empty($_SESSION['user_logged_in']) || !in_array($role, ['cashier', 'admin'], true)) {
        header('Location: ' . $loginPage);
        exit;
    }

    refreshSessionActivity();
}

function csrfToken() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function getLoginClientIp(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function getLoginThrottle(PDO $pdo, string $username): array {
    $stmt = $pdo->prepare('SELECT blocked_until FROM login_attempts WHERE ip_address = :ip_address AND username = :username');
    $stmt->execute(['ip_address' => getLoginClientIp(), 'username' => $username]);
    $blockedUntil = strtotime((string) $stmt->fetchColumn());
    return ['blocked' => $blockedUntil !== false && $blockedUntil > time(), 'retry_after' => max(0, $blockedUntil - time())];
}

function recordLoginFailure(PDO $pdo, string $username): void {
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (ip_address, username, failed_attempts, first_failed_at, last_failed_at, blocked_until)
         VALUES (:ip_address, :username, 1, NOW(), NOW(), NULL)
         ON DUPLICATE KEY UPDATE
           failed_attempts = IF(last_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, failed_attempts + 1),
           first_failed_at = IF(last_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), first_failed_at),
           last_failed_at = NOW(),
           blocked_until = IF(
                         failed_attempts >= 5,
             DATE_ADD(NOW(), INTERVAL 15 MINUTE),
             NULL
           )'
    );
    $stmt->execute(['ip_address' => getLoginClientIp(), 'username' => $username]);
}

function clearLoginFailures(PDO $pdo, string $username): void {
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip_address AND username = :username');
    $stmt->execute(['ip_address' => getLoginClientIp(), 'username' => $username]);
}

function establishAuthenticatedSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['last_activity_at'] = time();
}

function maybeRehashPassword(PDO $pdo, array $user, string $password): void {
    if (!password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        return;
    }

    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $user['id']]);
}
