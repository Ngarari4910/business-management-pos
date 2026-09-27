<?php
session_start();
require __DIR__ . '/security.php';
requireCashierPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';
require __DIR__ . '/receipt.php';

$cashierName = trim((string) ($_POST['cashier_name'] ?? 'Cashier'));
$expectedCash = floatval(str_replace([',', '$'], '', trim((string) ($_POST['expected_cash'] ?? '0'))));
$countedCash = floatval(str_replace([',', '$'], '', trim((string) ($_POST['counted_cash'] ?? '0'))));
$varianceReason = trim((string) ($_POST['variance_reason'] ?? ''));
$safeDropAmount = floatval(str_replace([',', '$'], '', trim((string) ($_POST['safe_drop_amount'] ?? '0'))));
$nextDayFloat = floatval(str_replace([',', '$'], '', trim((string) ($_POST['next_day_float'] ?? '0'))));

$variance = calculateShiftVariance($expectedCash, $countedCash);
$status = getShiftStatusLabel($expectedCash, $countedCash);
$priority = getShiftReviewPriority($expectedCash, $countedCash);

$stmt = $pdo->prepare(
    'INSERT INTO cashier_shifts (cashier_name, expected_cash, counted_cash, variance, variance_reason, status, priority, safe_drop_amount, next_day_float) VALUES (:cashier_name, :expected_cash, :counted_cash, :variance, :variance_reason, :status, :priority, :safe_drop_amount, :next_day_float)'
);
$stmt->execute([
    'cashier_name' => $cashierName !== '' ? $cashierName : 'Cashier',
    'expected_cash' => $expectedCash,
    'counted_cash' => $countedCash,
    'variance' => $variance,
    'variance_reason' => $varianceReason,
    'status' => $status,
    'priority' => $priority,
    'safe_drop_amount' => $safeDropAmount,
    'next_day_float' => $nextDayFloat,
]);

$_SESSION['shift_message'] = $variance === 0.0
    ? 'Shift closed successfully.'
    : 'Shift closed with variance and sent for review.';

header('Location: admin.php');
exit;
