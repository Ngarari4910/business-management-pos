<?php
session_start();
require __DIR__ . '/security.php';
requireCashierPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';

$receiptNumber = trim($_GET['receipt'] ?? '');
$receiptData = null;
$receiptItems = [];
$notice = '';

if ($receiptNumber !== '') {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.total_amount, s.amount_tendered, s.change_amount, s.payment_method, s.created_at, sp.receipt_number, sp.transaction_code, sp.amount AS payment_amount, sp.status AS payment_status ' .
        'FROM sales s ' .
        'JOIN sale_payments sp ON sp.sale_id = s.id ' .
        'WHERE sp.receipt_number = :receipt_number OR s.id = :sale_id LIMIT 1'
    );
    $stmt->execute([
        'receipt_number' => $receiptNumber,
        'sale_id' => intval($receiptNumber)
    ]);
    $receiptData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($receiptData) {
        $receiptData['receipt_number'] = $receiptData['receipt_number'] ?: $receiptNumber;
        $itemStmt = $pdo->prepare('SELECT product_name, quantity, unit_price, line_total FROM sale_items WHERE sale_id = :sale_id ORDER BY id ASC');
        $itemStmt->execute(['sale_id' => intval($receiptData['id'])]);
        $receiptItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $notice = 'Receipt not found.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receipt Verification</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
    .card { max-width: 760px; margin: 40px auto; background: #fff; border-radius: 18px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); padding: 24px; }
    .title { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
    .muted { color: #64748b; }
    .grid { display: grid; gap: 12px; margin-top: 18px; }
    .row { display: flex; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
    .label { font-weight: 600; color: #334155; }
    .value { text-align: right; }
    .badge { display: inline-block; padding: 6px 10px; border-radius: 999px; background: #dcfce7; color: #166534; font-weight: 600; }
    .badge.warn { background: #fef3c7; color: #92400e; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 8px 0; border-bottom: 1px solid #e2e8f0; text-align: left; }
    .totals { margin-top: 16px; display: grid; gap: 8px; }
    .total-row { display: flex; justify-content: space-between; }
    a { color: #0f766e; }
  </style>
</head>
<body>
  <div class="card">
    <div class="title">Receipt verification</div>
    <div class="muted">This page confirms the digital copy of a printed POS receipt.</div>

    <?php if ($notice !== ''): ?>
      <p class="badge warn"><?php echo htmlspecialchars($notice); ?></p>
    <?php elseif ($receiptData): ?>
      <p class="badge">Receipt found and verified</p>
      <div class="grid">
        <div class="row"><span class="label">Receipt number</span><span class="value"><?php echo htmlspecialchars($receiptData['receipt_number'] ?? $receiptNumber); ?></span></div>
        <div class="row"><span class="label">Sale ID</span><span class="value"><?php echo htmlspecialchars($receiptData['id'] ?? ''); ?></span></div>
        <div class="row"><span class="label">Payment method</span><span class="value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $receiptData['payment_method'] ?? ''))); ?></span></div>
        <div class="row"><span class="label">Payment status</span><span class="value"><?php echo htmlspecialchars($receiptData['payment_status'] ?? ''); ?></span></div>
        <div class="row"><span class="label">Recorded at</span><span class="value"><?php echo htmlspecialchars($receiptData['created_at'] ?? ''); ?></span></div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Item</th>
            <th>Qty</th>
            <th>Unit price</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($receiptItems as $item): ?>
            <tr>
              <td><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars($item['quantity'] ?? ''); ?></td>
              <td>KES <?php echo number_format(floatval($item['unit_price'] ?? 0), 2); ?></td>
              <td>KES <?php echo number_format(floatval($item['line_total'] ?? 0), 2); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="totals">
        <div class="total-row"><span class="label">Subtotal</span><span class="value">KES <?php echo number_format(floatval($receiptData['total_amount'] ?? 0), 2); ?></span></div>
        <div class="total-row"><span class="label">Amount tendered</span><span class="value">KES <?php echo number_format(floatval($receiptData['amount_tendered'] ?? 0), 2); ?></span></div>
        <div class="total-row"><span class="label">Change</span><span class="value">KES <?php echo number_format(floatval($receiptData['change_amount'] ?? 0), 2); ?></span></div>
        <div class="total-row"><span class="label">Transaction code</span><span class="value"><?php echo htmlspecialchars($receiptData['transaction_code'] ?? '—'); ?></span></div>
      </div>
    <?php else: ?>
      <p class="muted">Scan a valid receipt QR code to view the verification details.</p>
    <?php endif; ?>
  </div>
  <script src="assets/js/cashier-session.js"></script>
</body>
</html>
