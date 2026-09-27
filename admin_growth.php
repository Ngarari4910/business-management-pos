<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

$growthReportErrors = [];
require __DIR__ . '/payment_flow.php';
require __DIR__ . '/inventory_losses.php';

if (empty($_SESSION['user_logged_in']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

function formatCurrency($amount): string {
    return 'KES ' . number_format(floatval($amount), 2);
}

function profitColorClass(float $amount): string {
    return $amount < 0 ? 'text-rose-700' : 'text-emerald-700';
}

function getSalesStats(PDO $pdo, string $dateFilter): array {
  static $statsCache = [];
  if (isset($statsCache[$dateFilter])) {
    return $statsCache[$dateFilter];
  }

  $filteredSalesDateFilter = str_replace('s.', 'filtered_sales.', $dateFilter);
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
              JOIN sales filtered_sales ON filtered_sales.id = si.sale_id
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
              WHERE {$filteredSalesDateFilter} AND filtered_sales.payment_status = 'paid'
            GROUP BY si.sale_id
        ) sale_cost ON sale_cost.sale_id = s.id
        WHERE {$dateFilter} AND s.payment_status = 'paid'";

    try {
        $stmt = $pdo->prepare($baseQuery);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $statsCache[$dateFilter] = [
            'sales_count' => intval($result['sales_count'] ?? 0),
            'revenue' => floatval($result['revenue'] ?? 0),
            'cost_basis' => floatval($result['cost_basis'] ?? 0),
        ];
    } catch (Exception $e) {
        global $growthReportErrors;
        error_log('Growth report sales stats query error: ' . $e->getMessage());
        $growthReportErrors[] = 'Sales and cost data could not be loaded.';
        return $statsCache[$dateFilter] = ['sales_count' => 0, 'revenue' => 0, 'cost_basis' => 0];
    }
}

function getExpenseStats(PDO $pdo, string $dateFilter): float {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_amount FROM business_expenses WHERE {$dateFilter}");
        $stmt->execute();
        return floatval($stmt->fetchColumn() ?? 0);
    } catch (Exception $e) {
        global $growthReportErrors;
        error_log('Growth report expense stats query error: ' . $e->getMessage());
        $growthReportErrors[] = 'Business expense data could not be loaded.';
        return 0.0;
    }
}

function getOutstandingDebt(PDO $pdo): float {
    try {
        $stmt = $pdo->query('SELECT COALESCE(SUM(balance), 0) AS outstanding FROM customer_credit_invoices WHERE balance > 0');
        return floatval($stmt->fetchColumn() ?? 0);
    } catch (Exception $e) {
        error_log('Growth report outstanding debt query error: ' . $e->getMessage());
        return 0.0;
    }
}

function getCreditIssued(PDO $pdo, string $dateFilter): float {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS issued FROM customer_credit_invoices WHERE {$dateFilter}");
        $stmt->execute();
        return floatval($stmt->fetchColumn() ?? 0);
    } catch (Exception $e) {
        error_log('Growth report credit issued query error: ' . $e->getMessage());
        return 0.0;
    }
}

function getDebtCollections(PDO $pdo, string $dateFilter): float {
    try {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(sp.amount), 0) AS collected 
            FROM sale_payments sp 
            JOIN sales s ON s.id = sp.sale_id 
            WHERE sp.payment_method = 'cash' 
                AND sp.status = 'paid' 
                AND s.notes LIKE 'Payment for invoice(s):%' 
                AND {$dateFilter}"
        );
        $stmt->execute();
        return floatval($stmt->fetchColumn() ?? 0);
    } catch (Exception $e) {
        error_log('Growth report debt collections query error: ' . $e->getMessage());
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
        error_log('Growth report salary stats query error: ' . $e->getMessage());
        $growthReportErrors[] = 'Salary expense data could not be loaded.';
        return 0.0;
    }
}

function getCombinedExpenseStats(PDO $pdo, string $expenseDateFilter, string $salaryDateFilter): float {
    return getExpenseStats($pdo, $expenseDateFilter) + getSalaryExpenseStats($pdo, $salaryDateFilter);
}

function getMonthlySalesStatement(PDO $pdo, int $year, int $month): array {
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate));
    $stmt = $pdo->prepare(
        "SELECT DATE(s.created_at) AS sale_date, si.product_name, si.quantity,
                si.quantity_in_packets, si.sale_mode, si.line_total,
            CASE WHEN si.cost_total IS NULL OR si.cost_total = 0
                 THEN si.quantity_in_packets * COALESCE(si.cost_per_unit, 0)
                 ELSE si.cost_total END AS cost_total
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE s.payment_status = 'paid'
           AND DATE(s.created_at) BETWEEN :start_date AND :end_date
         ORDER BY s.created_at ASC, si.id ASC"
    );
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getMonthlyExpenseStatement(PDO $pdo, int $year, int $month): array {
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate));
    $rows = [];

    $expenseStmt = $pdo->prepare(
        "SELECT expense_date AS expense_date, title AS description, category, amount, 'Business expense' AS expense_type
         FROM business_expenses
         WHERE expense_date BETWEEN :start_date AND :end_date
         ORDER BY expense_date ASC, id ASC"
    );
    $expenseStmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $rows = $expenseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $salaryStmt = $pdo->prepare(
        "SELECT payment_date AS expense_date, CONCAT('Salary payment', COALESCE(CONCAT(' - ', payroll_reference), '')) AS description,
                'salary' AS category, net_pay AS amount, 'Salary' AS expense_type
         FROM salary_payments
         WHERE payment_status = 'paid'
           AND payment_date BETWEEN :start_date AND :end_date
         ORDER BY payment_date ASC, id ASC"
    );
    $salaryStmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $rows = array_merge($rows, $salaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    usort($rows, static function (array $left, array $right): int {
        return strcmp((string) $left['expense_date'], (string) $right['expense_date']);
    });
    return $rows;
}

