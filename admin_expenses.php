<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $expenseTitle = trim((string) ($_POST['expense_title'] ?? ''));
    $expenseCategory = strtolower(trim((string) ($_POST['expense_category'] ?? 'general')));
    $expenseAmountRaw = trim((string) ($_POST['expense_amount'] ?? ''));
    $expenseDate = trim((string) ($_POST['expense_date'] ?? date('Y-m-d')));
    $expenseNotes = trim((string) ($_POST['expense_notes'] ?? ''));
    $paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cash')));
    $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
    $cashDrawerMovement = $paymentMethod === 'cash';
    $cashDrawerAmountRaw = trim((string) ($_POST['cash_drawer_amount'] ?? ''));
    $allowedCategories = ['general', 'rent', 'utilities', 'transport', 'salary', 'marketing', 'maintenance', 'staff_lunch', 'other'];
    $allowedPaymentMethods = ['cash', 'bank', 'card', 'other'];

    if (!in_array($expenseCategory, $allowedCategories, true)) {
        $expenseCategory = 'general';
    }
    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $paymentMethod = 'cash';
    }

    $expenseErrors = [];
    if ($expenseTitle === '') {
        $expenseErrors[] = 'Expense title is required.';
    }
    if ($expenseAmountRaw === '' || !is_numeric($expenseAmountRaw) || (float) $expenseAmountRaw <= 0) {
        $expenseErrors[] = 'Enter a valid expense amount.';
    }
    if ($expenseDate === '' || strtotime($expenseDate) === false) {
        $expenseErrors[] = 'Enter a valid expense date.';
    }
    if ($cashDrawerMovement && $cashDrawerAmountRaw !== '' && (!is_numeric($cashDrawerAmountRaw) || (float) $cashDrawerAmountRaw <= 0)) {
        $expenseErrors[] = 'Enter a valid positive cash drawer amount.';
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $expenseErrors[] = 'Your session expired. Refresh the page and try again.';
    }

    if ($expenseErrors === []) {
        try {
            $expenseAmount = number_format((float) $expenseAmountRaw, 2, '.', '');
            $cashDrawerAmount = $cashDrawerMovement ? ($cashDrawerAmountRaw !== '' ? number_format((float) $cashDrawerAmountRaw, 2, '.', '') : $expenseAmount) : '0.00';

            $stmt = $pdo->prepare('INSERT INTO business_expenses (title, category, amount, payment_method, payment_reference, cash_drawer_movement, cash_drawer_amount, expense_date, notes, created_by) VALUES (:title, :category, :amount, :payment_method, :payment_reference, :cash_drawer_movement, :cash_drawer_amount, :expense_date, :notes, :created_by)');
            $stmt->execute([
                'title' => $expenseTitle,
                'category' => $expenseCategory,
                'amount' => $expenseAmount,
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
                'cash_drawer_movement' => $cashDrawerMovement ? 1 : 0,
                'cash_drawer_amount' => $cashDrawerAmount,
                'expense_date' => $expenseDate,
                'notes' => $expenseNotes !== '' ? $expenseNotes : null,
                'created_by' => $_SESSION['user_name'] ?? 'admin',
            ]);
            $_SESSION['expense_flash'] = ['type' => 'success', 'message' => 'Expense saved successfully.'];
            header('Location: admin_expenses.php');
            exit;
        } catch (Throwable $e) {
            $_SESSION['expense_flash'] = ['type' => 'error', 'message' => 'Unable to save expense: ' . $e->getMessage()];
            header('Location: admin_expenses.php');
            exit;
        }
    }

    $_SESSION['expense_flash'] = ['type' => 'error', 'message' => implode(' ', $expenseErrors)];
    header('Location: admin_expenses.php');
    exit;
}

$expenseFlash = $_SESSION['expense_flash'] ?? null;
unset($_SESSION['expense_flash']);

