<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';

function formatCurrency($amount): string {
    return 'KES ' . number_format(floatval($amount), 2);
}

function getCashierProfiles(PDO $pdo): array {
  $stmt = $pdo->query("SELECT id, full_name, employee_id, username, branch, status FROM users WHERE role = 'cashier' ORDER BY full_name ASC LIMIT 250");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCashierShiftRows(PDO $pdo, int $cashierId, int $limit = 500): array {
  $limit = max(1, min($limit, 500));
    $stmt = $pdo->prepare(
        "SELECT
            cs.*,
            COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_status = 'paid'), 0) AS shift_sales_total,
            COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'cash' AND sp.status = 'success'), 0) AS shift_cash_sales_total,
            COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id WHERE s.shift_id = cs.id AND s.payment_status = 'paid' AND sp.payment_method = 'equity'), 0) AS shift_equity_sales_total,
            COALESCE((SELECT SUM(total_amount) FROM sales WHERE shift_id = cs.id AND payment_status = 'paid' AND payment_method = 'mixed'), 0) AS shift_mixed_sales_total,
            COALESCE((SELECT SUM(total_cost) FROM quick_stock_purchases WHERE shift_id = cs.id), 0) AS quick_purchase_total,
            COALESCE((SELECT SUM(be.cash_drawer_amount)
                FROM business_expenses be
                JOIN users expense_user ON expense_user.full_name = be.created_by
                WHERE expense_user.id = cs.cashier_id
                  AND be.cash_drawer_movement = 1
                  AND be.expense_date >= DATE(COALESCE(cs.started_at, cs.created_at))
                  AND (cs.closed_at IS NULL OR be.expense_date <= DATE(cs.closed_at))), 0) AS expense_total
        FROM cashier_shifts cs
        WHERE cs.cashier_id = :cashier_id
        ORDER BY COALESCE(cs.started_at, cs.created_at) DESC
        LIMIT {$limit}"
    );
    $stmt->execute(['cashier_id' => $cashierId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$cashiers = getCashierProfiles($pdo);
$cashierData = [];

foreach ($cashiers as $cashier) {
    $shifts = getCashierShiftRows($pdo, intval($cashier['id']), 1);
    $latestShift = $shifts[0] ?? null;

    $cashierData[intval($cashier['id'])] = [
        'id' => intval($cashier['id']),
        'full_name' => $cashier['full_name'] ?? 'Cashier',
        'employee_id' => $cashier['employee_id'] ?? null,
        'username' => $cashier['username'] ?? null,
        'branch' => $cashier['branch'] ?? null,
        'status' => $cashier['status'] ?? 'active',
        'latest_shift' => $latestShift ? [
            'id' => intval($latestShift['id']),
            'started_at' => $latestShift['started_at'] ?? $latestShift['created_at'],
            'closed_at' => $latestShift['closed_at'],
            'status' => $latestShift['status'],
            'opening_cash' => floatval($latestShift['opening_cash']),
            'expected_cash' => floatval($latestShift['expected_cash']),
            'counted_cash' => floatval($latestShift['counted_cash']),
            'variance' => floatval($latestShift['variance']),
            'safe_drop_amount' => floatval($latestShift['safe_drop_amount']),
            'next_day_float' => floatval($latestShift['next_day_float']),
            'sales_total' => floatval($latestShift['shift_sales_total'] ?? 0),
            'cash_sales_total' => floatval($latestShift['shift_cash_sales_total'] ?? 0),
            'equity_sales_total' => floatval($latestShift['shift_equity_sales_total'] ?? 0),
            'mixed_sales_total' => floatval($latestShift['shift_mixed_sales_total'] ?? 0),
            'quick_purchase_total' => floatval($latestShift['quick_purchase_total'] ?? 0),
            'expense_total' => floatval($latestShift['expense_total'] ?? 0)
        ] : null,
        'shifts' => array_map(static function ($shift) {
            return [
                'id' => intval($shift['id']),
                'started_at' => $shift['started_at'] ?? $shift['created_at'],
                'closed_at' => $shift['closed_at'],
                'status' => $shift['status'],
                'opening_cash' => floatval($shift['opening_cash']),
                'closing_balance' => floatval($shift['counted_cash']),
                'expected_cash' => floatval($shift['expected_cash']),
                'counted_cash' => floatval($shift['counted_cash']),
                'variance' => floatval($shift['variance']),
                'safe_drop_amount' => floatval($shift['safe_drop_amount']),
                'next_day_float' => floatval($shift['next_day_float']),
                'sales_total' => floatval($shift['shift_sales_total'] ?? 0),
                'cash_sales_total' => floatval($shift['shift_cash_sales_total'] ?? 0),
                'equity_sales_total' => floatval($shift['shift_equity_sales_total'] ?? 0),
                'mixed_sales_total' => floatval($shift['shift_mixed_sales_total'] ?? 0),
                'quick_purchase_total' => floatval($shift['quick_purchase_total'] ?? 0),
                'expense_total' => floatval($shift['expense_total'] ?? 0)
            ];
        }, $shifts),
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Shift Overview — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <div class="admin-page-shell mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Admin panel</p>
        <h1 class="mt-2 text-3xl font-semibold text-slate-900">Shift overview</h1>
        <p class="mt-1 text-sm text-slate-500">Review each cashier's latest shift and open detailed history in a modal.</p>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="admin.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">Dashboard</a>
          <a href="admin_shifts.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Shifts</a>
          <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">Staff</a>
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
        <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Staff</a>
      </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <?php foreach ($cashierData as $cashier): ?>
        <button type="button" data-cashier-id="<?php echo $cashier['id']; ?>" class="group w-full cursor-pointer rounded-[2rem] border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md sm:p-6">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-[0.24em] text-slate-400 sm:text-sm"><?php echo htmlspecialchars($cashier['status'] === 'active' ? 'Active cashier' : 'Cashier'); ?></p>
              <h2 class="mt-3 text-xl font-semibold text-slate-900"><?php echo htmlspecialchars($cashier['full_name']); ?></h2>
              <p class="mt-1 break-words text-sm text-slate-500">Employee ID: <?php echo htmlspecialchars($cashier['employee_id'] ?? '—'); ?></p>
            </div>
            <div class="rounded-3xl bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700">View details</div>
          </div>

          <div class="mt-6 grid gap-3 grid-cols-1 sm:grid-cols-2">
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm text-slate-500">Latest shift sales</p>
              <p class="mt-2 text-xl font-semibold text-slate-900"><?php echo formatCurrency($cashier['latest_shift']['sales_total'] ?? 0); ?></p>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm text-slate-500">Opening balance</p>
              <p class="mt-2 text-xl font-semibold text-slate-900"><?php echo formatCurrency($cashier['latest_shift']['opening_cash'] ?? 0); ?></p>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm text-slate-500">Total expenses</p>
              <p class="mt-2 text-xl font-semibold text-slate-900"><?php echo formatCurrency($cashier['latest_shift']['expense_total'] ?? 0); ?></p>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm text-slate-500">Quick purchases</p>
              <p class="mt-2 text-xl font-semibold text-slate-900"><?php echo formatCurrency($cashier['latest_shift']['quick_purchase_total'] ?? 0); ?></p>
            </div>
          </div>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div id="shiftModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4">
    <div class="mx-auto w-full max-w-6xl overflow-hidden rounded-[2rem] bg-white shadow-2xl">
      <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-6 py-5">
        <div>
          <p class="text-sm text-slate-500">Cashier shift history</p>
          <h2 id="modalEmployeeName" class="mt-1 text-2xl font-semibold text-slate-900"></h2>
          <p id="modalEmployeeMeta" class="text-sm text-slate-500"></p>
          <p id="modalEmployeeVarianceHeader" class="mt-2 text-sm text-slate-500"></p>
        </div>
        <button id="closeShiftModal" type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 text-xl font-semibold text-slate-700 transition hover:bg-slate-100">×</button>
      </div>
      <div class="max-h-[80vh] overflow-y-auto px-6 py-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex flex-wrap gap-2">
            <button type="button" data-shift-filter="all" class="filter-btn rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">All shifts</button>
            <button type="button" data-shift-filter="monthly" class="filter-btn rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Monthly</button>
            <button type="button" data-shift-filter="annually" class="filter-btn rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Annually</button>
          </div>
          <div class="rounded-3xl bg-slate-50 px-4 py-3 text-sm text-slate-600">Showing <span id="modalFilterLabel">all shifts</span></div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="flex flex-col text-sm text-slate-600">
              From date
              <input id="modalDateFrom" type="date" class="mt-2 rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm" />
            </label>
            <label class="flex flex-col text-sm text-slate-600">
              To date
              <input id="modalDateTo" type="date" class="mt-2 rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm" />
            </label>
            <div class="flex items-end">
              <button id="applyDateFilter" type="button" class="w-full rounded-3xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Apply</button>
            </div>
            <div class="flex items-end">
              <button id="clearDateFilter" type="button" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Clear</button>
            </div>
          </div>
          <div class="rounded-3xl bg-slate-50 px-5 py-4 text-sm text-slate-600 shadow-sm">
            <p class="font-semibold text-slate-900">Filter status</p>
            <p id="modalFilterStatus" class="mt-2 text-slate-500">Showing all shifts</p>
          </div>
        </div>

        <div class="mt-6 grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-5">
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Latest shift sales</p>
            <p id="modalSalesTotal" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Equity sales</p>
            <p id="modalPaybillTotal" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Cash sales</p>
            <p id="modalCashTotal" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Mixed sales</p>
            <p id="modalMixedTotal" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Opening balance</p>
            <p id="modalOpeningCash" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Closing balance</p>
            <p id="modalClosingBalance" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Latest variance</p>
            <p id="modalVariance" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Shift expenses</p>
            <p id="modalExpenseTotal" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Quick purchases</p>
            <p id="modalQuickPurchaseTotal" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm text-slate-500">Safe drop</p>
            <p id="modalSafeDropTotal" class="mt-3 text-2xl font-semibold text-slate-900">KES 0.00</p>
          </div>
        </div>

        <div class="mt-6 overflow-x-auto rounded-[1.5rem] border border-slate-200 bg-white">
          <table class="min-w-[880px] text-left text-sm text-slate-700 md:min-w-full">
            <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th class="px-4 py-3">Period</th>
                <th class="px-4 py-3">Shift status</th>
                <th class="px-4 py-3">Sales</th>
                <th class="px-4 py-3">Equity</th>
                <th class="px-4 py-3">Cash</th>
                <th class="px-4 py-3">Mixed</th>
                <th class="px-4 py-3">Opening</th>
                <th class="px-4 py-3">Closing</th>
                <th class="px-4 py-3">Variance</th>
                <th class="px-4 py-3">Expenses</th>
                <th class="px-4 py-3">Quick buys</th>
                <th class="px-4 py-3">Safe drop</th>
              </tr>
            </thead>
            <tbody id="shiftTableBody" class="divide-y divide-slate-200 bg-white"></tbody>
          </table>
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

    const cashierShiftData = <?php echo json_encode($cashierData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const shiftModal = document.getElementById('shiftModal');
    const modalEmployeeName = document.getElementById('modalEmployeeName');
    const modalEmployeeMeta = document.getElementById('modalEmployeeMeta');
    const modalEmployeeVarianceHeader = document.getElementById('modalEmployeeVarianceHeader');
    const modalEmployeeVariance = document.getElementById('modalVariance');
    const modalSalesTotal = document.getElementById('modalSalesTotal');
    const modalPaybillTotal = document.getElementById('modalPaybillTotal');
    const modalCashTotal = document.getElementById('modalCashTotal');
    const modalMixedTotal = document.getElementById('modalMixedTotal');
    const modalOpeningCash = document.getElementById('modalOpeningCash');
    const modalClosingBalance = document.getElementById('modalClosingBalance');
    const modalExpenseTotal = document.getElementById('modalExpenseTotal');
    const modalQuickPurchaseTotal = document.getElementById('modalQuickPurchaseTotal');
    const modalSafeDropTotal = document.getElementById('modalSafeDropTotal');
    const modalFilterLabel = document.getElementById('modalFilterLabel');
    const modalFilterStatus = document.getElementById('modalFilterStatus');
    const dateFromInput = document.getElementById('modalDateFrom');
    const dateToInput = document.getElementById('modalDateTo');
    const applyDateFilterButton = document.getElementById('applyDateFilter');
    const clearDateFilterButton = document.getElementById('clearDateFilter');
    const shiftTableBody = document.getElementById('shiftTableBody');
    const filterButtons = Array.from(document.querySelectorAll('.filter-btn'));
    let selectedFilter = 'all';
    let activeCashier = null;
    let activeDateFrom = null;
    let activeDateTo = null;

    function formatCurrency(amount) {
      return 'KES ' + Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDate(value) {
      if (!value) {
        return '-';
      }
      return String(value).replace('T', ' ');
    }

    function formatPeriod(shift, mode) {
      const startedAt = String(shift.started_at || '');
      if (mode === 'annually') {
        return startedAt.slice(0, 4);
      }
      return startedAt.slice(0, 7);
    }

    async function loadShiftHistory(cashierId) {
      try {
        const response = await fetch('admin_shift_history.php?cashier_id=' + encodeURIComponent(cashierId), { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Unable to load shift history');
        const payload = await response.json();
        if (activeCashier && String(activeCashier.id) === String(cashierId)) {
          activeCashier.shifts = Array.isArray(payload.shifts) ? payload.shifts : [];
          renderShiftTable(activeCashier.shifts, selectedFilter);
        }
      } catch (error) {
        shiftTableBody.innerHTML = '<tr><td colspan="11" class="px-4 py-5 text-center text-sm text-rose-600">Unable to load shift history.</td></tr>';
      }
    }

    function openShiftModal(cashierId) {
      activeCashier = cashierShiftData[cashierId];
      if (!activeCashier) {
        return;
      }

      modalEmployeeName.textContent = activeCashier.full_name;
      modalEmployeeMeta.textContent = 'Employee ID: ' + (activeCashier.employee_id || '—') + ' · Username: ' + (activeCashier.username || '—');
      const latest = activeCashier.latest_shift;
      modalSalesTotal.textContent = formatCurrency(latest ? latest.sales_total : 0);
      modalPaybillTotal.textContent = formatCurrency(latest ? latest.equity_sales_total : 0);
      modalCashTotal.textContent = formatCurrency(latest ? latest.cash_sales_total : 0);
      modalMixedTotal.textContent = formatCurrency(latest ? latest.mixed_sales_total : 0);
      modalOpeningCash.textContent = formatCurrency(latest ? latest.opening_cash : 0);
      modalClosingBalance.textContent = formatCurrency(latest ? latest.closing_balance : 0);
      modalEmployeeVarianceHeader.textContent = 'Latest variance: ' + formatCurrency(latest ? latest.variance : 0);
      modalEmployeeVariance.textContent = formatCurrency(latest ? latest.variance : 0);
      modalExpenseTotal.textContent = formatCurrency(latest ? latest.expense_total : 0);
      modalQuickPurchaseTotal.textContent = formatCurrency(latest ? latest.quick_purchase_total : 0);
      modalSafeDropTotal.textContent = formatCurrency(latest ? latest.safe_drop_amount : 0);

      selectedFilter = 'all';
      activeDateFrom = null;
      activeDateTo = null;
      dateFromInput.value = '';
      dateToInput.value = '';
      modalFilterLabel.textContent = 'all shifts';
      updateFilterStatus();
      filterButtons.forEach((button) => {
        button.classList.toggle('bg-slate-900', button.dataset.shiftFilter === selectedFilter);
        button.classList.toggle('text-white', button.dataset.shiftFilter === selectedFilter);
        button.classList.toggle('bg-white', button.dataset.shiftFilter !== selectedFilter);
      });
      renderShiftTable(activeCashier.shifts, selectedFilter);
      shiftModal.classList.remove('hidden');
      shiftModal.classList.add('flex');
      loadShiftHistory(cashierId);
    }

    function closeShiftModal() {
      shiftModal.classList.add('hidden');
      shiftModal.classList.remove('flex');
    }

    function buildFilterRange(fromValue, toValue) {
      const from = fromValue || null;
      const to = toValue || null;
      if (!from && !to) {
        return null;
      }
      return { from, to };
    }

    function updateFilterStatus() {
      const range = buildFilterRange(activeDateFrom, activeDateTo);
      const modeLabel = selectedFilter === 'all' ? 'All shifts' : selectedFilter === 'monthly' ? 'Monthly summary' : 'Annual summary';
      let status = modeLabel;
      if (range && (range.from || range.to)) {
        const fromText = range.from || 'Any';
        const toText = range.to || 'Any';
        status += ` • Date range: ${fromText} → ${toText}`;
      }
      modalFilterStatus.textContent = status;
    }

    function filterShiftsByDate(shifts) {
      const range = buildFilterRange(activeDateFrom, activeDateTo);
      if (!range) {
        return shifts;
      }
      return shifts.filter((shift) => {
        const started = String(shift.started_at || '').slice(0, 10);
        if (range.from && started < range.from) {
          return false;
        }
        if (range.to && started > range.to) {
          return false;
        }
        return true;
      });
    }

    function aggregateShifts(shifts, mode) {
      const groups = {};
      shifts.forEach((shift) => {
        const startedAt = String(shift.started_at || '');
        const key = mode === 'annually' ? startedAt.slice(0, 4) : startedAt.slice(0, 7);
        if (!groups[key]) {
          groups[key] = {
            label: key,
            count: 0,
            sales_total: 0,
            paybill_total: 0,
            cash_total: 0,
            mixed_total: 0,
            opening_cash: 0,
            closing_balance: 0,
            variance: 0,
            expense_total: 0,
            quick_purchase_total: 0,
            safe_drop_amount: 0,
          };
        }
        groups[key].count += 1;
        groups[key].sales_total += shift.sales_total;
        groups[key].paybill_total += shift.equity_sales_total;
        groups[key].cash_total += shift.cash_sales_total;
        groups[key].mixed_total += shift.mixed_sales_total;
        groups[key].opening_cash += shift.opening_cash;
        groups[key].closing_balance += shift.closing_balance;
        groups[key].variance += shift.variance;
        groups[key].expense_total += shift.expense_total;
        groups[key].quick_purchase_total += shift.quick_purchase_total;
        groups[key].safe_drop_amount += shift.safe_drop_amount;
      });
      return Object.values(groups).sort((a, b) => (a.label < b.label ? 1 : -1));
    }

    function renderShiftTable(shifts, mode) {
      if (!Array.isArray(shifts)) {
        shifts = [];
      }

      shifts = filterShiftsByDate(shifts);
      let rows = [];
      if (mode === 'monthly' || mode === 'annually') {
        rows = aggregateShifts(shifts, mode).map((group) => ({
          period: group.label,
          status: group.count + ' shifts',
          sales_total: group.sales_total,
          paybill_total: group.paybill_total,
          cash_total: group.cash_total,
          mixed_total: group.mixed_total,
          opening_cash: group.opening_cash,
          closing_balance: group.closing_balance,
          variance: group.variance,
          expense_total: group.expense_total,
          quick_purchase_total: group.quick_purchase_total,
          safe_drop_amount: group.safe_drop_amount,
        }));
      } else {
        rows = shifts.map((shift) => ({
          period: formatDate(shift.started_at) + (shift.closed_at ? ' – ' + formatDate(shift.closed_at) : ''),
          status: shift.status || 'unknown',
          sales_total: shift.sales_total,
          paybill_total: shift.equity_sales_total,
          cash_total: shift.cash_sales_total,
          mixed_total: shift.mixed_sales_total,
          opening_cash: shift.opening_cash,
          closing_balance: shift.closing_balance,
          variance: shift.variance,
          expense_total: shift.expense_total,
          quick_purchase_total: shift.quick_purchase_total,
          safe_drop_amount: shift.safe_drop_amount,
        }));
      }

      shiftTableBody.innerHTML = '';
      if (rows.length === 0) {
        shiftTableBody.innerHTML = '<tr><td colspan="11" class="px-4 py-5 text-center text-sm text-slate-500">No shifts recorded for this cashier.</td></tr>';
        return;
      }
      for (const row of rows) {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-slate-200';
        [row.period, row.status, formatCurrency(row.sales_total), formatCurrency(row.paybill_total), formatCurrency(row.cash_total), formatCurrency(row.mixed_total), formatCurrency(row.opening_cash), formatCurrency(row.closing_balance), formatCurrency(row.variance), formatCurrency(row.expense_total), formatCurrency(row.quick_purchase_total), formatCurrency(row.safe_drop_amount)].forEach((value, index) => {
          const cell = document.createElement('td');
          cell.className = index === 0 ? 'px-4 py-4 font-medium text-slate-900' : 'px-4 py-4 text-slate-900';
          cell.textContent = value;
          tr.appendChild(cell);
        });
        shiftTableBody.appendChild(tr);
      }
    }

    document.querySelectorAll('[data-cashier-id]').forEach((card) => {
      card.addEventListener('click', () => {
        openShiftModal(card.dataset.cashierId);
      });
    });

    filterButtons.forEach((button) => {
      button.addEventListener('click', () => {
        selectedFilter = button.dataset.shiftFilter;
        modalFilterLabel.textContent = button.textContent.toLowerCase();
        updateFilterStatus();
        filterButtons.forEach((btn) => {
          btn.classList.toggle('bg-slate-900', btn.dataset.shiftFilter === selectedFilter);
          btn.classList.toggle('text-white', btn.dataset.shiftFilter === selectedFilter);
          btn.classList.toggle('bg-white', btn.dataset.shiftFilter !== selectedFilter);
        });
        if (activeCashier) {
          renderShiftTable(activeCashier.shifts, selectedFilter);
        }
      });
    });

    applyDateFilterButton.addEventListener('click', () => {
      activeDateFrom = dateFromInput.value || null;
      activeDateTo = dateToInput.value || null;
      updateFilterStatus();
      if (activeCashier) {
        renderShiftTable(activeCashier.shifts, selectedFilter);
      }
    });

    clearDateFilterButton.addEventListener('click', () => {
      activeDateFrom = null;
      activeDateTo = null;
      dateFromInput.value = '';
      dateToInput.value = '';
      updateFilterStatus();
      if (activeCashier) {
        renderShiftTable(activeCashier.shifts, selectedFilter);
      }
    });

    document.getElementById('closeShiftModal').addEventListener('click', closeShiftModal);
    shiftModal.addEventListener('click', (event) => {
      if (event.target === shiftModal) {
        closeShiftModal();
      }
    });
  </script>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
