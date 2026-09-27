<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

$growthReportErrors = [];

function formatCurrency($amount): string {
    return 'KES ' . number_format(floatval($amount), 2);
}

function getSalesStats(PDO $pdo, string $dateFilter): array {
    $baseQuery = "SELECT
            COUNT(DISTINCT s.id) AS sales_count,
            COALESCE(SUM(s.total_amount), 0) AS revenue,
            COALESCE(SUM(sale_cost.cost_basis), 0) AS cost_basis
        FROM sales s
        LEFT JOIN (
            SELECT
                si.sale_id,
                SUM(
                    CASE
                        WHEN si.cost_total IS NULL OR si.cost_total = 0 THEN si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)
                        ELSE si.cost_total
                    END
                ) AS cost_basis
            FROM sale_items si
            LEFT JOIN (
                SELECT product_id, SUM(total_cost) / NULLIF(SUM(total_quantity), 0) AS avg_cost_per_unit
                FROM (
                    SELECT product_id, total_cost, base_quantity AS total_quantity
                    FROM stock_intake_lines
                    UNION ALL
                    SELECT product_id, total_cost, quantity AS total_quantity
                    FROM quick_stock_purchase_lines
                ) combined
                GROUP BY product_id
            ) cost ON cost.product_id = si.product_id
            GROUP BY si.sale_id
        ) sale_cost ON sale_cost.sale_id = s.id
        WHERE {$dateFilter} AND s.payment_status = 'paid'";

    try {
        $stmt = $pdo->prepare($baseQuery);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'sales_count' => intval($result['sales_count'] ?? 0),
            'revenue' => floatval($result['revenue'] ?? 0),
            'cost_basis' => floatval($result['cost_basis'] ?? 0),
        ];
    } catch (Exception $e) {
        global $growthReportErrors;
        error_log('Growth analytics sales query error: ' . $e->getMessage());
        $growthReportErrors[] = 'Sales and cost data could not be loaded.';
        return ['sales_count' => 0, 'revenue' => 0, 'cost_basis' => 0];
    }
}

function getExpenseStats(PDO $pdo, string $dateFilter): float {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_amount FROM business_expenses WHERE {$dateFilter}");
        $stmt->execute();
        return floatval($stmt->fetchColumn() ?? 0);
    } catch (Exception $e) {
        global $growthReportErrors;
        error_log('Growth analytics expense query error: ' . $e->getMessage());
        $growthReportErrors[] = 'Business expense data could not be loaded.';
        return 0.0;
    }
}

function getSalaryExpenseStats(PDO $pdo, string $dateFilter): float {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_pay), 0) AS total_amount FROM salary_payments WHERE payment_status = 'paid' AND {$dateFilter}");
        $stmt->execute();
        return floatval($stmt->fetchColumn() ?? 0);
    } catch (Exception $e) {
        global $growthReportErrors;
        error_log('Growth analytics salary query error: ' . $e->getMessage());
        $growthReportErrors[] = 'Salary expense data could not be loaded.';
        return 0.0;
    }
}

function getCombinedExpenseStats(PDO $pdo, string $expenseDateFilter, string $salaryDateFilter): float {
    return getExpenseStats($pdo, $expenseDateFilter) + getSalaryExpenseStats($pdo, $salaryDateFilter);
}

function getMonthlyYearStats(PDO $pdo, int $year): array {
  $monthly = [];
  for ($month = 1; $month <= 12; $month++) {
    $monthly[$month] = ['sales_count' => 0, 'revenue' => 0.0, 'cost_basis' => 0.0, 'expenses' => 0.0];
  }

  $salesStmt = $pdo->prepare(
    'SELECT MONTH(s.created_at) AS month_number,
        COUNT(DISTINCT s.id) AS sales_count,
        COALESCE(SUM(s.total_amount), 0) AS revenue,
        COALESCE(SUM(sale_cost.cost_basis), 0) AS cost_basis
     FROM sales s
     LEFT JOIN (
       SELECT si.sale_id,
          SUM(CASE WHEN si.cost_total IS NULL OR si.cost_total = 0
               THEN si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)
               ELSE si.cost_total END) AS cost_basis
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
     WHERE YEAR(s.created_at) = :year AND s.payment_status = \'paid\'
     GROUP BY MONTH(s.created_at)'
  );
  $salesStmt->execute(['year' => $year]);
  foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $month = (int) $row['month_number'];
    $monthly[$month]['sales_count'] = (int) $row['sales_count'];
    $monthly[$month]['revenue'] = (float) $row['revenue'];
    $monthly[$month]['cost_basis'] = (float) $row['cost_basis'];
  }

  $expenseStmt = $pdo->prepare(
    'SELECT month_number, SUM(amount) AS expenses FROM (
       SELECT MONTH(expense_date) AS month_number, amount
       FROM business_expenses WHERE YEAR(expense_date) = :business_year
       UNION ALL
       SELECT MONTH(payment_date) AS month_number, net_pay AS amount
       FROM salary_payments WHERE YEAR(payment_date) = :salary_year AND payment_status = \'paid\'
     ) expenses_by_month
     GROUP BY month_number'
  );
  $expenseStmt->execute(['business_year' => $year, 'salary_year' => $year]);
  foreach ($expenseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $monthly[(int) $row['month_number']]['expenses'] = (float) $row['expenses'];
  }

  return $monthly;
}

