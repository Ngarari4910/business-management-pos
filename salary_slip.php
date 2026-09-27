<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';

$paymentId = intval($_GET['payment_id'] ?? 0);
$payment = getSalaryPaymentById($pdo, $paymentId);
if (!$payment) {
    header('Location: admin_payroll.php');
    exit;
}

$components = getSalaryComponents($pdo, $paymentId);
$earnings = 0;
$deductions = 0;
foreach ($components as $component) {
    if (in_array($component['component_type'], ['earning','allowance'], true)) {
        $earnings += floatval($component['amount']);
    } else {
        $deductions += floatval($component['amount']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Payslip — Smart POS Demo</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @media print {
      body { background: #ffffff; padding: 0; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body class="bg-slate-100 p-6 text-slate-900">
  <script>
    window.addEventListener('load', () => {
      const params = new URLSearchParams(window.location.search);
      if (params.get('print') === '1') {
        window.print();
      }
    });
  </script>
  <div class="mx-auto max-w-3xl rounded-[2rem] border border-slate-200 bg-white p-8 shadow-sm">
    <div class="no-print mb-4 flex flex-wrap items-center justify-end gap-2">
      <a href="admin_payroll.php" class="rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Back to payroll</a>
      <button type="button" onclick="window.print()" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Print payslip</button>
    </div>
    <div class="flex items-start justify-between border-b border-slate-200 pb-4">
      <div class="flex items-center gap-3">
        <img src="colour_logo.jpg" alt="Smart POS Demo logo" class="h-14 w-14 rounded-2xl object-cover" />
        <div>
          <h1 class="text-2xl font-semibold">Smart POS Demo</h1>
          <p class="mt-1 text-sm text-slate-500">Salary Slip</p>
        </div>
      </div>
      <div class="text-right text-sm text-slate-500">
        <p>Pay period: <?php echo htmlspecialchars($payment['pay_period_start']); ?> to <?php echo htmlspecialchars($payment['pay_period_end']); ?></p>
        <p>Payment date: <?php echo htmlspecialchars($payment['payment_date']); ?></p>
      </div>
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-2">
      <div>
        <p class="text-sm text-slate-500">Employee</p>
        <p class="mt-1 font-semibold"><?php echo htmlspecialchars($payment['full_name']); ?></p>
        <p class="text-sm text-slate-500"><?php echo htmlspecialchars($payment['employee_id'] ?? '-'); ?> · <?php echo htmlspecialchars($payment['role'] ?? '-'); ?></p>
      </div>
      <div>
        <p class="text-sm text-slate-500">Reference</p>
        <p class="mt-1 font-semibold"><?php echo htmlspecialchars($payment['payroll_reference'] ?? '-'); ?></p>
      </div>
    </div>

    <div class="mt-8 grid gap-6 md:grid-cols-2">
      <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
        <h2 class="font-semibold">Earnings</h2>
        <div class="mt-4 space-y-2 text-sm">
          <?php foreach ($components as $component): ?>
            <?php if (in_array($component['component_type'], ['earning','allowance'], true)): ?>
              <div class="flex justify-between">
                <span><?php echo htmlspecialchars($component['label']); ?></span>
                <span>KES <?php echo number_format(floatval($component['amount']), 2); ?></span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
          <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold">
            <span>Total earnings</span>
            <span>KES <?php echo number_format($earnings, 2); ?></span>
          </div>
        </div>
      </div>

      <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
        <h2 class="font-semibold">Deductions</h2>
        <div class="mt-4 space-y-2 text-sm">
          <?php foreach ($components as $component): ?>
            <?php if (!in_array($component['component_type'], ['earning','allowance'], true)): ?>
              <div class="flex justify-between">
                <span><?php echo htmlspecialchars($component['label']); ?></span>
                <span>KES <?php echo number_format(floatval($component['amount']), 2); ?></span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
          <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold">
            <span>Total deductions</span>
            <span>KES <?php echo number_format($deductions, 2); ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-8 rounded-[1.5rem] border border-emerald-200 bg-emerald-50 p-5">
      <div class="flex items-center justify-between">
        <span class="text-sm text-emerald-700">Net pay</span>
        <span class="text-2xl font-semibold text-emerald-900">KES <?php echo number_format(floatval($payment['net_pay']), 2); ?></span>
      </div>
    </div>

    <div class="mt-8 flex justify-between text-sm text-slate-500">
      <div>Prepared by admin</div>
      <div>Signature</div>
    </div>
  </div>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
