<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';

$equityReconciliationNotice = '';
$equityUploadSummary = null;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_equity_statement'])) {
        if (!isset($_FILES['equity_statement_file']) || $_FILES['equity_statement_file']['error'] !== UPLOAD_ERR_OK) {
            $equityReconciliationNotice = 'Please upload a valid Equity statement file.';
        } else {
            $uploadDir = __DIR__ . '/uploads/equity_statements';
            ensureUploadDirectory($uploadDir);
            $uploadedFile = $_FILES['equity_statement_file'];
            if (($uploadedFile['size'] ?? 0) > 10 * 1024 * 1024) {
              $equityReconciliationNotice = 'The Equity statement must be 10 MB or smaller.';
              $uploadedFile = null;
            }
            if ($uploadedFile === null) {
              // Keep oversized uploads out of the parsing and queue path.
            } else {
            $originalName = basename($uploadedFile['name']);
            $destinationName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
            $destinationPath = $uploadDir . '/' . $destinationName;

            $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['csv', 'txt', 'pdf'];
            if (!in_array($fileExtension, $allowedExtensions, true)) {
                $equityReconciliationNotice = 'Unsupported file type. Upload a CSV, TXT, or PDF file.';
            } elseif (move_uploaded_file($uploadedFile['tmp_name'], $destinationPath)) {
                try {
                    $statementUser = $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'Admin');
                    $uploadStmt = $pdo->prepare(
                        'INSERT INTO equity_statement_uploads (filename, uploaded_by, uploaded_at, total_transactions, matched_count, unmatched_pos_count, unmatched_bank_count, duplicate_codes_count, status) VALUES (:filename, :uploaded_by, NOW(), 0, 0, 0, 0, 0, :status)'
                    );
                    $uploadStmt->execute([
                      'filename' => $destinationName,
                        'uploaded_by' => $statementUser,
                        'status' => 'queued',
                    ]);
                    $equityUploadSummary = [
                        'upload_id' => (int) $pdo->lastInsertId(),
                        'total_transactions' => 0,
                        'matched_count' => 0,
                        'unmatched_bank_count' => 0,
                        'unmatched_pos_count' => 0,
                        'duplicate_codes_count' => 0,
                    ];
                    $equityReconciliationNotice = 'Equity statement queued for processing. Run the job in tools/process_equity_statement.php to reconcile it.';
                } catch (Exception $e) {
                    $equityReconciliationNotice = 'Unable to queue Equity statement: ' . $e->getMessage();
                }
            } else {
                $equityReconciliationNotice = 'Unable to save uploaded Equity statement file.';
            }
            }
        }
    } else {
        $action = trim($_POST['equity_action'] ?? '');
        $paymentId = intval($_POST['equity_payment_id'] ?? 0);
        if ($action !== '' && $paymentId > 0) {
            try {
                if ($action === 'verify') {
                    $stmt = $pdo->prepare('UPDATE sale_payments SET status = :status, verified_by = :verified_by, verified_at = NOW() WHERE id = :id');
                    $stmt->execute([
                        'status' => 'verified',
                        'verified_by' => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'Admin'),
                        'id' => $paymentId,
                    ]);
                    $equityReconciliationNotice = 'Equity payment marked as verified.';
                } elseif ($action === 'reject') {
                    $stmt = $pdo->prepare('UPDATE sale_payments SET status = :status, verified_by = :verified_by, verified_at = NOW() WHERE id = :id');
                    $stmt->execute([
                        'status' => 'unmatched',
                        'verified_by' => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'Admin'),
                        'id' => $paymentId,
                    ]);
                    $equityReconciliationNotice = 'Equity payment marked as unmatched.';
                }
            } catch (Exception $e) {
                $equityReconciliationNotice = 'Unable to update Equity payment: ' . $e->getMessage();
            }
        }
    }
}