$monthNames = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

$selectedYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($selectedYear < 2000) {
    $selectedYear = (int) date('Y');
}

$previousYear = $selectedYear - 1;

$selectedYearRows = [];
$previousYearRows = [];
$yearRevenueTotal = 0.0;
$yearCostTotal = 0.0;
$yearExpenseTotal = 0.0;
$yearProfitTotal = 0.0;

$previousYearRevenueTotal = 0.0;
$previousYearCostTotal = 0.0;
$previousYearExpenseTotal = 0.0;
$previousYearProfitTotal = 0.0;
$selectedMonthlyStats = getMonthlyYearStats($pdo, $selectedYear);
$previousMonthlyStats = getMonthlyYearStats($pdo, $previousYear);

for ($month = 1; $month <= 12; $month++) {
  $monthRevenueCurrent = $selectedMonthlyStats[$month];
  $monthExpensesCurrent = $monthRevenueCurrent['expenses'];

    $grossProfitCurrent = $monthRevenueCurrent['revenue'] - $monthRevenueCurrent['cost_basis'];
    $netProfitCurrent = $grossProfitCurrent - $monthExpensesCurrent;

    $selectedYearRows[] = [
        'month' => $month,
        'month_name' => $monthNames[$month],
        'sales_count' => $monthRevenueCurrent['sales_count'],
        'revenue' => $monthRevenueCurrent['revenue'],
        'cost_basis' => $monthRevenueCurrent['cost_basis'],
        'expenses' => $monthExpensesCurrent,
        'gross_profit' => $grossProfitCurrent,
        'net_profit' => $netProfitCurrent,
    ];

    $yearRevenueTotal += $monthRevenueCurrent['revenue'];
    $yearCostTotal += $monthRevenueCurrent['cost_basis'];
    $yearExpenseTotal += $monthExpensesCurrent;
    $yearProfitTotal += $netProfitCurrent;

    $monthRevenuePrevious = $previousMonthlyStats[$month];
    $monthExpensesPrevious = $monthRevenuePrevious['expenses'];

    $grossProfitPrevious = $monthRevenuePrevious['revenue'] - $monthRevenuePrevious['cost_basis'];
    $netProfitPrevious = $grossProfitPrevious - $monthExpensesPrevious;

    $previousYearRows[] = [
        'month' => $month,
        'month_name' => $monthNames[$month],
        'sales_count' => $monthRevenuePrevious['sales_count'],
        'revenue' => $monthRevenuePrevious['revenue'],
        'cost_basis' => $monthRevenuePrevious['cost_basis'],
        'expenses' => $monthExpensesPrevious,
        'gross_profit' => $grossProfitPrevious,
        'net_profit' => $netProfitPrevious,
    ];

    $previousYearRevenueTotal += $monthRevenuePrevious['revenue'];
    $previousYearCostTotal += $monthRevenuePrevious['cost_basis'];
    $previousYearExpenseTotal += $monthExpensesPrevious;
    $previousYearProfitTotal += $netProfitPrevious;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Growth Analytics — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <?php if (!empty($growthReportErrors)): ?>
      <div class="mb-6 rounded-3xl border border-rose-200 bg-rose-50 p-5 text-rose-900">
        <p class="font-semibold">Some growth report data could not be loaded.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
          <?php foreach (array_unique($growthReportErrors) as $reportError): ?>
            <li><?php echo htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8'); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <div class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Admin analytics</p>
        <h1 class="mt-2 text-3xl font-semibold text-slate-900">Growth analytics & comparison</h1>
        <p class="mt-1 text-sm text-slate-500">Compare the selected year against the previous year.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="admin_growth.php" class="rounded-3xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Back to growth report</a>
        <a href="admin.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Dashboard</a>
      </div>
    </div>

    <section class="mb-6 rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Reporting year</p>
          <h2 class="mt-2 text-2xl font-semibold text-slate-900"><?php echo (int) $selectedYear; ?> vs <?php echo (int) $previousYear; ?></h2>
        </div>
        <form method="get" class="flex flex-wrap items-end gap-3">
          <label class="block">
            <span class="mb-2 block text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Year</span>
            <select name="year" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 focus:border-slate-900 focus:outline-none">
              <?php
                $yearNow = (int) date('Y');
                for ($year = $yearNow - 3; $year <= $yearNow + 2; $year++) {
                    echo '<option value="' . (int) $year . '" ' . ($year === $selectedYear ? 'selected' : '') . '>' . (int) $year . '</option>';
                }
              ?>
            </select>
          </label>
          <button type="submit" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-bold text-white transition hover:bg-slate-800">Compare</button>
        </form>
      </div>
    </section>

    <section class="mb-6 grid gap-4 md:grid-cols-2">
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500"><?php echo (int) $selectedYear; ?> total revenue</p>
        <p class="mt-2 text-3xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($yearRevenueTotal)); ?></p>
        <p class="mt-2 text-sm text-slate-500"><?php echo (int) $selectedYear; ?> cost basis: <?php echo htmlspecialchars(formatCurrency($yearCostTotal)); ?></p>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500"><?php echo (int) $previousYear; ?> total revenue</p>
        <p class="mt-2 text-3xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($previousYearRevenueTotal)); ?></p>
        <p class="mt-2 text-sm text-slate-500"><?php echo (int) $previousYear; ?> cost basis: <?php echo htmlspecialchars(formatCurrency($previousYearCostTotal)); ?></p>
      </div>
    </section>

    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-900">Monthly comparison</h2>
          <p class="mt-1 text-sm text-slate-500">Sales, expenses, and net profit month by month.</p>
        </div>
        <span class="rounded-full bg-slate-100 px-4 py-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-600"><?php echo (int) $selectedYear; ?></span>
      </div>

      <div class="mt-5 overflow-x-auto rounded-3xl border border-slate-200">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-900 text-white">
            <tr>
              <th class="px-4 py-3 text-left font-semibold">Month</th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo (int) $selectedYear; ?> sales</th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo (int) $selectedYear; ?> expenses</th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo (int) $selectedYear; ?> profit</th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo (int) $previousYear; ?> sales</th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo (int) $previousYear; ?> expenses</th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo (int) $previousYear; ?> profit</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 bg-white">
            <?php for ($month = 1; $month <= 12; $month++): ?>
              <?php $cur = $selectedYearRows[$month - 1]; ?>
              <?php $prev = $previousYearRows[$month - 1]; ?>
              <tr>
                <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars($cur['month_name']); ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?php echo htmlspecialchars(formatCurrency($cur['revenue'])); ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?php echo htmlspecialchars(formatCurrency($cur['expenses'])); ?></td>
                <td class="px-4 py-3 text-right font-semibold text-emerald-700"><?php echo htmlspecialchars(formatCurrency($cur['net_profit'])); ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?php echo htmlspecialchars(formatCurrency($prev['revenue'])); ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?php echo htmlspecialchars(formatCurrency($prev['expenses'])); ?></td>
                <td class="px-4 py-3 text-right font-semibold text-slate-800"><?php echo htmlspecialchars(formatCurrency($prev['net_profit'])); ?></td>
              </tr>
            <?php endfor; ?>
          </tbody>
          <tfoot class="bg-slate-50">
            <tr>
              <th class="px-4 py-3 text-left font-semibold">Year total</th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo htmlspecialchars(formatCurrency($yearRevenueTotal)); ?></th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo htmlspecialchars(formatCurrency($yearExpenseTotal)); ?></th>
              <th class="px-4 py-3 text-right font-semibold text-emerald-700"><?php echo htmlspecialchars(formatCurrency($yearProfitTotal)); ?></th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo htmlspecialchars(formatCurrency($previousYearRevenueTotal)); ?></th>
              <th class="px-4 py-3 text-right font-semibold"><?php echo htmlspecialchars(formatCurrency($previousYearExpenseTotal)); ?></th>
              <th class="px-4 py-3 text-right font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($previousYearProfitTotal)); ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  </div>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
