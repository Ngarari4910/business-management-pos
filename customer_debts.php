<?php
session_start();
require __DIR__ . '/security.php';
requireCashierPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';

$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_invoice') {
    $paymentAmount = floatval(str_replace(',', '', trim((string) ($_POST['payment_amount'] ?? '0'))));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'cash'));
    $transactionCode = trim((string) ($_POST['transaction_code'] ?? ''));
    $invoiceIds = array_values(array_filter(array_map('intval', (array) ($_POST['selected_invoice_ids'] ?? []))));
    $singleInvoiceId = intval($_POST['invoice_id'] ?? 0);

    if ($singleInvoiceId > 0 && count($invoiceIds) === 0) {
        $invoiceIds = [$singleInvoiceId];
    }

    if (count($invoiceIds) === 0) {
        $errors[] = 'No invoice selected for payment.';
    }

    if ($paymentMethod === 'equity' && $transactionCode === '') {
        $errors[] = 'Enter the Equity transaction code to record this payment.';
    }

    if ($paymentAmount <= 0) {
        $errors[] = 'Enter a valid payment amount.';
    }

    $invoices = [];
    $customerPhone = null;
    $customerId = null;
    $totalBalance = 0.0;

    if (empty($errors)) {
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $invoiceStmt = $pdo->prepare(
            'SELECT i.*, c.customer_number, c.name AS customer_name, c.phone AS customer_phone, c.current_balance '
            . 'FROM customer_credit_invoices i '
            . 'JOIN customers c ON c.id = i.customer_id '
            . 'WHERE i.id IN (' . $placeholders . ') FOR UPDATE'
        );
        $invoiceStmt->execute($invoiceIds);
        while ($invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC)) {
            $invoices[] = $invoice;
        }

        if (count($invoices) !== count($invoiceIds)) {
            $errors[] = 'One or more selected invoices were not found.';
        }
    }

    if (empty($errors)) {
        foreach ($invoices as $invoice) {
            if ($customerId === null) {
                $customerId = intval($invoice['customer_id']);
                $customerPhone = trim((string) ($invoice['customer_phone'] ?? '')) ?: null;
            }
            if (intval($invoice['customer_id']) !== $customerId) {
                $errors[] = 'Selected invoices must belong to the same customer.';
                break;
            }
            if (floatval($invoice['balance']) <= 0) {
                $errors[] = 'One or more selected invoices already have no outstanding balance.';
                break;
            }
            $totalBalance += floatval($invoice['balance']);
        }
    }

    if (empty($errors) && $paymentAmount > $totalBalance) {
        $errors[] = 'Payment amount cannot exceed the outstanding balance of selected invoices.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            usort($invoices, static fn($a, $b) => strcmp($a['created_at'], $b['created_at']) ?: intval($a['id']) <=> intval($b['id']));
            $remaining = $paymentAmount;
            $updateInvoice = $pdo->prepare(
                'UPDATE customer_credit_invoices SET amount_paid = :amount_paid, balance = :balance, status = :status, updated_at = NOW() WHERE id = :id'
            );

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }
                $balance = floatval($invoice['balance']);
                if ($balance <= 0) {
                    continue;
                }
                $applyAmount = min($remaining, $balance);
                $newAmountPaid = floatval($invoice['amount_paid']) + $applyAmount;
                $newBalance = $balance - $applyAmount;
                $newStatus = $newBalance <= 0.0 ? 'paid' : 'partially_paid';
                $updateInvoice->execute([
                    'amount_paid' => number_format($newAmountPaid, 2, '.', ''),
                    'balance' => number_format($newBalance, 2, '.', ''),
                    'status' => $newStatus,
                    'id' => intval($invoice['id']),
                ]);
                $remaining -= $applyAmount;
            }

            $updateCustomer = $pdo->prepare(
                'UPDATE customers SET current_balance = current_balance - :payment_amount, updated_at = NOW() WHERE id = :customer_id'
            );
            $updateCustomer->execute([
                'payment_amount' => number_format($paymentAmount, 2, '.', ''),
                'customer_id' => $customerId,
            ]);

            $shiftId = null;
            if (!empty($_SESSION['user_id'])) {
                $openShift = getOpenShiftByCashier($pdo, intval($_SESSION['user_id']));
                if ($openShift) {
                    $shiftId = intval($openShift['id']);
                }
            }

            $saleStmt = $pdo->prepare(
                'INSERT INTO sales (total_amount, amount_tendered, change_amount, payment_method, payment_status, customer_phone, notes, cashier_id, shift_id) VALUES (:total_amount, :amount_tendered, :change_amount, :payment_method, :payment_status, :customer_phone, :notes, :cashier_id, :shift_id)'
            );
            $paymentStatus = 'paid';
            $saleStmt->execute([
                'total_amount' => $paymentAmount,
                'amount_tendered' => $paymentAmount,
                'change_amount' => 0.00,
                'payment_method' => normalizePaymentMethod($paymentMethod),
                'payment_status' => $paymentStatus,
                'customer_phone' => $customerPhone,
                'notes' => 'Payment for invoice(s): ' . implode(', ', array_map(static fn($invoice) => $invoice['invoice_number'], $invoices)),
                'cashier_id' => $_SESSION['user_id'] ?? null,
                'shift_id' => $shiftId,
            ]);
            $saleId = intval($pdo->lastInsertId());
            $receiptNumber = 'RCP-' . date('YmdHis') . '-' . $saleId;

            $paymentStmt = $pdo->prepare(
                'INSERT INTO sale_payments (sale_id, payment_method, amount, status, transaction_code, customer_phone, receipt_number) VALUES (:sale_id, :payment_method, :amount, :status, :transaction_code, :customer_phone, :receipt_number)'
            );
            $paymentStmt->execute([
                'sale_id' => $saleId,
                'payment_method' => normalizePaymentMethod($paymentMethod),
                'amount' => $paymentAmount,
                'status' => $paymentStatus,
                'transaction_code' => $transactionCode !== '' ? $transactionCode : null,
                'customer_phone' => $customerPhone,
                'receipt_number' => $receiptNumber,
            ]);

            $pdo->commit();
            $successMessage = 'Payment applied successfully. Invoice balance updated.';
            header('Location: customer_debts.php?success=1&receipt_number=' . urlencode($receiptNumber));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Unable to apply payment: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] === '1') {
    $successMessage = 'Payment applied successfully. Invoice balance updated.';
}

