<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

$search = trim((string)($_GET['search'] ?? ''));
$searchTerm = $search !== '' ? '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%' : null;

$customerQuery = 
    'SELECT c.id, c.customer_number, c.name, c.phone, c.current_balance, c.status, c.created_at, '
    . 'COUNT(i.id) AS unpaid_invoice_count, COALESCE(SUM(i.balance), 0.00) AS unpaid_balance '
    . 'FROM customers c '
    . 'LEFT JOIN customer_credit_invoices i ON i.customer_id = c.id AND i.balance > 0 '
    . 'WHERE c.current_balance > 0 ';

if ($searchTerm !== null) {
    $customerQuery .= 'AND (c.name LIKE :search OR c.phone LIKE :search OR c.customer_number LIKE :search) ';
}

$customerQuery .= 'GROUP BY c.id ORDER BY unpaid_balance DESC, c.current_balance DESC LIMIT 100';
$customerStmt = $pdo->prepare($customerQuery);
if ($searchTerm !== null) {
    $customerStmt->execute(['search' => $searchTerm]);
} else {
    $customerStmt->execute();
}
$customers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);

$invoiceQuery = 
    'SELECT i.id, i.invoice_number, i.customer_id, i.total_amount, i.amount_paid, i.balance, i.status, i.created_at, '
    . 'c.customer_number, c.name AS customer_name, c.phone AS customer_phone '
    . 'FROM customer_credit_invoices i '
    . 'JOIN customers c ON c.id = i.customer_id '
    . 'WHERE i.balance > 0 ';
if ($searchTerm !== null) {
    $invoiceQuery .= 'AND (c.name LIKE :search OR c.phone LIKE :search OR c.customer_number LIKE :search OR i.invoice_number LIKE :search) ';
}
$invoiceQuery .= 'ORDER BY i.created_at ASC LIMIT 250';
$invoiceStmt = $pdo->prepare($invoiceQuery);
if ($searchTerm !== null) {
    $invoiceStmt->execute(['search' => $searchTerm]);
} else {
    $invoiceStmt->execute();
}
$invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCustomers = count($customers);
$totalOutstanding = array_reduce($customers, static fn($carry, $item) => $carry + floatval($item['current_balance']), 0.0);
$totalInvoices = count($invoices);
$topDebtors = array_slice($customers, 0, 6);

function formatCurrency($amount): string {
    return 'KES ' . number_format(floatval($amount), 2);
}

function statusBadge(string $status): string {
    $normalized = strtolower(trim($status));
    if ($normalized === 'paid') {
        return '<span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Paid</span>';
    }
    if ($normalized === 'partially_paid') {
        return '<span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Partial</span>';
    }
    return '<span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-800">' . htmlspecialchars(ucfirst($status)) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Debt Monitor — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <div class="admin-page-shell mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Admin panel</p>
        <h1 class="mt-2 text-3xl font-semibold text-slate-900">Debt monitor</h1>
        <p class="mt-1 text-sm text-slate-500">Review outstanding customer debt and monitor open credit invoices.</p>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="admin.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">Dashboard</a>
          <a href="admin_shifts.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Shifts</a>
          <a href="admin_growth.php" class="rounded-3xl bg-slate-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Growth</a>
          <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-600">Expenses</a>
          <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">Cashiers</a>
          <a href="admin_payroll.php" class="rounded-3xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700">Payroll</a>
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
        <a href="admin_shifts.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Shifts</a>
        <a href="admin_growth.php" class="rounded-3xl bg-slate-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Growth</a>
        <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-600">Expenses</a>
        <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Cashiers</a>
        <a href="admin_payroll.php" class="rounded-3xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700">Payroll</a>
        <a href="logout.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 transition hover:bg-slate-100">Logout</a>
      </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Customers with debt</p>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?php echo number_format($totalCustomers); ?></p>
      </div>
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Total outstanding balance</p>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?php echo formatCurrency($totalOutstanding); ?></p>
      </div>
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Open credit invoices</p>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?php echo number_format($totalInvoices); ?></p>
      </div>
    </div>

    <div class="mt-6 rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
      <form method="get" class="grid gap-4 md:grid-cols-[1fr_auto]">
        <label class="block">
          <span class="text-sm font-medium text-slate-600">Search customers or invoice number</span>
          <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, phone, customer number or invoice" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200" />
        </label>
        <div class="flex items-end gap-2">
          <button type="submit" class="inline-flex items-center justify-center rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Search</button>
          <a href="admin_debts.php" class="inline-flex items-center justify-center rounded-3xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Reset</a>
        </div>
      </form>
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-3">
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm xl:col-span-1">
        <p class="text-sm font-medium text-slate-500">Top debtors</p>
        <div class="mt-5 space-y-4">
          <?php if (count($topDebtors) === 0): ?>
            <p class="text-sm text-slate-500">No debtors found.</p>
          <?php endif; ?>
          <?php foreach ($topDebtors as $debtor): ?>
            <div class="rounded-3xl bg-slate-50 p-4">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($debtor['name']); ?></p>
                  <p class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars($debtor['customer_number']); ?> · <?php echo htmlspecialchars($debtor['phone'] ?: 'No phone'); ?></p>
                </div>
                <span class="text-sm font-semibold text-slate-900"><?php echo formatCurrency($debtor['unpaid_balance']); ?></span>
              </div>
              <p class="mt-3 text-xs text-slate-500"><?php echo number_format($debtor['unpaid_invoice_count']); ?> open invoice<?php echo intval($debtor['unpaid_invoice_count']) === 1 ? '' : 's'; ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm xl:col-span-2">
        <div class="flex items-center justify-between gap-4">
          <div>
            <p class="text-sm font-medium text-slate-500">Open invoices</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900"><?php echo number_format($totalInvoices); ?></p>
          </div>
          <div class="rounded-3xl bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">Latest first</div>
        </div>

        <div class="mt-6 overflow-x-auto">
          <div class="overflow-x-auto rounded-2xl border border-slate-200">
          <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 font-semibold">Invoice</th>
                <th class="px-4 py-3 font-semibold">Customer</th>
                <th class="px-4 py-3 font-semibold">Total</th>
                <th class="px-4 py-3 font-semibold">Paid</th>
                <th class="px-4 py-3 font-semibold">Balance</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3 font-semibold">Created</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 bg-white text-slate-700">
              <?php if (count($invoices) === 0): ?>
                <tr>
                  <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500">No outstanding invoices match your search.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                  <tr>
                    <td class="px-4 py-4 font-medium text-slate-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                    <td class="px-4 py-4">
                      <div class="text-slate-900"><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                      <div class="text-xs text-slate-500"><?php echo htmlspecialchars($invoice['customer_number']); ?> · <?php echo htmlspecialchars($invoice['customer_phone'] ?: 'No phone'); ?></div>
                    </td>
                    <td class="px-4 py-4"><?php echo formatCurrency($invoice['total_amount']); ?></td>
                    <td class="px-4 py-4"><?php echo formatCurrency($invoice['amount_paid']); ?></td>
                    <td class="px-4 py-4"><?php echo formatCurrency($invoice['balance']); ?></td>
                    <td class="px-4 py-4"><?php echo statusBadge($invoice['status']); ?></td>
                    <td class="px-4 py-4"><?php echo date('Y-m-d', strtotime($invoice['created_at'])); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
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
