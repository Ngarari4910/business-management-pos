<?php
function normalizePaymentMethod($paymentMethod) {
    $method = strtolower(trim((string) $paymentMethod));
    if (in_array($method, ['equity', 'equity_paybill', 'paybill', 'act'], true)) {
        return 'equity';
    }
    if (in_array($method, ['mpesa', 'mpesa_stk', 'stk', 'stk_push'], true)) {
        return 'mpesa_stk';
    }
    if (in_array($method, ['mpesa_till', 'buy_goods', 'till', 'buygoods'], true)) {
        return 'mpesa_till';
    }
    if ($method === 'card') {
        return 'card';
    }
    if ($method === 'mixed') {
        return 'mixed';
    }
    if (in_array($method, ['credit', 'debt', 'customer_account'], true)) {
        return 'credit';
    }
    return 'cash';
}

function normalizePhoneNumber($phone) {
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') {
        return '';
    }
    if (substr($digits, 0, 3) === '254') {
        return $digits;
    }
    if (substr($digits, 0, 1) === '0') {
        return '254' . substr($digits, 1);
    }
    return '254' . $digits;
}

function getPaymentMethodLabel($paymentMethod) {
    switch (normalizePaymentMethod($paymentMethod)) {
        case 'mixed':
            return 'Cash + Equity PayBill';
        case 'equity':
            return 'Equity PayBill';
        case 'mpesa_stk':
            return 'M-Pesa STK Push';
        case 'mpesa_till':
            return 'M-Pesa Buy Goods / Till';
        case 'card':
            return 'Card';
        case 'credit':
            return 'Credit';
        default:
            return 'Cash';
    }
}

function getCustomerByPhoneOrName(PDO $pdo, string $phone, string $name): ?array {
    if ($phone !== '') {
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE phone = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $customer = $stmt->fetch();
        if ($customer) {
            return $customer;
        }
    }

    if ($name !== '') {
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        $customer = $stmt->fetch();
        if ($customer) {
            return $customer;
        }
    }

    return null;
}

function getCustomerByIdentifier(PDO $pdo, string $identifier): ?array {
    $identifier = trim((string) $identifier);
    if ($identifier === '') {
        return null;
    }

    $normalizedPhone = normalizePhoneNumber($identifier);
    if ($normalizedPhone !== '') {
        $customer = getCustomerByPhoneOrName($pdo, $normalizedPhone, '');
        if ($customer) {
            return $customer;
        }
    }

    return getCustomerByPhoneOrName($pdo, '', $identifier);
}

function getOrCreateCustomerByIdentifier(PDO $pdo, string $identifier): array {
    $identifier = trim((string) $identifier);
    $customer = getCustomerByIdentifier($pdo, $identifier);
    if ($customer) {
        return $customer;
    }

    $normalizedPhone = normalizePhoneNumber($identifier);
    if ($normalizedPhone !== '') {
        return createCustomer($pdo, 'Credit customer', $normalizedPhone);
    }

    return createCustomer($pdo, $identifier !== '' ? $identifier : 'Credit customer', '');
}

function generateCustomerNumber(PDO $pdo): string {
    $stmt = $pdo->query('SELECT COUNT(*) FROM customers');
    $count = intval($stmt->fetchColumn() ?? 0) + 1;
    return sprintf('CUS-%06d', $count);
}

function createCustomer(PDO $pdo, string $name, string $phone): array {
    $customerNumber = generateCustomerNumber($pdo);
    $stmt = $pdo->prepare('INSERT INTO customers (customer_number, name, phone, current_balance, status, created_at) VALUES (:customer_number, :name, :phone, 0.00, :status, NOW())');
    $stmt->execute([
        'customer_number' => $customerNumber,
        'name' => $name,
        'phone' => $phone !== '' ? $phone : null,
        'status' => 'active',
    ]);
    $customerId = intval($pdo->lastInsertId());
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    return $stmt->fetch();
}

