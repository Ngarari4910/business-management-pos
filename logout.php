<?php
session_start();
require __DIR__ . '/security.php';
$redirectTarget = 'login.php';
$returnTarget = (string) ($_GET['return'] ?? '');
if ($returnTarget === 'cashier' || (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'cashier')) {
    $redirectTarget = 'cashier_login.php';
}

destroySessionAndRedirect($redirectTarget);
