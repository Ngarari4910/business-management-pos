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
$activationLink = '';
$editEmployee = null;
$showCreateModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = trim((string) ($_POST['form_action'] ?? 'create'));

    if ($formAction === 'deactivate') {
        $employeeId = intval($_POST['employee_id'] ?? 0);
        if ($employeeId > 0) {
            if (deactivateEmployeeAccount($pdo, $employeeId)) {
                $successMessage = 'Employee account deactivated successfully.';
            } else {
                $errors[] = 'Unable to deactivate the selected employee account.';
            }
        } else {
            $errors[] = 'No employee was selected.';
        }
    } elseif ($formAction === 'start_edit') {
        $employeeId = intval($_POST['employee_id'] ?? 0);
        $editEmployee = getEmployeeById($pdo, $employeeId);
        if ($editEmployee === null) {
            $errors[] = 'Unable to load the selected employee record.';
        }
    } elseif ($formAction === 'save_edit') {
        $employeeId = intval($_POST['employee_id'] ?? 0);
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
        $employeeIdValue = trim((string) ($_POST['employee_id_value'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $branch = trim((string) ($_POST['branch'] ?? ''));
        $posTerminal = trim((string) ($_POST['pos_terminal'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'cashier'));
        $baseAmount = floatval($_POST['base_amount'] ?? 0);
        $department = trim((string) ($_POST['department'] ?? ''));
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $bankAccount = trim((string) ($_POST['bank_account'] ?? ''));

        if ($fullName === '') {
            $errors[] = 'Full Name is required.';
        }
        if ($phoneNumber === '') {
            $errors[] = 'Phone Number is required.';
        }
        if ($branch === '') {
            $errors[] = 'Branch is required.';
        }
        if ($posTerminal === '') {
            $errors[] = 'POS Terminal is required.';
        }
        if ($employeeIdValue !== '' && preg_match('/[^A-Za-z0-9\-]/', $employeeIdValue)) {
            $errors[] = 'Employee ID may only contain letters, numbers and hyphens.';
        }

        if (empty($errors)) {
            $profile = [
                'full_name' => $fullName,
                'phone_number' => $phoneNumber,
                'employee_id' => $employeeIdValue !== '' ? $employeeIdValue : null,
                'email' => $email !== '' ? $email : null,
                'branch' => $branch,
                'pos_terminal' => $posTerminal,
                'role' => in_array($role, ['cashier','manager','stock_clerk','supervisor','accountant','cleaner'], true) ? $role : 'cashier',
                'base_amount' => $baseAmount,
                'department' => $department !== '' ? $department : null,
                'bank_name' => $bankName !== '' ? $bankName : null,
                'bank_account' => $bankAccount !== '' ? $bankAccount : null,
            ];

            if (updateEmployeeProfile($pdo, $employeeId, $profile)) {
                $successMessage = 'Employee profile updated successfully.';
                $editEmployee = getEmployeeById($pdo, $employeeId);
            } else {
                $errors[] = 'Unable to update the employee profile.';
            }
        }
    } else {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
        $employeeIdValue = trim((string) ($_POST['employee_id'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $branch = trim((string) ($_POST['branch'] ?? ''));
        $posTerminal = trim((string) ($_POST['pos_terminal'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'cashier'));
        $baseAmount = floatval($_POST['base_amount'] ?? 0);
        $department = trim((string) ($_POST['department'] ?? ''));
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $bankAccount = trim((string) ($_POST['bank_account'] ?? ''));

        if ($fullName === '') {
            $errors[] = 'Full Name is required.';
        }
        if ($phoneNumber === '') {
            $errors[] = 'Phone Number is required.';
        }
        if ($branch === '') {
            $errors[] = 'Branch is required.';
        }
        if ($posTerminal === '') {
            $errors[] = 'POS Terminal is required.';
        }
        if ($employeeIdValue !== '' && preg_match('/[^A-Za-z0-9\-]/', $employeeIdValue)) {
            $errors[] = 'Employee ID may only contain letters, numbers and hyphens.';
        }

        if (!empty($errors)) {
            $showCreateModal = true;
        }

        if (empty($errors)) {
            $profile = [
                'full_name' => $fullName,
                'phone_number' => $phoneNumber,
                'employee_id' => $employeeIdValue !== '' ? $employeeIdValue : null,
                'email' => $email !== '' ? $email : null,
                'branch' => $branch,
                'pos_terminal' => $posTerminal,
                'role' => in_array($role, ['cashier','manager','stock_clerk','supervisor','accountant','cleaner'], true) ? $role : 'cashier',
                'base_amount' => $baseAmount,
                'department' => $department !== '' ? $department : null,
                'bank_name' => $bankName !== '' ? $bankName : null,
                'bank_account' => $bankAccount !== '' ? $bankAccount : null,
            ];

            $created = createCashierProfile($pdo, $profile);
            $activationLink = buildActivationLink($created['activation_token']);
            $successMessage = 'Cashier created successfully. Share the activation link with the new cashier.';
        }
    }
}

$pendingCashiersStmt = $pdo->query(
    "SELECT u.*, latest.base_amount
     FROM users u
     LEFT JOIN (
         SELECT es1.employee_id, es1.base_amount
         FROM employee_salaries es1
         INNER JOIN (
             SELECT employee_id, MAX(id) AS latest_id
             FROM employee_salaries
             GROUP BY employee_id
         ) es2 ON es2.employee_id = es1.employee_id AND es2.latest_id = es1.id
     ) latest ON latest.employee_id = u.id
     WHERE u.role != 'admin'
       AND NOT (u.username = 'cashier' AND u.full_name = 'Cashier' AND u.status = 'active' AND u.employee_id IS NULL AND u.branch IS NULL AND u.pos_terminal IS NULL)
     ORDER BY u.created_at DESC"
);
$pendingCashiers = $pendingCashiersStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Staff / Cashiers — SMART POS SYSTEM</title>
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
  <div class="admin-page-shell container mx-auto px-4 py-6">
    <div class="mb-6 flex items-center gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
      <img src="colour_logo.jpg" alt="POS2 logo" class="h-14 w-14 rounded-2xl object-cover shadow-sm">
      <div>
        <p class="text-lg font-semibold text-slate-900">SMART POS SYSTEM</p>
        <p class="text-sm text-slate-500">Staff management</p>
      </div>
    </div>
    <header class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Staff & Cashiers</h1>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="admin.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">Dashboard</a>
          <a href="admin_payroll.php" class="rounded-3xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700">Payroll</a>
          <a href="stock_intake.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">New Stock Intake</a>
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
        <a href="admin_payroll.php" class="rounded-3xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700">Payroll</a>
        <a href="stock_intake.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">New Stock Intake</a>
        <a href="logout.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 transition hover:bg-slate-100">Logout</a>
      </div>
    </div>

    <?php if ($successMessage): ?>
      <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
        <p class="font-semibold"><?php echo htmlspecialchars($successMessage); ?></p>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="mb-6 rounded-3xl border border-rose-200 bg-rose-50 p-5 text-rose-900">
        <ul class="list-disc space-y-2 pl-5">
          <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($activationLink): ?>
      <div class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-xl font-semibold mb-3">Activation Link Generated</h2>
        <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
          <p class="text-sm text-slate-500 mb-2">Share this link with the cashier so they can activate their account.</p>
          <div class="mb-3 break-all font-mono text-sm text-slate-800"><?php echo htmlspecialchars($activationLink); ?></div>
          <div class="flex flex-wrap gap-3">
            <button type="button" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($activationLink); ?>')" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white">Copy Link</button>
            <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('Hello ' . ($fullName ?? 'Cashier') . ', your cashier account has been created. Activate it here: ' . $activationLink); ?>" target="_blank" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white">Send via WhatsApp</a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold text-slate-900">Employee Records</h2>
        <p class="text-sm text-slate-500">Manage staff accounts and salary details.</p>
      </div>
      <button id="openCreateEmployeeModal" type="button" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Add New Employee</button>
    </div>

    <div class="grid gap-6 lg:grid-cols-1">
      <div id="createEmployeeModal" style="position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center; background:rgba(15,23,42,0.75); padding:1rem; <?php echo $showCreateModal ? 'display:flex;' : 'display:none;'; ?>">
        <div style="width:100%; max-width:760px; max-height:90vh; overflow-y:auto; border-radius:24px; border:1px solid #e2e8f0; background:#ffffff; box-shadow:0 24px 80px rgba(0,0,0,0.25); padding:24px;">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; border:1px solid #e2e8f0; border-radius:16px; background:#f8fafc; padding:14px 16px;">
            <div>
              <h2 style="font-size:20px; font-weight:700; color:#0f172a; margin:0;">Add New Employee</h2>
              <p style="font-size:13px; color:#64748b; margin:4px 0 0;">Create a new employee account and salary profile.</p>
            </div>
            <button id="closeCreateEmployeeModal" type="button" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; border-radius:999px; background:#ffffff; color:#334155; padding:8px 14px; font-size:14px; font-weight:600; cursor:pointer;">Close</button>
          </div>
          <form method="POST" style="display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" name="form_action" value="create">
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Full Name *</span>
              <input name="full_name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Phone Number *</span>
              <input name="phone_number" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>" required style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Employee ID</span>
              <input name="employee_id" value="<?php echo htmlspecialchars($_POST['employee_id'] ?? ''); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Email (optional)</span>
              <input name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Branch *</span>
              <input name="branch" value="<?php echo htmlspecialchars($_POST['branch'] ?? ''); ?>" required style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">POS Terminal *</span>
              <input name="pos_terminal" value="<?php echo htmlspecialchars($_POST['pos_terminal'] ?? ''); ?>" required style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
            </label>
            <label style="display:block; font-size:14px; color:#334155;">
              <span style="display:block; margin-bottom:6px; font-weight:600;">Role</span>
              <select name="role" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px; background:#ffffff;">
                <option value="cashier" <?php echo (($role ?? 'cashier') === 'cashier') ? 'selected' : ''; ?>>Cashier</option>
                <option value="manager" <?php echo (($role ?? 'cashier') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                <option value="stock_clerk" <?php echo (($role ?? 'cashier') === 'stock_clerk') ? 'selected' : ''; ?>>Stock Clerk</option>
                <option value="supervisor" <?php echo (($role ?? 'cashier') === 'supervisor') ? 'selected' : ''; ?>>Supervisor</option>
                <option value="accountant" <?php echo (($role ?? 'cashier') === 'accountant') ? 'selected' : ''; ?>>Accountant</option>
                <option value="cleaner" <?php echo (($role ?? 'cashier') === 'cleaner') ? 'selected' : ''; ?>>Cleaner</option>
              </select>
            </label>
            <div style="display:grid; gap:16px; grid-template-columns:repeat(2, minmax(0, 1fr));">
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Monthly Salary (KES)</span>
                <input type="number" step="0.01" min="0" name="base_amount" value="<?php echo htmlspecialchars((string) ($_POST['base_amount'] ?? '0')); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Department</span>
                <input name="department" value="<?php echo htmlspecialchars($_POST['department'] ?? ''); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
            </div>
            <div style="display:grid; gap:16px; grid-template-columns:repeat(2, minmax(0, 1fr));">
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Bank Name</span>
                <input name="bank_name" value="<?php echo htmlspecialchars($_POST['bank_name'] ?? ''); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Bank Account</span>
                <input name="bank_account" value="<?php echo htmlspecialchars($_POST['bank_account'] ?? ''); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
            </div>
            <div style="display:flex; flex-wrap:gap:12px; margin-top:8px;">
              <button type="submit" style="border:0; border-radius:999px; background:#059669; color:#ffffff; padding:12px 18px; font-size:14px; font-weight:700; cursor:pointer;">Create Employee Account</button>
              <button id="cancelCreateEmployeeModal" type="button" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; border-radius:999px; background:#ffffff; color:#334155; padding:12px 18px; font-size:14px; font-weight:700; cursor:pointer;">Cancel</button>
            </div>
          </form>
        </div>
      </div>

      <section class="rounded-3xl bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-semibold mb-4">Existing Employees</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-700">
            <thead class="border-b border-slate-200 text-slate-500">
              <tr>
                <th class="py-3 pr-4">Name</th>
                <th class="py-3 pr-4">Employee ID</th>
                <th class="py-3 pr-4">Branch</th>
                <th class="py-3 pr-4">Terminal</th>
                <th class="py-3 pr-4">Salary</th>
                <th class="py-3 pr-4">Status</th>
                <th class="py-3 pr-4">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <?php foreach ($pendingCashiers as $cashier): ?>
                <tr>
                  <td class="py-3 pr-4 font-medium"><?php echo htmlspecialchars($cashier['full_name']); ?></td>
                  <td class="py-3 pr-4"><?php echo htmlspecialchars($cashier['employee_id'] ?? '-'); ?></td>
                  <td class="py-3 pr-4"><?php echo htmlspecialchars($cashier['branch'] ?? '-'); ?></td>
                  <td class="py-3 pr-4"><?php echo htmlspecialchars($cashier['pos_terminal'] ?? '-'); ?></td>
                  <td class="py-3 pr-4">KES <?php echo number_format(floatval($cashier['base_amount'] ?? 0), 2); ?></td>
                  <td class="py-3 pr-4">
                    <span class="rounded-full px-3 py-1 text-xs font-semibold <?php echo ($cashier['status'] ?? 'pending') === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'; ?>">
                      <?php echo htmlspecialchars(ucfirst((string) ($cashier['status'] ?? 'pending'))); ?>
                    </span>
                  </td>
                  <td class="py-3 pr-4">
                    <div class="flex flex-wrap gap-2">
                      <form method="POST" class="inline">
                        <input type="hidden" name="form_action" value="start_edit">
                        <input type="hidden" name="employee_id" value="<?php echo (int) $cashier['id']; ?>">
                        <button type="submit" class="rounded-2xl bg-sky-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-sky-700">Edit</button>
                      </form>
                      <?php if (($cashier['status'] ?? 'pending') === 'active'): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Deactivate this employee account?');">
                          <input type="hidden" name="form_action" value="deactivate">
                          <input type="hidden" name="employee_id" value="<?php echo (int) $cashier['id']; ?>">
                          <button type="submit" class="rounded-2xl bg-amber-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-amber-700">Deactivate</button>
                        </form>
                      <?php else: ?>
                        <span class="rounded-2xl bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600">Inactive</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php if ($editEmployee): ?>
        <div id="editEmployeeModal" style="position:fixed; inset:0; z-index:9999; display:flex; align-items:center; justify-content:center; background:rgba(15,23,42,0.75); padding:1rem;">
          <div style="width:100%; max-width:760px; max-height:90vh; overflow-y:auto; border-radius:24px; border:1px solid #e2e8f0; background:#ffffff; box-shadow:0 24px 80px rgba(0,0,0,0.25); padding:24px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; border:1px solid #e2e8f0; border-radius:16px; background:#f8fafc; padding:14px 16px;">
              <div>
                <h2 style="font-size:20px; font-weight:700; color:#0f172a; margin:0;">Edit Employee</h2>
                <p style="font-size:13px; color:#64748b; margin:4px 0 0;">Update the selected employee profile details.</p>
              </div>
              <a href="admin_cashiers.php" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; border-radius:999px; background:#ffffff; color:#334155; padding:8px 14px; font-size:14px; font-weight:600; text-decoration:none;" aria-label="Close edit employee modal">Close</a>
            </div>
            <form method="POST" style="display:flex; flex-direction:column; gap:16px;">
              <input type="hidden" name="form_action" value="save_edit">
              <input type="hidden" name="employee_id" value="<?php echo (int) $editEmployee['id']; ?>">
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Full Name *</span>
                <input name="full_name" value="<?php echo htmlspecialchars((string) ($editEmployee['full_name'] ?? '')); ?>" required style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Phone Number *</span>
                <input name="phone_number" value="<?php echo htmlspecialchars((string) ($editEmployee['phone_number'] ?? '')); ?>" required style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Employee ID</span>
                <input name="employee_id_value" value="<?php echo htmlspecialchars((string) ($editEmployee['employee_id'] ?? '')); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Email (optional)</span>
                <input name="email" value="<?php echo htmlspecialchars((string) ($editEmployee['email'] ?? '')); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Branch *</span>
                <input name="branch" value="<?php echo htmlspecialchars((string) ($editEmployee['branch'] ?? '')); ?>" required style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">POS Terminal *</span>
                <input name="pos_terminal" value="<?php echo htmlspecialchars((string) ($editEmployee['pos_terminal'] ?? '')); ?>" required style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
              </label>
              <label style="display:block; font-size:14px; color:#334155;">
                <span style="display:block; margin-bottom:6px; font-weight:600;">Role</span>
                <select name="role" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px; background:#ffffff;">
                  <option value="cashier" <?php echo (($editEmployee['role'] ?? 'cashier') === 'cashier') ? 'selected' : ''; ?>>Cashier</option>
                  <option value="manager" <?php echo (($editEmployee['role'] ?? 'cashier') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                  <option value="stock_clerk" <?php echo (($editEmployee['role'] ?? 'cashier') === 'stock_clerk') ? 'selected' : ''; ?>>Stock Clerk</option>
                  <option value="supervisor" <?php echo (($editEmployee['role'] ?? 'cashier') === 'supervisor') ? 'selected' : ''; ?>>Supervisor</option>
                  <option value="accountant" <?php echo (($editEmployee['role'] ?? 'cashier') === 'accountant') ? 'selected' : ''; ?>>Accountant</option>
                  <option value="cleaner" <?php echo (($editEmployee['role'] ?? 'cashier') === 'cleaner') ? 'selected' : ''; ?>>Cleaner</option>
                </select>
              </label>
              <div style="display:grid; gap:16px; grid-template-columns:repeat(2, minmax(0, 1fr));">
                <label style="display:block; font-size:14px; color:#334155;">
                  <span style="display:block; margin-bottom:6px; font-weight:600;">Monthly Salary (KES)</span>
                  <input type="number" step="0.01" min="0" name="base_amount" value="<?php echo htmlspecialchars((string) ($editEmployee['base_amount'] ?? '0')); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
                </label>
                <label style="display:block; font-size:14px; color:#334155;">
                  <span style="display:block; margin-bottom:6px; font-weight:600;">Department</span>
                  <input name="department" value="<?php echo htmlspecialchars((string) ($editEmployee['department'] ?? '')); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
                </label>
              </div>
              <div style="display:grid; gap:16px; grid-template-columns:repeat(2, minmax(0, 1fr));">
                <label style="display:block; font-size:14px; color:#334155;">
                  <span style="display:block; margin-bottom:6px; font-weight:600;">Bank Name</span>
                  <input name="bank_name" value="<?php echo htmlspecialchars((string) ($editEmployee['bank_name'] ?? '')); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
                </label>
                <label style="display:block; font-size:14px; color:#334155;">
                  <span style="display:block; margin-bottom:6px; font-weight:600;">Bank Account</span>
                  <input name="bank_account" value="<?php echo htmlspecialchars((string) ($editEmployee['bank_account'] ?? '')); ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:16px; padding:12px 14px;" />
                </label>
              </div>
              <div style="display:flex; flex-wrap:gap:12px; margin-top:8px;">
                <button type="submit" style="border:0; border-radius:999px; background:#059669; color:#ffffff; padding:12px 18px; font-size:14px; font-weight:700; cursor:pointer;">Save Changes</button>
                <a href="admin_cashiers.php" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; border-radius:999px; background:#ffffff; color:#334155; padding:12px 18px; font-size:14px; font-weight:700; text-decoration:none;">Cancel</a>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>
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
    const openCreateEmployeeModal = document.getElementById('openCreateEmployeeModal');
    const createEmployeeModal = document.getElementById('createEmployeeModal');
    const closeCreateEmployeeModal = document.getElementById('closeCreateEmployeeModal');
    const cancelCreateEmployeeModal = document.getElementById('cancelCreateEmployeeModal');

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

    if (openCreateEmployeeModal && createEmployeeModal) {
      openCreateEmployeeModal.addEventListener('click', () => {
        createEmployeeModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
      });
    }

    const closeCreateModal = () => {
      if (createEmployeeModal) {
        createEmployeeModal.style.display = 'none';
        document.body.style.overflow = '';
      }
    };

    if (closeCreateEmployeeModal) {
      closeCreateEmployeeModal.addEventListener('click', closeCreateModal);
    }

    if (cancelCreateEmployeeModal) {
      cancelCreateEmployeeModal.addEventListener('click', closeCreateModal);
    }

    if (createEmployeeModal) {
      createEmployeeModal.addEventListener('click', (event) => {
        if (event.target === createEmployeeModal) {
          closeCreateModal();
        }
      });
    }

    const editModal = document.getElementById('editEmployeeModal');
    if (editModal) {
      editModal.addEventListener('click', (event) => {
        if (event.target === editModal) {
          window.location.href = 'admin_cashiers.php';
        }
      });
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        if (createEmployeeModal && createEmployeeModal.style.display === 'flex') {
          closeCreateModal();
        } else if (editModal) {
          window.location.href = 'admin_cashiers.php';
        }
      }
    });
  </script>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