$reportMonth = trim((string) ($_GET['report_month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth)) {
    $reportMonth = date('Y-m');
}

$businessExpenses = $pdo->query("SELECT * FROM business_expenses ORDER BY expense_date DESC, created_at DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$salaryPayments = $pdo->query("SELECT sp.*, u.full_name AS employee_name
    FROM salary_payments sp
    LEFT JOIN users u ON u.id = sp.employee_id
    WHERE sp.payment_status = 'paid'
    ORDER BY sp.payment_date DESC, sp.created_at DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$expenseRows = [];
foreach ($businessExpenses as $expense) {
    $expenseRows[] = [
        'id' => (int) ($expense['id'] ?? 0),
        'title' => (string) ($expense['title'] ?? 'Expense'),
        'category' => (string) ($expense['category'] ?? 'general'),
        'amount' => (float) ($expense['amount'] ?? 0),
        'payment_method' => (string) ($expense['payment_method'] ?? 'cash'),
        'payment_reference' => $expense['payment_reference'] ?? null,
        'cash_drawer_movement' => (int) ($expense['cash_drawer_movement'] ?? 0),
        'cash_drawer_amount' => (float) ($expense['cash_drawer_amount'] ?? 0),
        'expense_date' => (string) ($expense['expense_date'] ?? date('Y-m-d')),
        'notes' => $expense['notes'] ?? null,
        'created_at' => (string) ($expense['created_at'] ?? ''),
        'source' => 'business',
    ];
}
foreach ($salaryPayments as $payment) {
    $expenseRows[] = [
        'id' => (int) ($payment['id'] ?? 0),
        'title' => 'Salary payout' . ((string) ($payment['employee_name'] ?? '') !== '' ? ' - ' . $payment['employee_name'] : ''),
        'category' => 'salary',
        'amount' => (float) ($payment['net_pay'] ?? 0),
        'payment_method' => (string) ($payment['payment_method'] ?? 'bank'),
        'payment_reference' => $payment['payroll_reference'] ?? null,
        'expense_date' => (string) ($payment['payment_date'] ?? date('Y-m-d')),
        'notes' => $payment['notes'] ?? null,
        'created_at' => (string) ($payment['created_at'] ?? ''),
        'source' => 'salary',
    ];
}

usort($expenseRows, static function ($left, $right): int {
    $leftDate = (string) ($left['expense_date'] ?? '');
    $rightDate = (string) ($right['expense_date'] ?? '');
    if ($leftDate !== $rightDate) {
        return strcmp($rightDate, $leftDate);
    }
    $leftCreated = (string) ($left['created_at'] ?? '');
    $rightCreated = (string) ($right['created_at'] ?? '');
    return strcmp($rightCreated, $leftCreated);
});

$categoryBreakdown = [];
foreach ($expenseRows as $row) {
    $category = $row['category'] ?? 'general';
    if (!isset($categoryBreakdown[$category])) {
        $categoryBreakdown[$category] = ['category' => $category, 'total_amount' => 0.0, 'expense_count' => 0];
    }
    $categoryBreakdown[$category]['total_amount'] += (float) ($row['amount'] ?? 0);
    $categoryBreakdown[$category]['expense_count'] += 1;
}
$categoryBreakdown = array_values($categoryBreakdown);
usort($categoryBreakdown, static function ($left, $right): int {
    return ((float) ($right['total_amount'] ?? 0) <=> (float) ($left['total_amount'] ?? 0));
});

$maxCategoryTotal = 0.0;
foreach ($categoryBreakdown as $row) {
    $maxCategoryTotal = max($maxCategoryTotal, (float) ($row['total_amount'] ?? 0));
}

$dailyExpenseSummary = [];
foreach ($expenseRows as $row) {
    $day = (string) ($row['expense_date'] ?? date('Y-m-d'));
    $key = $day;
    if (!isset($dailyExpenseSummary[$key])) {
        $dailyExpenseSummary[$key] = ['expense_day' => $day, 'total_amount' => 0.0, 'expense_count' => 0];
    }
    $dailyExpenseSummary[$key]['total_amount'] += (float) ($row['amount'] ?? 0);
    $dailyExpenseSummary[$key]['expense_count'] += 1;
}
$dailyExpenseSummary = array_values($dailyExpenseSummary);
usort($dailyExpenseSummary, static function ($left, $right): int {
    return strcmp((string) ($right['expense_day'] ?? ''), (string) ($left['expense_day'] ?? ''));
});
$dailyExpenseSummary = array_slice($dailyExpenseSummary, 0, 7);

$weeklyExpenseSummary = [];
foreach ($expenseRows as $row) {
    $day = (string) ($row['expense_date'] ?? date('Y-m-d'));
    $weekKey = date('Y-W', strtotime($day));
    $weekStart = date('Y-m-d', strtotime($weekKey . '-1'));
    $weekEnd = date('Y-m-d', strtotime($weekKey . '-7'));
    if (!isset($weeklyExpenseSummary[$weekKey])) {
        $weeklyExpenseSummary[$weekKey] = ['week_key' => $weekKey, 'week_start' => $weekStart, 'week_end' => $weekEnd, 'total_amount' => 0.0, 'expense_count' => 0];
    }
    $weeklyExpenseSummary[$weekKey]['total_amount'] += (float) ($row['amount'] ?? 0);
    $weeklyExpenseSummary[$weekKey]['expense_count'] += 1;
}
$weeklyExpenseSummary = array_values($weeklyExpenseSummary);
usort($weeklyExpenseSummary, static function ($left, $right): int {
    return strcmp((string) ($right['week_key'] ?? ''), (string) ($left['week_key'] ?? ''));
});
$weeklyExpenseSummary = array_slice($weeklyExpenseSummary, 0, 8);

$allExpenses = $expenseRows;
$printExpenseRows = array_values(array_filter($allExpenses, static function (array $expense) use ($reportMonth): bool {
    return str_starts_with((string) ($expense['expense_date'] ?? ''), $reportMonth . '-');
}));
$printExpenseTotal = array_reduce($printExpenseRows, static fn($sum, $expense) => $sum + (float) ($expense['amount'] ?? 0), 0.0);
$totalExpenses = array_reduce($allExpenses, static fn($sum, $expense) => $sum + (float) ($expense['amount'] ?? 0), 0.0);
$csrfToken = csrfToken();
$salaryExpenseTotal = array_reduce($salaryPayments, static fn($sum, $payment) => $sum + (float) ($payment['net_pay'] ?? 0), 0.0);
$salaryExpenseCount = count($salaryPayments);

$formatMoney = static fn($value): string => 'KES ' . number_format((float) $value, 2);
$categoryColors = ['#f97316', '#f59e0b', '#ef4444', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Expense Report — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/splash-screen.css">
  <style>
    .expenses-print-report {
      display: none;
    }

    @media print {
      @page {
        size: auto;
        margin: 12mm;
      }

      body {
        background: #fff !important;
      }

      body > *:not(.expenses-print-report) {
        display: none !important;
      }

      .expenses-print-report {
        display: block !important;
        color: #14213d;
      }

      .expenses-print-report table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
      }

      .expenses-print-report th {
        background: #123b78 !important;
        color: #fff !important;
        text-align: left;
      }

      .expenses-print-report th,
      .expenses-print-report td {
        border: 1px solid #d8e3f2;
        padding: 7px;
      }

      .expenses-print-report tr:nth-child(even) {
        background: #f6f9fe !important;
      }
    }
  </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen splash-loading">
  <div class="splash-screen" id="splashScreen">
    <div class="splash-screen-logo">
      <img src="colour_logo.jpg" alt="Logo">
    </div>
    <div class="splash-screen-spinner"></div>
    <div class="splash-screen-text">LOADING</div>
  </div>

  <div class="admin-page-shell container mx-auto px-4 py-6">
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 flex flex-col gap-3"></div>
    <div class="mb-6 flex items-center gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
      <img src="colour_logo.jpg" alt="POS2 logo" class="h-14 w-14 rounded-2xl object-cover shadow-sm">
      <div>
        <p class="text-lg font-semibold text-slate-900">SMART POS SYSTEM</p>
        <p class="text-sm text-slate-500">Expense reporting</p>
      </div>
    </div>
    <header class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Expense report</h1>
        <p class="mt-1 text-sm text-slate-500 sm:text-base">Business spending overview</p>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="admin.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">Dashboard</a>
          <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">Staff / Cashiers</a>
          <a href="logout.php" class="rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Logout</a>
        </div>
      </div>
    </header>

    <div id="mobileMenu" class="fixed inset-x-0 top-0 z-50 hidden bg-slate-50/95 p-4 shadow-lg backdrop-blur-sm border-b border-slate-200 sm:hidden">
      <div class="flex items-center justify-between gap-3 mb-4">
        <span class="text-base font-semibold">Menu</span>
        <button id="mobileMenuClose" type="button" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Close</button>
      </div>
      <div class="flex flex-col gap-3">
        <a href="admin.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Dashboard</a>
        <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Staff / Cashiers</a>
        <a href="logout.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 transition hover:bg-slate-100">Logout</a>
      </div>
    </div>

    <div class="mb-6 grid gap-6 md:grid-cols-4">
      <div class="rounded-2xl bg-white p-6 shadow-sm">
        <p class="text-sm text-slate-500">Total expenses</p>
        <div class="mt-3 text-3xl font-bold text-rose-700"><?php echo $formatMoney($totalExpenses); ?></div>
      </div>
      <div class="rounded-2xl bg-white p-6 shadow-sm">
        <p class="text-sm text-slate-500">Expense count</p>
        <div class="mt-3 text-3xl font-bold text-slate-900"><?php echo count($allExpenses); ?></div>
      </div>
      <div class="rounded-2xl bg-white p-6 shadow-sm">
        <p class="text-sm text-slate-500">Salary payouts</p>
        <div class="mt-3 text-3xl font-bold text-emerald-700"><?php echo $formatMoney($salaryExpenseTotal); ?></div>
        <div class="text-sm text-slate-600 mt-1"><?php echo $salaryExpenseCount; ?> payroll entries</div>
      </div>
      <div class="rounded-2xl bg-white p-6 shadow-sm">
        <p class="text-sm text-slate-500">Top category</p>
        <div class="mt-3 text-xl font-bold text-slate-900"><?php echo htmlspecialchars(ucfirst((string) ($categoryBreakdown[0]['category'] ?? 'No data'))); ?></div>
        <div class="text-sm text-slate-600 mt-1"><?php echo $formatMoney((float) ($categoryBreakdown[0]['total_amount'] ?? 0)); ?></div>
      </div>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
      <section class="rounded-2xl bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">Expense category breakdown</h2>
          <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">by category</span>
        </div>

        <?php if (count($categoryBreakdown) === 0): ?>
          <p class="text-slate-500">No expense records found yet.</p>
        <?php else: ?>
          <div class="space-y-4">
            <?php foreach ($categoryBreakdown as $index => $category): ?>
              <?php $barWidth = $maxCategoryTotal > 0 ? ((float) ($category['total_amount'] ?? 0) / $maxCategoryTotal) * 100 : 0; ?>
              <div>
                <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                  <span class="font-medium text-slate-700"><?php echo htmlspecialchars(ucfirst((string) ($category['category'] ?? 'General'))); ?></span>
                  <span class="text-slate-500"><?php echo $formatMoney((float) ($category['total_amount'] ?? 0)); ?></span>
                </div>
                <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100">
                  <div class="h-full rounded-full" style="width: <?php echo $barWidth; ?>%; background: <?php echo $categoryColors[$index % count($categoryColors)]; ?>;"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="rounded-2xl bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">Daily expense summary</h2>
          <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">last 7 days</span>
        </div>

        <?php if (count($dailyExpenseSummary) === 0): ?>
          <p class="text-slate-500">No daily expense summary available.</p>
        <?php else: ?>
          <div class="overflow-hidden rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm text-slate-700">
              <thead class="bg-slate-50 text-slate-500">
                <tr>
                  <th class="px-3 py-2">Date</th>
                  <th class="px-3 py-2">Expenses</th>
                  <th class="px-3 py-2 text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($dailyExpenseSummary as $row): ?>
                  <tr class="border-t border-slate-200">
                    <td class="px-3 py-2"><?php echo htmlspecialchars(date('d M Y', strtotime((string) ($row['expense_day'] ?? date('Y-m-d'))))); ?></td>
                    <td class="px-3 py-2"><?php echo (int) ($row['expense_count'] ?? 0); ?> item(s)</td>
                    <td class="px-3 py-2 text-right font-semibold text-slate-800"><?php echo $formatMoney((float) ($row['total_amount'] ?? 0)); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <section class="mb-6 rounded-2xl bg-white p-6 shadow-sm">
      <div class="mb-4 flex items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">Weekly expense summary</h2>
        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">last 8 weeks</span>
      </div>

      <?php if (count($weeklyExpenseSummary) === 0): ?>
        <p class="text-slate-500">No weekly expense summary available.</p>
      <?php else: ?>
        <div class="overflow-hidden rounded-xl border border-slate-200">
          <table class="w-full text-left text-sm text-slate-700">
            <thead class="bg-slate-50 text-slate-500">
              <tr>
                <th class="px-3 py-2">Week</th>
                <th class="px-3 py-2">Period</th>
                <th class="px-3 py-2">Entries</th>
                <th class="px-3 py-2 text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($weeklyExpenseSummary as $row): ?>
                <tr class="border-t border-slate-200">
                  <td class="px-3 py-2 font-medium text-slate-800">Week <?php echo date('W', strtotime((string) ($row['week_start'] ?? date('Y-m-d')))); ?></td>
                  <td class="px-3 py-2"><?php echo htmlspecialchars(date('d M', strtotime((string) ($row['week_start'] ?? date('Y-m-d')))) . ' - ' . date('d M Y', strtotime((string) ($row['week_end'] ?? date('Y-m-d'))))); ?></td>
                  <td class="px-3 py-2"><?php echo (int) ($row['expense_count'] ?? 0); ?></td>
                  <td class="px-3 py-2 text-right font-semibold text-slate-800"><?php echo $formatMoney((float) ($row['total_amount'] ?? 0)); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="rounded-2xl bg-white p-6 shadow-sm">
      <div class="mb-4 flex items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">Expense list</h2>
        <div class="flex items-center gap-2">
          <form id="expensePrintForm" method="get" class="flex items-center gap-2">
            <label for="reportMonth" class="sr-only">Report month</label>
            <input id="reportMonth" type="month" name="report_month" value="<?php echo htmlspecialchars($reportMonth, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700">
            <button type="button" id="printExpensesButton" class="rounded-xl bg-[#1683ff] px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-600">Print month</button>
          </form>
          <button type="button" id="openExpenseModalBtn" class="rounded-xl bg-rose-600 px-3 py-2 text-sm font-semibold text-white">Add expense</button>
          <a href="admin.php" class="rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white">Back to dashboard</a>
        </div>
      </div>

      <div class="overflow-x-auto rounded-xl border border-slate-200">
        <table class="w-full text-left text-sm text-slate-700">
          <thead class="bg-slate-50 text-slate-500">
            <tr>
              <th class="px-3 py-3">Title</th>
              <th class="px-3 py-3">Category</th>
              <th class="px-3 py-3">Payment</th>
              <th class="px-3 py-3">Cash out</th>
              <th class="px-3 py-3">Date</th>
              <th class="px-3 py-3">Notes</th>
              <th class="px-3 py-3 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($allExpenses) === 0): ?>
              <tr>
                <td colspan="7" class="px-3 py-4 text-slate-500">No expenses recorded yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($allExpenses as $expense): ?>
                <tr class="border-t border-slate-200">
                  <td class="px-3 py-3 font-medium text-slate-800"><?php echo htmlspecialchars($expense['title'] ?? 'Expense'); ?></td>
                  <td class="px-3 py-3"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($expense['category'] ?? 'general')))); ?></td>
                  <td class="px-3 py-3"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($expense['payment_method'] ?? 'cash')))); ?><?php echo !empty($expense['payment_reference']) ? ' · ' . htmlspecialchars((string) $expense['payment_reference']) : ''; ?></td>
                  <td class="px-3 py-3"><?php echo !empty($expense['cash_drawer_movement']) ? 'Yes · ' . $formatMoney((float) ($expense['cash_drawer_amount'] ?? 0)) : 'No'; ?></td>
                  <td class="px-3 py-3"><?php echo htmlspecialchars(date('d M Y', strtotime((string) ($expense['expense_date'] ?? date('Y-m-d'))))); ?></td>
                  <td class="px-3 py-3"><?php echo htmlspecialchars((string) ($expense['notes'] ?? '-')); ?></td>
                  <td class="px-3 py-3 text-right font-semibold text-slate-800"><?php echo $formatMoney((float) ($expense['amount'] ?? 0)); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <section class="expenses-print-report">
    <?php $reportMonthLabel = date('F Y', strtotime($reportMonth . '-01')); ?>
    <div style="border-bottom: 3px solid #7f1d1d; padding-bottom: 12px; margin-bottom: 18px;">
      <div style="display: flex; align-items: center; justify-content: space-between; gap: 18px;">
        <img src="colour_logo.jpg" alt="Smart POS Demo" style="width: 88px; height: 88px; object-fit: cover; border-radius: 14px; border: 1px solid #d8e3f2;">
        <div style="text-align: right;">
          <div style="color: #7f1d1d; font-size: 22px; font-weight: 700; letter-spacing: 0.04em;">Smart POS Demo</div>
          <div style="color: #1683ff; font-size: 13px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase;">Expenses Report</div>
          <div style="color: #64748b; font-size: 11px; margin-top: 4px;"><?php echo htmlspecialchars($reportMonthLabel); ?></div>
        </div>
      </div>
    </div>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Expense</th>
          <th>Category</th>
          <th>Payment</th>
          <th>Cash out</th>
          <th style="text-align: right;">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($printExpenseRows as $expense): ?>
          <tr>
            <td><?php echo htmlspecialchars(date('d M Y', strtotime((string) ($expense['expense_date'] ?? date('Y-m-d'))))); ?></td>
            <td><?php echo htmlspecialchars((string) ($expense['title'] ?? 'Expense')); ?></td>
            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($expense['category'] ?? 'general')))); ?></td>
            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($expense['payment_method'] ?? 'cash')))); ?><?php echo !empty($expense['payment_reference']) ? ' · ' . htmlspecialchars((string) $expense['payment_reference']) : ''; ?></td>
            <td><?php echo !empty($expense['cash_drawer_movement']) ? 'Yes · ' . $formatMoney((float) ($expense['cash_drawer_amount'] ?? 0)) : 'No'; ?></td>
            <td style="text-align: right; font-weight: 700;"><?php echo $formatMoney((float) ($expense['amount'] ?? 0)); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($printExpenseRows === []): ?>
          <tr><td colspan="6">No expenses recorded for this month.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="7" style="text-align: right; font-weight: 700;">Total expenses</td>
          <td style="text-align: right; font-weight: 700;"><?php echo $formatMoney($printExpenseTotal); ?></td>
        </tr>
      </tfoot>
    </table>
  </section>

  <div id="expenseModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-2 sm:p-4">
    <div class="max-h-[95vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
      <div class="flex items-start justify-between border-b border-slate-200 px-4 py-4 sm:px-6">
        <div>
          <h3 class="text-xl font-semibold text-slate-800">Add expense</h3>
          <p class="text-sm text-slate-500">Record a new business expense</p>
        </div>
        <button type="button" id="closeExpenseModal" class="rounded-full border border-slate-200 px-3 py-1 text-lg text-slate-600 hover:bg-slate-100">×</button>
      </div>
      <div class="p-6">
        <form method="POST" action="admin_expenses.php" class="grid gap-3 md:grid-cols-2">
          <input type="hidden" name="add_expense" value="1">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="md:col-span-2">
            <label class="mb-1 block text-sm font-medium text-slate-700">Expense title</label>
            <input type="text" name="expense_title" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-sky-500 focus:outline-none" placeholder="Rent, fuel, utilities, etc." required>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Category</label>
            <select name="expense_category" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-sky-500 focus:outline-none">
              <option value="general">General</option>
              <option value="rent">Rent</option>
              <option value="utilities">Utilities</option>
              <option value="transport">Transport</option>
              <option value="salary">Salary</option>
              <option value="marketing">Marketing</option>
              <option value="maintenance">Maintenance</option>
              <option value="staff_lunch">Staff Lunch</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Payment method</label>
            <select name="payment_method" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-sky-500 focus:outline-none">
              <option value="cash">Cash</option>
              <option value="bank">Bank</option>
              <option value="card">Card</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Amount (KES)</label>
            <input type="number" name="expense_amount" min="0.01" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-sky-500 focus:outline-none" placeholder="0.00" required>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Payment reference</label>
            <input type="text" name="payment_reference" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-sky-500 focus:outline-none" placeholder="Receipt / ref / transaction ID">
          </div>
          <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input type="checkbox" name="cash_drawer_movement" value="1" class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
              <span>This also reduced the POS cash drawer</span>
            </label>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
              <div>
                <label class="mb-1 block text-sm text-slate-600">Cash drawer amount (KES)</label>
                <input type="number" name="cash_drawer_amount" min="0.01" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-sky-500 focus:outline-none" placeholder="0.00">
              </div>
              <p class="text-sm text-slate-500">If left blank, the expense amount is used.</p>
            </div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Expense date</label>
            <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-sky-500 focus:outline-none">
          </div>
          <div class="md:col-span-2 flex justify-end gap-2">
            <button type="button" id="cancelExpenseModal" class="rounded-xl border border-slate-200 px-4 py-2.5 font-semibold text-slate-700">Cancel</button>
            <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2.5 font-semibold text-white shadow-sm hover:bg-rose-700">Save expense</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    const expenseFlash = <?php echo json_encode($expenseFlash ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function showToast(message, type = 'success') {
      const container = document.getElementById('toastContainer');
      if (!container || !message) {
        return;
      }

      const toast = document.createElement('div');
      const toneClasses = {
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        error: 'border-rose-200 bg-rose-50 text-rose-900'
      };
      toast.className = `max-w-sm rounded-2xl border px-4 py-3 shadow-lg ${toneClasses[type] || toneClasses.success}`;
      toast.innerHTML = `<div class="font-semibold">${type === 'error' ? 'Expense not saved' : 'Expense saved'}</div><div class="mt-1 text-sm">${message}</div>`;
      container.appendChild(toast);

      setTimeout(() => {
        toast.classList.add('opacity-0', 'transition-opacity', 'duration-500');
        setTimeout(() => toast.remove(), 500);
      }, 3200);
    }

    window.addEventListener('load', function() {
      const splashScreen = document.getElementById('splashScreen');
      if (splashScreen) {
        splashScreen.classList.add('hidden');
        document.body.classList.remove('splash-loading');
      }

      if (expenseFlash && expenseFlash.message) {
        showToast(expenseFlash.message, expenseFlash.type || 'success');
      }
    });

    const expenseModal = document.getElementById('expenseModal');
    const openExpenseModalBtn = document.getElementById('openExpenseModalBtn');
    const closeExpenseModal = document.getElementById('closeExpenseModal');
    const cancelExpenseModal = document.getElementById('cancelExpenseModal');
    const printExpensesButton = document.getElementById('printExpensesButton');

    [openExpenseModalBtn, closeExpenseModal, cancelExpenseModal].forEach((button) => {
      if (button) {
        button.addEventListener('click', () => {
          expenseModal.classList.toggle('hidden');
          expenseModal.classList.toggle('flex');
        });
      }
    });

    if (printExpensesButton) {
      printExpensesButton.addEventListener('click', () => {
        const reportMonth = document.getElementById('reportMonth');
        if (reportMonth && reportMonth.value) {
          const url = new URL(window.location.href);
          url.searchParams.set('report_month', reportMonth.value);
          window.location.href = url.toString();
        } else {
          window.print();
        }
      });
    }

    if (expenseModal) {
      expenseModal.addEventListener('click', (event) => {
        if (event.target === expenseModal) {
          expenseModal.classList.add('hidden');
          expenseModal.classList.remove('flex');
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