$search = trim((string) ($_GET['search'] ?? ''));
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
$customerQuery .= 'GROUP BY c.id ORDER BY c.current_balance DESC, c.name ASC';

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
    $invoiceQuery .= 'AND (c.name LIKE :search OR c.phone LIKE :search OR c.customer_number LIKE :search) ';
}
$invoiceQuery .= 'ORDER BY i.created_at DESC LIMIT 200';

$invoiceStmt = $pdo->prepare($invoiceQuery);
if ($searchTerm !== null) {
    $invoiceStmt->execute(['search' => $searchTerm]);
} else {
    $invoiceStmt->execute();
}
$invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

$invoiceItemsByInvoiceId = [];
if (count($invoices) > 0) {
    $invoiceIds = array_column($invoices, 'id');
    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $itemQuery =
        'SELECT i.id AS invoice_id, si.product_name, si.quantity, si.unit_price, si.line_total '
        . 'FROM sale_items si '
        . 'JOIN customer_credit_invoices i ON i.sale_id = si.sale_id '
        . 'WHERE i.id IN (' . $placeholders . ') '
        . 'ORDER BY i.id ASC, si.id ASC';
    $itemStmt = $pdo->prepare($itemQuery);
    $itemStmt->execute($invoiceIds);
    while ($item = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
        $invoiceItemsByInvoiceId[$item['invoice_id']][] = $item;
    }
}

$totalCustomers = count($customers);
$totalOutstanding = array_reduce($customers, static fn($carry, $item) => $carry + floatval($item['current_balance']), 0.0);
$totalInvoices = count($invoices);