function getSupplierBalance(PDO $pdo): float {
    try {
        $stmt = $pdo->query('SELECT COALESCE(SUM(si.balance), 0) AS outstanding FROM stock_intakes si WHERE si.balance > 0');
        return floatval($stmt->fetchColumn() ?? 0);
    } catch (Exception $e) {
        global $growthReportErrors;
        error_log('Growth report supplier balance query error: ' . $e->getMessage());
        $growthReportErrors[] = 'Supplier balance data could not be loaded.';
        return 0.0;
    }
}

function computeChange(float $current, float $previous): array {
    $difference = $current - $previous;

    if ($previous === 0.0) {
        $percent = $current === 0.0 ? 0.0 : null;
    } else {
        $percent = ($difference / abs($previous)) * 100.0;
    }

    return ['difference' => $difference, 'percent' => $percent];
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

$reportType = isset($_GET['report_type']) ? trim((string) $_GET['report_type']) : 'monthly';
if (!in_array($reportType, ['daily', 'monthly', 'yearly'], true)) {
    $reportType = 'monthly';
}

$reportDate = isset($_GET['report_date']) ? (string) $_GET['report_date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d');
}

$selectedStartMonth = isset($_GET['start_month']) ? (int) $_GET['start_month'] : 1;
$selectedEndMonth = isset($_GET['end_month']) ? (int) $_GET['end_month'] : 12;
if ($selectedStartMonth < 1) {
    $selectedStartMonth = 1;
}
if ($selectedEndMonth < 1) {
    $selectedEndMonth = 1;
}
if ($selectedStartMonth > 12) {
    $selectedStartMonth = 12;
}
if ($selectedEndMonth > 12) {
    $selectedEndMonth = 12;
}
if ($selectedStartMonth > $selectedEndMonth) {
    $tmpMonth = $selectedStartMonth;
    $selectedStartMonth = $selectedEndMonth;
    $selectedEndMonth = $tmpMonth;
}

$showGrowthReportModal = isset($_GET['generate_report']) && $_GET['generate_report'] === '1' && isset($_GET['year']);

$growthReportRows = [];
$growthRevenueTotal = 0.0;
$growthCostTotal = 0.0;
$growthExpenseTotal = 0.0;
$growthProfitTotal = 0.0;
$monthlySalesStatement = [];
$monthlyExpenseStatement = [];
$monthlyRetailAmount = 0.0;
$monthlyRetailProfit = 0.0;
$monthlyWholesaleAmount = 0.0;
$monthlyWholesaleProfit = 0.0;
$monthlyStatementExpenses = 0.0;

if ($reportType === 'daily') {
    $dailyRevenue = getSalesStats($pdo, "DATE(s.created_at) = '{$reportDate}'");
    $dailyExpenses = getCombinedExpenseStats(
        $pdo,
        "DATE(expense_date) = '{$reportDate}'",
        "DATE(payment_date) = '{$reportDate}'"
    );

    $dailyGrossProfit = $dailyRevenue['revenue'] - $dailyRevenue['cost_basis'];
    $dailyNetProfit = $dailyGrossProfit - $dailyExpenses;

    $growthReportRows[] = [
        'year' => (int) date('Y', strtotime($reportDate)),
        'month' => (int) date('n', strtotime($reportDate)),
        'month_name' => date('F', strtotime($reportDate)),
        'day_label' => date('d M Y', strtotime($reportDate)),
        'sales_count' => $dailyRevenue['sales_count'],
        'revenue' => $dailyRevenue['revenue'],
        'cost_basis' => $dailyRevenue['cost_basis'],
        'expenses' => $dailyExpenses,
        'gross_profit' => $dailyGrossProfit,
        'net_profit' => $dailyNetProfit,
    ];

    $growthRevenueTotal = $dailyRevenue['revenue'];
    $growthCostTotal = $dailyRevenue['cost_basis'];
    $growthExpenseTotal = $dailyExpenses;
    $growthProfitTotal = $dailyNetProfit;
} elseif ($reportType === 'yearly') {
    for ($month = 1; $month <= 12; $month++) {
        $monthRevenue = getSalesStats($pdo, "YEAR(s.created_at) = {$selectedYear} AND MONTH(s.created_at) = {$month}");
        $monthExpenses = getCombinedExpenseStats(
            $pdo,
            "YEAR(expense_date) = {$selectedYear} AND MONTH(expense_date) = {$month}",
            "YEAR(payment_date) = {$selectedYear} AND MONTH(payment_date) = {$month}"
        );

        $grossProfit = $monthRevenue['revenue'] - $monthRevenue['cost_basis'];
        $netProfit = $grossProfit - $monthExpenses;

        $growthReportRows[] = [
            'year' => $selectedYear,
            'month' => $month,
            'month_name' => $monthNames[$month],
            'sales_count' => $monthRevenue['sales_count'],
            'revenue' => $monthRevenue['revenue'],
            'cost_basis' => $monthRevenue['cost_basis'],
            'expenses' => $monthExpenses,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
        ];

        $growthRevenueTotal += $monthRevenue['revenue'];
        $growthCostTotal += $monthRevenue['cost_basis'];
        $growthExpenseTotal += $monthExpenses;
        $growthProfitTotal += $netProfit;
    }
} else {
    for ($month = $selectedStartMonth; $month <= $selectedEndMonth; $month++) {
        $monthRevenue = getSalesStats($pdo, "YEAR(s.created_at) = {$selectedYear} AND MONTH(s.created_at) = {$month}");
        $monthExpenses = getCombinedExpenseStats(
            $pdo,
            "YEAR(expense_date) = {$selectedYear} AND MONTH(expense_date) = {$month}",
            "YEAR(payment_date) = {$selectedYear} AND MONTH(payment_date) = {$month}"
        );

        $grossProfit = $monthRevenue['revenue'] - $monthRevenue['cost_basis'];
        $netProfit = $grossProfit - $monthExpenses;

        $growthReportRows[] = [
            'year' => $selectedYear,
            'month' => $month,
            'month_name' => $monthNames[$month],
            'sales_count' => $monthRevenue['sales_count'],
            'revenue' => $monthRevenue['revenue'],
            'cost_basis' => $monthRevenue['cost_basis'],
            'expenses' => $monthExpenses,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
        ];

        $growthRevenueTotal += $monthRevenue['revenue'];
        $growthCostTotal += $monthRevenue['cost_basis'];
        $growthExpenseTotal += $monthExpenses;
        $growthProfitTotal += $netProfit;
    }
}

