<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';

if (empty($_SESSION['user_logged_in']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$errors = [];
$successMessage = '';
$showPayrollModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $paymentId = intval($_POST['payment_id'] ?? 0);
    $allowanceAmount = floatval($_POST['allowance_amount'] ?? 0);
    $deductionAmount = floatval($_POST['deduction_amount'] ?? 0);
    $paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
    $paymentNotes = trim((string) ($_POST['payment_notes'] ?? ''));

    if ($paymentId <= 0) {
        $errors[] = 'Please select a payroll entry to mark as paid.';
    } else {
        try {
            updateSalaryPaymentBreakdown($pdo, $paymentId, $allowanceAmount, $deductionAmount, $paymentDate, $paymentNotes);
            $redirectPaymentId = $paymentId;
            header('Location: salary_slip.php?payment_id=' . intval($redirectPaymentId) . '&print=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_payroll'])) {
    $employeeId = intval($_POST['employee_id'] ?? 0);
    $payPeriodStart = trim((string) ($_POST['pay_period_start'] ?? ''));
    $payPeriodEnd = trim((string) ($_POST['pay_period_end'] ?? ''));
    $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
    $allowanceAmount = floatval($_POST['allowance_amount'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($employeeId <= 0 || $payPeriodStart === '' || $payPeriodEnd === '' || $paymentDate === '') {
        $errors[] = 'Please select an employee and complete the payroll dates.';
    }

    if (!empty($errors)) {
        $showPayrollModal = true;
    }

    if (empty($errors)) {
        try {
            buildMonthlySalaryPayment($pdo, $employeeId, $payPeriodStart, $payPeriodEnd, $paymentDate, $allowanceAmount, $notes);
            $successMessage = 'Payroll entry created successfully and is waiting for the admin to mark it as paid.';
            $_POST = [];
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $showPayrollModal = true;
        }
    }
}

$stmt = $pdo->query(
    "SELECT u.id, u.full_name, u.employee_id, u.role, u.status,
            es.base_amount, es.salary_type, es.department
     FROM users u
     LEFT JOIN employee_salaries es ON es.employee_id = u.id
     WHERE u.status = 'active'
     ORDER BY u.full_name ASC"
);
$employees = $stmt->fetchAll();

$today = date('Y-m-d');
$autoResetStmt = $pdo->prepare("UPDATE salary_payments SET payment_status = 'pending' WHERE payment_status <> 'paid' AND pay_period_end <= :today");
$autoResetStmt->execute(['today' => $today]);

$paymentPage = max(1, (int) ($_GET['page'] ?? 1));
$paymentPageSize = 25;
$paymentTotalCount = (int) $pdo->query('SELECT COUNT(*) FROM salary_payments')->fetchColumn();
$paymentTotalPages = max(1, (int) ceil($paymentTotalCount / $paymentPageSize));
$paymentPage = min($paymentPage, $paymentTotalPages);
$paymentOffset = ($paymentPage - 1) * $paymentPageSize;

$paymentsStmt = $pdo->prepare(
    "SELECT sp.id, sp.pay_period_start, sp.pay_period_end, sp.payment_date, sp.gross_pay, sp.total_deductions, sp.net_pay, sp.payment_status, u.full_name, u.employee_id, u.role
     FROM salary_payments sp
     JOIN users u ON u.id = sp.employee_id
    ORDER BY sp.payment_date DESC, sp.id DESC
  LIMIT :limit OFFSET :offset"
);
$paymentsStmt->bindValue(':limit', $paymentPageSize, PDO::PARAM_INT);
$paymentsStmt->bindValue(':offset', $paymentOffset, PDO::PARAM_INT);
$paymentsStmt->execute();
$payments = $paymentsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Payroll — SMART POS SYSTEM</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <div class="admin-page-shell mx-auto max-w-7xl px-4 py-6">
    <div class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h1 class="text-2xl font-semibold">Payroll</h1>
        <p class="mt-1 text-sm text-slate-500">Monthly payroll and variance deductions from shift records.</p>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="admin.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200">Dashboard</a>
          <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Staff</a>
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
        <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Staff</a>
      </div>
    </div>

    <?php if ($successMessage): ?>
      <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
        <?php echo htmlspecialchars($successMessage); ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="mb-6 rounded-3xl border border-rose-200 bg-rose-50 p-4 text-rose-900">
        <ul class="list-disc space-y-2 pl-5">
          <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold text-slate-900">Payroll entries</h2>
        <p class="text-sm text-slate-500">Create payroll entries and review recent payouts.</p>
      </div>
      <button id="openPayrollModal" type="button" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white">Create payroll entry</button>
    </div>

    <div class="grid gap-6 lg:grid-cols-[1fr]">
      <div id="payrollModal" style="position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center; background:rgba(15,23,42,0.75); padding:1rem; <?php echo $showPayrollModal ? 'display:flex;' : 'display:none;'; ?>">
        <div style="width:100%; max-width:760px; max-height:90vh; overflow-y:auto; border-radius:24px; border:1px solid #e2e8f0; background:#ffffff; box-shadow:0 24px 80px rgba(0,0,0,0.25); padding:24px;">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; border:1px solid #e2e8f0; border-radius:16px; background:#f8fafc; padding:14px 16px;">
            <div>
              <h2 style="font-size:20px; font-weight:700; color:#0f172a; margin:0;">Create payroll entry</h2>
              <p style="font-size:13px; color:#64748b; margin:4px 0 0;">Create a payroll entry from the selected employee profile and shift variance data.</p>
            </div>
            <button id="closePayrollModal" type="button" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; border-radius:999px; background:#ffffff; color:#334155; padding:8px 14px; font-size:14px; font-weight:600; cursor:pointer;">Close</button>
          </div>
          <form method="POST" style="display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" name="create_payroll" value="1">
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Employee</span>
              <select name="employee_id" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px; background:#ffffff;" required>
                <option value="">Select employee</option>
                <?php foreach ($employees as $employee): ?>
                  <option value="<?php echo intval($employee['id']); ?>">
                    <?php echo htmlspecialchars($employee['full_name']); ?> — <?php echo htmlspecialchars($employee['role'] ?? 'employee'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <div style="display:grid; gap:16px; grid-template-columns:repeat(2, minmax(0, 1fr));">
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Pay period start</span>
                <input type="date" name="pay_period_start" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" required>
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Pay period end</span>
                <input type="date" name="pay_period_end" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" required>
              </label>
            </div>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Payment date</span>
              <input type="date" name="payment_date" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" required>
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Allowance</span>
              <input type="number" step="0.01" min="0" name="allowance_amount" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" value="0">
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Notes</span>
              <textarea name="notes" rows="3" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;"></textarea>
            </label>
            <div style="display:flex; flex-wrap:gap:12px; margin-top:8px;">
              <button type="submit" style="border:0; border-radius:999px; background:#0f172a; color:#ffffff; padding:12px 18px; font-size:14px; font-weight:700; cursor:pointer;">Create payroll entry</button>
              <button id="cancelPayrollModal" type="button" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; border-radius:999px; background:#ffffff; color:#334155; padding:12px 18px; font-size:14px; font-weight:700; cursor:pointer;">Cancel</button>
            </div>
          </form>
        </div>
      </div>

      <div id="payModal" style="position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center; background:rgba(15,23,42,0.75); padding:1rem; display:none;">
        <div style="width:100%; max-width:760px; max-height:90vh; overflow-y:auto; border-radius:24px; border:1px solid #e2e8f0; background:#ffffff; box-shadow:0 24px 80px rgba(0,0,0,0.25); padding:24px;">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; border:1px solid #e2e8f0; border-radius:16px; background:#f8fafc; padding:14px 16px;">
            <div>
              <h2 style="font-size:20px; font-weight:700; color:#0f172a; margin:0;">Confirm payroll payment</h2>
              <p style="font-size:13px; color:#64748b; margin:4px 0 0;">Add allowance and deductions, then mark the payroll entry as paid and print the payslip.</p>
            </div>
            <button id="closePayModal" type="button" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; border-radius:999px; background:#ffffff; color:#334155; padding:8px 14px; font-size:14px; font-weight:600; cursor:pointer;">Close</button>
          </div>
          <form id="payModalForm" method="POST" style="display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" name="mark_paid" value="1">
            <input type="hidden" name="payment_id" id="payModalPaymentId" value="0">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm text-slate-500">Employee</p>
              <p id="payModalEmployee" class="mt-1 font-semibold text-slate-900">—</p>
              <p id="payModalPeriod" class="mt-1 text-sm text-slate-600">—</p>
            </div>
            <div style="display:grid; gap:16px; grid-template-columns:repeat(2, minmax(0, 1fr));">
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Allowance</span>
                <input type="number" step="0.01" min="0" name="allowance_amount" id="payModalAllowance" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" value="0">
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Deductions</span>
                <input type="number" step="0.01" min="0" name="deduction_amount" id="payModalDeduction" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" value="0">
              </label>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
              <p class="font-semibold">Variance deduction</p>
              <p id="payModalVariance" class="mt-1">KES 0.00</p>
            </div>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Payment date</span>
              <input type="date" name="payment_date" id="payModalPaymentDate" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" required>
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Notes</span>
              <textarea name="payment_notes" rows="3" id="payModalNotes" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;"></textarea>
            </label>
            <div style="display:flex; flex-wrap:gap:12px; margin-top:8px;">
              <button type="submit" style="border:0; border-radius:999px; background:#0f172a; color:#ffffff; padding:12px 18px; font-size:14px; font-weight:700; cursor:pointer;">Confirm payment</button>
              <button id="cancelPayModal" type="button" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; border-radius:999px; background:#ffffff; color:#334155; padding:12px 18px; font-size:14px; font-weight:700; cursor:pointer;">Cancel</button>
            </div>
          </form>
        </div>
      </div>

      <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-semibold">Recent payroll entries</h2>
        <div class="mt-5 overflow-x-auto">
          <table class="min-w-full text-left text-sm text-slate-700">
            <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th class="px-3 py-3">Employee</th>
                <th class="px-3 py-3">Period</th>
                <th class="px-3 py-3">Net</th>
                <th class="px-3 py-3">Status</th>
                <th class="px-3 py-3">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <?php foreach ($payments as $payment): ?>
                <?php $paymentStatus = strtolower(trim((string) ($payment['payment_status'] ?? 'pending'))); $isPaid = $paymentStatus === 'paid'; ?>
                <tr>
                  <td class="px-3 py-3">
                    <div class="font-medium text-slate-900"><?php echo htmlspecialchars($payment['full_name']); ?></div>
                    <div class="text-xs text-slate-500"><?php echo htmlspecialchars($payment['employee_id'] ?? '-'); ?></div>
                  </td>
                  <td class="px-3 py-3">
                    <div><?php echo htmlspecialchars($payment['pay_period_start']); ?></div>
                    <div class="text-xs text-slate-500">to <?php echo htmlspecialchars($payment['pay_period_end']); ?></div>
                  </td>
                  <td class="px-3 py-3 font-semibold">KES <?php echo number_format(floatval($payment['net_pay']), 2); ?></td>
                  <td class="px-3 py-3">
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo $isPaid ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'; ?>">
                      <?php echo $isPaid ? 'Paid' : 'Not paid'; ?>
                    </span>
                  </td>
                  <td class="px-3 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                      <button type="button"
                              class="pay-entry-button rounded-full bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-sm"
                              data-payment-id="<?php echo intval($payment['id']); ?>"
                              data-employee-name="<?php echo htmlspecialchars($payment['full_name'], ENT_QUOTES); ?>"
                              data-period-start="<?php echo htmlspecialchars($payment['pay_period_start'], ENT_QUOTES); ?>"
                              data-period-end="<?php echo htmlspecialchars($payment['pay_period_end'], ENT_QUOTES); ?>"
                              data-allowance="<?php echo number_format(floatval($payment['total_allowances'] ?? 0), 2, '.', ''); ?>"
                              data-deduction="<?php echo number_format(floatval($payment['total_deductions'] ?? 0), 2, '.', ''); ?>"
                              data-variance="<?php echo number_format(getEmployeeVarianceDeduction($pdo, intval($payment['id']), (string) $payment['pay_period_start'], (string) $payment['pay_period_end']), 2, '.', ''); ?>"
                              data-payment-date="<?php echo htmlspecialchars($payment['payment_date'] ?? date('Y-m-d'), ENT_QUOTES); ?>">
                        Pay
                      </button>
                      <a href="salary_slip.php?payment_id=<?php echo intval($payment['id']); ?>" class="rounded-full bg-slate-900 px-3 py-2 text-xs font-semibold text-white">View payslip</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mt-4 flex items-center justify-between gap-3 text-sm text-slate-600">
          <span>Page <?php echo $paymentPage; ?> of <?php echo $paymentTotalPages; ?> (<?php echo number_format($paymentTotalCount); ?> entries)</span>
          <div class="flex gap-2">
            <?php if ($paymentPage > 1): ?><a href="admin_payroll.php?page=<?php echo $paymentPage - 1; ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold hover:bg-slate-50">Previous</a><?php endif; ?>
            <?php if ($paymentPage < $paymentTotalPages): ?><a href="admin_payroll.php?page=<?php echo $paymentPage + 1; ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold hover:bg-slate-50">Next</a><?php endif; ?>
          </div>
        </div>
      </section>
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

    const openPayrollModalButton = document.getElementById('openPayrollModal');
    const payrollModal = document.getElementById('payrollModal');
    const closePayrollModalButton = document.getElementById('closePayrollModal');
    const cancelPayrollModalButton = document.getElementById('cancelPayrollModal');
    const payModal = document.getElementById('payModal');
    const closePayModalButton = document.getElementById('closePayModal');
    const cancelPayModalButton = document.getElementById('cancelPayModal');
    const payModalPaymentId = document.getElementById('payModalPaymentId');
    const payModalEmployee = document.getElementById('payModalEmployee');
    const payModalPeriod = document.getElementById('payModalPeriod');
    const payModalAllowance = document.getElementById('payModalAllowance');
    const payModalDeduction = document.getElementById('payModalDeduction');
    const payModalVariance = document.getElementById('payModalVariance');
    const payModalPaymentDate = document.getElementById('payModalPaymentDate');
    const payModalNotes = document.getElementById('payModalNotes');
    const payEntryButtons = document.querySelectorAll('.pay-entry-button');

    const closePayrollModal = () => {
      if (payrollModal) {
        payrollModal.style.display = 'none';
        document.body.style.overflow = '';
      }
    };

    if (openPayrollModalButton && payrollModal) {
      openPayrollModalButton.addEventListener('click', () => {
        payrollModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
      });
    }

    if (closePayrollModalButton) {
      closePayrollModalButton.addEventListener('click', closePayrollModal);
    }

    if (cancelPayrollModalButton) {
      cancelPayrollModalButton.addEventListener('click', closePayrollModal);
    }

    if (payrollModal) {
      payrollModal.addEventListener('click', (event) => {
        if (event.target === payrollModal) {
          closePayrollModal();
        }
      });
    }

    const closePayModal = () => {
      if (payModal) {
        payModal.style.display = 'none';
        document.body.style.overflow = '';
      }
    };

    if (payEntryButtons.length > 0) {
      payEntryButtons.forEach((button) => {
        button.addEventListener('click', () => {
          if (!payModal) {
            return;
          }
          const paymentId = button.getAttribute('data-payment-id') || '0';
          const employeeName = button.getAttribute('data-employee-name') || 'Employee';
          const periodStart = button.getAttribute('data-period-start') || '';
          const periodEnd = button.getAttribute('data-period-end') || '';
          const allowance = button.getAttribute('data-allowance') || '0';
          const deduction = button.getAttribute('data-deduction') || '0';
          const variance = button.getAttribute('data-variance') || '0';
          const paymentDate = button.getAttribute('data-payment-date') || new Date().toISOString().slice(0, 10);

          if (payModalPaymentId) {
            payModalPaymentId.value = paymentId;
          }
          if (payModalEmployee) {
            payModalEmployee.textContent = employeeName;
          }
          if (payModalPeriod) {
            payModalPeriod.textContent = `${periodStart}${periodStart && periodEnd ? ' to ' : ''}${periodEnd}`;
          }
          if (payModalAllowance) {
            payModalAllowance.value = allowance;
          }
          if (payModalDeduction) {
            payModalDeduction.value = deduction;
          }
          if (payModalVariance) {
            payModalVariance.textContent = `KES ${Number(variance).toFixed(2)}`;
          }
          if (payModalPaymentDate) {
            payModalPaymentDate.value = paymentDate;
          }
          if (payModalNotes) {
            payModalNotes.value = '';
          }

          payModal.style.display = 'flex';
          document.body.style.overflow = 'hidden';
        });
      });
    }

    if (closePayModalButton) {
      closePayModalButton.addEventListener('click', closePayModal);
    }

    if (cancelPayModalButton) {
      cancelPayModalButton.addEventListener('click', closePayModal);
    }

    if (payModal) {
      payModal.addEventListener('click', (event) => {
        if (event.target === payModal) {
          closePayModal();
        }
      });
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        if (payrollModal && payrollModal.style.display === 'flex') {
          closePayrollModal();
        }
        if (payModal && payModal.style.display === 'flex') {
          closePayModal();
        }
      }
    });
  </script>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