function getOrCreateCustomer(PDO $pdo, string $name, string $phone): array {
    $customer = getCustomerByPhoneOrName($pdo, $phone, $name);
    if ($customer) {
        return $customer;
    }
    return createCustomer($pdo, $name, $phone);
}

function createCustomerCreditInvoice(PDO $pdo, int $customerId, int $saleId, float $totalAmount): array {
    $invoiceNumber = 'INV-' . date('YmdHis') . '-' . $saleId;
    $stmt = $pdo->prepare('INSERT INTO customer_credit_invoices (invoice_number, customer_id, sale_id, total_amount, amount_paid, balance, status, created_at) VALUES (:invoice_number, :customer_id, :sale_id, :total_amount, 0.00, :balance, :status, NOW())');
    $stmt->execute([
        'invoice_number' => $invoiceNumber,
        'customer_id' => $customerId,
        'sale_id' => $saleId,
        'total_amount' => number_format($totalAmount, 2, '.', ''),
        'balance' => number_format($totalAmount, 2, '.', ''),
        'status' => 'unpaid',
    ]);
    $invoiceId = intval($pdo->lastInsertId());
    $stmt = $pdo->prepare('SELECT * FROM customer_credit_invoices WHERE id = :id');
    $stmt->execute(['id' => $invoiceId]);
    return $stmt->fetch();
}

function updateCustomerBalance(PDO $pdo, int $customerId, float $amount): void {
    $stmt = $pdo->prepare('UPDATE customers SET current_balance = current_balance + :amount, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['amount' => number_format($amount, 2, '.', ''), 'id' => $customerId]);
}

function isPaymentPending($paymentMethod, $status) {
    $normalizedMethod = normalizePaymentMethod($paymentMethod);
    return $normalizedMethod === 'mpesa_stk' || $normalizedMethod === 'mpesa_till'
        ? in_array(strtolower((string) $status), ['pending', 'waiting', 'initiated', 'sent'], true)
        : false;
}

function getPaymentStatusBadge($status) {
    $normalized = strtolower((string) $status);
    if (in_array($normalized, ['success', 'paid', 'completed', 'verified', 'matched'], true)) {
        return 'bg-emerald-100 text-emerald-800';
    }
    if (in_array($normalized, ['pending', 'waiting', 'initiated', 'sent', 'recorded'], true)) {
        return 'bg-amber-100 text-amber-800';
    }
    if (in_array($normalized, ['amount_mismatch', 'unmatched'], true)) {
        return 'bg-rose-100 text-rose-800';
    }
    return 'bg-slate-100 text-slate-700';
}

function getProductAverageCostPerUnit(PDO $pdo, int $productId): float {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(total_cost) / NULLIF(SUM(total_quantity), 0), 0) AS avg_cost
         FROM (
             SELECT total_cost, base_quantity AS total_quantity
             FROM stock_intake_lines
             WHERE product_id = :product_id_intake
             UNION ALL
             SELECT total_cost, quantity AS total_quantity
             FROM quick_stock_purchase_lines
             WHERE product_id = :product_id_quick
         ) combined'
    );
    $stmt->execute([
        'product_id_intake' => $productId,
        'product_id_quick' => $productId,
    ]);
    return floatval($stmt->fetchColumn());
}