$equityPendingPayments = [];
try {
    $equityStmt = $pdo->prepare(
        'SELECT sp.id, sp.sale_id, sp.transaction_code, sp.amount, sp.status, sp.created_at,
                s.created_at AS sale_date, s.total_amount AS sale_total, s.payment_status,
                GROUP_CONCAT(CONCAT(si.product_name, " (", FORMAT(si.quantity, 3), ")") ORDER BY si.id SEPARATOR ", ") AS sold_items
         FROM sale_payments sp
         JOIN sales s ON s.id = sp.sale_id
         LEFT JOIN sale_items si ON si.sale_id = sp.sale_id
         WHERE sp.payment_method = :method
         GROUP BY sp.id, sp.sale_id, sp.transaction_code, sp.amount, sp.status, sp.created_at, s.created_at, s.total_amount, s.payment_status
         ORDER BY sp.created_at DESC
         LIMIT 30'
    );
    $equityStmt->execute(['method' => 'equity']);
    $equityPendingPayments = $equityStmt->fetchAll();
} catch (Exception $e) {
    error_log('Equity pending payments query error: ' . $e->getMessage());
    $equityPendingPayments = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Equity Reconciliation — SMART POS SYSTEM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/splash-screen.css">
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen splash-loading">
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
        <p class="text-sm text-slate-500">Equity reconciliation center</p>
      </div>
    </div>

    <header class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Equity Reconciliation</h1>
        <p class="mt-1 text-sm text-slate-500 sm:text-base">Upload Equity PayBill statements and verify recorded payments.</p>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="admin.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">Dashboard</a>
          <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-600">Expenses</a>
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
        <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-600">Expenses</a>
        <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Staff / Cashiers</a>
        <a href="logout.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 transition hover:bg-slate-100">Logout</a>
      </div>
    </div>

    <?php if ($equityReconciliationNotice !== ''): ?>
      <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-slate-900">
        <?php echo htmlspecialchars($equityReconciliationNotice); ?>
      </div>
    <?php endif; ?>

    <div class="rounded-2xl bg-white p-6 shadow-sm mb-6">
      <h2 class="text-lg font-semibold mb-3">Upload Equity statement</h2>
      <p class="text-sm text-slate-500 mb-4">Upload a CSV or text statement to match bank transaction codes against recorded Equity payments.</p>
      <form method="post" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-[1fr_auto] items-end">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Statement file</span>
          <input type="file" name="equity_statement_file" accept=".csv,.txt,.pdf" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" required>
        </label>
        <button type="submit" name="upload_equity_statement" class="rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white hover:bg-slate-700">Upload and reconcile</button>
      </form>
    </div>

    <?php if ($equityUploadSummary !== null): ?>
      <div class="mb-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
        <div class="font-semibold text-slate-900">Upload summary</div>
        <ul class="mt-3 space-y-2">
          <li>Total rows processed: <?php echo intval($equityUploadSummary['total_transactions']); ?></li>
          <li>Matched payments: <?php echo intval($equityUploadSummary['matched_count']); ?></li>
          <li>Bank rows unmatched: <?php echo intval($equityUploadSummary['unmatched_bank_count']); ?></li>
          <li>POS records still unrecorded: <?php echo intval($equityUploadSummary['unmatched_pos_count']); ?></li>
          <li>Duplicate transaction codes: <?php echo intval($equityUploadSummary['duplicate_codes_count']); ?></li>
        </ul>
      </div>
    <?php endif; ?>

    <section class="rounded-2xl bg-white p-6 shadow-sm">
      <h2 class="text-lg font-semibold mb-3">Recent Equity payment records</h2>
      <?php if (count($equityPendingPayments) === 0): ?>
        <p class="text-slate-500">No recorded Equity payments found.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-700">
            <thead class="border-b border-slate-200 text-slate-500">
              <tr>
                <th class="py-3 pe-4">Sale #</th>
                <th class="py-3 pe-4">Sale date</th>
                <th class="py-3 pe-4">Items sold</th>
                <th class="py-3 pe-4">Transaction code</th>
                <th class="py-3 pe-4">Amount</th>
                <th class="py-3 pe-4">Status</th>
                <th class="py-3 pe-4">Recorded</th>
                <th class="py-3 pe-4">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <?php foreach ($equityPendingPayments as $payment): ?>
                <tr>
                  <td class="py-3 pe-4 font-medium"><?php echo intval($payment['sale_id']); ?></td>
                  <td class="py-3 pe-4"><?php echo htmlspecialchars(date('d M Y H:i', strtotime((string) ($payment['sale_date'] ?? $payment['created_at'])))); ?></td>
                  <td class="py-3 pe-4"><?php echo htmlspecialchars((string) ($payment['sold_items'] ?? 'No item details')); ?></td>
                  <td class="py-3 pe-4"><?php echo htmlspecialchars($payment['transaction_code'] ?? '—'); ?></td>
                  <td class="py-3 pe-4">KES <?php echo number_format(floatval($payment['amount']), 2); ?><span class="block text-xs text-slate-500">Sale total: KES <?php echo number_format(floatval($payment['sale_total'] ?? 0), 2); ?></span></td>
                  <td class="py-3 pe-4"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $payment['status']))); ?></td>
                  <td class="py-3 pe-4"><?php echo htmlspecialchars($payment['created_at']); ?></td>
                  <td class="py-3 pe-4">
                    <form method="post" class="flex flex-wrap gap-2">
                      <input type="hidden" name="equity_payment_id" value="<?php echo intval($payment['id']); ?>">
                      <button type="submit" name="equity_action" value="verify" class="rounded-full bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">Verify</button>
                      <button type="submit" name="equity_action" value="reject" class="rounded-full bg-rose-500 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-600">Mark unmatched</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
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
    });

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
