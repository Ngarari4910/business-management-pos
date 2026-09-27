<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$cashierId = (int) ($_GET['cashier_id'] ?? 0);
if ($cashierId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid cashier.']);
    exit;
}

$limit = max(1, min((int) ($_GET['limit'] ?? 500), 500));
$stmt = $pdo->prepare(
    "SELECT
        cs.*,
        COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_status = 'paid'), 0) AS shift_sales_total,
        COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'cash' AND sp.status = 'success'), 0) AS shift_cash_sales_total,
        COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'equity'), 0) AS shift_equity_sales_total,
        COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_status = 'paid' AND payment_method = 'mixed'), 0) AS shift_mixed_sales_total,
        COALESCE((SELECT SUM(total_cost) FROM quick_stock_purchases WHERE shift_id = cs.id), 0) AS quick_purchase_total,
        COALESCE((SELECT SUM(be.cash_drawer_amount)
            FROM business_expenses be
            JOIN users expense_user ON expense_user.full_name = be.created_by
            WHERE expense_user.id = cs.cashier_id
              AND be.cash_drawer_movement = 1
              AND be.expense_date >= DATE(COALESCE(cs.started_at, cs.created_at))
              AND (cs.closed_at IS NULL OR be.expense_date <= DATE(cs.closed_at))), 0) AS expense_total
    FROM cashier_shifts cs
    WHERE cs.cashier_id = :cashier_id
    ORDER BY COALESCE(cs.started_at, cs.created_at) DESC
    LIMIT {$limit}"
);
$stmt->execute(['cashier_id' => $cashierId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$shifts = array_map(static function (array $shift): array {
    return [
        'id' => (int) $shift['id'],
        'started_at' => $shift['started_at'] ?? $shift['created_at'],
        'closed_at' => $shift['closed_at'],
        'status' => $shift['status'],
        'opening_cash' => (float) $shift['opening_cash'],
        'closing_balance' => (float) $shift['counted_cash'],
        'expected_cash' => (float) $shift['expected_cash'],
        'counted_cash' => (float) $shift['counted_cash'],
        'variance' => (float) $shift['variance'],
        'safe_drop_amount' => (float) $shift['safe_drop_amount'],
        'next_day_float' => (float) $shift['next_day_float'],
        'sales_total' => (float) ($shift['shift_sales_total'] ?? 0),
        'cash_sales_total' => (float) ($shift['shift_cash_sales_total'] ?? 0),
        'equity_sales_total' => (float) ($shift['shift_equity_sales_total'] ?? 0),
        'mixed_sales_total' => (float) ($shift['shift_mixed_sales_total'] ?? 0),
        'quick_purchase_total' => (float) ($shift['quick_purchase_total'] ?? 0),
        'expense_total' => (float) ($shift['expense_total'] ?? 0),
    ];
}, $rows);

echo json_encode(['shifts' => $shifts], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