function getShiftAvailableCash(PDO $pdo, array $shift): float {
    $shiftId = (int) ($shift['id'] ?? 0);
    $cashierId = (int) ($shift['cashier_id'] ?? 0);
    if ($shiftId <= 0) {
        return 0.0;
    }

    $cashSalesStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(sp.amount), 0)
         FROM sale_payments sp
         JOIN sales s ON s.id = sp.sale_id
         WHERE s.shift_id = :shift_id AND s.payment_status = 'paid'
           AND sp.payment_method = 'cash' AND sp.status = 'success'"
    );
    $cashSalesStmt->execute(['shift_id' => $shiftId]);

    $purchaseStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_cost), 0)
         FROM quick_stock_purchases
         WHERE shift_id = :shift_id AND payment_method = 'cash'"
    );
    $purchaseStmt->execute(['shift_id' => $shiftId]);

    $expenseStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(be.cash_drawer_amount), 0)
         FROM business_expenses be
         JOIN users u ON u.full_name = be.created_by
         WHERE u.id = :cashier_id AND be.cash_drawer_movement = 1
           AND be.expense_date >= DATE(COALESCE(:started_at, :created_at))
           AND be.expense_date <= CURDATE()"
    );
    $expenseStmt->execute([
        'cashier_id' => $cashierId,
        'started_at' => $shift['started_at'] ?? null,
        'created_at' => $shift['created_at'] ?? null,
    ]);

    return max(
        0.0,
        (float) ($shift['opening_cash'] ?? 0)
        + (float) $cashSalesStmt->fetchColumn()
        - (float) $purchaseStmt->fetchColumn()
        - (float) $expenseStmt->fetchColumn()
    );
}

function calculateShiftVariance(float $expectedCash, float $countedCash): float {
    return $countedCash - $expectedCash;
}

function getShiftReviewPriority(float $expectedCash, float $countedCash, float $varianceThreshold = 100.0, float $highPriorityThreshold = 1000.0): string {
    $variance = abs(calculateShiftVariance($expectedCash, $countedCash));
    if ($variance >= $highPriorityThreshold) {
        return 'high';
    }
    if ($variance > $varianceThreshold) {
        return 'medium';
    }
    return 'low';
}

function getShiftStatusLabel(float $expectedCash, float $countedCash): string {
    $variance = calculateShiftVariance($expectedCash, $countedCash);
    if ($variance === 0.0) {
        return 'closed';
    }
    return 'closed_with_variance';
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnName = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
    return $stmt !== false && (bool) $stmt->fetch();
}

function tableHasIndex(PDO $pdo, string $table, string $index): bool {
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $indexName = preg_replace('/[^a-zA-Z0-9_]/', '', $index);
    $stmt = $pdo->query("SHOW INDEX FROM `{$tableName}` WHERE Key_name = '{$indexName}'");
    return $stmt !== false && (bool) $stmt->fetch();
}

/*
 * Schema creation and ALTER TABLE mutations are intentionally kept out of runtime PHP.
 * They are applied via migration files under database/migrations/.
 */

function getEmployeeSalaryProfile(PDO $pdo, int $employeeId): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM employee_salaries WHERE employee_id = :employee_id ORDER BY effective_from DESC, id DESC LIMIT 1'
    );
    $stmt->execute(['employee_id' => $employeeId]);
    $profile = $stmt->fetch();
    return $profile === false ? null : $profile;
}

function getEmployeeVarianceDeduction(PDO $pdo, int $employeeId, string $fromDate, string $toDate): float {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN variance < 0 THEN ABS(variance) ELSE 0 END), 0) AS deduction
         FROM cashier_shifts
         WHERE cashier_id = :employee_id
           AND status = :status
           AND closed_at IS NOT NULL
           AND closed_at >= :from_date
           AND closed_at <= :to_date'
    );
    $stmt->execute([
        'employee_id' => $employeeId,
        'status' => 'closed',
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);
    return floatval($stmt->fetchColumn());
}

