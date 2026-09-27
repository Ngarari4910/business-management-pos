<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$today = date('Y-m-d');
$reportView = ($_GET['view'] ?? '') === 'month' ? 'month' : 'day';
$reportMonth = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth) || strtotime($reportMonth . '-01') === false || $reportMonth > date('Y-m')) {
    $reportMonth = date('Y-m');
}
$reportDate = trim((string) ($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) || strtotime($reportDate) === false || $reportDate > $today) {
    $reportDate = $today;
}
$reportStart = $reportView === 'month' ? $reportMonth . '-01' : $reportDate;
$reportEnd = $reportView === 'month' ? date('Y-m-t', strtotime($reportStart)) : $reportDate;
$reportStartSql = $pdo->quote($reportStart);
$reportEndSql = $pdo->quote($reportEnd);
$errors = [];
$fetch = static function (PDO $pdo, string $query) use (&$errors): array {
    try {
        return $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Daily operations report query error: ' . $e->getMessage());
        $errors[] = 'One section could not be loaded.';
        return [];
    }
};

$sales = $fetch($pdo, "SELECT CASE WHEN s.payment_method = 'mixed' THEN 'Cash + Equity' ELSE s.payment_method END AS payment_method, COUNT(DISTINCT s.id) AS transactions, COALESCE(SUM(s.total_amount), 0) AS total_amount,
        COALESCE(SUM(COALESCE(sale_cost.cost_basis, 0)), 0) AS cost_basis,
        COALESCE(SUM(s.total_amount - COALESCE(sale_cost.cost_basis, 0)), 0) AS gross_profit
    FROM sales s
    LEFT JOIN (
        SELECT si.sale_id,
            SUM(CASE
                WHEN si.cost_total IS NULL OR si.cost_total = 0
                THEN si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)
                ELSE si.cost_total
            END) AS cost_basis
        FROM sale_items si
        LEFT JOIN (
            SELECT product_id, SUM(total_cost) / NULLIF(SUM(total_quantity), 0) AS avg_cost_per_unit
            FROM (
                SELECT product_id, total_cost, base_quantity AS total_quantity FROM stock_intake_lines
                UNION ALL
                SELECT product_id, total_cost, quantity AS total_quantity FROM quick_stock_purchase_lines
            ) combined
            GROUP BY product_id
        ) cost ON cost.product_id = si.product_id
        GROUP BY si.sale_id
    ) sale_cost ON sale_cost.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN {$reportStartSql} AND {$reportEndSql} AND s.payment_status = 'paid'
    GROUP BY s.payment_method ORDER BY s.payment_method");
$salesByType = $fetch($pdo, "SELECT
        CASE
            WHEN si.sale_mode = 'bale' OR si.quantity >= 6 THEN 'Wholesale'
            ELSE 'Retail'
        END AS sale_type,
        COUNT(DISTINCT s.id) AS transactions,
        COALESCE(SUM(COALESCE(si.line_total, si.quantity * si.unit_price)), 0) AS sales_revenue,
        COALESCE(SUM(CASE
            WHEN si.cost_total IS NULL OR si.cost_total = 0
            THEN si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)
            ELSE si.cost_total
        END), 0) AS cost_basis,
        COALESCE(SUM(COALESCE(si.line_total, si.quantity * si.unit_price) - CASE
            WHEN si.cost_total IS NULL OR si.cost_total = 0
            THEN si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)
            ELSE si.cost_total
        END), 0) AS gross_profit
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    LEFT JOIN (
        SELECT product_id, SUM(total_cost) / NULLIF(SUM(total_quantity), 0) AS avg_cost_per_unit
        FROM (
            SELECT product_id, total_cost, base_quantity AS total_quantity FROM stock_intake_lines
            UNION ALL
            SELECT product_id, total_cost, quantity AS total_quantity FROM quick_stock_purchase_lines
        ) combined
        GROUP BY product_id
    ) cost ON cost.product_id = si.product_id
    WHERE DATE(s.created_at) BETWEEN {$reportStartSql} AND {$reportEndSql}
      AND s.payment_status = 'paid'
    GROUP BY sale_type
    ORDER BY CASE WHEN sale_type = 'Retail' THEN 1 ELSE 2 END");
