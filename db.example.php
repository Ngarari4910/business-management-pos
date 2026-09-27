<?php
date_default_timezone_set('Africa/Nairobi');

$host = getenv('POS_DB_HOST') ?: '127.0.0.1';
$db = getenv('POS_DB_NAME') ?: 'pos2_demo';
$user = getenv('POS_DB_USER') ?: 'pos2_demo';
$pass = getenv('POS_DB_PASSWORD') ?: 'change-me';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    die('Database connection failed. Check your local POS demo configuration.');
}