function buildMonthlySalaryPayment(PDO $pdo, int $employeeId, string $payPeriodStart, string $payPeriodEnd, string $paymentDate, float $allowanceAmount = 0.0, string $notes = '', string $paymentMethod = 'bank', string $paymentStatus = 'pending'): int {
    $employeeStmt = $pdo->prepare('SELECT id, full_name, employee_id, role FROM users WHERE id = :id');
    $employeeStmt->execute(['id' => $employeeId]);
    $employee = $employeeStmt->fetch();
    if (!$employee) {
        throw new InvalidArgumentException('Employee not found.');
    }

    $salaryProfile = getEmployeeSalaryProfile($pdo, $employeeId);
    $baseAmount = floatval($salaryProfile['base_amount'] ?? 0);
    $varianceDeduction = getEmployeeVarianceDeduction($pdo, $employeeId, $payPeriodStart, $payPeriodEnd);
    $gross = $baseAmount + $allowanceAmount;
    $deductions = $varianceDeduction;
    $net = $gross - $deductions;

    return createSalaryPayment($pdo, [
        'employee_id' => $employeeId,
        'pay_period_start' => $payPeriodStart,
        'pay_period_end' => $payPeriodEnd,
        'payment_date' => $paymentDate,
        'gross_pay' => $gross,
        'total_allowances' => $allowanceAmount,
        'total_deductions' => $deductions,
        'net_pay' => $net,
        'payment_status' => $paymentStatus,
        'payment_method' => $paymentMethod,
        'payroll_reference' => 'PR-' . date('Ymd') . '-' . $employeeId,
        'notes' => $notes,
        'components' => [
            ['component_type' => 'earning', 'label' => 'Base salary', 'amount' => $baseAmount],
            ['component_type' => 'allowance', 'label' => 'Monthly allowance', 'amount' => $allowanceAmount],
            ['component_type' => 'deduction', 'label' => 'Shift variance deduction', 'amount' => $deductions],
        ],
    ]);
}

function createSalaryPayment(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare(
        'INSERT INTO salary_payments (
            employee_id, pay_period_start, pay_period_end, payment_date, gross_pay, total_allowances, total_deductions, net_pay, payment_status, payment_method, payroll_reference, notes, created_at
        ) VALUES (
            :employee_id, :pay_period_start, :pay_period_end, :payment_date, :gross_pay, :total_allowances, :total_deductions, :net_pay, :payment_status, :payment_method, :payroll_reference, :notes, NOW()
        )'
    );

    $stmt->execute([
        'employee_id' => $data['employee_id'],
        'pay_period_start' => $data['pay_period_start'],
        'pay_period_end' => $data['pay_period_end'],
        'payment_date' => $data['payment_date'],
        'gross_pay' => $data['gross_pay'],
        'total_allowances' => $data['total_allowances'] ?? 0,
        'total_deductions' => $data['total_deductions'] ?? 0,
        'net_pay' => $data['net_pay'],
        'payment_status' => $data['payment_status'] ?? 'pending',
        'payment_method' => $data['payment_method'] ?? 'bank',
        'payroll_reference' => $data['payroll_reference'] ?? null,
        'notes' => $data['notes'] ?? null,
    ]);

    $paymentId = intval($pdo->lastInsertId());
    foreach ($data['components'] ?? [] as $component) {
        $componentStmt = $pdo->prepare(
            'INSERT INTO salary_components (salary_payment_id, component_type, label, amount, created_at)
             VALUES (:salary_payment_id, :component_type, :label, :amount, NOW())'
        );
        $componentStmt->execute([
            'salary_payment_id' => $paymentId,
            'component_type' => $component['component_type'],
            'label' => $component['label'],
            'amount' => $component['amount'],
        ]);
    }

    return $paymentId;
}

function getSalaryPaymentById(PDO $pdo, int $paymentId): ?array {
    $stmt = $pdo->prepare(
        'SELECT sp.*, u.full_name, u.employee_id, u.role, u.username
         FROM salary_payments sp
         JOIN users u ON u.id = sp.employee_id
         WHERE sp.id = :payment_id'
    );
    $stmt->execute(['payment_id' => $paymentId]);
    $payment = $stmt->fetch();
    return $payment === false ? null : $payment;
}

function getSalaryComponents(PDO $pdo, int $paymentId): array {
    $stmt = $pdo->prepare(
        'SELECT * FROM salary_components WHERE salary_payment_id = :salary_payment_id ORDER BY id ASC'
    );
    $stmt->execute(['salary_payment_id' => $paymentId]);
    return $stmt->fetchAll();
}

