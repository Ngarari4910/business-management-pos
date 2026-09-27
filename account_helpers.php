<?php
function isUsernameTaken(PDO $pdo, string $username): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
    $stmt->execute(['username' => $username]);
    return intval($stmt->fetchColumn()) > 0;
}

function getPendingUserByToken(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE activation_token = :token AND status = :status');
    $stmt->execute(['token' => $token, 'status' => 'pending']);
    $user = $stmt->fetch();
    if ($user === false) {
        return null;
    }
    if (!empty($user['activation_token_expires_at']) && strtotime($user['activation_token_expires_at']) < time()) {
        return null;
    }
    return $user;
}

function activateCashierAccount(PDO $pdo, int $userId, string $username, string $passwordHash): bool {
    $stmt = $pdo->prepare(
        'UPDATE users SET username = :username, password_hash = :password_hash, status = :status, activation_token = NULL, activation_token_expires_at = NULL, activated_at = NOW(), updated_at = NOW() WHERE id = :id AND status = :pending'
    );
    return $stmt->execute([
        'username' => $username,
        'password_hash' => $passwordHash,
        'status' => 'active',
        'id' => $userId,
        'pending' => 'pending',
    ]);
}

function getUserByUsername(PDO $pdo, string $username): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
    return $user === false ? null : $user;
}