if ($reportType === 'monthly' && $selectedStartMonth === $selectedEndMonth && $showGrowthReportModal) {
    $monthlySalesStatement = getMonthlySalesStatement($pdo, $selectedYear, $selectedStartMonth);
    $monthlyExpenseStatement = getMonthlyExpenseStatement($pdo, $selectedYear, $selectedStartMonth);

    foreach ($monthlySalesStatement as $statementRow) {
        $amount = (float) ($statementRow['line_total'] ?? 0);
        $cost = (float) ($statementRow['cost_total'] ?? 0);
        if ((float) ($statementRow['quantity'] ?? 0) > 6) {
            $monthlyWholesaleAmount += $amount;
            $monthlyWholesaleProfit += $amount - $cost;
        } else {
            $monthlyRetailAmount += $amount;
            $monthlyRetailProfit += $amount - $cost;
        }
    }
    foreach ($monthlyExpenseStatement as $expenseRow) {
        $monthlyStatementExpenses += (float) ($expenseRow['amount'] ?? 0);
    }
}

$todayStats = getSalesStats($pdo, 'DATE(s.created_at) = CURDATE()');
$yesterdayStats = getSalesStats($pdo, 'DATE(s.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)');

$weekStats = getSalesStats($pdo, 'YEARWEEK(s.created_at, 1) = YEARWEEK(CURDATE(), 1)');
$lastWeekStats = getSalesStats($pdo, 'YEARWEEK(s.created_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)');

$monthStats = getSalesStats($pdo, 'YEAR(s.created_at) = YEAR(CURDATE()) AND MONTH(s.created_at) = MONTH(CURDATE())');
$lastMonthStats = getSalesStats($pdo, 'YEAR(s.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(s.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))');

$todayExpenses = getCombinedExpenseStats($pdo, 'DATE(expense_date) = CURDATE()', 'DATE(payment_date) = CURDATE()');
$weekExpenses = getCombinedExpenseStats($pdo, 'YEARWEEK(expense_date, 1) = YEARWEEK(CURDATE(), 1)', 'YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1)');
$monthExpenses = getCombinedExpenseStats($pdo, 'YEAR(expense_date) = YEAR(CURDATE()) AND MONTH(expense_date) = MONTH(CURDATE())', 'YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())');

$todaySalaryExpenses = getSalaryExpenseStats($pdo, 'DATE(payment_date) = CURDATE()');
$weekSalaryExpenses = getSalaryExpenseStats($pdo, 'YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1)');
$monthSalaryExpenses = getSalaryExpenseStats($pdo, 'YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())');

$todayGrossProfit = $todayStats['revenue'] - $todayStats['cost_basis'];
$weekGrossProfit = $weekStats['revenue'] - $weekStats['cost_basis'];
$monthGrossProfit = $monthStats['revenue'] - $monthStats['cost_basis'];

$todayNetProfit = $todayGrossProfit - $todayExpenses;
$weekNetProfit = $weekGrossProfit - $weekExpenses;
$monthNetProfit = $monthGrossProfit - $monthExpenses;

$todayNetLabel = $todayNetProfit < 0 ? 'Today net loss' : "Today's net profit";
$weekNetLabel = $weekNetProfit < 0 ? 'This week net loss' : 'This week net profit';
$monthNetLabel = $monthNetProfit < 0 ? 'This month net loss' : 'This month net profit';

$todayNetClass = $todayNetProfit < 0 ? 'text-rose-900' : 'text-slate-900';
$weekNetClass = $weekNetProfit < 0 ? 'text-rose-900' : 'text-slate-900';
$monthNetClass = $monthNetProfit < 0 ? 'text-rose-900' : 'text-slate-900';

$todayNetCompare = computeChange($todayNetProfit, $yesterdayStats['revenue'] - $yesterdayStats['cost_basis'] - getCombinedExpenseStats($pdo, 'DATE(expense_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)', 'DATE(payment_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)'));
$weekNetCompare = computeChange($weekNetProfit, $lastWeekStats['revenue'] - $lastWeekStats['cost_basis'] - getCombinedExpenseStats($pdo, 'YEARWEEK(expense_date, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)', 'YEARWEEK(payment_date, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)'));
$monthNetCompare = computeChange($monthNetProfit, $lastMonthStats['revenue'] - $lastMonthStats['cost_basis'] - getCombinedExpenseStats($pdo, 'YEAR(expense_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(expense_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))', 'YEAR(payment_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(payment_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))'));

$outstandingDebt = getOutstandingDebt($pdo);
$todayDebtIssued = getCreditIssued($pdo, 'DATE(created_at) = CURDATE()');
$weekDebtIssued = getCreditIssued($pdo, 'YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)');
$monthDebtIssued = getCreditIssued($pdo, 'YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())');

$todayDebtCollected = getDebtCollections($pdo, 'DATE(s.created_at) = CURDATE()');
$weekDebtCollected = getDebtCollections($pdo, 'YEARWEEK(s.created_at, 1) = YEARWEEK(CURDATE(), 1)');
$monthDebtCollected = getDebtCollections($pdo, 'YEAR(s.created_at) = YEAR(CURDATE()) AND MONTH(s.created_at) = MONTH(CURDATE())');