function formatMoney($value): string {
    return 'KES ' . number_format((float) $value, 2);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Customer Debts — SMART POS SYSTEM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/splash-screen.css">
  <style>
    #printOutput {
      display: none;
    }

    #printOutput .receipt-wrapper {
      width: 58mm;
      padding: 10px;
      box-sizing: border-box;
      background: #fff;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
      font-size: 12px;
      line-height: 1.2;
    }

    #printOutput .logo-wrap {
      text-align: center;
      margin-bottom: 10px;
    }

    #printOutput .logo-wrap img {
      display: block;
      max-width: 100%;
      height: auto;
      margin: 0 auto;
    }

    @media print {
      @page {
        size: 58mm auto;
        margin: 4mm;
      }

      body * {
        visibility: hidden;
      }

      #printOutput,
      #printOutput * {
        visibility: visible;
      }

      #printOutput {
        display: block;
        position: absolute;
        left: 0;
        top: 0;
        background: #fff;
      }
    }
  </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">
  <div class="container mx-auto px-4 py-6">
    <div class="mb-6 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p class="text-lg font-semibold text-slate-900">Customer Debt Ledger</p>
          <p class="mt-1 text-sm text-slate-500">Review unpaid customer credit invoices and outstanding balances.</p>
        </div>
        <div class="flex flex-wrap gap-3">
          <a href="index.php" class="rounded-3xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Back to Sales</a>
          <a href="logout.php" class="rounded-3xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-rose-700">Logout</a>
        </div>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="mb-6 rounded-[2rem] border border-rose-200 bg-rose-50 p-5 text-rose-800 shadow-sm">
        <p class="font-semibold">Payment could not be completed</p>
        <ul class="mt-3 list-disc pl-5">
          <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
      <div class="mb-6 rounded-[2rem] border border-emerald-200 bg-emerald-50 p-5 text-emerald-800 shadow-sm">
        <?php echo htmlspecialchars($successMessage); ?>
      </div>
    <?php endif; ?>

    <form method="get" class="mb-6 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm">
      <div class="grid gap-4 sm:grid-cols-3">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Search customer</span>
          <input name="search" type="text" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, phone or customer number" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
        <div class="sm:col-span-2 flex items-end gap-3">
          <button type="submit" class="rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Search</button>
          <a href="customer_debts.php" class="rounded-3xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Clear</a>
        </div>
      </div>
    </form>

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Customers with debt</p>
        <p class="mt-3 text-3xl font-semibold text-slate-900"><?php echo number_format($totalCustomers); ?></p>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Total outstanding balance</p>
        <p class="mt-3 text-3xl font-semibold text-slate-900"><?php echo formatMoney($totalOutstanding); ?></p>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Open credit invoices</p>
        <p class="mt-3 text-3xl font-semibold text-slate-900"><?php echo number_format($totalInvoices); ?></p>
      </div>
    </div>

    <section class="mb-6 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="mb-4 text-xl font-semibold text-slate-900">Customer summary</h2>
      <?php if (count($customers) === 0): ?>
        <p class="text-slate-500">No customers with outstanding balances were found.</p>
      <?php else: ?>
        <div class="overflow-hidden rounded-[1.5rem] border border-slate-200">
          <table class="min-w-full text-left text-sm text-slate-700">
            <thead class="bg-slate-100 text-slate-900">
              <tr>
                <th class="px-4 py-3 font-semibold">Customer</th>
                <th class="px-4 py-3 font-semibold">Phone</th>
                <th class="px-4 py-3 font-semibold">Outstanding</th>
                <th class="px-4 py-3 font-semibold">Open invoices</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3 font-semibold">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($customers as $customer): ?>
                <tr class="border-t border-slate-200 hover:bg-slate-50">
                  <td class="px-4 py-4 font-semibold text-slate-900"><?php echo htmlspecialchars($customer['name']); ?></td>
                  <td class="px-4 py-4"><?php echo htmlspecialchars($customer['phone'] ?: '-'); ?></td>
                  <td class="px-4 py-4 text-slate-900"><?php echo formatMoney($customer['current_balance']); ?></td>
                  <td class="px-4 py-4"><?php echo number_format($customer['unpaid_invoice_count']); ?></td>
                  <td class="px-4 py-4 uppercase tracking-[0.12em] text-xs text-slate-600"><?php echo htmlspecialchars($customer['status']); ?></td>
                  <td class="px-4 py-4">
                    <button
                      type="button"
                      class="view-details-button rounded-full border border-slate-200 bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-200"
                      data-customer-id="<?php echo htmlspecialchars($customer['id']); ?>"
                      data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                      data-customer-balance="<?php echo htmlspecialchars($customer['current_balance']); ?>"
                    >
                      View details
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="mb-4 text-xl font-semibold text-slate-900">Open invoices</h2>
      <?php if (count($invoices) === 0): ?>
        <p class="text-slate-500">No unpaid credit invoices were found.</p>
      <?php else: ?>
        <div class="overflow-hidden rounded-[1.5rem] border border-slate-200">
          <table class="min-w-full text-left text-sm text-slate-700">
            <thead class="bg-slate-100 text-slate-900">
              <tr>
                <th class="px-4 py-3 font-semibold">Invoice</th>
                <th class="px-4 py-3 font-semibold">Customer</th>
                <th class="px-4 py-3 font-semibold">Total</th>
                <th class="px-4 py-3 font-semibold">Balance</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3 font-semibold">Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($invoices as $invoice): ?>
                <tr class="border-t border-slate-200 hover:bg-slate-50">
                  <td class="px-4 py-4 font-semibold text-slate-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                  <td class="px-4 py-4"><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                  <td class="px-4 py-4 text-slate-900"><?php echo formatMoney($invoice['total_amount']); ?></td>
                  <td class="px-4 py-4 text-rose-600"><?php echo formatMoney($invoice['balance']); ?></td>
                  <td class="px-4 py-4 uppercase tracking-[0.12em] text-xs text-slate-600"><?php echo htmlspecialchars($invoice['status']); ?></td>
                  <td class="px-4 py-4"><?php echo htmlspecialchars(date('Y-m-d', strtotime($invoice['created_at']))); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div id="customerDetailsModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-4xl overflow-hidden rounded-[2rem] bg-white shadow-xl">
      <div class="flex flex-col gap-4 border-b border-slate-200 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p class="text-sm font-medium text-slate-500">Customer details</p>
          <h2 id="modalCustomerName" class="mt-1 text-2xl font-semibold text-slate-900">Customer name</h2>
          <p id="modalCustomerSummary" class="mt-1 text-sm text-slate-500">Open invoices and outstanding balance.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button id="openPaySelectedInvoicesButton" type="button" class="rounded-3xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">Pay selected invoices</button>
          <button id="printCustomerStatementButton" type="button" class="rounded-3xl bg-cyan-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-cyan-700">Print</button>
          <button id="closeCustomerDetailsModal" type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 text-xl font-semibold text-slate-700 transition hover:bg-slate-100">×</button>
        </div>
      </div>
      <div class="space-y-4 px-6 py-5">
        <div class="overflow-hidden rounded-[1.5rem] border border-slate-200">
          <table class="min-w-full text-left text-sm text-slate-700">
            <thead class="bg-slate-100 text-slate-900">
              <tr>
                <th class="px-4 py-3 font-semibold">Invoice</th>
                <th class="px-4 py-3 font-semibold">Total</th>
                <th class="px-4 py-3 font-semibold">Balance</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3 font-semibold">Created</th>
                <th class="px-4 py-3 font-semibold">Select</th>
              </tr>
            </thead>
            <tbody id="modalInvoiceRows">
              <tr>
                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Select a customer to view invoice details.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div id="payInvoiceModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-xl overflow-hidden rounded-[2rem] bg-white shadow-xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <div>
          <p class="text-sm font-medium text-slate-500">Invoice payment</p>
          <h2 class="mt-1 text-2xl font-semibold text-slate-900">Pay invoice</h2>
        </div>
        <button id="closePayInvoiceModal" type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 text-xl font-semibold text-slate-700 transition hover:bg-slate-100">×</button>
      </div>
      <form id="payInvoiceForm" method="post" class="space-y-4 px-6 py-5">
        <input type="hidden" name="action" value="pay_invoice">
        <input type="hidden" name="invoice_id" id="payInvoiceId" value="">
        <div id="selectedInvoicesContainer"></div>
        <div>
          <label class="block text-sm font-medium text-slate-700">Selected invoices</label>
          <input id="payInvoiceNumber" type="text" readonly class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-700">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700">Outstanding balance</label>
          <input id="payInvoiceBalance" type="text" readonly class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-700">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700">Amount to pay</label>
          <input id="payInvoiceAmount" name="payment_amount" type="number" step="0.01" min="0.01" placeholder="0.00" required class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-slate-900">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700">Payment method</label>
          <select id="payInvoiceMethod" name="payment_method" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-slate-900">
            <option value="cash">Cash</option>
            <option value="equity">Equity PayBill</option>
            <option value="card">Card</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700">Transaction / reference</label>
          <input id="payInvoiceTransaction" name="transaction_code" type="text" placeholder="Optional reference" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-slate-900">
          <p id="payInvoiceTransactionHint" class="mt-2 text-sm text-slate-500">Required for Equity PayBill payment.</p>
        </div>
        <div class="flex flex-wrap justify-end gap-3">
          <button type="button" id="printInvoiceReceiptButton" class="rounded-3xl border border-slate-200 bg-slate-100 px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-200">Print receipt</button>
          <button type="button" id="cancelPayInvoiceButton" class="rounded-3xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Cancel</button>
          <button type="submit" class="rounded-3xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Submit payment</button>
        </div>
      </form>
    </div>
  </div>

  <div id="printOutput">
    <div class="receipt-wrapper">
      <div class="logo-wrap">
        <img src="logo.png" alt="Store logo">
      </div>
      <pre id="printOutputText"></pre>
    </div>
  </div>

  <script>
    const allCustomerInvoices = <?php echo json_encode($invoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const invoiceItemsByInvoiceId = <?php echo json_encode($invoiceItemsByInvoiceId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const serverReceiptNumber = <?php echo json_encode($_GET['receipt_number'] ?? ''); ?>;

    const detailsModal = document.getElementById('customerDetailsModal');
    const modalCustomerName = document.getElementById('modalCustomerName');
    const modalCustomerSummary = document.getElementById('modalCustomerSummary');
    const modalInvoiceRows = document.getElementById('modalInvoiceRows');
    const closeCustomerDetailsModal = document.getElementById('closeCustomerDetailsModal');
    const printCustomerStatementButton = document.getElementById('printCustomerStatementButton');
    const printOutputText = document.getElementById('printOutputText');
    let currentCustomer = null;

    function wrapText(text, width) {
      const cleaned = String(text || '').trim();
      if (cleaned === '') {
        return [];
      }
      const regex = new RegExp(`.{1,${width}}`, 'g');
      return cleaned.match(regex) || [];
    }

    function formatThermalLine(left, right, width = 32) {
      const leftText = String(left);
      const rightText = String(right);
      const spaces = Math.max(width - leftText.length - rightText.length, 1);
      return leftText + ' '.repeat(spaces) + rightText;
    }

    function renderCustomerInvoices(customerId) {
      const invoices = allCustomerInvoices.filter(invoice => String(invoice.customer_id) === String(customerId));
      if (invoices.length === 0) {
        modalInvoiceRows.innerHTML = '<tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">No open invoices found for this customer.</td></tr>';
        return;
      }

      modalInvoiceRows.innerHTML = invoices.map(invoice => `
        <tr class="border-t border-slate-200 hover:bg-slate-50">
          <td class="px-4 py-4 font-semibold text-slate-900">${invoice.invoice_number}</td>
          <td class="px-4 py-4 text-slate-900">KES ${Number(invoice.total_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
          <td class="px-4 py-4 text-rose-600">KES ${Number(invoice.balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
          <td class="px-4 py-4 uppercase tracking-[0.12em] text-xs text-slate-600">${invoice.status}</td>
          <td class="px-4 py-4">${new Date(invoice.created_at).toISOString().slice(0, 10)}</td>
          <td class="px-4 py-4">
            <label class="inline-flex items-center gap-2 text-xs font-medium text-slate-700">
              <input type="checkbox" class="invoice-select-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" data-invoice-id="${invoice.id}" data-invoice-number="${invoice.invoice_number}" data-invoice-balance="${invoice.balance}" />
              Select
            </label>
          </td>
        </tr>
      `).join('');
    }

    function buildPrintStatement(customerId, customerName, balance) {
      const invoices = allCustomerInvoices.filter(invoice => String(invoice.customer_id) === String(customerId));
      const totalBalance = invoices.reduce((sum, invoice) => sum + Number(invoice.balance), 0);
      const headerLines = [
        'SMART POS SYSTEM',
        'CUSTOMER DEBT STATEMENT',
        '------------------------------',
        `Customer: ${customerName}`,
        `Outstanding: KES ${Number(balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`,
        `Date: ${new Date().toLocaleString()}`,
        '------------------------------',
        'INV    TOTAL   BAL',
      ];

      const detailLines = invoices.flatMap(invoice => {
        const invoiceLine = `${invoice.invoice_number}`.padEnd(8).slice(0, 8) + ' ' + Number(invoice.total_amount).toFixed(2).padStart(7) + ' ' + Number(invoice.balance).toFixed(2).padStart(7);
        const metaLine = `${invoice.status.toUpperCase()} ${invoice.created_at.slice(0, 10)}`;
        const invoiceItems = invoiceItemsByInvoiceId[invoice.id] || [];
        const itemLines = invoiceItems.flatMap(item => {
          const wrappedName = wrapText(item.product_name, 24);
          const productLines = wrappedName.map(line => `  ${line}`);
          const qtyLine = formatThermalLine(`${item.quantity}x${Number(item.unit_price).toFixed(2)}`, `KES ${Number(item.line_total).toFixed(2)}`);
          return productLines.concat([qtyLine]);
        });
        return [invoiceLine, metaLine].concat(itemLines);
      });

      const footerLines = [
        '------------------------------',
        `Invoice count: ${invoices.length}`,
        `Total due: KES ${totalBalance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`,
        '------------------------------',
        'THANK YOU',
      ];

      printStatementText.textContent = headerLines.concat(detailLines, footerLines).join('\n');
    }

    function openCustomerDetailsModal(customerId, customerName, balance) {
      currentCustomer = { id: customerId, name: customerName, balance };
      modalCustomerName.textContent = customerName;
      modalCustomerSummary.textContent = `Outstanding balance: KES ${Number(balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
      renderCustomerInvoices(customerId);
      detailsModal.classList.remove('hidden');
      detailsModal.classList.add('flex');
    }

    function closeModal() {
      detailsModal.classList.add('hidden');
      detailsModal.classList.remove('flex');
    }

    document.querySelectorAll('.view-details-button').forEach(button => {
      button.addEventListener('click', () => {
        openCustomerDetailsModal(button.dataset.customerId, button.dataset.customerName, button.dataset.customerBalance);
      });
    });

    const payInvoiceModal = document.getElementById('payInvoiceModal');
    const closePayInvoiceModal = document.getElementById('closePayInvoiceModal');
    const cancelPayInvoiceButton = document.getElementById('cancelPayInvoiceButton');
    const payInvoiceForm = document.getElementById('payInvoiceForm');
    const payInvoiceId = document.getElementById('payInvoiceId');
    const payInvoiceNumber = document.getElementById('payInvoiceNumber');
    const payInvoiceBalance = document.getElementById('payInvoiceBalance');
    const payInvoiceAmount = document.getElementById('payInvoiceAmount');
    const payInvoiceMethod = document.getElementById('payInvoiceMethod');
    const payInvoiceTransaction = document.getElementById('payInvoiceTransaction');
    const payInvoiceTransactionHint = document.getElementById('payInvoiceTransactionHint');
    const printInvoiceReceiptButton = document.getElementById('printInvoiceReceiptButton');
    const selectedInvoicesContainer = document.getElementById('selectedInvoicesContainer');
    const openPaySelectedInvoicesButton = document.getElementById('openPaySelectedInvoicesButton');
    const invoicePaymentReceiptKey = 'invoicePaymentReceiptData';

    function openPayInvoiceModal(invoiceId, invoiceNumber, invoiceBalance, selectedInvoiceIds = []) {
      payInvoiceId.value = invoiceId;
      payInvoiceNumber.value = invoiceNumber;
      payInvoiceBalance.value = 'KES ' + Number(invoiceBalance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
      payInvoiceAmount.value = invoiceBalance;
      payInvoiceMethod.value = 'cash';
      payInvoiceTransaction.value = '';
      payInvoiceTransactionHint.textContent = 'Required for Equity PayBill payment.';
      selectedInvoicesContainer.innerHTML = '';
      if (selectedInvoiceIds.length > 0) {
        payInvoiceNumber.value = `Selected invoices (${selectedInvoiceIds.length})`;
        selectedInvoiceIds.forEach(id => {
          const hiddenInput = document.createElement('input');
          hiddenInput.type = 'hidden';
          hiddenInput.name = 'selected_invoice_ids[]';
          hiddenInput.value = id;
          selectedInvoicesContainer.appendChild(hiddenInput);
        });
      }
      payInvoiceModal.classList.remove('hidden');
      payInvoiceModal.classList.add('flex');
    }

    function closePayModal() {
      payInvoiceModal.classList.add('hidden');
      payInvoiceModal.classList.remove('flex');
    }

    function buildInvoicePaymentReceipt(customerId, customerName, amountPaid, paymentMethod, transactionCode, receiptNumber = '') {
      const selectedIds = Array.from(document.querySelectorAll('input[name="selected_invoice_ids[]"]')).map(input => input.value);
      if (selectedIds.length === 0 && payInvoiceId.value) {
        selectedIds.push(payInvoiceId.value);
      }
      const selectedSet = new Set(selectedIds.map(String));
      const openInvoices = allCustomerInvoices
        .filter(invoice => String(invoice.customer_id) === String(customerId) && Number(invoice.balance) > 0)
        .sort((a, b) => new Date(a.created_at) - new Date(b.created_at) || a.id - b.id);
      const paymentAmount = Number(amountPaid || 0);
      let remainingPayment = paymentAmount;
      const receiptLines = [];
      const selectedBalanceTotal = openInvoices
        .filter(invoice => selectedSet.has(String(invoice.id)))
        .reduce((sum, invoice) => sum + Number(invoice.balance), 0);
      const totalOpenBalance = openInvoices.reduce((sum, invoice) => sum + Number(invoice.balance), 0);

      receiptLines.push('SMART POS SYSTEM');
      receiptLines.push('INVOICE PAYMENT RECEIPT');
      receiptLines.push('------------------------------');
      receiptLines.push(`Customer: ${customerName}`);
      receiptLines.push(`Date: ${new Date().toLocaleString()}`);
      if (receiptNumber) {
        receiptLines.push(`Receipt: ${receiptNumber}`);
      }
      receiptLines.push(`Payment method: ${String(paymentMethod).replace('_', ' ').toUpperCase()}`);
      if (transactionCode.trim() !== '') {
        receiptLines.push(`Reference: ${transactionCode}`);
      }
      receiptLines.push('------------------------------');
      receiptLines.push(`Open invoices: ${openInvoices.length}`);
      receiptLines.push(`Open balance: KES ${totalOpenBalance.toFixed(2)}`);
      receiptLines.push(`Selected invoices: ${selectedIds.length}`);
      receiptLines.push(`Selected balance: KES ${selectedBalanceTotal.toFixed(2)}`);
      receiptLines.push('------------------------------');
      receiptLines.push('INV   TOTAL    PAID    REM');

      let remainingDueTotal = 0;
      openInvoices.forEach(invoice => {
        const invoiceTotal = Number(invoice.total_amount);
        const invoiceBalance = Number(invoice.balance);
        let paid = 0;
        if (selectedSet.has(String(invoice.id)) && remainingPayment > 0) {
          paid = Math.min(remainingPayment, invoiceBalance);
          remainingPayment -= paid;
        }
        const remaining = invoiceBalance - paid;
        remainingDueTotal += remaining;
        const invoiceLine = `${invoice.invoice_number}`.padEnd(6).slice(0, 6)
          + ' ' + formatThermalNumber(invoiceTotal, 7)
          + ' ' + formatThermalNumber(paid, 7)
          + ' ' + formatThermalNumber(remaining, 7);
        receiptLines.push(invoiceLine);
      });

      receiptLines.push('------------------------------');
      receiptLines.push(`Total paid: KES ${paymentAmount.toFixed(2)}`);
      receiptLines.push(`Remaining open balance: KES ${remainingDueTotal.toFixed(2)}`);
      receiptLines.push('------------------------------');
      receiptLines.push('THANK YOU!');
      receiptLines.push('SMART POS SYSTEM');

      printOutputText.textContent = receiptLines.join('\n');
    }

    function formatThermalNumber(value, width = 7) {
      return String(Number(value).toFixed(2)).padStart(width);
    }

    let selectedInvoiceIds = [];
    let selectedInvoiceBalance = 0;
    let selectedInvoiceNumbers = [];

    function refreshSelectedInvoices() {
      selectedInvoiceIds = [];
      selectedInvoiceBalance = 0;
      selectedInvoiceNumbers = [];
      document.querySelectorAll('.invoice-select-checkbox').forEach(checkbox => {
        if (checkbox.checked) {
          selectedInvoiceIds.push(checkbox.dataset.invoiceId);
          selectedInvoiceBalance += Number(checkbox.dataset.invoiceBalance || 0);
          selectedInvoiceNumbers.push(checkbox.dataset.invoiceNumber);
        }
      });
      openPaySelectedInvoicesButton.disabled = selectedInvoiceIds.length === 0;
    }

    document.addEventListener('change', event => {
      const checkbox = event.target.closest('.invoice-select-checkbox');
      if (!checkbox) return;
      refreshSelectedInvoices();
    });

    document.addEventListener('click', event => {
      const button = event.target.closest('.pay-invoice-button');
      if (button) {
        openPayInvoiceModal(button.dataset.invoiceId, button.dataset.invoiceNumber, button.dataset.invoiceBalance);
        return;
      }
    });

    openPaySelectedInvoicesButton.addEventListener('click', () => {
      if (selectedInvoiceIds.length === 0) {
        alert('Select at least one invoice to pay.');
        return;
      }
      openPayInvoiceModal('', selectedInvoiceNumbers.join(', '), selectedInvoiceBalance.toFixed(2), selectedInvoiceIds);
    });

    payInvoiceMethod.addEventListener('change', () => {
      if (payInvoiceMethod.value === 'equity') {
        payInvoiceTransactionHint.textContent = 'Enter the Equity transaction code.';
      } else {
        payInvoiceTransactionHint.textContent = 'Transaction/reference is optional.';
      }
    });

    printInvoiceReceiptButton.addEventListener('click', () => {
      if (!currentCustomer) {
        return;
      }
      const amountPaid = payInvoiceAmount.value;
      const paymentMethod = payInvoiceMethod.value;
      const transactionCode = payInvoiceTransaction.value;
      buildInvoicePaymentReceipt(currentCustomer.id, currentCustomer.name, amountPaid, paymentMethod, transactionCode, serverReceiptNumber);
      window.print();
    });

    payInvoiceForm.addEventListener('submit', () => {
      if (!currentCustomer) {
        return;
      }
      const receiptData = {
        customerId: currentCustomer.id,
        customerName: currentCustomer.name,
        amountPaid: payInvoiceAmount.value,
        paymentMethod: payInvoiceMethod.value,
        transactionCode: payInvoiceTransaction.value,
        selectedInvoiceIds: Array.from(document.querySelectorAll('input[name="selected_invoice_ids[]"]')).map(input => input.value),
        invoiceId: payInvoiceId.value,
      };
      localStorage.setItem(invoicePaymentReceiptKey, JSON.stringify(receiptData));
    });

    closePayInvoiceModal.addEventListener('click', closePayModal);
    cancelPayInvoiceButton.addEventListener('click', closePayModal);
    payInvoiceModal.addEventListener('click', event => {
      if (event.target === payInvoiceModal) {
        closePayModal();
      }
    });

    printCustomerStatementButton.addEventListener('click', () => {
      if (!currentCustomer) {
        return;
      }
      buildPrintStatement(currentCustomer.id, currentCustomer.name, currentCustomer.balance);
      window.print();
    });

    function replayPendingInvoiceReceipt() {
      const raw = localStorage.getItem(invoicePaymentReceiptKey);
      if (!raw) {
        return;
      }
      localStorage.removeItem(invoicePaymentReceiptKey);
      let receiptData;
      try {
        receiptData = JSON.parse(raw);
      } catch (error) {
        return;
      }
      if (!receiptData || !receiptData.customerId) {
        return;
      }
      const selected = receiptData.selectedInvoiceIds || [];
      const hiddenInputs = selected.map(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_invoice_ids[]';
        input.value = id;
        return input;
      });
      selectedInvoicesContainer.innerHTML = '';
      hiddenInputs.forEach(input => selectedInvoicesContainer.appendChild(input));
      buildInvoicePaymentReceipt(
        receiptData.customerId,
        receiptData.customerName,
        receiptData.amountPaid,
        receiptData.paymentMethod,
        receiptData.transactionCode || '',
        serverReceiptNumber || receiptData.receiptNumber || ''
      );
      window.print();
    }

    closeCustomerDetailsModal.addEventListener('click', closeModal);
    detailsModal.addEventListener('click', event => {
      if (event.target === detailsModal) {
        closeModal();
      }
    });

    replayPendingInvoiceReceipt();
  </script>
  <script src="assets/js/cashier-session.js"></script>
</body>
</html>
