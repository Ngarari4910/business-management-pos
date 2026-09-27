<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

$period = $_GET['period'] ?? 'today';
$periodLabels = [
    'today' => "Today's",
    'week' => "This week's",
    'month' => "This month's",
];
$dateFilters = [
    'today' => 'DATE(s.created_at) = CURDATE()',
    'week' => 's.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
    'month' => 's.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
];
$employee = trim((string) ($_GET['employee'] ?? ''));

if (!isset($dateFilters[$period])) {
    $period = 'today';
}

$rows = [];
$error = null;
if ($employee === '') {
    $error = 'No employee was selected.';
} else {
    $query = "SELECT
                s.id AS sale_id,
                s.created_at,
                COALESCE(si.line_total, si.quantity * si.unit_price) AS amount_paid,
                s.payment_method,
                COALESCE(sp.receipt_number, CONCAT('RCP-', DATE_FORMAT(s.created_at, '%Y%m%d%H%i%s'), '-', s.id)) AS receipt_number,
                si.product_name,
                si.quantity,
                si.quantity_in_packets,
                CASE WHEN COALESCE(si.quantity_in_packets, si.quantity) >= 6 THEN 1 ELSE 0 END AS is_wholesale,
                COALESCE(si.line_total, si.quantity * si.unit_price) - (
                    CASE
                        WHEN si.cost_total IS NULL OR si.cost_total = 0 THEN si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)
                        ELSE si.cost_total
                    END
                ) AS sale_profit
            FROM sales s
            LEFT JOIN users u ON u.id = s.cashier_id
            LEFT JOIN sale_items si ON si.sale_id = s.id
            LEFT JOIN sale_payments sp ON sp.sale_id = s.id AND sp.status = 'success'
            LEFT JOIN (
                SELECT product_id, SUM(total_cost) / NULLIF(SUM(total_quantity), 0) AS avg_cost_per_unit
                FROM (
                    SELECT product_id, total_cost, base_quantity AS total_quantity FROM stock_intake_lines
                    UNION ALL
                    SELECT product_id, total_cost, quantity AS total_quantity FROM quick_stock_purchase_lines
                ) combined
                GROUP BY product_id
            ) cost ON cost.product_id = si.product_id
            WHERE {$dateFilters[$period]}
              AND s.payment_status = 'paid'
              AND COALESCE(u.full_name, s.cashier_id, 'Unassigned') = :employee
            ORDER BY s.created_at DESC, si.id ASC
            LIMIT 500";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute(['employee' => $employee]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log('Employee sales detail query error: ' . $e->getMessage());
        $error = 'Sales details could not be loaded.';
    }
}