$outstandingSupplierBalance = getSupplierBalance($pdo);
$expiredStockLosses = getExpiredStockLosses($pdo);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Business Growth & Loss — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --brand-blue: #123b78;
      --brand-blue-light: #eaf2ff;
      --brand-red: #c62828;
      --brand-red-light: #fff0f0;
      --brand-ink: #14213d;
    }

    body {
      background:
        radial-gradient(circle at 8% 0%, rgba(198, 40, 40, 0.08), transparent 28rem),
        radial-gradient(circle at 92% 0%, rgba(18, 59, 120, 0.1), transparent 30rem),
        #f5f8fc !important;
    }

    .admin-page-shell {
      position: relative;
    }

    .admin-page-shell > div:first-child {
      border-top: 5px solid var(--brand-red) !important;
      background: linear-gradient(115deg, #ffffff 0%, #eef5ff 100%) !important;
      box-shadow: 0 18px 45px rgba(18, 59, 120, 0.12) !important;
    }

    .admin-page-shell > div:first-child h1 {
      color: var(--brand-blue) !important;
    }

    .admin-page-shell > div:first-child p:first-child {
      color: var(--brand-red) !important;
    }

    #growthRangeModal > div > div:first-child {
      background: linear-gradient(110deg, var(--brand-blue), #1d5aa6) !important;
      color: #fff !important;
    }

    #growthRangeModal > div > div:first-child p,
    #growthRangeModal > div > div:first-child h2 {
      color: #fff !important;
    }

    #growthRangeModal > div > div:first-child button {
      border-color: rgba(255, 255, 255, 0.35) !important;
      color: #fff !important;
    }

    #growthRangeModal .border-b-2 {
      border-color: var(--brand-red) !important;
    }

    #growthRangeModal h2,
    #growthRangeModal h3 {
      color: var(--brand-blue) !important;
    }

    #growthRangeModal section,
    #growthRangeModal .rounded-\[1\.5rem\],
    #growthRangeModal .rounded-\[1\.75rem\] {
      border-color: #d8e3f2 !important;
      box-shadow: 0 10px 28px rgba(18, 59, 120, 0.07) !important;
    }

    #growthRangeModal th {
      background: var(--brand-blue) !important;
      color: #fff !important;
    }

    #growthRangeModal tbody tr:nth-child(even) {
      background: #f6f9fe !important;
    }

    #growthRangeModal tbody tr:hover {
      background: var(--brand-red-light) !important;
    }

    #growthRangeModal .text-rose-700,
    #growthRangeModal .text-rose-900 {
      color: var(--brand-red) !important;
    }

    #growthRangeModal .text-emerald-700 {
      color: var(--brand-blue) !important;
    }

    #growthRangeModal .bg-rose-50 {
      background: var(--brand-red-light) !important;
    }

    #growthRangeModal .bg-emerald-50,
    #growthRangeModal .bg-emerald-50\/60 {
      background: var(--brand-blue-light) !important;
    }

    #growthRangeModal .text-emerald-800,
    #growthRangeModal .text-emerald-700 {
      color: var(--brand-blue) !important;
    }

    @media print {
      @page {
        size: auto;
        margin: 12mm;
      }

      body {
        background: #fff !important;
      }

      body > *:not(.admin-page-shell),
      .admin-page-shell > *:not(#growthRangeModal),
      #growthRangeModal button {
        display: none !important;
      }

      #growthRangeModal {
        display: block !important;
        position: static !important;
        inset: auto !important;
        overflow: visible !important;
        padding: 0 !important;
        background: #fff !important;
      }

      #growthRangeModal > div {
        display: block !important;
        max-width: none !important;
        max-height: none !important;
        overflow: visible !important;
        border: 0 !important;
        box-shadow: none !important;
      }

      #growthRangeModal > div > div:first-child {
        display: none !important;
      }

      #growthRangeModal > div > div:last-child {
        display: block !important;
        overflow: visible !important;
        padding: 0 !important;
      }

      #growthRangeModal .overflow-y-auto {
        display: block !important;
        overflow: visible !important;
      }

      #growthRangeModal .mb-5 {
        page-break-inside: avoid;
      }

      #growthRangeModal table {
        width: 100% !important;
        font-size: 10px !important;
      }

      #growthRangeModal tr {
        page-break-inside: avoid;
      }

      #growthRangeModal .overflow-x-auto {
        overflow: visible !important;
      }
    }
  </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <div class="admin-page-shell mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
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
        <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Admin report</p>
        <h1 class="mt-2 text-3xl font-semibold text-slate-900">Business Growth & Loss</h1>
        <p class="mt-1 text-sm text-slate-500">Compare revenue, profit, and expenses across key periods.</p>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="admin.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">Dashboard</a>
          <a href="admin_growth.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Growth report</a>
          <a href="purchases_suppliers.php" class="rounded-3xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">Purchases</a>
          <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-600">Expenses</a>
          <a href="admin_cashiers.php" class="rounded-3xl bg-slate-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Staff</a>
          <a href="logout.php" class="rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Logout</a>
        </div>
      </div>
    </div>

    <div id="mobileMenu" class="fixed inset-x-0 top-0 z-50 hidden bg-slate-50/95 p-4 shadow-lg backdrop-blur-sm border-b border-slate-200 sm:hidden">
      <div class="flex items-center justify-between gap-3 mb-4">
        <span class="text-base font-semibold">Menu</span>
        <button id="mobileMenuClose" type="button" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Close</button>
      </div>
      <div class="flex flex-col gap-3">
        <a href="admin.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Dashboard</a>
        <a href="admin_growth.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Growth report</a>
        <a href="purchases_suppliers.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Purchases</a>
        <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-600">Expenses</a>
        <a href="admin_cashiers.php" class="rounded-3xl bg-slate-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Staff</a>
        <a href="logout.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 transition hover:bg-slate-100">Logout</a>
      </div>
    </div>

    <section class="mb-6 rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <div class="flex items-center gap-3">
            <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-white">Growth filters</span>
            <span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Choose a report</span>
          </div>
          <h2 class="mt-4 text-xl font-semibold text-slate-900">Growth report range</h2>
          <p class="mt-1 text-sm text-slate-500">Start with a quick choice, or set your own dates and months.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <a href="admin_growth_analytics.php?year=<?php echo (int) $selectedYear; ?>" class="rounded-3xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Analytics & comparison</a>
          <button type="button" id="printGrowthReport" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Print report</button>
        </div>
      </div>

      <div class="mt-5 rounded-3xl border border-emerald-100 bg-emerald-50/60 p-4">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-800">Quick choices</p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button type="button" data-range-preset="today" class="rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">Today</button>
          <button type="button" data-range-preset="this-month" class="rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">This month</button>
          <button type="button" data-range-preset="last-month" class="rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">Last month</button>
          <button type="button" data-range-preset="this-year" class="rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">This year</button>
        </div>
      </div>

      <form id="growthReportFilterForm" method="get" class="mt-5 flex flex-wrap items-end gap-4">
        <input id="growthReportType" type="hidden" name="report_type" value="monthly">
        <input id="growthDateField" type="hidden" name="report_date" value="<?php echo htmlspecialchars($reportDate); ?>">
        <label class="block min-w-32">
          <span class="mb-2 block text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Year</span>
          <select name="year" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 focus:border-slate-900 focus:outline-none">
            <?php
            $yearNow = (int) date('Y');
            for ($year = $yearNow - 3; $year <= $yearNow + 2; $year++) {
                $selected = $year === $selectedYear ? 'selected' : '';
                echo '<option value="' . (int) $year . '" ' . $selected . '>' . (int) $year . '</option>';
            }
            ?>
          </select>
        </label>
        <label class="block min-w-36">
          <span class="mb-2 block text-xs font-bold uppercase tracking-[0.2em] text-slate-500">From month</span>
          <select id="growthStartMonthField" name="start_month" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 focus:border-slate-900 focus:outline-none">
            <?php foreach ($monthNames as $monthNumber => $monthName): ?>
              <option value="<?php echo (int) $monthNumber; ?>" <?php echo $monthNumber === $selectedStartMonth ? 'selected' : ''; ?>><?php echo htmlspecialchars($monthName); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block min-w-36">
          <span class="mb-2 block text-xs font-bold uppercase tracking-[0.2em] text-slate-500">To month</span>
          <select id="growthEndMonthField" name="end_month" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 focus:border-slate-900 focus:outline-none">
            <?php foreach ($monthNames as $monthNumber => $monthName): ?>
              <option value="<?php echo (int) $monthNumber; ?>" <?php echo $monthNumber === $selectedEndMonth ? 'selected' : ''; ?>><?php echo htmlspecialchars($monthName); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" name="generate_report" value="1" class="rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-emerald-700">Show report</button>
        <a href="admin_growth.php" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-50">Reset</a>
      </form>
    </section>

    <div id="growthRangeModal" class="<?php echo $showGrowthReportModal ? 'fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4' : 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4'; ?>">
      <div class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-2xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500"><?php echo $reportType === 'daily' ? 'Selected day' : ($reportType === 'yearly' ? 'Selected year' : 'Selected range'); ?></p>
            <h2 class="mt-2 text-2xl font-semibold text-slate-900">
              <?php
                if ($reportType === 'daily') {
                  echo htmlspecialchars(date('d M Y', strtotime($reportDate)));
                } elseif ($reportType === 'yearly') {
                  echo 'January ' . (int) $selectedYear . ' - December ' . (int) $selectedYear;
                } else {
                  echo htmlspecialchars($monthNames[$selectedStartMonth]) . ' ' . (int) $selectedYear . ' - ' . htmlspecialchars($monthNames[$selectedEndMonth]) . ' ' . (int) $selectedYear;
                }
              ?>
            </h2>
          </div>
          <button type="button" id="closeGrowthReportModal" class="rounded-full border border-slate-200 px-3 py-1 text-xl font-semibold text-slate-600 transition hover:bg-slate-100">×</button>
        </div>
        <div class="min-h-0 overflow-y-auto px-6 py-5">
          <div class="mb-5 rounded-[1.75rem] border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between gap-4">
              <div class="flex items-center gap-4">
                <img src="colour_logo.jpg" alt="Smart POS Demo" class="h-20 w-20 rounded-2xl object-cover border border-slate-200 bg-white">
              </div>
              <div class="text-right">
                <p class="text-xl font-bold tracking-wide text-[#7f1d1d]">Smart POS Demo</p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-[0.2em] text-[#1683ff]">Business Growth Report</p>
              </div>
            </div>
            <div class="mt-5 border-b-2 border-slate-900 pb-2 text-center">
              <h2 class="text-2xl font-bold uppercase tracking-[0.25em] text-slate-900">
                <?php
                  if ($reportType === 'daily') {
                    echo 'Daily growth report';
                  } else {
                    $titleMonthText = '';
                    if ($reportType === 'yearly') {
                      $titleMonthText = 'Growth report – January to December';
                    } else {
                      $titleMonthText = 'Growth report – ' . htmlspecialchars($monthNames[$selectedStartMonth]) . ' to ' . htmlspecialchars($monthNames[$selectedEndMonth]);
                    }
                    echo htmlspecialchars($titleMonthText);
                  }
                ?>
              </h2>
            </div>
          </div>
          <div class="mb-4 rounded-3xl bg-slate-50 px-4 py-3">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500"><?php echo $reportType === 'daily' ? 'Daily total' : 'Range totals'; ?></p>
            <p class="mt-1 text-xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($growthRevenueTotal)); ?> sales</p>
          </div>
          <div class="overflow-x-auto rounded-3xl border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="bg-slate-900 text-white">
                <tr>
                  <th class="px-4 py-3 text-left font-semibold"><?php echo $reportType === 'daily' ? 'Date' : 'Month'; ?></th>
                  <th class="px-4 py-3 text-right font-semibold">Sales</th>
                  <th class="px-4 py-3 text-right font-semibold">Expenses</th>
                  <th class="px-4 py-3 text-right font-semibold">Gross profit</th>
                  <th class="px-4 py-3 text-right font-semibold">Net profit</th>
                  <th class="px-4 py-3 text-right font-semibold">Sales count</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 bg-white">
                <?php foreach ($growthReportRows as $row): ?>
                  <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars($reportType === 'daily' ? ($row['day_label'] ?? date('d M Y', strtotime($reportDate))) : $row['month_name']); ?></td>
                    <td class="px-4 py-3 text-right text-slate-700"><?php echo htmlspecialchars(formatCurrency($row['revenue'])); ?></td>
                    <td class="px-4 py-3 text-right text-slate-700"><?php echo htmlspecialchars(formatCurrency($row['expenses'])); ?></td>
                    <td class="px-4 py-3 text-right font-semibold <?php echo htmlspecialchars(profitColorClass((float) $row['gross_profit'])); ?>"><?php echo htmlspecialchars(formatCurrency($row['gross_profit'])); ?></td>
                    <td class="px-4 py-3 text-right font-semibold <?php echo htmlspecialchars(profitColorClass((float) $row['net_profit'])); ?>"><?php echo htmlspecialchars(formatCurrency($row['net_profit'])); ?></td>
                    <td class="px-4 py-3 text-right text-slate-700"><?php echo (int) $row['sales_count']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot class="bg-slate-50">
                <tr>
                  <th class="px-4 py-3 text-left font-semibold"><?php echo $reportType === 'daily' ? 'Daily' : 'Total'; ?></th>
                  <th class="px-4 py-3 text-right font-semibold"><?php echo htmlspecialchars(formatCurrency($growthRevenueTotal)); ?></th>
                  <th class="px-4 py-3 text-right font-semibold"><?php echo htmlspecialchars(formatCurrency($growthExpenseTotal)); ?></th>
                  <th class="px-4 py-3 text-right font-semibold <?php echo htmlspecialchars(profitColorClass($growthRevenueTotal - $growthCostTotal)); ?>"><?php echo htmlspecialchars(formatCurrency($growthRevenueTotal - $growthCostTotal)); ?></th>
                  <th class="px-4 py-3 text-right font-semibold <?php echo htmlspecialchars(profitColorClass($growthProfitTotal)); ?>"><?php echo htmlspecialchars(formatCurrency($growthProfitTotal)); ?></th>
                  <th class="px-4 py-3 text-right font-semibold">-</th>
                </tr>
              </tfoot>
            </table>
          </div>
          <?php if ($reportType === 'monthly' && $selectedStartMonth === $selectedEndMonth): ?>
            <div class="mt-8">
              <h3 class="text-xl font-semibold text-slate-900">Retail sales (quantity 6 or less)</h3>
              <div class="mt-3 overflow-x-auto rounded-3xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                  <thead class="bg-slate-900 text-white"><tr><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Product</th><th class="px-4 py-3 text-right">Qty</th><th class="px-4 py-3 text-left">Sale type</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3 text-right">Profit</th></tr></thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <?php $retailStatementRows = array_filter($monthlySalesStatement, static fn (array $row): bool => (float) ($row['quantity'] ?? 0) <= 6); ?>
                    <?php if ($retailStatementRows === []): ?>
                      <tr><td colspan="6" class="px-4 py-4 text-center text-slate-500">No retail sales recorded for this month.</td></tr>
                    <?php else: ?>
                      <?php foreach ($retailStatementRows as $statementRow): ?>
                        <?php $statementProfit = (float) ($statementRow['line_total'] ?? 0) - (float) ($statementRow['cost_total'] ?? 0); ?>
                        <tr><td class="px-4 py-3"><?php echo htmlspecialchars((string) $statementRow['sale_date']); ?></td><td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars((string) $statementRow['product_name']); ?></td><td class="px-4 py-3 text-right"><?php echo htmlspecialchars(number_format((float) ($statementRow['quantity'] ?? 0), 3)); ?></td><td class="px-4 py-3">Retail</td><td class="px-4 py-3 text-right"><?php echo htmlspecialchars(formatCurrency($statementRow['line_total'] ?? 0)); ?></td><td class="px-4 py-3 text-right font-semibold <?php echo htmlspecialchars(profitColorClass($statementProfit)); ?>"><?php echo htmlspecialchars(formatCurrency($statementProfit)); ?></td></tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <h3 class="mt-8 text-xl font-semibold text-slate-900">Wholesale sales (quantity greater than 6)</h3>
              <div class="mt-3 overflow-x-auto rounded-3xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                  <thead class="bg-slate-900 text-white"><tr><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Product</th><th class="px-4 py-3 text-right">Qty</th><th class="px-4 py-3 text-left">Sale type</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3 text-right">Profit</th></tr></thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <?php $wholesaleStatementRows = array_filter($monthlySalesStatement, static fn (array $row): bool => (float) ($row['quantity'] ?? 0) > 6); ?>
                    <?php if ($wholesaleStatementRows === []): ?>
                      <tr><td colspan="6" class="px-4 py-4 text-center text-slate-500">No wholesale sales recorded for this month.</td></tr>
                    <?php else: ?>
                      <?php foreach ($wholesaleStatementRows as $statementRow): ?>
                        <?php $statementProfit = (float) ($statementRow['line_total'] ?? 0) - (float) ($statementRow['cost_total'] ?? 0); ?>
                        <tr><td class="px-4 py-3"><?php echo htmlspecialchars((string) $statementRow['sale_date']); ?></td><td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars((string) $statementRow['product_name']); ?></td><td class="px-4 py-3 text-right"><?php echo htmlspecialchars(number_format((float) ($statementRow['quantity'] ?? 0), 3)); ?></td><td class="px-4 py-3">Wholesale</td><td class="px-4 py-3 text-right"><?php echo htmlspecialchars(formatCurrency($statementRow['line_total'] ?? 0)); ?></td><td class="px-4 py-3 text-right font-semibold <?php echo htmlspecialchars(profitColorClass($statementProfit)); ?>"><?php echo htmlspecialchars(formatCurrency($statementProfit)); ?></td></tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <h3 class="mt-8 text-xl font-semibold text-slate-900">Expenses incurred</h3>
              <div class="mt-3 overflow-x-auto rounded-3xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                  <thead class="bg-slate-900 text-white"><tr><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Description</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-right">Amount</th></tr></thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <?php if ($monthlyExpenseStatement === []): ?>
                      <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">No expenses recorded for this month.</td></tr>
                    <?php else: ?>
                      <?php foreach ($monthlyExpenseStatement as $expenseRow): ?>
                        <tr><td class="px-4 py-3"><?php echo htmlspecialchars((string) $expenseRow['expense_date']); ?></td><td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars((string) $expenseRow['description']); ?></td><td class="px-4 py-3"><?php echo htmlspecialchars((string) $expenseRow['expense_type']); ?></td><td class="px-4 py-3 text-right"><?php echo htmlspecialchars(formatCurrency($expenseRow['amount'] ?? 0)); ?></td></tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                  <tfoot class="bg-slate-50"><tr><th colspan="3" class="px-4 py-3 text-right">Total expenses</th><th class="px-4 py-3 text-right"><?php echo htmlspecialchars(formatCurrency($monthlyStatementExpenses)); ?></th></tr></tfoot>
                </table>
              </div>
              <h3 class="mt-8 text-xl font-semibold text-slate-900">Monthly analysis</h3>
              <div class="mt-3 overflow-x-auto rounded-3xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                  <thead class="bg-slate-900 text-white"><tr><th class="px-4 py-3 text-left">Category</th><th class="px-4 py-3 text-right">Amount sold</th><th class="px-4 py-3 text-right">Profit</th></tr></thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <tr><td class="px-4 py-3 font-semibold">Retail sales</td><td class="px-4 py-3 text-right"><?php echo htmlspecialchars(formatCurrency($monthlyRetailAmount)); ?></td><td class="px-4 py-3 text-right font-semibold <?php echo htmlspecialchars(profitColorClass($monthlyRetailProfit)); ?>"><?php echo htmlspecialchars(formatCurrency($monthlyRetailProfit)); ?></td></tr>
                    <tr><td class="px-4 py-3 font-semibold">Wholesale sales</td><td class="px-4 py-3 text-right"><?php echo htmlspecialchars(formatCurrency($monthlyWholesaleAmount)); ?></td><td class="px-4 py-3 text-right font-semibold <?php echo htmlspecialchars(profitColorClass($monthlyWholesaleProfit)); ?>"><?php echo htmlspecialchars(formatCurrency($monthlyWholesaleProfit)); ?></td></tr>
                    <?php $monthlyNetProfit = $monthlyRetailProfit + $monthlyWholesaleProfit - $monthlyStatementExpenses; ?>
                    <tr class="bg-slate-50"><td class="px-4 py-3 font-bold">Total profit less expenses</td><td class="px-4 py-3 text-right font-bold"><?php echo htmlspecialchars(formatCurrency($monthlyRetailAmount + $monthlyWholesaleAmount)); ?></td><td class="px-4 py-3 text-right font-bold <?php echo htmlspecialchars(profitColorClass($monthlyNetProfit)); ?>"><?php echo htmlspecialchars(formatCurrency($monthlyNetProfit)); ?></td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-slate-500"><?php echo htmlspecialchars($todayNetLabel); ?></p>
        <p class="mt-3 text-3xl font-semibold <?php echo htmlspecialchars($todayNetClass); ?>"><?php echo htmlspecialchars(formatCurrency($todayNetProfit)); ?></p>
        <p class="mt-2 text-sm text-slate-600">
          <?php echo $todayNetCompare['difference'] >= 0 ? 'Up' : 'Down'; ?> <?php echo htmlspecialchars(formatCurrency(abs($todayNetCompare['difference']))); ?> since yesterday
        </p>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-slate-500"><?php echo htmlspecialchars($weekNetLabel); ?></p>
        <p class="mt-3 text-3xl font-semibold <?php echo htmlspecialchars($weekNetClass); ?>"><?php echo htmlspecialchars(formatCurrency($weekNetProfit)); ?></p>
        <p class="mt-2 text-sm text-slate-600">
          <?php echo $weekNetCompare['difference'] >= 0 ? 'Up' : 'Down'; ?> <?php echo htmlspecialchars(formatCurrency(abs($weekNetCompare['difference']))); ?> since last week
        </p>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-slate-500"><?php echo htmlspecialchars($monthNetLabel); ?></p>
        <p class="mt-3 text-3xl font-semibold <?php echo htmlspecialchars($monthNetClass); ?>"><?php echo htmlspecialchars(formatCurrency($monthNetProfit)); ?></p>
        <p class="mt-2 text-sm text-slate-600">
          <?php echo $monthNetCompare['difference'] >= 0 ? 'Up' : 'Down'; ?> <?php echo htmlspecialchars(formatCurrency(abs($monthNetCompare['difference']))); ?> since last month
        </p>
      </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
      <section class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-semibold text-slate-900">Revenue & profit</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Today revenue</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($todayStats['revenue'])); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Today cost</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($todayStats['cost_basis'])); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Week revenue</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($weekStats['revenue'])); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Week cost</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($weekStats['cost_basis'])); ?></p>
          </div>
        </div>
      </section>

      <section class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-semibold text-slate-900">Expenses & liabilities</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Today expenses</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($todayExpenses)); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Week expenses</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($weekExpenses)); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Month expenses</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($monthExpenses)); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Payroll payouts</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($monthSalaryExpenses)); ?></p>
            <p class="mt-1 text-xs text-slate-500">This month salary payments are included in net profit.</p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Outstanding supplier balance</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($outstandingSupplierBalance)); ?></p>
          </div>
        </div>
      </section>

      <section class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-semibold text-slate-900">Debt & receivables</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-2">
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Outstanding customer debt</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($outstandingDebt)); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Debt issued this month</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($monthDebtIssued)); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Debt collected today</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($todayDebtCollected)); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Debt collected this week</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($weekDebtCollected)); ?></p>
          </div>
        </div>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-2">
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Debt issued today</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($todayDebtIssued)); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <p class="text-sm text-slate-500">Debt collected this month</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo htmlspecialchars(formatCurrency($monthDebtCollected)); ?></p>
          </div>
        </div>
      </section>
    </div>

    <section class="mt-6 rounded-[1.5rem] border border-rose-200 bg-rose-50 p-6 shadow-sm">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="text-xl font-semibold text-rose-900">Inventory losses</h2>
          <p class="mt-1 text-sm text-rose-700">Expired stock automatically removed from active inventory and listed here as a loss.</p>
        </div>
        <span class="rounded-full bg-white px-3 py-1 text-sm font-semibold text-rose-700"><?php echo count($expiredStockLosses); ?> item(s)</span>
      </div>
      <?php if (!empty($expiredStockLosses)): ?>
        <div class="mt-5 overflow-hidden rounded-3xl border border-rose-200 bg-white">
          <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-rose-100 text-sm">
            <thead class="bg-rose-50 text-left text-rose-800">
              <tr>
                <th class="px-4 py-3 font-semibold">Product</th>
                <th class="px-4 py-3 font-semibold">Qty lost</th>
                <th class="px-4 py-3 font-semibold">Expiry date</th>
                <th class="px-4 py-3 font-semibold">Recorded</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-rose-100 bg-white">
              <?php foreach ($expiredStockLosses as $loss): ?>
                <tr>
                  <td class="px-4 py-3 font-medium text-slate-900"><?php echo htmlspecialchars($loss['product_name']); ?></td>
                  <td class="px-4 py-3 text-slate-700"><?php echo htmlspecialchars((string) $loss['quantity']); ?></td>
                  <td class="px-4 py-3 text-slate-700"><?php echo htmlspecialchars($loss['expiry_date']); ?></td>
                  <td class="px-4 py-3 text-slate-700"><?php echo htmlspecialchars($loss['recorded_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </div>
      <?php else: ?>
        <p class="mt-4 text-sm text-rose-700">No expired stock losses have been recorded.</p>
      <?php endif; ?>
    </section>
  </div>
  <script>
    const growthRangeModal = document.getElementById('growthRangeModal');
    const closeGrowthReportModal = document.getElementById('closeGrowthReportModal');
    const growthFilterForm = document.getElementById('growthReportFilterForm');
    const growthReportType = document.getElementById('growthReportType');
    const growthStartMonthField = document.getElementById('growthStartMonthField');
    const growthEndMonthField = document.getElementById('growthEndMonthField');
    const growthDateField = document.getElementById('growthDateField');

    function updateGrowthFilterFields() {
      const type = growthReportType?.value || 'monthly';
      const isDaily = type === 'daily';
      const isMonthly = type === 'monthly';
      [growthStartMonthField, growthEndMonthField].forEach((field) => {
        field?.classList.toggle('hidden', !isMonthly);
      });
      growthDateField?.classList.toggle('hidden', !isDaily);
    }

    growthReportType?.addEventListener('change', updateGrowthFilterFields);
    updateGrowthFilterFields();

    document.querySelectorAll('[data-range-preset]').forEach((button) => {
      button.addEventListener('click', () => {
        const now = new Date();
        const yearInput = growthFilterForm?.querySelector('[name="year"]');
        const reportTypeInput = growthFilterForm?.querySelector('[name="report_type"]');
        const reportDateInput = growthFilterForm?.querySelector('[name="report_date"]');
        const startMonthInput = growthFilterForm?.querySelector('[name="start_month"]');
        const endMonthInput = growthFilterForm?.querySelector('[name="end_month"]');
        const preset = button.dataset.rangePreset;
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth() + 1;

        if (yearInput) yearInput.value = String(currentYear);
        if (preset === 'today') {
          if (reportTypeInput) reportTypeInput.value = 'daily';
          if (reportDateInput) reportDateInput.value = now.toISOString().slice(0, 10);
        } else if (preset === 'this-month') {
          if (reportTypeInput) reportTypeInput.value = 'monthly';
          if (startMonthInput) startMonthInput.value = String(currentMonth);
          if (endMonthInput) endMonthInput.value = String(currentMonth);
        } else if (preset === 'last-month') {
          const lastMonth = currentMonth === 1 ? 12 : currentMonth - 1;
          if (reportTypeInput) reportTypeInput.value = 'monthly';
          if (yearInput && currentMonth === 1) yearInput.value = String(currentYear - 1);
          if (startMonthInput) startMonthInput.value = String(lastMonth);
          if (endMonthInput) endMonthInput.value = String(lastMonth);
        } else if (preset === 'this-year') {
          if (reportTypeInput) reportTypeInput.value = 'yearly';
        }

        updateGrowthFilterFields();
        growthFilterForm?.requestSubmit();
      });
    });

    document.getElementById('printGrowthReport')?.addEventListener('click', () => {
      if (growthRangeModal && !growthRangeModal.classList.contains('hidden')) {
        window.print();
      } else {
        window.print();
      }
    });

    if (closeGrowthReportModal && growthRangeModal) {
      closeGrowthReportModal.addEventListener('click', () => {
        growthRangeModal.classList.add('hidden');
        growthRangeModal.classList.remove('flex');
      });
    }

    if (growthRangeModal) {
      growthRangeModal.addEventListener('click', (event) => {
        if (event.target === growthRangeModal) {
          growthRangeModal.classList.add('hidden');
          growthRangeModal.classList.remove('flex');
        }
      });
    }

    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mobileMenuClose = document.getElementById('mobileMenuClose');
    const mobileMenu = document.getElementById('mobileMenu');

    if (mobileMenuToggle && mobileMenu) {
      mobileMenuToggle.addEventListener('click', () => {
        mobileMenu.classList.toggle('hidden');
        document.body.classList.toggle('overflow-hidden', !mobileMenu.classList.contains('hidden'));
      });
    }

    if (mobileMenuClose && mobileMenu) {
      mobileMenuClose.addEventListener('click', () => {
        mobileMenu.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      });
    }
  </script>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