function updateSalaryPaymentBreakdown(PDO $pdo, int $paymentId, float $allowanceAmount, float $deductionAmount, string $paymentDate, string $notes = ''): bool {
    $paymentStmt = $pdo->prepare(
        'SELECT sp.*, u.id AS employee_id
         FROM salary_payments sp
         JOIN users u ON u.id = sp.employee_id
         WHERE sp.id = :payment_id'
    );
    $paymentStmt->execute(['payment_id' => $paymentId]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        return false;
    }

    $employeeId = intval($payment['employee_id'] ?? 0);
    $salaryProfile = getEmployeeSalaryProfile($pdo, $employeeId);
    $baseAmount = floatval($salaryProfile['base_amount'] ?? 0);
    $grossPay = $baseAmount + $allowanceAmount;
    $netPay = $grossPay - $deductionAmount;

    $updateStmt = $pdo->prepare(
        'UPDATE salary_payments
         SET payment_status = :payment_status,
             payment_date = :payment_date,
             total_allowances = :total_allowances,
             total_deductions = :total_deductions,
             gross_pay = :gross_pay,
             net_pay = :net_pay,
             notes = :notes
         WHERE id = :payment_id'
    );
    $updateStmt->execute([
        'payment_status' => 'paid',
        'payment_date' => $paymentDate,
        'total_allowances' => $allowanceAmount,
        'total_deductions' => $deductionAmount,
        'gross_pay' => $grossPay,
        'net_pay' => $netPay,
        'notes' => $notes,
        'payment_id' => $paymentId,
    ]);

    $deleteComponentsStmt = $pdo->prepare('DELETE FROM salary_components WHERE salary_payment_id = :payment_id');
    $deleteComponentsStmt->execute(['payment_id' => $paymentId]);

    $insertComponentStmt = $pdo->prepare(
        'INSERT INTO salary_components (salary_payment_id, component_type, label, amount, created_at)
         VALUES (:salary_payment_id, :component_type, :label, :amount, NOW())'
    );
    $insertComponentStmt->execute([
        'salary_payment_id' => $paymentId,
        'component_type' => 'earning',
        'label' => 'Base salary',
        'amount' => $baseAmount,
    ]);
    $insertComponentStmt->execute([
        'salary_payment_id' => $paymentId,
        'component_type' => 'allowance',
        'label' => 'Monthly allowance',
        'amount' => $allowanceAmount,
    ]);
    $insertComponentStmt->execute([
        'salary_payment_id' => $paymentId,
        'component_type' => 'deduction',
        'label' => 'Payroll deduction',
        'amount' => $deductionAmount,
    ]);

    return true;
}

/* Runtime user-table mutation removed. Use database migrations instead. */