$creditSales = $fetch($pdo, "SELECT
        s.id AS sale_id,
        COALESCE(sp.receipt_number, CONCAT('RCP-', DATE_FORMAT(s.created_at, '%Y%m%d%H%i%s'), '-', s.id)) AS receipt_number,
        COALESCE(u.full_name, s.cashier_id, 'Unassigned') AS cashier_name,
        COALESCE(c.name, 'Unassigned') AS customer_name,
        COALESCE(c.phone, s.customer_phone, 'No phone') AS customer_phone,
        COALESCE(si.product_name, 'Unspecified product') AS product_name,
        COALESCE(si.quantity_in_packets, si.quantity) AS quantity,
        COALESCE(si.line_total, si.quantity * si.unit_price, s.total_amount) AS amount,
        s.created_at
    FROM sales s
    LEFT JOIN users u ON u.id = s.cashier_id
    LEFT JOIN customer_credit_invoices i ON i.sale_id = s.id
    LEFT JOIN customers c ON c.id = i.customer_id
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN sale_payments sp ON sp.sale_id = s.id AND sp.status = 'success'
    WHERE DATE(s.created_at) BETWEEN {$reportStartSql} AND {$reportEndSql} AND s.payment_status = 'credit'
    ORDER BY s.created_at DESC, si.id ASC");
$debtPayments = $fetch($pdo, "SELECT
        s.id AS sale_id,
        COALESCE(sp.receipt_number, CONCAT('RCP-', DATE_FORMAT(s.created_at, '%Y%m%d%H%i%s'), '-', s.id)) AS reference,
        COALESCE(u.full_name, s.cashier_id, 'Unassigned') AS cashier_name,
        COALESCE((
            SELECT GROUP_CONCAT(DISTINCT customer.name ORDER BY customer.name SEPARATOR ', ')
            FROM customer_credit_invoices invoice
            JOIN customers customer ON customer.id = invoice.customer_id
            WHERE s.notes LIKE CONCAT('%', invoice.invoice_number, '%')
        ), 'Unassigned') AS customer_name,
        COALESCE(s.customer_phone, 'No phone') AS customer_phone,
        s.total_amount AS amount,
        s.created_at,
        s.notes
    FROM sales s
    LEFT JOIN users u ON u.id = s.cashier_id
    LEFT JOIN sale_payments sp ON sp.sale_id = s.id AND sp.status = 'success'
    WHERE DATE(s.created_at) BETWEEN {$reportStartSql} AND {$reportEndSql}
      AND s.payment_status = 'paid'
      AND s.payment_method = 'cash'
      AND s.notes LIKE 'Payment for invoice(s):%'
    ORDER BY s.created_at DESC");
$expenses = $fetch($pdo, "SELECT title, category, amount, payment_method, cash_drawer_amount, expense_date
    FROM business_expenses     WHERE expense_date BETWEEN {$reportStartSql} AND {$reportEndSql} ORDER BY created_at DESC");
$quickPurchases = $fetch($pdo, "SELECT
        q.purchase_no,
        COALESCE(s.name, 'Unassigned') AS supplier_name,
        q.total_cost,
        q.total_cost AS purchase_total,
        (qpl.quantity * qpl.cost_price) AS purchase_cost,
        q.payment_method,
        q.purchase_date,
        qpl.product_id,
        COALESCE(p.name, 'Unspecified product') AS product_name,
        qpl.quantity AS purchased_quantity,
        COALESCE(sold.sold_quantity, 0) AS sold_quantity,
        COALESCE(sold.sales_revenue, 0) AS sales_revenue,
        COALESCE(sold.sales_revenue, 0) - (COALESCE(sold.sold_quantity, 0) * qpl.cost_price) AS product_profit
    FROM quick_stock_purchases q
    JOIN quick_stock_purchase_lines qpl ON qpl.quick_purchase_id = q.id
    LEFT JOIN products p ON p.id = qpl.product_id
    LEFT JOIN suppliers s ON s.id = q.supplier_id
    LEFT JOIN (
        SELECT si.product_id,
               SUM(si.quantity_in_packets) AS sold_quantity,
               SUM(COALESCE(si.line_total, si.quantity * si.unit_price)) AS sales_revenue
        FROM sale_items si
        JOIN sales sale ON sale.id = si.sale_id
        WHERE DATE(sale.created_at) BETWEEN {$reportStartSql} AND {$reportEndSql}
          AND sale.payment_status = 'paid'
        GROUP BY si.product_id
    ) sold ON sold.product_id = qpl.product_id
    WHERE q.purchase_date BETWEEN {$reportStartSql} AND {$reportEndSql}
    ORDER BY q.created_at DESC, qpl.id ASC");
$stockPurchases = $fetch($pdo, "SELECT
        si.receipt_number,
        COALESCE(s.name, 'Unassigned') AS supplier_name,
        COALESCE(s.phone, 'No phone') AS supplier_phone,
        si.amount_paid,
        si.payment_method,
        si.total_cost,
        si.receipt_date
    FROM stock_intakes si
    LEFT JOIN suppliers s ON s.id = si.supplier_id
    WHERE si.receipt_date BETWEEN {$reportStartSql} AND {$reportEndSql}
    ORDER BY si.created_at DESC");
$expiredStock = $fetch($pdo, "SELECT
        p.brand,
        p.name AS product_name,
        sb.expiry_date,
        sb.base_quantity_remaining AS quantity,
        (sb.base_quantity_remaining * sb.cost_per_package / COALESCE(NULLIF(sb.package_size_value, 0), 1)) AS cost_loss,
        (sb.quantity_remaining * sb.retail_price) AS potential_sales,
        ((sb.quantity_remaining * sb.retail_price) - (sb.base_quantity_remaining * sb.cost_per_package / COALESCE(NULLIF(sb.package_size_value, 0), 1))) AS potential_profit
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    WHERE sb.expiry_date BETWEEN {$reportStartSql} AND {$reportEndSql} AND sb.base_quantity_remaining > 0
    ORDER BY p.brand, p.name");
$shifts = $fetch($pdo, "SELECT cs.cashier_name, cs.opening_cash,
        COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
            WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'cash' AND sp.status = 'success'), 0) AS cash_sales,
        COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_status = 'paid' AND payment_method = 'mixed'), 0) AS mixed_sales,
        COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
            WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'equity'), 0) AS equity_sales,
         COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_method = 'credit' AND payment_status = 'credit'), 0) AS debt_issued,
         COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_method = 'cash' AND payment_status = 'paid' AND notes LIKE 'Payment for invoice(s):%'), 0) AS debt_collected,
        COALESCE((SELECT SUM(total_cost) FROM quick_stock_purchases WHERE shift_id = cs.id AND payment_method = 'cash'), 0) AS quick_purchases,
        COALESCE((SELECT SUM(be.cash_drawer_amount) FROM business_expenses be
            JOIN users eu ON eu.full_name = be.created_by
            WHERE eu.id = cs.cashier_id AND be.cash_drawer_movement = 1
              AND be.expense_date >= DATE(COALESCE(cs.started_at, cs.created_at))
              AND (cs.closed_at IS NULL OR be.expense_date <= DATE(cs.closed_at))), 0) AS cash_expenses,
        cs.counted_cash, cs.safe_drop_amount, cs.next_day_float, cs.status, cs.started_at, cs.closed_at,
        (cs.opening_cash
          + COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
              WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'cash' AND sp.status = 'success'), 0)
          + COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_method = 'cash' AND payment_status = 'paid' AND notes LIKE 'Payment for invoice(s):%'), 0)
          - COALESCE((SELECT SUM(total_cost) FROM quick_stock_purchases WHERE shift_id = cs.id AND payment_method = 'cash'), 0)
          - COALESCE((SELECT SUM(be.cash_drawer_amount) FROM business_expenses be
              JOIN users eu ON eu.full_name = be.created_by
              WHERE eu.id = cs.cashier_id AND be.cash_drawer_movement = 1
                AND be.expense_date >= DATE(COALESCE(cs.started_at, cs.created_at))
                AND (cs.closed_at IS NULL OR be.expense_date <= DATE(cs.closed_at))), 0)
        ) AS corrected_expected_cash
    FROM cashier_shifts cs
    WHERE DATE(COALESCE(started_at, created_at)) BETWEEN {$reportStartSql} AND {$reportEndSql}
       OR DATE(closed_at) BETWEEN {$reportStartSql} AND {$reportEndSql}
    ORDER BY COALESCE(started_at, created_at)");
