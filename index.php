<?php
session_start();
require __DIR__ . '/security.php';
requireCashierPage();
require __DIR__ . '/payment_flow.php';
require __DIR__ . '/receipt.php';
require __DIR__ . '/db.php';
require __DIR__ . '/stock_batches.php';
require __DIR__ . '/inventory_losses.php';

function getProductExpiryStatus(array $product): array {
    $expiryDate = trim((string) ($product['expiry_date'] ?? ''));
    if ($expiryDate === '') {
        return ['status' => 'none', 'days_left' => null, 'expires_at' => null];
    }

    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate);
    $today = new DateTimeImmutable('today');
    if ($expiry === false) {
        return ['status' => 'none', 'days_left' => null, 'expires_at' => null];
    }

    $daysLeft = (int) $today->diff($expiry)->format('%r%a');

    if ($daysLeft <= 0) {
        return ['status' => 'expired', 'days_left' => $daysLeft, 'expires_at' => $expiryDate];
    }

    if ($daysLeft === 1) {
        return ['status' => 'expiring_soon', 'days_left' => $daysLeft, 'expires_at' => $expiryDate];
    }

    return ['status' => 'ok', 'days_left' => $daysLeft, 'expires_at' => $expiryDate];
}

$isCli = php_sapi_name() === 'cli';
$expiredStockRemovals = [];

$openShift = null;
$cashierId = 0;
$shiftId = null;
if (!$isCli && (($_SESSION['user_role'] ?? '') === 'cashier')) {
    $cashierId = intval($_SESSION['user_id'] ?? 0);
    $openShift = $cashierId > 0 ? getOpenShiftByCashier($pdo, $cashierId) : null;
    if ($cashierId <= 0 || $openShift === null) {
        header('Location: cashier_shift.php');
        exit;
    }
    if (isShiftFromPreviousDay($openShift)) {
        header('Location: cashier_shift.php');
        exit;
    }
    $shiftId = intval($openShift['id'] ?? 0);
}