function createCashierProfile(PDO $pdo, array $data): array {
    $activationToken = bin2hex(random_bytes(24));
    $expiresAt = (new DateTime('+72 hours'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, status, activation_token, activation_token_expires_at, phone_number, employee_id, email, branch, pos_terminal, created_at, updated_at)
         VALUES (NULL, NULL, :full_name, :role, :status, :activation_token, :activation_token_expires_at, :phone_number, :employee_id, :email, :branch, :pos_terminal, NOW(), NOW())'
    );

    $stmt->execute([
        'full_name' => $data['full_name'],
        'role' => $data['role'] ?? 'cashier',
        'status' => 'pending',
        'activation_token' => $activationToken,
        'activation_token_expires_at' => $expiresAt,
        'phone_number' => $data['phone_number'] ?? null,
        'employee_id' => $data['employee_id'] ?? null,
        'email' => $data['email'] ?? null,
        'branch' => $data['branch'] ?? null,
        'pos_terminal' => $data['pos_terminal'] ?? null,
    ]);

    $employeeId = intval($pdo->lastInsertId());

    if ($employeeId > 0) {
        $salaryStmt = $pdo->prepare(
            'INSERT INTO employee_salaries (employee_id, salary_type, base_amount, currency, department, bank_name, bank_account, notes, effective_from, created_at, updated_at)
             VALUES (:employee_id, :salary_type, :base_amount, :currency, :department, :bank_name, :bank_account, :notes, CURDATE(), NOW(), NOW())'
        );
        $salaryStmt->execute([
            'employee_id' => $employeeId,
            'salary_type' => 'monthly',
            'base_amount' => $data['base_amount'] ?? 0,
            'currency' => $data['currency'] ?? 'KES',
            'department' => $data['department'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account' => $data['bank_account'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    return [
        'id' => $employeeId,
        'activation_token' => $activationToken,
        'activation_token_expires_at' => $expiresAt,
    ];
}

function getEmployeeById(PDO $pdo, int $employeeId): ?array {
    $stmt = $pdo->prepare(
        'SELECT u.*, es.base_amount, es.department, es.bank_name, es.bank_account
         FROM users u
         LEFT JOIN employee_salaries es ON es.employee_id = u.id
         WHERE u.id = :id
         ORDER BY es.id DESC
         LIMIT 1'
    );
    $stmt->execute(['id' => $employeeId]);
    $employee = $stmt->fetch();
    return $employee === false ? null : $employee;
}

function updateEmployeeProfile(PDO $pdo, int $employeeId, array $data): bool {
    $stmt = $pdo->prepare(
        'UPDATE users
         SET full_name = :full_name,
             phone_number = :phone_number,
             employee_id = :employee_id,
             email = :email,
             branch = :branch,
             pos_terminal = :pos_terminal,
             role = :role,
             updated_at = NOW()
         WHERE id = :id'
    );

    $updated = $stmt->execute([
        'id' => $employeeId,
        'full_name' => $data['full_name'],
        'phone_number' => $data['phone_number'] ?? null,
        'employee_id' => $data['employee_id'] ?? null,
        'email' => $data['email'] ?? null,
        'branch' => $data['branch'] ?? null,
        'pos_terminal' => $data['pos_terminal'] ?? null,
        'role' => $data['role'] ?? 'cashier',
    ]);

    if ($updated) {
        $salaryStmt = $pdo->prepare(
            'INSERT INTO employee_salaries (employee_id, salary_type, base_amount, currency, department, bank_name, bank_account, notes, effective_from, created_at, updated_at)
             VALUES (:employee_id, :salary_type, :base_amount, :currency, :department, :bank_name, :bank_account, :notes, CURDATE(), NOW(), NOW())'
        );
        $salaryStmt->execute([
            'employee_id' => $employeeId,
            'salary_type' => 'monthly',
            'base_amount' => $data['base_amount'] ?? 0,
            'currency' => $data['currency'] ?? 'KES',
            'department' => $data['department'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account' => $data['bank_account'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    return $updated;
}

function deactivateEmployeeAccount(PDO $pdo, int $employeeId): bool {
    $stmt = $pdo->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
    return $stmt->execute([
        'id' => $employeeId,
        'status' => 'inactive',
    ]);
}

function buildActivationLink(string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/'), '/\\');
    return sprintf('%s://%s%s/activate.php?token=%s', $scheme, $host, $basePath === '.' ? '' : $basePath, $token);
}

function getOpenShiftByCashier(PDO $pdo, int $cashierId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM cashier_shifts WHERE cashier_id = :cashier_id AND status = :status ORDER BY created_at DESC LIMIT 1');
    $stmt->execute(['cashier_id' => $cashierId, 'status' => 'open']);
    $shift = $stmt->fetch();
    return $shift === false ? null : $shift;
}

function isShiftFromPreviousDay(array $shift): bool {
    $startedAt = trim((string) ($shift['started_at'] ?? $shift['created_at'] ?? ''));
    if ($startedAt === '') {
        return true;
    }

    $startedDate = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $startedAt,
        new DateTimeZone('Africa/Nairobi')
    );
    if ($startedDate === false) {
        return true;
    }

    $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi'));
    return $startedDate->format('Y-m-d') < $today->format('Y-m-d');
}

/* Runtime schema migration functions were removed; use database migrations instead. */