$shifts = array_map(static function (array $shift): array {
    $outflow = (float) ($shift['quick_purchases'] ?? 0) + (float) ($shift['cash_expenses'] ?? 0);
    $opening = (float) ($shift['opening_cash'] ?? 0);
    $openingUsed = min($opening, $outflow);
    $shift['opening_after_outflow'] = max(0.0, $opening - $outflow);
    $shift['from_opening'] = $openingUsed;
    $shift['from_sales'] = max(0.0, $outflow - $openingUsed);
    $shift['variance'] = (float) ($shift['counted_cash'] ?? 0) - (float) ($shift['corrected_expected_cash'] ?? 0);
    return $shift;
}, $shifts);
$shiftVariances = $fetch($pdo, "SELECT cs.cashier_name, cs.counted_cash,
        (cs.opening_cash
          + COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
              WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'cash' AND sp.status = 'success'), 0)
          + COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_method = 'cash' AND payment_status = 'paid' AND notes LIKE 'Payment for invoice(s):%'), 0)
          - COALESCE((SELECT SUM(total_cost) FROM quick_stock_purchases WHERE shift_id = cs.id AND payment_method = 'cash'), 0)
        - COALESCE((SELECT SUM(be.cash_drawer_amount) FROM business_expenses be
            JOIN users eu ON eu.full_name = be.created_by
            WHERE eu.id = cs.cashier_id AND be.cash_drawer_movement = 1
              AND be.expense_date >= DATE(COALESCE(cs.started_at, cs.created_at))
              AND (cs.closed_at IS NULL OR be.expense_date <= DATE(cs.closed_at))), 0)
        ) AS corrected_expected_cash,
        cs.counted_cash - (cs.opening_cash
          + COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
              WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'cash' AND sp.status = 'success'), 0)
          + COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_method = 'cash' AND payment_status = 'paid' AND notes LIKE 'Payment for invoice(s):%'), 0)
          - COALESCE((SELECT SUM(total_cost) FROM quick_stock_purchases WHERE shift_id = cs.id AND payment_method = 'cash'), 0)
          - COALESCE((SELECT SUM(be.cash_drawer_amount) FROM business_expenses be
              JOIN users eu ON eu.full_name = be.created_by
              WHERE eu.id = cs.cashier_id AND be.cash_drawer_movement = 1
                AND be.expense_date >= DATE(COALESCE(cs.started_at, cs.created_at))
                AND (cs.closed_at IS NULL OR be.expense_date <= DATE(cs.closed_at))), 0)
        ) AS corrected_variance,
        cs.status, cs.variance_reason, cs.closed_at
    FROM cashier_shifts cs
    WHERE DATE(closed_at) BETWEEN {$reportStartSql} AND {$reportEndSql}
    ORDER BY closed_at DESC");