$retailRows = array_filter($rows, static fn(array $row): bool => (int) $row['is_wholesale'] !== 1);
$wholesaleRows = array_filter($rows, static fn(array $row): bool => (int) $row['is_wholesale'] === 1);
$money = static fn(float $value): string => 'KES ' . number_format($value, 2);
$amount = static fn(array $row): float => (float) ($row['amount_paid'] ?? 0);
$profit = static fn(array $row): float => (float) ($row['sale_profit'] ?? 0);
$isEquity = static fn(array $row): bool => strtolower(trim((string) ($row['payment_method'] ?? ''))) === 'equity';
$retailTotal = array_sum(array_map($amount, $retailRows));
$wholesaleTotal = array_sum(array_map($amount, $wholesaleRows));
$totalProfit = array_sum(array_map($profit, $rows));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Employee Sales Report — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --brand-blue: #1683ff;
      --brand-red: #7f1d1d;
      --brand-blue-light: #eef5ff;
    }

    .report-brand-header {
      border-top: 5px solid var(--brand-red);
      background: linear-gradient(115deg, #ffffff 0%, var(--brand-blue-light) 100%);
    }

    .report-title {
      border-bottom: 2px solid #0f172a;
    }

    @media print {
      @page {
        size: A4 landscape;
        margin: 8mm;
      }

      body {
        background: #fff !important;
      }

      .no-print {
        display: none !important;
      }

      .report-brand-header {
        display: block !important;
        border-top: 5px solid var(--brand-red) !important;
        background: #fff !important;
        box-shadow: none !important;
      }

      main {
        max-width: none !important;
        padding: 0 !important;
      }

      .report-brand-header {
        margin-bottom: 8px !important;
        padding: 8px 12px !important;
      }

      .report-brand-header img {
        width: 42px !important;
        height: 42px !important;
      }

      .report-brand-header p {
        margin-top: 0 !important;
        font-size: 10px !important;
      }

      .report-title {
        margin-top: 6px !important;
        padding-bottom: 4px !important;
      }

      .report-title h1 {
        font-size: 16px !important;
      }

      .report-title p {
        font-size: 9px !important;
      }

      .mb-8 {
        margin-bottom: 8px !important;
      }

      .p-4 {
        padding: 8px !important;
      }

      section,
      .rounded-xl {
        border: 0 !important;
        box-shadow: none !important;
      }

      table {
        width: 96% !important;
        max-width: 96% !important;
        margin: 0 auto !important;
        min-width: 0 !important;
        table-layout: fixed !important;
        font-size: 7.5px !important;
      }

      th,
      td {
        padding: 2px 3px !important;
        overflow-wrap: anywhere !important;
        word-break: break-word !important;
      }

      th:nth-child(1),
      td:nth-child(1) {
        width: 14%;
      }

      th:nth-child(2),
      td:nth-child(2) {
        width: 22%;
      }

      th:nth-child(3),
      td:nth-child(3) {
        width: 8%;
      }

      th:nth-child(4),
      td:nth-child(4),
      th:nth-child(5),
      td:nth-child(5) {
        width: 14%;
      }

      th:nth-child(6),
      td:nth-child(6) {
        width: 17%;
      }

      th:nth-child(7),
      td:nth-child(7) {
        width: 11%;
      }

      th {
        background: #0f172a !important;
        color: #fff !important;
      }

      tr {
        page-break-inside: avoid;
      }
    }
  </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
  <main class="mx-auto max-w-7xl p-4 sm:p-8">
    <section class="report-brand-header mb-6 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center justify-between gap-4">
        <img src="colour_logo.jpg" alt="Smart POS Demo" class="h-20 w-20 rounded-2xl border border-slate-200 bg-white object-cover">
        <div class="text-right">
          <p class="text-xl font-bold tracking-wide text-[#7f1d1d]">Smart POS Demo</p>
          <p class="mt-1 text-xs font-semibold uppercase tracking-[0.2em] text-[#1683ff]">Employee Sales Report</p>
        </div>
      </div>
      <div class="report-title mt-5 pb-2 text-center">
        <h1 class="text-2xl font-bold uppercase tracking-[0.25em] text-slate-900">Sales details</h1>
        <p class="mt-2 text-sm text-slate-500"><?php echo htmlspecialchars($periodLabels[$period] . ' sales · ' . $employee, ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
    </section>
    <div class="no-print mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">Sales details</h1>
        <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars($periodLabels[$period] . ' sales · ' . $employee, ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
      <div class="flex gap-2">
        <button type="button" id="printEmployeeSalesReport" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Print report</button>
        <a href="admin.php" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Back to dashboard</a>
      </div>
    </div>

    <?php if ($error !== null): ?>
      <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
      <div class="mb-8 grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Total sales</div><div class="mt-1 text-xl font-bold"><?php echo $money($retailTotal + $wholesaleTotal); ?></div></div>
        <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Retail sales</div><div class="mt-1 text-xl font-bold"><?php echo $money($retailTotal); ?></div></div>
        <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Wholesale sales</div><div class="mt-1 text-xl font-bold"><?php echo $money($wholesaleTotal); ?></div></div>
        <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Total profit</div><div class="mt-1 text-xl font-bold text-emerald-700"><?php echo $money($totalProfit); ?></div></div>
      </div>
      <?php foreach (['Retail sales' => $retailRows, 'Wholesale sales (quantity 6 or more)' => $wholesaleRows] as $heading => $sectionRows): ?>
        <section class="mb-8 overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
          <h2 class="mb-3 text-lg font-semibold"><?php echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?></h2>
          <table class="w-full min-w-[760px] text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500"><tr><th class="px-2 py-2">Receipt No</th><th class="px-2 py-2">Package</th><th class="px-2 py-2">Qty</th><th class="px-2 py-2">Amount paid</th><th class="px-2 py-2">Profit</th><th class="px-2 py-2">Time</th><th class="px-2 py-2">Mode</th></tr></thead>
            <tbody>
              <?php if (!$sectionRows): ?><tr><td colspan="7" class="px-2 py-6 text-center text-slate-500">No sales found.</td></tr><?php endif; ?>
              <?php foreach ($sectionRows as $row): ?>
                <?php $rowProfit = $profit($row); ?>
                <tr class="border-b border-slate-100"><td class="px-2 py-3"><?php echo htmlspecialchars($row['receipt_number'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-2 py-3"><?php echo htmlspecialchars($row['product_name'] ?: 'Unspecified product', ENT_QUOTES, 'UTF-8'); ?></td><td class="px-2 py-3"><?php echo number_format((float) ($row['quantity_in_packets'] ?: $row['quantity']), 3); ?></td><td class="px-2 py-3"><?php echo $money($amount($row)); ?></td><td class="px-2 py-3 font-semibold <?php echo $rowProfit < 0 ? 'text-red-600' : 'text-emerald-700'; ?>"><?php echo $money($rowProfit); ?></td><td class="px-2 py-3"><?php echo htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-2 py-3"><?php echo htmlspecialchars(str_replace('_', ' ', $row['payment_method'] ?: 'cash'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
              <?php endforeach; ?>
            </tbody>
            <?php
              $sectionTotal = array_sum(array_map($amount, $sectionRows));
              $sectionCash = array_sum(array_map(static fn(array $row): float => $isEquity($row) ? 0.0 : $amount($row), $sectionRows));
              $sectionEquity = array_sum(array_map(static fn(array $row): float => $isEquity($row) ? $amount($row) : 0.0, $sectionRows));
              $sectionProfit = array_sum(array_map($profit, $sectionRows));
            ?>
            <tfoot class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
              <tr>
                <td colspan="3" class="px-2 py-3">Totals</td>
                <td class="px-2 py-3"><?php echo $money($sectionTotal); ?></td>
                <td class="px-2 py-3 <?php echo $sectionProfit < 0 ? 'text-red-600' : 'text-emerald-700'; ?>"><?php echo $money($sectionProfit); ?></td>
                <td colspan="2" class="px-2 py-3">
                  Cash: <?php echo $money($sectionCash); ?> · Equity: <?php echo $money($sectionEquity); ?>
                </td>
              </tr>
            </tfoot>
          </table>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
  <script>
    document.getElementById('printEmployeeSalesReport')?.addEventListener('click', () => window.print());
  </script>
  </body>
</html>