$errors = [];
$success = false;
$csrfToken = csrfToken();
$checkoutNonce = $_SESSION['checkout_nonce'] ?? bin2hex(random_bytes(32));
$_SESSION['checkout_nonce'] = $checkoutNonce;
$paymentNotice = '';
$businessTillNumber = '1234567';
$welcomeMessage = $_SESSION['shift_message'] ?? '';
unset($_SESSION['shift_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_quick_stock_purchase'])) {
        $supplierId = intval($_POST['quick_purchase_supplier_id'] ?? 0);
        $purchaseDateRaw = trim((string) ($_POST['quick_purchase_date'] ?? ''));
        $productIds = $_POST['quick_purchase_product_id'] ?? [];
        $productNames = $_POST['quick_purchase_product_name'] ?? [];
        $packageQtys = $_POST['quick_purchase_package_qty'] ?? [];
        $packageSizeValues = $_POST['quick_purchase_package_size_value'] ?? [];
        $costPerPackages = $_POST['quick_purchase_cost_per_package'] ?? [];
        $retailPrices = $_POST['quick_purchase_retail_price'] ?? [];
        $expiryDates = $_POST['quick_purchase_expiry_date'] ?? [];
        $purchaseLines = [];
        $lineErrors = [];
        $purchaseTotal = 0.0;

        $findProductStmt = $pdo->prepare('SELECT * FROM products WHERE TRIM(CONCAT(IFNULL(brand, ""), " ", IFNULL(name, ""))) = :full_name1 OR TRIM(IFNULL(name, "")) = :full_name2 ORDER BY id LIMIT 1');
        $createProductStmt = $pdo->prepare('INSERT INTO products (brand, name, category, base_unit, price, retail_price, wholesale_price, stock, packets_per_bale, expiry_date, created_at) VALUES (:brand, :name, :category, :base_unit, :price, :retail_price, :wholesale_price, :stock, :packets_per_bale, :expiry_date, NOW())');

        $lineCount = max(count($productIds), count($packageQtys), count($packageSizeValues), count($costPerPackages), count($retailPrices), count($expiryDates));
        $submittedPurchaseTotal = 0.0;
        for ($i = 0; $i < $lineCount; $i++) {
          $submittedQuantity = floatval(str_replace(',', '', trim((string) ($packageQtys[$i] ?? '0'))));
          $submittedCost = floatval(str_replace(',', '', trim((string) ($costPerPackages[$i] ?? '0'))));
          if ($submittedQuantity > 0 && $submittedCost > 0) {
            $submittedPurchaseTotal += $submittedQuantity * $submittedCost;
          }
        }

        $preflightShiftId = intval($openShift['id'] ?? 0);
        if ($preflightShiftId <= 0 || !$openShift) {
          $lineErrors[] = 'An active cashier shift is required before recording a quick purchase.';
        } else {
          $preflightAvailableCash = getShiftAvailableCash($pdo, $openShift);
          if ($submittedPurchaseTotal > $preflightAvailableCash) {
            $lineErrors[] = sprintf('Quick purchase cost of KES %.2f exceeds available cash of KES %.2f.', $submittedPurchaseTotal, $preflightAvailableCash);
          }
        }

        for ($i = 0; $lineErrors === [] && $i < $lineCount; $i++) {
            $productId = intval($productIds[$i] ?? 0);
            $productName = trim((string) ($productNames[$i] ?? ''));
            $packageQty = floatval(str_replace(',', '', trim((string) ($packageQtys[$i] ?? '0'))));
            $packageSizeValue = floatval(str_replace(',', '', trim((string) ($packageSizeValues[$i] ?? '0'))));
            $costPerPackage = floatval(str_replace(',', '', trim((string) ($costPerPackages[$i] ?? '0'))));
            $retailPrice = floatval(str_replace(',', '', trim((string) ($retailPrices[$i] ?? '0'))));
            $wholesalePrice = $retailPrice;
            $expiryDate = trim((string) ($expiryDates[$i] ?? ''));

            if ($packageQty <= 0 || $packageSizeValue <= 0 || $costPerPackage <= 0 || $retailPrice <= 0) {
                continue;
            }

            $product = null;
            if ($productId > 0) {
                $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
                $productStmt->execute(['id' => $productId]);
                $product = $productStmt->fetch();
            }

            if (!$product && $productName !== '') {
                $findProductStmt->execute([
                    'full_name1' => trim($productName),
                    'full_name2' => trim($productName),
                ]);
                $product = $findProductStmt->fetch();
            }

            if (!$product && $productName !== '') {
                $brand = '';
                $name = $productName;

                if (strpos($productName, ' ') !== false) {
                    $pieces = preg_split('/\s+/', $productName);
                    if (count($pieces) >= 2) {
                        $brand = array_shift($pieces);
                        $name = implode(' ', $pieces);
                    }
                }

                $createProductStmt->execute([
                    'brand' => $brand !== '' ? $brand : $productName,
                    'name' => $name,
                    'category' => 'Misc',
                    'base_unit' => 'unit',
                    'price' => number_format($retailPrice, 2, '.', ''),
                    'retail_price' => number_format($retailPrice, 2, '.', ''),
                    'wholesale_price' => number_format($wholesalePrice, 2, '.', ''),
                    'stock' => 0,
                    'packets_per_bale' => max(1, intval($packageSizeValue)),
                    'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                ]);
                $productId = intval($pdo->lastInsertId());
                $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
                $productStmt->execute(['id' => $productId]);
                $product = $productStmt->fetch();
            }

            if (!$product) {
                $lineErrors[] = 'One or more selected products were not found or could not be created.';
                continue;
            }

            $productId = intval($product['id']);
            $quantity = $packageQty * $packageSizeValue;
            $costPrice = $packageSizeValue > 0 ? $costPerPackage / $packageSizeValue : 0;
            $lineCost = $packageQty * $costPerPackage;
            $purchaseTotal += $lineCost;
            $purchaseLines[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'cost_price' => $costPrice,
                'retail_price' => $retailPrice,
                'wholesale_price' => $wholesalePrice,
                'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                'line_cost' => $lineCost,
                'packets_per_bale' => max(1, intval($packageSizeValue)),
            ];
        }

        if ($purchaseLines === []) {
            $lineErrors[] = 'Add at least one valid item to the quick purchase.';
        }

        if ($lineErrors === []) {
            try {
                $pdo->beginTransaction();
                $shiftId = intval($openShift['id'] ?? 0);
            if ($shiftId <= 0 || !$openShift) {
              throw new Exception('An active cashier shift is required before recording a quick purchase.');
            }

            $shiftBalanceStmt = $pdo->prepare('SELECT * FROM cashier_shifts WHERE id = :shift_id AND cashier_id = :cashier_id AND status = :status FOR UPDATE');
            $shiftBalanceStmt->execute([
              'shift_id' => $shiftId,
              'cashier_id' => intval($_SESSION['user_id'] ?? 0),
              'status' => 'open',
            ]);
            $lockedShift = $shiftBalanceStmt->fetch(PDO::FETCH_ASSOC);
            if ($lockedShift === false) {
              throw new Exception('The active cashier shift could not be found.');
            }

            $availableCash = getShiftAvailableCash($pdo, $lockedShift);
            if ($purchaseTotal > $availableCash) {
              throw new Exception(sprintf('Quick purchase cost of KES %.2f exceeds available cash of KES %.2f.', $purchaseTotal, $availableCash));
            }

              if ($supplierId <= 0) {
                $supplierName = trim((string) ($_POST['quick_purchase_supplier_name'] ?? 'Counter Supplier'));
                if ($supplierName === '') {
                  $supplierName = 'Counter Supplier';
                }
                $supplierInsert = $pdo->prepare('INSERT INTO suppliers (name, kra_pin, phone) VALUES (:name, :kra_pin, :phone)');
                $supplierInsert->execute(['name' => $supplierName, 'kra_pin' => null, 'phone' => null]);
                $supplierId = intval($pdo->lastInsertId());
              }

                $purchaseStmt = $pdo->prepare('INSERT INTO quick_stock_purchases (cashier_id, shift_id, supplier_id, purchase_date, total_cost, payment_method, receipt_type, notes, created_at) VALUES (:cashier_id, :shift_id, :supplier_id, :purchase_date, :total_cost, :payment_method, :receipt_type, :notes, NOW())');
                $purchaseStmt->execute([
                    'cashier_id' => intval($_SESSION['user_id'] ?? 0),
                    'shift_id' => $shiftId > 0 ? $shiftId : null,
                    'supplier_id' => $supplierId > 0 ? $supplierId : null,
                    'purchase_date' => $purchaseDateRaw !== '' ? $purchaseDateRaw : date('Y-m-d'),
                    'total_cost' => number_format($purchaseTotal, 2, '.', ''),
                    'payment_method' => 'cash',
                    'receipt_type' => 'none',
                    'notes' => null,
                ]);
                $purchaseId = intval($pdo->lastInsertId());
                $purchaseNo = sprintf('QP-%04d', $purchaseId);
                $pdo->prepare('UPDATE quick_stock_purchases SET purchase_no = :purchase_no WHERE id = :id')->execute([
                    'purchase_no' => $purchaseNo,
                    'id' => $purchaseId,
                ]);

                foreach ($purchaseLines as $line) {
                    $lineStmt = $pdo->prepare('INSERT INTO quick_stock_purchase_lines (quick_purchase_id, product_id, quantity, cost_price, selling_price, expiry_date, total_cost) VALUES (:quick_purchase_id, :product_id, :quantity, :cost_price, :selling_price, :expiry_date, :total_cost)');
                    $lineStmt->execute([
                        'quick_purchase_id' => $purchaseId,
                        'product_id' => $line['product_id'],
                        'quantity' => $line['quantity'],
                        'cost_price' => number_format($line['cost_price'], 2, '.', ''),
                        'selling_price' => number_format($line['retail_price'], 2, '.', ''),
                        'expiry_date' => $line['expiry_date'] !== null ? $line['expiry_date'] : null,
                        'total_cost' => number_format($line['line_cost'], 2, '.', ''),
                    ]);
                      addStockBatch($pdo, [
                        'product_id' => $line['product_id'],
                        'quick_purchase_id' => $purchaseId,
                        'source_type' => 'quick_purchase',
                        'quantity_received' => $line['quantity'] / max(1, $line['packets_per_bale']),
                        'base_quantity_received' => $line['quantity'],
                        'package_size_value' => $line['packets_per_bale'],
                        'package_size_unit' => null,
                        'cost_per_package' => $line['cost_price'] * max(1, $line['packets_per_bale']),
                        'retail_price' => $line['retail_price'],
                        'wholesale_price' => $line['wholesale_price'],
                        'expiry_date' => $line['expiry_date'],
                      ]);

                    $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
                    $productStmt->execute(['id' => $line['product_id']]);
                    $product = $productStmt->fetch();
                    if ($product) {
                        $updatedStock = floatval($product['stock']) + $line['quantity'];
                        $priceValue = $line['retail_price'] > 0 ? $line['retail_price'] : floatval($product['retail_price'] ?: $product['price'] ?: 0);
                        $updateProductStmt = $pdo->prepare('UPDATE products SET stock = :stock, price = :price, retail_price = :retail_price, packets_per_bale = :packets_per_bale WHERE id = :id');
                        $updateProductStmt->execute([
                            'stock' => $updatedStock,
                            'price' => number_format($priceValue, 2, '.', ''),
                            'retail_price' => number_format($priceValue, 2, '.', ''),
                            'packets_per_bale' => intval($line['packets_per_bale']),
                            'id' => $line['product_id'],
                        ]);

                        if ($line['expiry_date'] !== null) {
                            $currentExpiry = trim((string) ($product['expiry_date'] ?? ''));
                            $newExpiryTimestamp = strtotime($line['expiry_date']);
                            $currentExpiryTimestamp = $currentExpiry !== '' ? strtotime($currentExpiry) : null;
                            if ($newExpiryTimestamp !== false && ($currentExpiryTimestamp === null || $newExpiryTimestamp < $currentExpiryTimestamp)) {
                                $expiryUpdateStmt = $pdo->prepare('UPDATE products SET expiry_date = :expiry_date WHERE id = :id');
                                $expiryUpdateStmt->execute([
                                    'expiry_date' => $line['expiry_date'],
                                    'id' => $line['product_id'],
                                ]);
                            }
                        }

                        $movementReason = sprintf('Quick stock purchase %s for %s', $purchaseNo, $product['brand'] . ' ' . $product['name']);
                        $movementStmt = $pdo->prepare('INSERT INTO stock_movements (product_id, change_quantity, reason) VALUES (:product_id, :change_quantity, :reason)');
                        $movementStmt->execute([
                            'product_id' => $product['id'],
                            'change_quantity' => $line['quantity'],
                            'reason' => $movementReason,
                        ]);
                    }
                }

                $pdo->commit();
                $success = true;
                $paymentNotice = sprintf('Quick stock purchase %s recorded. Inventory was increased and KES %.2f was deducted from cash.', $purchaseNo, $purchaseTotal);
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Unable to save the quick stock purchase.';
            }
        } else {
            $errors = array_merge($errors, $lineErrors);
        }
    } elseif (isset($_POST['add_expense'])) {
        $expenseTitle = trim((string) ($_POST['expense_title'] ?? ''));
        $expenseCategory = strtolower(trim((string) ($_POST['expense_category'] ?? 'general')));
        $expenseAmountRaw = trim((string) ($_POST['expense_amount'] ?? ''));
        $expenseDate = trim((string) ($_POST['expense_date'] ?? date('Y-m-d')));
        $expenseNotes = trim((string) ($_POST['expense_notes'] ?? ''));
        $paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cash')));
        $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
        $cashDrawerMovement = $paymentMethod === 'cash';
        $cashDrawerAmountRaw = trim((string) ($_POST['cash_drawer_amount'] ?? ''));
        $allowedCategories = ['general', 'rent', 'utilities', 'transport', 'salary', 'marketing', 'maintenance', 'staff_lunch', 'other'];
        $allowedPaymentMethods = ['cash', 'bank', 'card', 'other'];

        if (!in_array($expenseCategory, $allowedCategories, true)) {
            $expenseCategory = 'general';
        }
        if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
            $paymentMethod = 'cash';
        }

        $requestErrors = [];
        if ($expenseTitle === '') {
            $requestErrors[] = 'Expense title is required.';
        }
        if ($expenseAmountRaw === '' || !is_numeric($expenseAmountRaw) || (float) $expenseAmountRaw <= 0) {
            $requestErrors[] = 'Enter a valid expense amount.';
        }
        if ($expenseDate === '' || strtotime($expenseDate) === false) {
            $requestErrors[] = 'Enter a valid expense date.';
        }
        if ($cashDrawerMovement && $cashDrawerAmountRaw !== '' && (!is_numeric($cashDrawerAmountRaw) || (float) $cashDrawerAmountRaw <= 0)) {
            $requestErrors[] = 'Enter a valid positive cash drawer amount.';
        }
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            $requestErrors[] = 'Your session expired. Refresh the page and try again.';
        }

        if ($requestErrors === []) {
            if ($cashDrawerMovement) {
                $availableCash = getShiftAvailableCash($pdo, $openShift);
                $requestedCash = $cashDrawerAmountRaw !== '' ? (float) $cashDrawerAmountRaw : (float) $expenseAmountRaw;
                if ($requestedCash > $availableCash) {
                    $requestErrors[] = sprintf('Expense cash amount of KES %.2f exceeds available cash of KES %.2f.', $requestedCash, $availableCash);
                }
            }
        }

        if ($requestErrors === []) {
            $stmt = $pdo->prepare('INSERT INTO business_expenses (title, category, amount, payment_method, payment_reference, cash_drawer_movement, cash_drawer_amount, expense_date, notes, created_by) VALUES (:title, :category, :amount, :payment_method, :payment_reference, :cash_drawer_movement, :cash_drawer_amount, :expense_date, :notes, :created_by)');
            $stmt->execute([
                'title' => $expenseTitle,
                'category' => $expenseCategory,
                'amount' => number_format((float) $expenseAmountRaw, 2, '.', ''),
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
                'cash_drawer_movement' => $cashDrawerMovement ? 1 : 0,
                'cash_drawer_amount' => $cashDrawerMovement ? ($cashDrawerAmountRaw !== '' ? number_format((float) $cashDrawerAmountRaw, 2, '.', '') : number_format((float) $expenseAmountRaw, 2, '.', '')) : '0.00',
                'expense_date' => $expenseDate,
                'notes' => $expenseNotes !== '' ? $expenseNotes : null,
                'created_by' => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'Cashier'),
            ]);
            $success = true;
            $paymentNotice = 'Expense recorded successfully.';
        } else {
            $errors = array_merge($errors, $requestErrors);
        }
    } else {
    $productIds = $_POST['product_id'] ?? [];
    $productNames = $_POST['product_name'] ?? [];
    $saleQuantities = $_POST['sale_quantity'] ?? [];
    $saleModes = $_POST['sale_mode'] ?? [];
    $amountPaid = floatval(str_replace(',', '', trim($_POST['amount_paid'] ?? '0')));
    $cashApplied = floatval(str_replace(',', '', trim($_POST['cash_amount'] ?? (string) $amountPaid)));
    $equityAmount = floatval(str_replace(',', '', trim($_POST['equity_amount'] ?? '0')));
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $saleDate = trim((string) ($_POST['sale_date'] ?? date('Y-m-d')));
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $customerIdentifier = trim($_POST['customer_identifier'] ?? '');
    $transactionCode = trim($_POST['transaction_code'] ?? '');

    if (!in_array($paymentMethod, ['cash', 'equity', 'bank', 'credit'], true)) {
        $paymentMethod = 'cash';
    }

    $saleDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $saleDate);
    $saleDateErrors = DateTimeImmutable::getLastErrors();
    if ($saleDateObject === false || ($saleDateErrors !== false && ($saleDateErrors['warning_count'] > 0 || $saleDateErrors['error_count'] > 0)) || $saleDateObject > new DateTimeImmutable('today')) {
      $errors[] = 'Sale date must be a valid date that is not in the future.';
    }
    $saleCreatedAt = $saleDateObject instanceof DateTimeImmutable ? $saleDateObject->format('Y-m-d') . ' ' . date('H:i:s') : date('Y-m-d H:i:s');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
      $errors[] = 'Your checkout session expired. Refresh the page and try again.';
    }
    if (!hash_equals($checkoutNonce, (string) ($_POST['checkout_nonce'] ?? ''))) {
      $errors[] = 'This checkout request has already been submitted or has expired. Please start again.';
    }

    $lineCount = max(count($productIds), count($saleQuantities), count($saleModes));
    $saleLines = [];

    for ($i = 0; $i < $lineCount; $i++) {
        $productId = intval($productIds[$i] ?? 0);
        $saleQuantity = floatval(str_replace(',', '', trim($saleQuantities[$i] ?? '0')));
        $saleMode = isset($saleModes[$i]) && in_array($saleModes[$i], ['packet', 'bale', 'unit', 'package'], true) ? $saleModes[$i] : 'packet';
        $normalizedSaleMode = $saleMode === 'package' || $saleMode === 'bale' ? 'bale' : 'packet';
        if ($productId <= 0) {
            $errors[] = 'Select a product for each sale line.';
            continue;
        }
        if ($saleQuantity <= 0) {
            $errors[] = 'Enter a sale quantity greater than zero for each line.';
            continue;
        }
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();

        if (!$product) {
            $errors[] = 'Selected product was not found.';
            continue;
        }

        $batchPrices = getAvailableBatchPricing($pdo, $productId);
        if ((float) $batchPrices['retail_price'] > 0) {
          $product['retail_price'] = $batchPrices['retail_price'];
          $product['price'] = $batchPrices['retail_price'];
        }
        if ((float) $batchPrices['wholesale_price'] > 0) {
          $product['wholesale_price'] = $batchPrices['wholesale_price'];
        }

        $packetsPerBale = max(1, intval($product['packets_per_bale'] ?: 24));
        $effectivePackets = $normalizedSaleMode === 'bale' ? $saleQuantity * $packetsPerBale : $saleQuantity;
        $availableStock = getAvailableBatchQuantity($pdo, $productId);
        $maxAllowed = $normalizedSaleMode === 'bale' ? floor($availableStock / $packetsPerBale) : $availableStock;

        if ($effectivePackets > $availableStock) {
            $errors[] = sprintf('Sale quantity for %s cannot exceed current stock.', htmlspecialchars($product['brand'] . ' ' . $product['name']));
            continue;
        }

        if ($normalizedSaleMode === 'bale' && $saleQuantity > $maxAllowed) {
            $errors[] = sprintf('Only %s whole bales are available for %s.', $maxAllowed, htmlspecialchars($product['brand'] . ' ' . $product['name']));
            continue;
        }

        $retailPrice = floatval($product['retail_price'] ?? $product['price'] ?? 0);
        $wholesalePrice = floatval($product['wholesale_price'] ?? 0);
        $trustedUnitPrice = $normalizedSaleMode === 'bale'
          ? ($wholesalePrice > 0 ? $wholesalePrice : $retailPrice)
          : ($saleQuantity >= 6 && $wholesalePrice > 0 ? $wholesalePrice : $retailPrice);
        if ($trustedUnitPrice <= 0) {
          $errors[] = sprintf('No valid price is configured for %s.', htmlspecialchars($product['brand'] . ' ' . $product['name']));
          continue;
        }

        $productName = trim($productNames[$i] ?? '');
        $saleLines[] = [
            'product' => $product,
            'product_name' => $productName !== '' ? $productName : trim($product['brand'] . ' ' . $product['name']),
            'quantity' => $saleQuantity,
            'quantity_in_packets' => $effectivePackets,
            'unit_price' => $trustedUnitPrice,
            'sale_mode' => $normalizedSaleMode,
        ];
    }

    if (empty($saleLines) && empty($errors)) {
        $errors[] = 'Add at least one valid sale item before checkout.';
    }

    if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $normalizedPaymentMethod = normalizePaymentMethod($paymentMethod);
                $grandTotal = array_reduce($saleLines, static function ($sum, $line) {
                    return $sum + floatval($line['quantity']) * floatval($line['unit_price']);
                }, 0.0);

                if ($normalizedPaymentMethod === 'equity') {
                  if ($transactionCode === '') {
                    $errors[] = 'Enter the Equity transaction code to record the payment.';
                  }
                  if ($amountPaid < 0 || $cashApplied < 0 || $equityAmount < 0 || $amountPaid + 0.01 < $cashApplied || abs(($cashApplied + $equityAmount) - $grandTotal) > 0.01) {
                    $errors[] = 'Cash and Equity amounts must add up exactly to the sale total.';
                  }
                }

                if ($normalizedPaymentMethod === 'credit' && $customerIdentifier === '') {
                  $errors[] = 'Enter the customer phone number or name to record this credit sale.';
                }

                if ($normalizedPaymentMethod === 'cash' && $amountPaid < $grandTotal) {
                  $errors[] = 'Cash received cannot be less than the sale total.';
                }

                if (!empty($errors)) {
                  throw new Exception('Payment validation failed.');
                }

                $isSplitEquity = $normalizedPaymentMethod === 'equity' && $amountPaid > 0.01;
                $tenderedAmount = $normalizedPaymentMethod === 'cash' ? $amountPaid : ($isSplitEquity ? $amountPaid : $grandTotal);
                $changeAmount = $normalizedPaymentMethod === 'cash'
                    ? max(0.0, $tenderedAmount - $grandTotal)
                    : ($isSplitEquity ? max(0.0, $amountPaid - $cashApplied) : 0.0);

                if ($normalizedPaymentMethod === 'credit') {
                    $customer = getOrCreateCustomerByIdentifier($pdo, $customerIdentifier);
                    $creditCustomerId = intval($customer['id']);
                    $customerPhoneOnRecord = trim((string) ($customer['phone'] ?? '')) !== '' ? trim((string) $customer['phone']) : null;

                    $saleStmt = $pdo->prepare('INSERT INTO sales (created_at, total_amount, amount_tendered, change_amount, payment_method, payment_status, customer_phone, notes, cashier_id, shift_id) VALUES (:created_at, :total_amount, :amount_tendered, :change_amount, :payment_method, :payment_status, :customer_phone, :notes, :cashier_id, :shift_id)');
                    $saleStmt->execute([
                        'created_at' => $saleCreatedAt,
                        'total_amount' => $grandTotal,
                        'amount_tendered' => 0.0,
                        'change_amount' => 0.0,
                        'payment_method' => 'credit',
                        'payment_status' => 'credit',
                        'customer_phone' => $customerPhoneOnRecord,
                        'notes' => 'Customer credit sale recorded to ledger.',
                        'cashier_id' => $cashierId > 0 ? $cashierId : null,
                        'shift_id' => $shiftId > 0 ? $shiftId : null,
                    ]);
                    $saleId = $pdo->lastInsertId();

                    createCustomerCreditInvoice($pdo, $creditCustomerId, $saleId, $grandTotal);
                    updateCustomerBalance($pdo, $creditCustomerId, $grandTotal);
                } else {
                    $saleStmt = $pdo->prepare('INSERT INTO sales (created_at, total_amount, amount_tendered, change_amount, payment_method, payment_status, notes, cashier_id, shift_id) VALUES (:created_at, :total_amount, :amount_tendered, :change_amount, :payment_method, :payment_status, :notes, :cashier_id, :shift_id)');
                    $saleStmt->execute([
                        'created_at' => $saleCreatedAt,
                        'total_amount' => $grandTotal,
                        'amount_tendered' => $tenderedAmount,
                        'change_amount' => $changeAmount,
                        'payment_method' => $isSplitEquity ? 'mixed' : normalizePaymentMethod($paymentMethod),
                        'payment_status' => 'paid',
                        'notes' => $isSplitEquity
                            ? 'Split payment: cash and Equity PayBill; Equity payment awaiting reconciliation.'
                            : ($normalizedPaymentMethod === 'equity'
                                ? 'Equity PayBill payment recorded and awaiting reconciliation.'
                                : 'Sale completed'),
                        'cashier_id' => $cashierId > 0 ? $cashierId : null,
                        'shift_id' => $shiftId > 0 ? $shiftId : null,
                    ]);
                    $saleId = $pdo->lastInsertId();
                }

                foreach ($saleLines as $line) {
                    $product = $line['product'];
                    $costPerUnit = getProductAverageCostPerUnit($pdo, $product['id']);
                    $costTotal = floatval($line['quantity_in_packets']) * $costPerUnit;
                    $stmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, product_name, quantity, quantity_in_packets, sale_mode, unit_price, cost_per_unit, cost_total, line_total) VALUES (:sale_id, :product_id, :product_name, :quantity, :quantity_in_packets, :sale_mode, :unit_price, :cost_per_unit, :cost_total, :line_total)');
                    $stmt->execute([
                        'sale_id' => $saleId,
                        'product_id' => $product['id'],
                        'product_name' => $line['product_name'],
                        'quantity' => $line['quantity'],
                        'quantity_in_packets' => $line['quantity_in_packets'],
                        'sale_mode' => $line['sale_mode'],
                        'unit_price' => $line['unit_price'],
                        'cost_per_unit' => $costPerUnit,
                        'cost_total' => $costTotal,
                        'line_total' => floatval($line['quantity']) * floatval($line['unit_price']),
                    ]);

                    $stockChange = floatval($line['quantity_in_packets']);
                    consumeStockBatches($pdo, (int) $product['id'], $stockChange, (string) $saleId);
                    $remainingStockStmt = $pdo->prepare(
                        'SELECT COALESCE(SUM(base_quantity_remaining), 0)
                         FROM stock_batches
                         WHERE product_id = :product_id
                           AND base_quantity_remaining > 0
                           AND (expiry_date IS NULL OR expiry_date > CURDATE())'
                    );
                    $remainingStockStmt->execute(['product_id' => (int) $product['id']]);
                    $remainingStock = (float) $remainingStockStmt->fetchColumn();
                    $syncStockStmt = $pdo->prepare('UPDATE products SET stock = :stock WHERE id = :id');
                    $syncStockStmt->execute([
                        'stock' => $remainingStock,
                        'id' => (int) $product['id'],
                    ]);

                    $reason = sprintf(
                        'Completed sale #%s for %s at KES %.2f',
                        $saleId,
                        $line['product_name'],
                        floatval($line['quantity']) * floatval($line['unit_price'])
                    );

                    $stmt = $pdo->prepare('INSERT INTO stock_movements (product_id, change_quantity, reason) VALUES (:product_id, :change_quantity, :reason)');
                    $stmt->execute([
                        'product_id' => $product['id'],
                        'change_quantity' => -$stockChange,
                        'reason' => $reason,
                    ]);
                }

                $paymentRows = $isSplitEquity
                    ? [
                        ['method' => 'cash', 'amount' => $cashApplied, 'status' => 'success', 'code' => null],
                        ['method' => 'equity', 'amount' => $equityAmount, 'status' => 'recorded', 'code' => $transactionCode],
                    ]
                    : [[
                        'method' => $normalizedPaymentMethod,
                        'amount' => $grandTotal,
                        'status' => $normalizedPaymentMethod === 'equity' || $normalizedPaymentMethod === 'credit' ? 'recorded' : 'success',
                        'code' => $normalizedPaymentMethod === 'equity' ? $transactionCode : null,
                    ]];
                $paymentStmt = $pdo->prepare('INSERT INTO sale_payments (sale_id, payment_method, amount, status, transaction_code, customer_phone) VALUES (:sale_id, :payment_method, :amount, :status, :transaction_code, :customer_phone)');
                foreach ($paymentRows as $paymentRow) {
                    if ((float) $paymentRow['amount'] <= 0.01) {
                        continue;
                    }
                    $paymentStmt->execute([
                        'sale_id' => $saleId,
                        'payment_method' => $paymentRow['method'],
                        'amount' => $paymentRow['amount'],
                        'status' => $paymentRow['status'],
                        'transaction_code' => $paymentRow['code'],
                        'customer_phone' => $normalizedPaymentMethod === 'credit' ? ($customerPhoneOnRecord ?? null) : null,
                    ]);
                }

                $receiptNumber = 'RCP-' . date('YmdHis') . '-' . $saleId;
                $paymentReceiptStmt = $pdo->prepare('UPDATE sale_payments SET receipt_number = :receipt_number WHERE sale_id = :sale_id');
                $paymentReceiptStmt->execute(['receipt_number' => $receiptNumber, 'sale_id' => $saleId]);

                $pdo->commit();
                $saleItemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = :sale_id');
                $saleItemsStmt->execute(['sale_id' => $saleId]);
                $receiptItems = $saleItemsStmt->fetchAll();
                $receiptFile = printThermalReceipt(generateThermalReceiptText($sale ?? ['payment_method' => $normalizedPaymentMethod], $receiptItems, $isSplitEquity ? 'mixed' : $normalizedPaymentMethod, $receiptNumber, $_SESSION['user_name'] ?? 'Cashier', $tenderedAmount, $changeAmount, $paymentRows));
                $_SESSION['last_receipt_file'] = $receiptFile;
                $_SESSION['receipt_auto_print'] = true;
                if ($normalizedPaymentMethod === 'credit') {
                  $paymentNotice = 'Credit sale recorded. Customer invoice created and balance updated.';
                } elseif ($normalizedPaymentMethod === 'equity') {
                  $paymentNotice = 'Equity PayBill recorded and sale completed. Receipt ready for printing.';
                } else {
                  $paymentNotice = 'Sale completed and receipt ready for printing.';
                }
                $success = true;
                $checkoutNonce = bin2hex(random_bytes(32));
                $_SESSION['checkout_nonce'] = $checkoutNonce;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Sale recording failed: ' . $e->getMessage());
                $errors[] = 'Unable to record sale: ' . $e->getMessage();
            }
    }
      }
}

