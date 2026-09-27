<?php
session_start();
require __DIR__ . '/security.php';
requireCashierPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';
require __DIR__ . '/receipt.php';

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'Cashier');
$userRole = $_SESSION['user_role'] ?? 'cashier';

$errors = [];
$success = '';
$activeShift = null;
$isStaleShift = false;
$expectedCash = 0.0;
$shiftExpenses = [];
$cashExpenseTotal = 0.0;
$openingCashAfterExpenses = 0.0;
$totalCashOutflows = 0.0;
$openingCashUsed = 0.0;
$cashSalesUsed = 0.0;

$shiftStmt = $pdo->prepare('SELECT * FROM cashier_shifts WHERE cashier_id = :cashier_id AND status = :status ORDER BY created_at DESC LIMIT 1');
$shiftStmt->execute(['cashier_id' => $userId, 'status' => 'open']);
$activeShift = $shiftStmt->fetch();
if ($activeShift) {
    $isStaleShift = isShiftFromPreviousDay($activeShift);
    $showCloseForm = $isStaleShift || isset($_GET['close']);

    $shiftStart = $activeShift['started_at'] ?? $activeShift['created_at'];
    $cashSaleStmt = $pdo->prepare("SELECT COALESCE(SUM(sp.amount), 0) AS expected_sales
        FROM sale_payments sp
        JOIN sales s ON s.id = sp.sale_id
        WHERE sp.payment_method = 'cash' AND sp.status = 'success'
          AND s.payment_status = 'paid' AND s.shift_id = :shift_id");
    $cashSaleStmt->execute([
        'shift_id' => intval($activeShift['id']),
    ]);
    $expectedSales = floatval($cashSaleStmt->fetchColumn() ?? 0);
    $debtIssuedStmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) AS debt_issued FROM sales WHERE payment_method = :payment_method AND payment_status = :payment_status AND shift_id = :shift_id');
    $debtIssuedStmt->execute([
        'payment_method' => 'credit',
        'payment_status' => 'credit',
        'shift_id' => intval($activeShift['id']),
    ]);
    $debtIssued = floatval($debtIssuedStmt->fetchColumn() ?? 0);
    $debtCollectionStmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) AS debt_collections FROM sales WHERE shift_id = :shift_id AND payment_method = :payment_method AND payment_status = :payment_status AND notes LIKE :notes_pattern');
    $debtCollectionStmt->execute([
        'shift_id' => intval($activeShift['id']),
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'notes_pattern' => 'Payment for invoice(s):%'
    ]);
    $debtCollectedCash = floatval($debtCollectionStmt->fetchColumn() ?? 0);
    $quickPurchaseStmt = $pdo->prepare('SELECT COALESCE(SUM(total_cost), 0) AS quick_purchase_cost FROM quick_stock_purchases WHERE shift_id = :shift_id AND payment_method = :payment_method');
    $quickPurchaseStmt->execute([
        'shift_id' => intval($activeShift['id']),
        'payment_method' => 'cash',
    ]);
    $quickPurchaseCost = floatval($quickPurchaseStmt->fetchColumn() ?? 0);
    $cashExpenseStmt = $pdo->prepare("SELECT COALESCE(SUM(be.cash_drawer_amount), 0)
        FROM business_expenses be
        JOIN users eu ON eu.full_name = be.created_by
        WHERE eu.id = :cashier_id AND be.cash_drawer_movement = 1
          AND be.expense_date >= DATE(COALESCE(:started_at, :created_at))
          AND be.expense_date <= CURDATE()");
    $cashExpenseStmt->execute([
        'cashier_id' => intval($activeShift['cashier_id']),
        'started_at' => $activeShift['started_at'] ?? null,
        'created_at' => $activeShift['created_at'] ?? null,
    ]);
    $cashExpenseTotal = floatval($cashExpenseStmt->fetchColumn() ?? 0);
    $expenseListStmt = $pdo->prepare("SELECT be.title, be.amount, be.payment_method, be.cash_drawer_movement,
            be.cash_drawer_amount, be.expense_date
        FROM business_expenses be
        JOIN users eu ON eu.full_name = be.created_by
        WHERE eu.id = :cashier_id
          AND be.expense_date >= DATE(COALESCE(:started_at, :created_at))
          AND be.expense_date <= CURDATE()
        ORDER BY be.expense_date ASC, be.created_at ASC");
    $expenseListStmt->execute([
        'cashier_id' => intval($activeShift['cashier_id']),
        'started_at' => $activeShift['started_at'] ?? null,
        'created_at' => $activeShift['created_at'] ?? null,
    ]);
    $shiftExpenses = $expenseListStmt->fetchAll();
    $totalCashOutflows = $quickPurchaseCost + $cashExpenseTotal;
    $openingCashUsed = min((float) $activeShift['opening_cash'], $totalCashOutflows);
    $cashSalesUsed = max(0.0, $totalCashOutflows - $openingCashUsed);
    $openingCashAfterExpenses = max(0.0, (float) $activeShift['opening_cash'] - $totalCashOutflows);
    $expectedCash = getShiftAvailableCash($pdo, $activeShift);
    $expectedCashIfDebtPaid = $expectedCash + $debtCollectedCash + $debtIssued;
} else {
    $showCloseForm = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'start_shift') {
        $existingShift = getOpenShiftByCashier($pdo, (int) $userId);
        if ($existingShift) {
            $errors[] = isShiftFromPreviousDay($existingShift)
                ? 'Your previous shift is still open. Close it before starting a new shift.'
                : 'You already have an open shift.';
        }

        $openingCash = floatval(str_replace([',', '$'], '', trim($_POST['opening_cash'] ?? '0')));
        if (empty($errors) && $openingCash <= 0) {
            $errors[] = 'Opening cash must be greater than zero.';
        }
        if (empty($errors)) {
            $stmt = $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, cashier_username, cashier_name, opening_cash, status, created_at, started_at) VALUES (:cashier_id, :cashier_username, :cashier_name, :opening_cash, :status, NOW(), NOW())');
            $stmt->execute([
                'cashier_id' => $userId,
                'cashier_username' => $_SESSION['username'] ?? null,
                'cashier_name' => $userName,
                'opening_cash' => $openingCash,
                'status' => 'open',
            ]);
            $_SESSION['shift_message'] = 'Shift started successfully. Welcome back, ' . $userName . '!';
            header('Location: index.php');
            exit;
        }
    } elseif ($action === 'close_shift' && $activeShift) {
        $countedCash = floatval(str_replace([',', '$'], '', trim($_POST['counted_cash'] ?? '0')));
        $varianceReason = trim((string) ($_POST['variance_reason'] ?? ''));
        $safeDropAmount = floatval(str_replace([',', '$'], '', trim($_POST['safe_drop_amount'] ?? '0')));
        $nextDayFloat = floatval(str_replace([',', '$'], '', trim($_POST['next_day_float'] ?? '0')));

        if ($countedCash < 0) {
            $errors[] = 'Counted cash is required.';
        }
        if ($expectedCash < 0) {
            $errors[] = 'Expected cash is required.';
        }

        if (empty($errors)) {
            $variance = calculateShiftVariance($expectedCash, $countedCash);
            $status = getShiftStatusLabel($expectedCash, $countedCash);
            $priority = getShiftReviewPriority($expectedCash, $countedCash);

            $salesStmt = $pdo->prepare("SELECT s.id, s.total_amount, s.payment_method,
                    COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.payment_method = 'cash' AND sp.status = 'success'), 0) AS cash_amount,
                    COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.payment_method = 'equity'), 0) AS equity_amount
                FROM sales s
                WHERE s.shift_id = :shift_id AND s.payment_status = :payment_status
                ORDER BY s.created_at ASC");
            $salesStmt->execute([
                'shift_id' => intval($activeShift['id']),
                'payment_status' => 'paid',
            ]);
            $shiftSales = $salesStmt->fetchAll();

            $saleItemsStmt = $pdo->prepare("SELECT si.product_name, si.quantity, si.unit_price, si.line_total, s.payment_method,
                    COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.payment_method = 'cash' AND sp.status = 'success'), 0) AS cash_amount,
                    COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.payment_method = 'equity'), 0) AS equity_amount
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                WHERE s.shift_id = :shift_id AND s.payment_status = :payment_status
                ORDER BY s.created_at ASC, si.id ASC");
            $saleItemsStmt->execute([
                'shift_id' => intval($activeShift['id']),
                'payment_status' => 'paid',
            ]);
            $shiftSaleItems = $saleItemsStmt->fetchAll();

            $stmt = $pdo->prepare('UPDATE cashier_shifts SET expected_cash = :expected_cash, counted_cash = :counted_cash, variance = :variance, variance_reason = :variance_reason, status = :status, priority = :priority, safe_drop_amount = :safe_drop_amount, next_day_float = :next_day_float, closed_at = NOW() WHERE id = :id');
            $stmt->execute([
                'expected_cash' => $expectedCash,
                'counted_cash' => $countedCash,
                'variance' => $variance,
                'variance_reason' => $varianceReason,
                'status' => $status,
                'priority' => $priority,
                'safe_drop_amount' => $safeDropAmount,
                'next_day_float' => $nextDayFloat,
                'id' => $activeShift['id'],
            ]);

            $statementText = generateShiftCloseStatementText(
                $activeShift,
                $shiftSales,
                $shiftSaleItems,
                floatval($activeShift['opening_cash']),
                $expectedCash,
                $countedCash,
                $variance,
                $userName,
                $debtIssued,
                $debtCollectedCash,
                $expectedCashIfDebtPaid,
                $quickPurchaseCost,
                $cashExpenseTotal,
                $openingCashAfterExpenses,
                $shiftExpenses,
                $openingCashUsed,
                $cashSalesUsed
            );
            $closedShiftId = (int) $activeShift['id'];
            $statementFile = printThermalReceipt($statementText, __DIR__ . '/tmp/shift_statement_' . $closedShiftId . '.txt');
            $analysisText = generateShiftAnalysisText(
                $activeShift,
                $shiftSales,
                $debtIssued,
                $debtCollectedCash,
                floatval($activeShift['opening_cash']),
                $openingCashAfterExpenses,
                $openingCashUsed,
                $cashSalesUsed,
                $quickPurchaseCost,
                $cashExpenseTotal,
                $shiftExpenses,
                $expectedCash,
                $countedCash,
                $variance,
                $userName
            );
            $analysisFile = printThermalReceipt($analysisText, __DIR__ . '/tmp/shift_analysis_' . $closedShiftId . '.txt');
            $_SESSION['last_shift_statement_file'] = $statementFile;
            $_SESSION['last_shift_analysis_file'] = $analysisFile;
            $_SESSION['shift_statement_auto_print'] = true;

            $success = $variance === 0.0 ? 'Shift closed successfully.' : 'Shift closed with variance and sent for review.';
            header('Location: cashier_shift.php');
            exit;
        }
    }
}