$money = static fn($value): string => 'KES ' . number_format((float) $value, 2);
$salesTotal = array_sum(array_map(static fn(array $row): float => (float) $row['total_amount'], $sales));
$salesCostTotal = array_sum(array_map(static fn(array $row): float => (float) $row['cost_basis'], $sales));
$generalProfit = $salesTotal - $salesCostTotal;
$expenseTotal = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $expenses));
$quickPurchaseTotals = [];
foreach ($quickPurchases as $quickPurchase) {
    $quickPurchaseTotals[$quickPurchase['purchase_no']] = (float) $quickPurchase['total_cost'];
}
$quickPurchaseTotal = array_sum($quickPurchaseTotals);
$expiredStockLossTotal = array_sum(array_map(static fn(array $row): float => (float) $row['cost_loss'], $expiredStock));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Daily Operations Report — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .daily-report-table tbody tr:nth-child(odd) {
      background-color: #ffffff;
    }

    .daily-report-table tbody tr:nth-child(even) {
      background-color: #e0f2fe;
    }

    @media print {
      @page { size: A4 landscape; margin: 8mm; }
      body { background: #fff !important; }
      .no-print { display: none !important; }
      main { max-width: none !important; padding: 0 !important; }
      table { width: 96% !important; max-width: 96% !important; margin: 0 auto !important; font-size: 8px !important; }
      th, td { padding: 3px 4px !important; }
      section { box-shadow: none !important; }
    }
  </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <main class="mx-auto max-w-7xl p-4 sm:p-8">
    <section class="mb-6 border-t-[5px] border-[#7f1d1d] bg-gradient-to-r from-white to-[#eef5ff] p-5 shadow-sm">
      <div class="flex items-center justify-between gap-4">
        <img src="colour_logo.jpg" alt="Smart POS Demo" class="h-20 w-20 rounded-2xl border border-slate-200 object-cover">
        <div class="text-right">
          <p class="text-xl font-bold tracking-wide text-[#7f1d1d]">Smart POS Demo</p>
          <p class="mt-1 text-xs font-semibold uppercase tracking-[0.2em] text-[#1683ff]">Daily Operations Report</p>
        </div>
      </div>
      <div class="mt-5 border-b-2 border-slate-900 pb-2 text-center">
        <h1 class="text-2xl font-bold uppercase tracking-[0.25em]"><?php echo $reportView === 'month' ? 'Monthly report' : 'Daily report'; ?></h1>
        <p class="mt-2 text-sm text-slate-500"><?php echo htmlspecialchars($reportView === 'month' ? $reportMonth : $reportDate, ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
    </section>
    <div class="no-print mb-6 flex gap-2">
      <form method="get" action="admin_daily_report.php" class="flex items-center gap-2">
        <?php if ($reportView === 'month'): ?>
          <input type="hidden" name="view" value="month">
          <label for="reportMonth" class="text-sm font-semibold text-slate-700">Report month</label>
          <input id="reportMonth" name="month" type="month" value="<?php echo htmlspecialchars($reportMonth, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo htmlspecialchars(date('Y-m'), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
        <?php else: ?>
          <label for="reportDate" class="text-sm font-semibold text-slate-700">Report date</label>
          <input id="reportDate" name="date" type="date" value="<?php echo htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
        <?php endif; ?>
        <button type="submit" class="rounded-xl bg-[#1683ff] px-4 py-2 text-sm font-semibold text-white">View report</button>
      </form>
      <button type="button" onclick="window.print()" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Print report</button>
      <a href="admin.php" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold">Back to dashboard</a>
    </div>
    <?php if ($errors): ?><div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800">Some report sections could not be loaded.</div><?php endif; ?>
    <div class="mb-8 grid gap-4 sm:grid-cols-3">
      <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Total sales</div><div class="mt-1 text-xl font-bold"><?php echo $money($salesTotal); ?></div></div>
      <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">General profit</div><div class="mt-1 text-xl font-bold <?php echo $generalProfit < 0 ? 'text-red-600' : 'text-emerald-700'; ?>"><?php echo $money($generalProfit); ?></div></div>
      <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">General expenses</div><div class="mt-1 text-xl font-bold"><?php echo $money($expenseTotal); ?></div></div>
      <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Quick purchases</div><div class="mt-1 text-xl font-bold"><?php echo $money($quickPurchaseTotal); ?></div></div>
      <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Expired stock loss</div><div class="mt-1 text-xl font-bold text-red-600"><?php echo $money($expiredStockLossTotal); ?></div></div>
    </div>
    <?php
      $sections = [
        ['Sales by payment method', $sales, ['payment_method', 'transactions', 'total_amount', 'cost_basis', 'gross_profit']],
        ['Profit by retail and wholesale sales', $salesByType, ['sale_type', 'transactions', 'sales_revenue', 'cost_basis', 'gross_profit']],
        ['Items sold on credit / debt', $creditSales, ['receipt_number', 'cashier_name', 'customer_name', 'customer_phone', 'product_name', 'quantity', 'amount', 'created_at']],
        ['Credit / debt paid', $debtPayments, ['reference', 'cashier_name', 'customer_name', 'customer_phone', 'amount', 'created_at', 'notes']],
        ['General expenses', $expenses, ['title', 'category', 'amount', 'payment_method', 'cash_drawer_amount']],
        ['Stock purchases', $stockPurchases, ['receipt_number', 'supplier_name', 'supplier_phone', 'amount_paid', 'payment_method', 'receipt_date']],
        ['Quick purchases and sales profit', $quickPurchases, ['purchase_no', 'supplier_name', 'product_name', 'purchased_quantity', 'purchase_cost', 'purchase_total', 'sold_quantity', 'sales_revenue', 'product_profit', 'payment_method']],
        ['Stock expiring today / expiry loss', $expiredStock, ['brand', 'product_name', 'expiry_date', 'quantity', 'cost_loss', 'potential_sales', 'potential_profit']],
        ['Shift analysis', $shifts, ['cashier_name', 'started_at', 'cash_sales', 'equity_sales', 'mixed_sales', 'debt_issued', 'debt_collected', 'opening_cash', 'opening_after_outflow', 'from_opening', 'from_sales', 'quick_purchases', 'cash_expenses', 'corrected_expected_cash', 'counted_cash', 'variance', 'status']],
      ];
    ?>
    <?php foreach ($sections as [$heading, $sectionRows, $columns]): ?>
      <section class="mb-6 overflow-x-auto rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold text-[#1683ff]"><?php echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php
          $currencyColumns = ['total_amount', 'amount', 'amount_paid', 'cash_drawer_amount', 'total_cost', 'purchase_total', 'purchase_cost', 'opening_cash', 'expected_cash', 'corrected_expected_cash', 'counted_cash', 'safe_drop_amount', 'cash_sales', 'mixed_sales', 'equity_sales', 'debt_issued', 'debt_collected', 'quick_purchases', 'cash_expenses', 'from_opening', 'from_sales', 'opening_after_outflow', 'cost_loss', 'potential_sales', 'potential_profit', 'cost_basis', 'gross_profit', 'sales_revenue', 'product_profit', 'variance', 'corrected_variance'];
          $totalColumns = ['transactions', 'quantity', 'purchased_quantity', 'sold_quantity'];
        ?>
        <table class="daily-report-table w-full text-left text-sm">
          <thead class="bg-slate-900 text-white"><tr><?php foreach ($columns as $column): ?><th class="px-3 py-2"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $column)), ENT_QUOTES, 'UTF-8'); ?></th><?php endforeach; ?></tr></thead>
          <tbody class="divide-y divide-slate-100">
          <?php if (!$sectionRows): ?><tr><td colspan="<?php echo count($columns); ?>" class="px-3 py-5 text-center text-slate-500">No records found.</td></tr><?php endif; ?>
          <?php foreach ($sectionRows as $row): ?><tr><?php foreach ($columns as $column): ?><td class="px-3 py-2 <?php echo in_array($column, ['cost_loss', 'potential_profit', 'gross_profit', 'product_profit', 'variance'], true) && (float) ($row[$column] ?? 0) < 0 ? 'text-red-600' : (in_array($column, ['potential_profit', 'gross_profit', 'product_profit', 'variance'], true) && (float) ($row[$column] ?? 0) > 0 ? 'text-emerald-700' : ''); ?>"><?php echo htmlspecialchars(isset($row[$column]) && is_numeric($row[$column]) && in_array($column, $currencyColumns, true) ? $money($row[$column]) : (string) ($row[$column] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
          </tbody>
          <tfoot class="border-t-2 border-slate-300 bg-sky-50 font-semibold">
            <tr>
              <?php foreach ($columns as $column): ?>
                <?php
                  $columnTotal = 0.0;
                  if (in_array($column, $currencyColumns, true) || in_array($column, $totalColumns, true)) {
                      foreach ($sectionRows as $row) {
                          $columnTotal += (float) ($row[$column] ?? 0);
                      }
                  }
                  $totalClass = in_array($column, ['cost_loss', 'potential_profit', 'gross_profit', 'product_profit'], true) && $columnTotal < 0
                      ? 'text-red-600'
                      : (in_array($column, ['potential_profit', 'gross_profit', 'product_profit'], true) ? 'text-emerald-700' : '');
                ?>
                <td class="px-3 py-2 <?php echo $totalClass; ?>">
                  <?php if ($column === $columns[0]): ?>
                    Totals
                  <?php elseif (in_array($column, $currencyColumns, true)): ?>
                    <?php echo $money($columnTotal); ?>
                  <?php elseif (in_array($column, $totalColumns, true)): ?>
                    <?php echo number_format($columnTotal, 3); ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          </tfoot>
        </table>
      </section>
    <?php endforeach; ?>
  </main>
  <button type="button" id="backToTop" class="no-print fixed bottom-5 right-5 hidden rounded-full bg-[#1683ff] px-4 py-3 text-sm font-semibold text-white shadow-lg hover:bg-blue-600">Back to top</button>
  <script>
    const backToTop = document.getElementById('backToTop');
    window.addEventListener('scroll', () => {
      backToTop.classList.toggle('hidden', window.scrollY < 400);
    });
    backToTop.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  </script>
</body>
</html>