$products = [];
$supplierStmt = $pdo->query('SELECT * FROM suppliers ORDER BY name LIMIT 250');
$suppliers = $supplierStmt->fetchAll();
$barcodes = [];
$expiringSoonProducts = getBatchExpiryProducts($pdo, true);
$expiredProducts = getBatchExpiryProducts($pdo, false);

$autoPrintReceipt = !empty($_SESSION['receipt_auto_print']);
if ($autoPrintReceipt) {
    unset($_SESSION['receipt_auto_print']);
}
$success = $success || (isset($_GET['success']) && $_GET['success'] === '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMART POS SYSTEM</title>
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
    <div class="mb-6 hidden flex-wrap items-center justify-between gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:flex">
      <div class="flex items-center gap-3">
        <img src="colour_logo.jpg" alt="POS2 logo" class="h-14 w-14 rounded-2xl object-cover shadow-sm">
        <div>
          <p class="text-lg font-semibold text-slate-900">SMART POS SYSTEM</p>
          <p class="text-sm text-slate-500">Cashier workspace</p>
        </div>
      </div>
      <span class="hidden rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 sm:inline-flex">Logged in as <?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['username'] ?? 'Cashier'); ?></span>
    </div>
    <header class="mb-8">
      <div class="flex items-center justify-between gap-3 mb-4">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100">Menu</button>
        <span class="rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">Logged in as <?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['username'] ?? 'Cashier'); ?></span>
      </div>
      <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap gap-3">
          <a href="stock_intake.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">New Stock Intake</a>
          <a href="index.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Sales</a>
          <a href="customer_debts.php" class="rounded-3xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700">Customer Debts</a>
          <a href="out_of_stock.php" class="rounded-3xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-700">Out of stock</a>
          <button type="button" id="openQuickPurchaseModalBtn" class="rounded-3xl bg-cyan-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-cyan-700">Quick purchase</button>
          <button type="button" id="openExpenseModalBtn" class="rounded-3xl bg-amber-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-700">Daily expenses</button>
          <a href="cashier_shift.php" class="rounded-3xl bg-slate-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">My Shift</a>
        </div>
        <a href="logout.php?return=cashier" class="rounded-3xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-700">Logout</a>
      </div>
    </header>

    <div id="mobileMenu" class="fixed inset-x-0 top-0 z-50 hidden bg-slate-50/95 p-4 shadow-lg backdrop-blur-sm border-b border-slate-200 sm:hidden">
      <div class="flex items-center justify-between gap-3 mb-4">
        <span class="text-base font-semibold">Menu</span>
        <button id="mobileMenuClose" type="button" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Close</button>
      </div>
      <div class="flex flex-col gap-3">
        <a href="stock_intake.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">New Stock Intake</a>
        <a href="index.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Sales</a>
        <a href="customer_debts.php" class="rounded-3xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700">Customer Debts</a>
        <a href="out_of_stock.php" class="rounded-3xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-700">Out of stock</a>
        <button type="button" id="mobileOpenQuickPurchaseModalBtn" class="rounded-3xl bg-cyan-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-cyan-700">Quick purchase</button>
        <button type="button" id="mobileOpenExpenseModalBtn" class="rounded-3xl bg-amber-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-700">Daily expenses</button>
        <a href="cashier_shift.php" class="rounded-3xl bg-slate-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">My Shift</a>
        <a href="logout.php?return=cashier" class="rounded-3xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-700">Logout</a>
      </div>
    </div>

    <div class="grid gap-6">
      <div id="quickPurchaseModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-2 sm:p-4">
        <div class="max-h-[95vh] w-full max-w-3xl overflow-y-auto rounded-[2rem] border border-slate-200 bg-white p-4 shadow-2xl sm:p-6">
          <div class="mb-4 flex items-start justify-between gap-3">
            <div class="flex items-start gap-3">
              <img src="colour_logo.jpg" alt="SMART POS SYSTEM logo" class="mt-1 h-11 w-11 rounded-2xl object-cover shadow-sm">
              <div>
                <h2 class="text-2xl font-semibold">Quick stock purchase</h2>
                <p class="text-sm text-slate-500">Record counter-stock bought from the opening float as stock, not as an expense.</p>
              </div>
            </div>
            <button type="button" id="closeQuickPurchaseModalBtn" class="rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Close</button>
          </div>

          <form method="POST" class="space-y-4">
            <input type="hidden" name="submit_quick_stock_purchase" value="1">
            <div class="grid gap-3 md:grid-cols-3">
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Supplier</label>
                <select name="quick_purchase_supplier_id" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
                  <option value="0">Create a new counter supplier</option>
                  <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo (int) $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">New supplier name</label>
                <input type="text" name="quick_purchase_supplier_name" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="Counter Supplier">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Receipt date</label>
                <input type="date" name="quick_purchase_date" value="<?php echo date('Y-m-d'); ?>" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
              </div>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <h3 class="text-base font-semibold text-slate-900">Items</h3>
                  <p class="text-sm text-slate-500">Add bread, cakes, milk, or other fast-moving goods.</p>
                </div>
                <button type="button" id="addQuickPurchaseLineBtn" class="rounded-2xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">+ Add item</button>
              </div>
              <div id="quickPurchaseLines" class="mt-3 space-y-3">
                 <div class="grid gap-3 md:grid-cols-6">
                  <div class="md:col-span-2 relative">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Product</label>
                    <input type="text" name="quick_purchase_product_name[]" class="product-search-input w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="Type product name" autocomplete="off" required>
                    <input type="hidden" name="quick_purchase_product_id[]" class="product-id-input" value="0">
                    <div class="product-suggestions hidden absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-3xl border border-slate-200 bg-white shadow-lg"></div>
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Package qty</label>
                    <input type="number" min="0.01" step="0.01" name="quick_purchase_package_qty[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="0.00" required>
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Package size</label>
                    <input type="number" min="1" step="1" name="quick_purchase_package_size_value[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="24" required>
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Cost per package</label>
                    <input type="number" min="0.01" step="0.01" name="quick_purchase_cost_per_package[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="0.00" required>
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Retail price per unit</label>
                    <input type="number" min="0.01" step="0.01" name="quick_purchase_retail_price[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="0.00" required>
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Expiry date</label>
                    <input type="date" name="quick_purchase_expiry_date[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="YYYY-MM-DD">
                  </div>
                </div>
              </div>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="rounded-3xl bg-cyan-600 px-5 py-3 text-sm font-semibold text-white">Record quick purchase</button>
            </div>
          </form>
        </div>
      </div>

      <div id="expenseRequestModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-2 sm:p-4">
        <div class="max-h-[95vh] w-full max-w-2xl overflow-y-auto rounded-[2rem] border border-slate-200 bg-white p-4 shadow-2xl sm:p-6">
          <div class="mb-4 flex items-start justify-between gap-3 sm:mb-4">
            <div class="flex items-start gap-3">
              <img src="colour_logo.jpg" alt="SMART POS SYSTEM logo" class="mt-1 h-11 w-11 rounded-2xl object-cover shadow-sm">
              <div>
                <h2 class="text-2xl font-semibold">Record expense</h2>
                <p class="text-sm text-slate-500">Record the expense directly in the business expenses ledger.</p>
              </div>
            </div>
            <button type="button" id="closeExpenseModalBtn" class="rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Close</button>
          </div>

          <form method="POST" class="grid gap-3 md:grid-cols-2">
            <input type="hidden" name="add_expense" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="md:col-span-2">
              <label class="mb-1 block text-sm font-medium text-slate-700">Expense title</label>
              <input type="text" name="expense_title" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="Fuel, cleaning, airtime, etc." required>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-700">Category</label>
              <select name="expense_category" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
                <option value="general">General</option>
                <option value="rent">Rent</option>
                <option value="utilities">Utilities</option>
                <option value="transport">Transport</option>
                <option value="salary">Salary</option>
                <option value="marketing">Marketing</option>
                <option value="maintenance">Maintenance</option>
                <option value="staff_lunch">Staff Lunch</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-700">Payment method</label>
              <select name="payment_method" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
                <option value="card">Card</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-700">Amount (KES)</label>
              <input type="number" name="expense_amount" min="0.01" step="0.01" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="0.00" required>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-700">Payment reference</label>
              <input type="text" name="payment_reference" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="Receipt / transaction ID">
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-700">Expense date</label>
              <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
            </div>
            <div class="md:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" name="cash_drawer_movement" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                <span>This also reduced the POS cash drawer</span>
              </label>
              <div class="mt-3 grid gap-3 md:grid-cols-2">
                <div>
                  <label class="mb-1 block text-sm text-slate-600">Cash drawer amount (KES)</label>
                  <input type="number" name="cash_drawer_amount" min="0.01" step="0.01" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="0.00">
                </div>
                <p class="text-sm text-slate-500">If left blank, the expense amount is used.</p>
              </div>
            </div>
            <div class="md:col-span-2 flex justify-end gap-3">
              <button type="button" id="cancelExpenseModalBtn" class="rounded-3xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700">Cancel</button>
              <button type="submit" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Save expense</button>
            </div>
          </form>
        </div>
      </div>

      <aside class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        
        <h2 class="text-2xl font-semibold mb-4">Record Sale</h2>

        <?php if ($welcomeMessage): ?>
          <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
            <p class="font-semibold"><?php echo htmlspecialchars($welcomeMessage); ?></p>
          </div>
        <?php endif; ?>
        <?php if ($success): ?>
          <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
            <p class="font-semibold">Sale recorded successfully.</p>
            <p class="text-sm mt-1">Stock and movement records were updated.</p>
          </div>
        <?php endif; ?>
        <?php if ($paymentNotice): ?>
          <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
            <p class="font-semibold">Expense recorded</p>
            <p class="text-sm mt-1"><?php echo htmlspecialchars($paymentNotice); ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($expiringSoonProducts)): ?>
          <div class="mb-6 rounded-3xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
            <p class="font-semibold">Expiry alert</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
              <?php foreach ($expiringSoonProducts as $product): ?>
                <?php $status = getProductExpiryStatus($product); ?>
                <li><?php echo htmlspecialchars($product['brand'] . ' ' . $product['name']); ?> expires on <?php echo htmlspecialchars($status['expires_at']); ?>.</li>
              <?php endforeach; ?>
            </ul>
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

        <form id="salesForm" method="POST" class="space-y-6">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="checkout_nonce" value="<?php echo htmlspecialchars($checkoutNonce, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="hidden rounded-3xl border border-slate-200 bg-slate-50 p-4 sm:block">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Checkout wizard</p>
              </div>
              <div class="flex flex-wrap gap-2 text-sm">
                <span data-step-indicator class="rounded-full bg-slate-900 px-3 py-1 font-semibold text-white">1. Product</span>
                <span data-step-indicator class="rounded-full bg-white px-3 py-1 font-semibold text-slate-700">2. Review</span>
              </div>
            </div>
          </div>

          <section data-wizard-step="1" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2">
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Scan barcode</span>
                <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                  <input id="barcodeInput" type="text" autocomplete="off" placeholder="Scan QR / barcode and press Enter" class="flex-1 min-w-0 rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
                  <div class="flex flex-wrap gap-2">
                    <button type="button" id="barcodeScanTrigger" class="whitespace-nowrap rounded-3xl border border-slate-200 bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Tap to scan</button>
                    <button type="button" id="cameraScanButton" class="whitespace-nowrap rounded-3xl border border-slate-200 bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Camera scan</button>
                  </div>
                </div>
                <div id="cameraScannerContainer" class="hidden mt-3 rounded-3xl border border-slate-200 bg-slate-50 p-4 mx-auto" style="max-width: 360px; width: calc(100% - 1rem);">
                  <div class="flex items-center justify-between gap-3 mb-3">
                    <p id="cameraStatus" class="text-sm text-slate-600">Camera scanner is ready.</p>
                    <button type="button" id="cameraStopButton" class="rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Stop</button>
                  </div>
                  <div id="cameraVideo" class="w-full rounded-3xl bg-black overflow-hidden" style="width:100%; aspect-ratio: 3 / 4; min-height: 180px; max-height: 320px;"></div>
                </div>
              </label>
              <label class="block">
                <div class="flex items-center justify-between gap-3">
                  <span class="text-sm font-medium text-slate-700">Search product</span>
                  <button type="button" id="openPriceLookupButton" class="rounded-2xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100">Price lookup</button>
                </div>
                <div class="mt-2 relative">
                  <input id="productSearchInput" type="text" autocomplete="off" placeholder="Type product name to search" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
                  <div id="productSuggestions" class="hidden absolute left-0 right-0 z-30 mt-2 max-h-56 overflow-y-auto rounded-3xl border border-slate-200 bg-white shadow-xl"></div>
                </div>
                <select id="productSelect" class="mt-3 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
                  <option value="">Select a product</option>
                  <option value="">Search or scan a product to begin</option>
                </select>
              </label>
            </div>
            <p id="barcodeMessage" class="mt-2 text-xs text-slate-500">Step 1: Scan or select the product, choose quantity, then continue to review.</p>

            <div class="grid gap-4 sm:grid-cols-2">
              <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-sm font-medium text-slate-700">Current stock</p>
                <p id="currentStockAmount" class="mt-3 text-2xl font-semibold text-slate-900">0</p>
                <p id="currentBaseUnit" class="text-sm text-slate-500">unit</p>
              </div>
              <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-sm font-medium text-slate-700">Selected product</p>
                <p id="selectedProductName" class="mt-3 text-xl font-semibold text-slate-900">None</p>
              </div>
            </div>

            <div class="flex justify-end">
              <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5 w-full mb-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                  <div>
                    <p class="text-sm uppercase tracking-[0.2em] text-slate-500">Quick quantities</p>
                    <p class="mt-2 text-sm text-slate-700">Choose a quick quantity or type a custom amount.</p>
                  </div>
                  <div id="quickQtyButtons" class="flex flex-wrap gap-2"></div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                  <input id="customQuantityInput" type="number" min="0.01" step="0.01" placeholder="Custom qty" class="w-36 rounded-3xl border border-slate-200 bg-white px-4 py-2 text-slate-700 focus:border-slate-400 focus:ring-0">
                  <button type="button" id="applyCustomQty" class="rounded-full bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Set qty</button>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 mt-4">
                  <label class="block">
                    <span class="text-sm font-medium text-slate-700">Quantity to sell</span>
                    <input id="saleQuantity" type="number" min="0.25" step="0.25" value="0.25" placeholder="0.25" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
                  </label>
                  <label class="block">
                    <span class="text-sm font-medium text-slate-700">Sell as</span>
                    <select id="saleModeSelect" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
                      <option value="unit">Retail unit</option>
                      <option value="package">Whole package</option>
                    </select>
                    <p class="mt-2 text-xs text-slate-500">Choose retail units for loose sales. Quantities above 6 will automatically use wholesale unit pricing if available.</p>
                  </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 mt-4">
                  <div>
                    <span class="text-sm font-medium text-slate-700">Unit price</span>
                    <input id="saleUnitPriceDisplay" type="text" readonly class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-700 focus:border-slate-400 focus:ring-0" value="KES 0.00">
                    <p id="saleUnitLabel" class="mt-2 text-xs text-slate-500">Price per unit</p>
                    <input id="saleUnitPrice" type="hidden" value="0">
                  </div>
                  <div class="rounded-3xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
                    <p class="font-medium text-slate-700">Stock note</p>
                    <p id="saleStockNote" class="mt-2">0 packets available</p>
                  </div>
                </div>
                <div class="flex items-center justify-end gap-3 mt-4">
                  <button type="button" id="addSaleItem" class="rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">Add item</button>
                  <button type="button" id="wizardNextToReviewButton" class="rounded-2xl bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Next: review</button>
                </div>
            </div>
          </section>


          <section data-wizard-step="2" class="space-y-6 hidden">
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="text-sm font-medium text-slate-700">Sale items</p>
                  <p class="mt-2 text-slate-700">Review the items you added before taking payment.</p>
                </div>
                <p class="text-3xl font-semibold text-slate-900" id="saleGrandTotal">KES 0.00</p>
              </div>
              <div id="saleItemsList" class="mt-4 space-y-3"></div>
            </div>

            <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
              <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <p class="font-semibold">Ready to pay</p>
                  <p id="wizardSummaryItems" class="mt-1 text-sm">0 items ready for checkout</p>
                  <p id="wizardSummaryTotal" class="mt-1 text-sm">Total: KES 0.00</p>
                </div>
                <div class="rounded-2xl bg-white px-4 py-3 text-right shadow-sm">
                  <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-700">Balance due</p>
                  <p id="wizardSummaryBalance" class="mt-1 text-lg font-semibold text-emerald-900">KES 0.00</p>
                </div>
              </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Sale date</span>
                <input id="saleDateInput" name="sale_date" type="date" value="<?php echo htmlspecialchars((string) ($_POST['sale_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>" required class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
                <p class="mt-2 text-sm text-slate-500">Use an earlier date when recording a sale made before this system was introduced.</p>
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Payment method</span>
                <select id="paymentMethodSelect" name="payment_method" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
                  <option value="cash">Cash</option>
                  <option value="equity">Equity PayBill</option>
                  <option value="bank">Bank</option>
                  <option value="credit">Credit</option>
                </select>
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Amount paid</span>
                <input id="amountPaid" name="amount_paid" type="number" min="0" step="0.01" value="0" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
              </label>
              <label id="creditCustomerField" class="block hidden">
                <span class="text-sm font-medium text-slate-700">Customer phone or name</span>
                <input id="customerIdentifierInput" name="customer_identifier" type="text" placeholder="0712 345 678 or John Doe" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-slate-700 focus:border-slate-400 focus:ring-0">
                <p class="mt-2 text-sm text-slate-500">Use this to create or lookup the customer's credit account.</p>
              </label>
            </div>

            <div id="equityTransactionField" class="hidden">
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Equity transaction code</span>
                <input id="transactionCodeInput" name="transaction_code" type="hidden" value="<?php echo htmlspecialchars((string) ($_POST['transaction_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              </label>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5 text-slate-700">
              <p class="text-sm">Balance due: <span id="saleBalance" class="font-semibold text-slate-900">KES 0.00</span></p>
            </div>

            <div class="flex flex-wrap justify-between gap-3">
              <button type="button" id="wizardBackToQtyButton" class="rounded-3xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Back</button>
              <div class="flex items-center gap-3">
                <button type="button" id="wizardAddAnotherItemButton" class="rounded-3xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Add another item</button>
                <div class="flex flex-wrap justify-end gap-3">
                  <button type="submit" name="payment_action" value="confirm" class="rounded-3xl bg-emerald-600 px-8 py-4 text-sm font-semibold text-white transition hover:bg-emerald-700">Complete sale</button>
                </div>
              </div>
            </div>
          </section>
        </form>
      </aside>
    </div>
  </div>

  <div id="priceLookupModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="priceLookupTitle">
    <div class="max-h-[90vh] w-full max-w-2xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl">
      <div class="flex items-center justify-between gap-4 border-b border-slate-200 p-5">
        <div>
          <h2 id="priceLookupTitle" class="text-xl font-semibold text-slate-900">Product price lookup</h2>
          <p class="mt-1 text-sm text-slate-500">Search a product to view its current retail and wholesale prices.</p>
        </div>
        <button type="button" id="closePriceLookupButton" class="rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" aria-label="Close price lookup">Close</button>
      </div>
      <div class="p-5">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Product name</span>
          <input id="priceLookupInput" type="search" autocomplete="off" placeholder="Type brand or product name" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
        </label>
        <div id="priceLookupResults" class="mt-4 max-h-[55vh] space-y-3 overflow-y-auto" aria-live="polite"></div>
      </div>
    </div>
  </div>

  <div id="equityPaymentModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-[2rem] border border-slate-200 bg-white p-6 shadow-2xl">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Equity PayBill</p>
          <h2 class="mt-2 text-2xl font-semibold text-slate-900">Confirm payment</h2>
          <p class="mt-1 text-sm text-slate-500">Enter the transaction number from the Equity confirmation.</p>
        </div>
        <button type="button" id="closeEquityPaymentModal" class="rounded-full border border-slate-200 px-3 py-1 text-lg text-slate-600 hover:bg-slate-100">×</button>
      </div>
      <div class="mt-5 rounded-2xl border border-blue-100 bg-blue-50 p-4">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-700">Amount</p>
        <p id="equityPaymentAmount" class="mt-1 text-2xl font-bold text-blue-900">KES 0.00</p>
        <label class="mt-4 block">
          <span class="text-sm font-medium text-slate-700">Cash amount</span>
          <input id="equityCashAmountModal" name="cash_amount" form="salesForm" type="number" min="0" step="0.01" value="0" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-slate-700">
          <label class="mt-4 block">
            <span class="text-sm font-medium text-slate-700">Cash received</span>
            <input id="equityCashTenderedModal" type="number" min="0" step="0.01" value="0" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-slate-700">
          </label>
        </label>
        <label class="mt-4 block">
          <span class="text-sm font-medium text-slate-700">Equity amount</span>
          <input id="equityPaymentAmountInput" name="equity_amount" form="salesForm" type="number" min="0" step="0.01" value="0" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-slate-700">
        </label>
        <p id="equitySplitBalance" class="mt-3 text-sm text-slate-600">Cash + Equity must equal the sale total.</p>
      </div>
      <label class="mt-5 block">
        <span class="text-sm font-medium text-slate-700">Transaction number</span>
        <input id="equityTransactionCodeModal" type="text" autocomplete="off" placeholder="QJK123456" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-slate-700 focus:border-slate-400 focus:ring-0">
      </label>
      <div class="mt-5 flex justify-end gap-3">
        <button type="button" id="cancelEquityPaymentModal" class="rounded-3xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
        <button type="button" id="confirmEquityPaymentModal" class="rounded-3xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700">Save payment</button>
      </div>
    </div>
  </div>

  <script>
    (function() {
      window.BARCODE_MAP = [];

    const receiptAutoPrint = <?php echo json_encode($autoPrintReceipt ?? false); ?>;
    const receiptPath = <?php echo json_encode($_SESSION['last_receipt_file'] ?? ''); ?>;

    if (receiptAutoPrint && receiptPath) {
      // Redirect directly to the receipt preview so the user sees it immediately.
      window.location.href = 'receipt_view.php?path=' + encodeURIComponent(receiptPath);
    }
  })();

    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mobileMenuClose = document.getElementById('mobileMenuClose');
    const mobileMenu = document.getElementById('mobileMenu');
    const barcodeInputElement = document.getElementById('barcodeInput');

    function focusBarcodeField() {
      if (!barcodeInputElement) {
        return;
      }
      barcodeInputElement.focus({ preventScroll: true });
      barcodeInputElement.select();
    }

    if (barcodeScanTrigger) {
      barcodeScanTrigger.addEventListener('click', () => {
        focusBarcodeField();
      });
    }

    if (barcodeInputElement) {
      barcodeInputElement.addEventListener('focus', () => {
        barcodeInputElement.setSelectionRange(0, barcodeInputElement.value.length);
      });
    }

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
  <script src="node_modules/quagga/dist/quagga.min.js"></script>
  <script src="assets/js/sales.js?v=<?php echo file_exists('assets/js/sales.js') ? filemtime('assets/js/sales.js') : time(); ?>"></script>
  <script>
    const quickPurchaseModal = document.getElementById('quickPurchaseModal');
    const expenseRequestModal = document.getElementById('expenseRequestModal');
    const openQuickPurchaseModalButtons = [
      document.getElementById('openQuickPurchaseModalBtn'),
      document.getElementById('mobileOpenQuickPurchaseModalBtn')
    ].filter(Boolean);
    const closeQuickPurchaseModalButtons = [
      document.getElementById('closeQuickPurchaseModalBtn')
    ].filter(Boolean);
    const openExpenseModalButtons = [
      document.getElementById('openExpenseModalBtn'),
      document.getElementById('mobileOpenExpenseModalBtn'),
      document.getElementById('closeExpenseModalBtn'),
      document.getElementById('cancelExpenseModalBtn')
    ].filter(Boolean);

    function toggleQuickPurchaseModal(forceOpen) {
      if (!quickPurchaseModal) {
        return;
      }
      const shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : quickPurchaseModal.classList.contains('hidden');
      quickPurchaseModal.classList.toggle('hidden', !shouldOpen);
      quickPurchaseModal.classList.toggle('flex', shouldOpen);
    }

    openQuickPurchaseModalButtons.forEach((button) => {
      button.addEventListener('click', () => {
        toggleQuickPurchaseModal(true);
      });
    });

    closeQuickPurchaseModalButtons.forEach((button) => {
      button.addEventListener('click', () => {
        toggleQuickPurchaseModal(false);
      });
    });

    if (quickPurchaseModal) {
      quickPurchaseModal.addEventListener('click', (event) => {
        if (event.target === quickPurchaseModal) {
          toggleQuickPurchaseModal(false);
        }
      });
    }

    function initializeQuickPurchaseRow(row) {
      const productInput = row.querySelector('.product-search-input');
      const productIdInput = row.querySelector('.product-id-input');
      const suggestionBox = row.querySelector('.product-suggestions');

      if (!productInput || !productIdInput || !suggestionBox) {
        return;
      }

      function hideSuggestions() {
        suggestionBox.classList.add('hidden');
        suggestionBox.innerHTML = '';
      }

      function showSuggestions(items) {
        suggestionBox.innerHTML = items.map((item) => `
          <button type="button" class="product-suggestion-item w-full text-left px-4 py-3 text-sm text-slate-700 hover:bg-slate-100" data-product-id="${item.id}" data-product-name="${item.name}">${item.name}</button>
        `).join('');
        suggestionBox.classList.remove('hidden');
      }

      let lookupTimer = null;

      async function resolveExactProductMatch() {
        const query = productInput.value.trim();
        if (query === '') {
          productIdInput.value = '0';
          return;
        }
        try {
          const response = await fetch(`product_lookup.php?q=${encodeURIComponent(query)}&include_all=1&limit=8`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
          const data = await response.json();
          const matches = Array.isArray(data.products) ? data.products : [];
          const match = matches.find((product) => `${product.brand || ''} ${product.name || ''}`.trim().toLowerCase() === query.toLowerCase());
          if (match) productIdInput.value = String(match.id);
        }
        catch (error) { /* Keep zero so the server can create a new product when allowed. */ }
      }

      productInput.addEventListener('input', () => {
        const query = productInput.value.trim().toLowerCase();
        productIdInput.value = '0';

        if (query === '') {
          hideSuggestions();
          return;
        }

        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(async () => {
          try {
            const response = await fetch(`product_lookup.php?q=${encodeURIComponent(query)}&include_all=1&limit=8`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();
            const matches = (Array.isArray(data.products) ? data.products : []).map((product) => ({
              id: product.id,
              name: `${product.brand || ''} ${product.name || ''}`.trim(),
            }));
            if (matches.length === 0) hideSuggestions(); else showSuggestions(matches);
          } catch (error) { hideSuggestions(); }
        }, 180);
      });

      suggestionBox.addEventListener('click', (event) => {
        const button = event.target.closest('.product-suggestion-item');
        if (!button) {
          return;
        }
        const selectedId = button.getAttribute('data-product-id');
        const selectedName = button.getAttribute('data-product-name');
        productInput.value = selectedName;
        productIdInput.value = selectedId;
        hideSuggestions();
      });

      productInput.addEventListener('blur', () => {
        resolveExactProductMatch();
        setTimeout(hideSuggestions, 150);
      });

      productInput.addEventListener('focus', () => {
        if (productInput.value.trim() !== '') {
          productInput.dispatchEvent(new Event('input'));
        }
      });
    }

    const addQuickPurchaseLineBtn = document.getElementById('addQuickPurchaseLineBtn');
    const quickPurchaseLinesContainer = document.getElementById('quickPurchaseLines');
    if (addQuickPurchaseLineBtn && quickPurchaseLinesContainer) {
      addQuickPurchaseLineBtn.addEventListener('click', () => {
        const newRow = document.createElement('div');
        newRow.className = 'grid gap-3 md:grid-cols-6';
        newRow.innerHTML = `
          <div class="md:col-span-2 relative">
            <label class="mb-1 block text-sm font-medium text-slate-700">Product</label>
            <input type="text" name="quick_purchase_product_name[]" class="product-search-input w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="Type product name" autocomplete="off" required>
            <input type="hidden" name="quick_purchase_product_id[]" class="product-id-input" value="0">
            <div class="product-suggestions hidden absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-3xl border border-slate-200 bg-white shadow-lg"></div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Package qty</label>
            <input type="number" min="0.01" step="0.01" name="quick_purchase_package_qty[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="0.00" required>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Package size</label>
            <input type="number" min="1" step="1" name="quick_purchase_package_size_value[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="24" required>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Cost per package</label>
            <input type="number" min="0.01" step="0.01" name="quick_purchase_cost_per_package[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="0.00" required>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Retail price per unit</label>
            <input type="number" min="0.01" step="0.01" name="quick_purchase_retail_price[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="0.00" required>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Expiry date</label>
            <input type="date" name="quick_purchase_expiry_date[]" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0" placeholder="YYYY-MM-DD">
          </div>`;
        quickPurchaseLinesContainer.appendChild(newRow);
        initializeQuickPurchaseRow(newRow);
      });
    }

    document.querySelectorAll('#quickPurchaseLines .grid').forEach(initializeQuickPurchaseRow);

    function toggleExpenseModal(forceOpen) {
      if (!expenseRequestModal) {
        return;
      }
      const shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : expenseRequestModal.classList.contains('hidden');
      expenseRequestModal.classList.toggle('hidden', !shouldOpen);
      expenseRequestModal.classList.toggle('flex', shouldOpen);
    }

    openExpenseModalButtons.forEach((button) => {
      button.addEventListener('click', (event) => {
        if (button.id === 'closeExpenseModalBtn' || button.id === 'cancelExpenseModalBtn') {
          toggleExpenseModal(false);
        } else {
          toggleExpenseModal(true);
        }
      });
    });

    if (expenseRequestModal) {
      expenseRequestModal.addEventListener('click', (event) => {
        if (event.target === expenseRequestModal) {
          toggleExpenseModal(false);
        }
      });
    }

    window.addEventListener('load', function() {
      const splashScreen = document.getElementById('splashScreen');
      if (splashScreen) {
        splashScreen.classList.add('hidden');
        document.body.classList.remove('splash-loading');
      }
    });
  </script>
  <script src="assets/js/cashier-session.js"></script>
</body>
</html>