$recentShiftStmt = $pdo->prepare(
    'SELECT * FROM cashier_shifts
     WHERE cashier_id = :cashier_id
       AND status <> :open_status
       AND COALESCE(closed_at, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     ORDER BY COALESCE(closed_at, created_at) DESC'
);
$recentShiftStmt->execute([
    'cashier_id' => $userId,
    'open_status' => 'open',
]);
$recentShifts = $recentShiftStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cashier Shift — SMART POS SYSTEM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/splash-screen.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 splash-loading">
  <div class="splash-screen" id="splashScreen">
    <div class="splash-screen-logo">
      <img src="colour_logo.jpg" alt="Logo">
    </div>
    <div class="splash-screen-spinner"></div>
    <div class="splash-screen-text">LOADING</div>
  </div>
  <div class="container mx-auto px-4 py-6">
    <div class="mb-6 flex items-center gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
      <img src="colour_logo.jpg" alt="POS2 logo" class="h-14 w-14 rounded-2xl object-cover shadow-sm">
      <div>
        <p class="text-lg font-semibold text-slate-900">SMART POS SYSTEM</p>
        <p class="text-sm text-slate-500">Cashier shift</p>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
        <?php echo htmlspecialchars($success); ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['last_shift_statement_file'] ?? '')): ?>
      <div class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="font-semibold text-slate-900">Shift statements ready</p>
            <p class="text-sm text-slate-500">Full sales details and a compact cash-flow analysis were generated.</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" id="printShiftStatementButton" data-statement-path="<?php echo htmlspecialchars($_SESSION['last_shift_statement_file']); ?>" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Print full statement</button>
            <?php if (!empty($_SESSION['last_shift_analysis_file'] ?? '')): ?>
              <button type="button" id="printShiftAnalysisButton" data-statement-path="<?php echo htmlspecialchars($_SESSION['last_shift_analysis_file']); ?>" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Print shift analysis</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="mb-6 rounded-3xl border border-rose-200 bg-rose-50 p-5 text-rose-900">
        <ul class="list-disc pl-5 space-y-2">
          <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($isStaleShift): ?>
      <div class="mb-6 rounded-3xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
        <p class="font-semibold">You have an open shift from a previous day.</p>
        <p class="text-sm mt-1">Please close that shift before continuing to the POS.</p>
      </div>
    <?php endif; ?>

    <?php if (!$activeShift): ?>
      <section class="rounded-3xl bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-semibold mb-4">Start Shift</h2>
        <p class="text-sm text-slate-500 mb-6">Count your opening cash and begin the shift.</p>
        <form method="POST" class="space-y-5">
          <label class="block text-sm text-slate-700">
            <span class="mb-1 block font-medium">Opening cash</span>
            <input name="opening_cash" type="number" min="0" step="0.01" required class="w-full rounded-3xl border border-slate-200 px-4 py-3" />
          </label>
          <label class="block text-sm text-slate-700">
            <span class="mb-1 block font-medium">Start time</span>
            <input type="text" value="<?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?>" readonly class="w-full rounded-3xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-500" />
          </label>
          <input type="hidden" name="action" value="start_shift" />
          <div class="flex justify-end">
            <button type="submit" class="rounded-3xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white">Start Shift</button>
          </div>
        </form>
      </section>
    <?php elseif (!$isStaleShift && !$showCloseForm): ?>
      <section class="rounded-3xl bg-white p-6 shadow-sm mb-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-2xl font-semibold">Shift in progress</h2>
            <p class="text-sm text-slate-500">Your shift is active and can continue until the end of the day.</p>
          </div>
          <div class="rounded-3xl bg-slate-100 px-4 py-3 text-sm text-slate-500">Started at <?php echo htmlspecialchars($activeShift['started_at'] ?? $activeShift['created_at']); ?></div>
        </div>
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Shift status</div>
            <div class="mt-2 font-semibold uppercase text-emerald-700"><?php echo htmlspecialchars($activeShift['status']); ?></div>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Opening cash</div>
            <div class="mt-2 text-xl font-bold text-slate-900">KES <?php echo number_format(floatval($activeShift['opening_cash']), 2); ?></div>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Started</div>
            <div class="mt-2 font-semibold text-slate-900"><?php echo htmlspecialchars($activeShift['started_at'] ?? $activeShift['created_at']); ?></div>
          </div>
        </div>
        <div class="mt-6 grid gap-4 lg:grid-cols-2">
          <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5">
            <h3 class="font-semibold text-emerald-950">Money in</h3>
            <div class="mt-4 space-y-3 text-sm text-emerald-900">
              <div class="flex justify-between gap-4"><span>Cash sales</span><strong>KES <?php echo number_format($expectedSales, 2); ?></strong></div>
              <div class="flex justify-between gap-4"><span>Debt collected in cash</span><strong>KES <?php echo number_format($debtCollectedCash, 2); ?></strong></div>
              <div class="flex justify-between gap-4 border-t border-emerald-200 pt-3"><span>Debt sales issued</span><strong>KES <?php echo number_format($debtIssued, 2); ?></strong></div>
            </div>
          </div>
          <div class="rounded-3xl border border-rose-200 bg-rose-50 p-5">
            <h3 class="font-semibold text-rose-950">Money out</h3>
            <div class="mt-4 space-y-3 text-sm text-rose-900">
              <div class="flex justify-between gap-4"><span>Quick stock purchases</span><strong>KES <?php echo number_format($quickPurchaseCost, 2); ?></strong></div>
              <div class="flex justify-between gap-4"><span>Cash expenses</span><strong>KES <?php echo number_format($cashExpenseTotal, 2); ?></strong></div>
              <div class="flex justify-between gap-4 border-t border-rose-200 pt-3"><span>Total cash outflows</span><strong>KES <?php echo number_format($totalCashOutflows, 2); ?></strong></div>
            </div>
          </div>
        </div>
        <div class="mt-4 rounded-3xl border border-amber-200 bg-amber-50 p-5">
          <h3 class="font-semibold text-amber-950">How outflows were funded</h3>
          <p class="mt-1 text-sm text-amber-800">Purchases and cash expenses use opening cash first. Only the remaining amount uses cash sales.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm text-amber-950">
            <div><div class="text-amber-700">From opening cash</div><strong>KES <?php echo number_format($openingCashUsed, 2); ?></strong></div>
            <div><div class="text-amber-700">From cash sales</div><strong>KES <?php echo number_format($cashSalesUsed, 2); ?></strong></div>
            <div><div class="text-amber-700">Opening cash remaining</div><strong>KES <?php echo number_format($openingCashAfterExpenses, 2); ?></strong></div>
          </div>
        </div>
        <div class="mt-4 grid gap-4 md:grid-cols-3 text-sm">
          <div class="rounded-3xl bg-slate-50 p-4">
            <div class="text-slate-500">Total cash outflows</div>
            <div class="font-semibold">KES <?php echo number_format($totalCashOutflows, 2); ?></div>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <div class="text-slate-500">Taken from opening cash</div>
            <div class="font-semibold">KES <?php echo number_format($openingCashUsed, 2); ?></div>
          </div>
          <div class="rounded-3xl bg-slate-50 p-4">
            <div class="text-slate-500">Taken from cash sales</div>
            <div class="font-semibold">KES <?php echo number_format($cashSalesUsed, 2); ?></div>
          </div>
        </div>
        <div class="mt-4 rounded-3xl border border-slate-200 bg-white p-4">
          <h3 class="font-semibold text-slate-900">Shift expenses</h3>
          <?php if ($shiftExpenses === []): ?>
            <p class="mt-2 text-sm text-slate-500">No expenses recorded for this shift.</p>
          <?php else: ?>
            <div class="mt-3 space-y-2 text-sm">
              <?php foreach ($shiftExpenses as $expense): ?>
                <?php $deductedAmount = (float) ($expense['cash_drawer_amount'] ?? 0); ?>
                <div class="flex flex-col gap-1 rounded-2xl bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <div class="font-medium text-slate-900"><?php echo htmlspecialchars((string) $expense['title']); ?></div>
                    <div class="text-slate-500"><?php echo htmlspecialchars((string) $expense['expense_date']); ?> · <?php echo htmlspecialchars(strtoupper((string) $expense['payment_method'])); ?></div>
                  </div>
                  <div class="text-left sm:text-right">
                    <div class="font-semibold text-slate-900">KES <?php echo number_format((float) $expense['amount'], 2); ?></div>
                    <div class="<?php echo $deductedAmount > 0 ? 'text-rose-700' : 'text-slate-500'; ?>">
                      <?php echo $deductedAmount > 0 ? 'Deducted from drawer: KES ' . number_format($deductedAmount, 2) : 'Not deducted from drawer'; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="mt-4 rounded-3xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
          <div class="font-semibold">Expected cash for close</div>
          <div class="mt-1">KES <?php echo number_format($expectedCash, 2); ?></div>
          <p class="mt-2 text-emerald-800">Opening cash + cash sales + cash collections − quick purchases − cash expenses.</p>
        </div>
        <div class="mt-4 rounded-3xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
          <div class="font-semibold">Expected if debt paid in cash</div>
          <div class="mt-1">KES <?php echo number_format($expectedCashIfDebtPaid, 2); ?></div>
          <p class="mt-2 text-slate-500">Includes cash collections from invoice payments in this shift.</p>
        </div>
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
          <a href="index.php" class="rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white text-center">Continue to POS</a>
          <a href="cashier_shift.php?close=1" class="rounded-3xl border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-900 text-center">Close Shift</a>
        </div>
      </section>
    <?php elseif ($showCloseForm): ?>
      <section class="rounded-3xl bg-white p-6 shadow-sm mb-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-2xl font-semibold">Close Shift</h2>
            <p class="text-sm text-slate-500">Finish your current shift by counting cash and recording variances.</p>
          </div>
          <div class="rounded-3xl bg-slate-100 px-4 py-3 text-sm text-slate-500">Shift opened at <?php echo htmlspecialchars($activeShift['started_at'] ?? $activeShift['created_at']); ?></div>
        </div>
        <form method="POST" class="mt-6 grid gap-4 md:grid-cols-2">
          <label class="block text-sm text-slate-700">
            <span class="mb-1 block font-medium">Expected cash</span>
            <input name="expected_cash" type="number" min="0" step="0.01" readonly value="<?php echo htmlspecialchars(number_format($expectedCash, 2, '.', '')); ?>" class="w-full rounded-3xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-500" />
          </label>
          <label class="block text-sm text-slate-700">
            <span class="mb-1 block font-medium">Counted cash</span>
            <input name="counted_cash" type="number" min="0" step="0.01" required class="w-full rounded-3xl border border-slate-200 px-4 py-3" />
          </label>
          <label class="block text-sm text-slate-700 md:col-span-2">
            <span class="mb-1 block font-medium">Variance reason</span>
            <textarea name="variance_reason" rows="3" class="w-full rounded-3xl border border-slate-200 px-4 py-3"></textarea>
          </label>
          <label class="block text-sm text-slate-700">
            <span class="mb-1 block font-medium">Safe drop amount</span>
            <input name="safe_drop_amount" type="number" min="0" step="0.01" class="w-full rounded-3xl border border-slate-200 px-4 py-3" />
          </label>
          <label class="block text-sm text-slate-700">
            <span class="mb-1 block font-medium">Next-day float</span>
            <input name="next_day_float" type="number" min="0" step="0.01" class="w-full rounded-3xl border border-slate-200 px-4 py-3" />
          </label>
          <input type="hidden" name="action" value="close_shift" />
          <div class="md:col-span-2 flex justify-end">
            <button type="submit" class="rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white">Close Shift</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <section class="rounded-3xl bg-white p-6 shadow-sm">
      <h2 class="text-lg font-semibold mb-4">Yesterday's shifts</h2>
      <?php if (count($recentShifts) === 0): ?>
        <p class="text-slate-500">No yesterday shift records found.</p>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($recentShifts as $shift): ?>
            <div class="rounded-3xl border border-slate-200 p-4">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="font-semibold"><?php echo htmlspecialchars($shift['cashier_name']); ?></div>
                  <div class="text-xs text-slate-500">Started <?php echo htmlspecialchars($shift['started_at'] ?? $shift['created_at']); ?></div>
                </div>
                <span class="inline-flex items-center justify-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-700"><?php echo htmlspecialchars($shift['status']); ?></span>
              </div>
              <div class="mt-3 grid gap-2 sm:grid-cols-2 text-sm text-slate-700">
                <div class="rounded-2xl bg-slate-50 p-3">Opening cash: KES <?php echo number_format(floatval($shift['opening_cash']), 2); ?></div>
                <div class="rounded-2xl bg-slate-50 p-3">Counted cash: KES <?php echo number_format(floatval($shift['counted_cash']), 2); ?></div>
                <div class="rounded-2xl bg-slate-50 p-3">Variance: KES <?php echo number_format(floatval($shift['variance']), 2); ?></div>
                <div class="rounded-2xl bg-slate-50 p-3">Safe drop: KES <?php echo number_format(floatval($shift['safe_drop_amount']), 2); ?></div>
              </div>
              <?php $historicalStatementPath = __DIR__ . '/tmp/shift_statement_' . (int) $shift['id'] . '.txt'; ?>
              <?php $historicalAnalysisPath = __DIR__ . '/tmp/shift_analysis_' . (int) $shift['id'] . '.txt'; ?>
              <?php if (is_file($historicalStatementPath) || is_file($historicalAnalysisPath)): ?>
                <div class="mt-3 flex flex-wrap gap-2">
                  <?php if (is_file($historicalStatementPath)): ?>
                    <a href="receipt_view.php?path=<?php echo urlencode($historicalStatementPath); ?>" target="_blank" class="rounded-2xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white">Print full statement</a>
                  <?php endif; ?>
                  <?php if (is_file($historicalAnalysisPath)): ?>
                    <a href="receipt_view.php?path=<?php echo urlencode($historicalAnalysisPath); ?>" target="_blank" class="rounded-2xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white">Print analysis</a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <script>
    window.addEventListener('load', function() {
      const splashScreen = document.getElementById('splashScreen');
      if (splashScreen) {
        splashScreen.classList.add('hidden');
        document.body.classList.remove('splash-loading');
      }

      const printShiftStatementButton = document.getElementById('printShiftStatementButton');
      const printShiftAnalysisButton = document.getElementById('printShiftAnalysisButton');
      const autoPrintShiftStatement = <?php echo json_encode(!empty($_SESSION['shift_statement_auto_print'])); ?>;
      if (printShiftStatementButton) {
        const openShiftStatement = () => {
          const statementPath = printShiftStatementButton.getAttribute('data-statement-path') || '';
          if (!statementPath) {
            return;
          }
          window.open('receipt_view.php?path=' + encodeURIComponent(statementPath), '_blank', 'width=400,height=700');
        };
        if (autoPrintShiftStatement) {
          setTimeout(openShiftStatement, 250);
        }
        printShiftStatementButton.addEventListener('click', openShiftStatement);
      }
      if (printShiftAnalysisButton) {
        printShiftAnalysisButton.addEventListener('click', () => {
          const statementPath = printShiftAnalysisButton.getAttribute('data-statement-path') || '';
          if (statementPath) {
            window.open('receipt_view.php?path=' + encodeURIComponent(statementPath), '_blank', 'width=400,height=700');
          }
        });
      }
    });
  </script>
  <script src="assets/js/cashier-session.js"></script>
</body>
</html>
