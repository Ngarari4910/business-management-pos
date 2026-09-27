<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2 * 1024 * 1024) {
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Request payload is too large.']);
    exit;
  }
    $postInput = file_get_contents('php://input');
    if ($postInput !== false && trim($postInput) !== '') {
        $payload = json_decode($postInput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
            if (!empty($payload['product_edit'])) {
                $editPayload = $payload['product_edit'];
                $productId = isset($editPayload['id']) ? (int) $editPayload['id'] : 0;

                if ($productId > 0) {
                    $pdo->beginTransaction();
                    try {
                        $brand = trim((string) ($editPayload['brand'] ?? ''));
                        $name = trim((string) ($editPayload['name'] ?? ''));
                        $category = trim((string) ($editPayload['category'] ?? ''));
                        $baseUnit = trim((string) ($editPayload['base_unit'] ?? ''));
                        $price = isset($editPayload['price']) ? (float) $editPayload['price'] : 0.0;
                        $retailPrice = isset($editPayload['retail_price']) ? (float) $editPayload['retail_price'] : 0.0;
                        $wholesalePrice = isset($editPayload['wholesale_price']) ? (float) $editPayload['wholesale_price'] : 0.0;
                        $packetsPerBale = isset($editPayload['packets_per_bale']) ? max(1, (int) $editPayload['packets_per_bale']) : 24;
                        $expiryDate = trim((string) ($editPayload['expiry_date'] ?? ''));

                        $stmt = $pdo->prepare(
                            'UPDATE products SET '
                            . 'brand = :brand, '
                            . 'name = :name, '
                            . 'category = :category, '
                            . 'base_unit = :base_unit, '
                            . 'price = :price, '
                            . 'retail_price = :retail_price, '
                            . 'wholesale_price = :wholesale_price, '
                            . 'packets_per_bale = :packets_per_bale, '
                            . 'expiry_date = :expiry_date '
                            . 'WHERE id = :id'
                        );
                        $stmt->execute([
                            'brand' => $brand,
                            'name' => $name,
                            'category' => $category,
                            'base_unit' => $baseUnit,
                            'price' => number_format($price, 2, '.', ''),
                            'retail_price' => number_format($retailPrice, 2, '.', ''),
                            'wholesale_price' => number_format($wholesalePrice, 2, '.', ''),
                            'packets_per_bale' => $packetsPerBale,
                            'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                            'id' => $productId,
                        ]);

                        $pdo->commit();
                        echo json_encode(['success' => true, 'message' => 'Product details updated.']);
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Unable to update product: ' . $e->getMessage()]);
                    }
                    exit;
                }
            }

            if (!empty($payload['manual_counts'])) {
                $manualCounts = $payload['manual_counts'];
                $ok = true;
                $pdo->beginTransaction();
                try {
                    foreach ($manualCounts as $row) {
                        $productId = isset($row['product_id']) ? (int)$row['product_id'] : 0;
                        $systemQty = isset($row['system_qty']) ? (float)$row['system_qty'] : 0.0;
                        $manualQty = isset($row['manual_qty']) ? (float)$row['manual_qty'] : 0.0;
                        $difference = $manualQty - $systemQty;

                        if ($productId > 0 && abs($difference) > 0.000001) {
                            $updateStmt = $pdo->prepare('UPDATE products SET stock = :stock WHERE id = :id');
                            $updateStmt->execute([
                                'stock' => $manualQty,
                                'id' => $productId,
                            ]);

                            $movementStmt = $pdo->prepare('INSERT INTO stock_movements (product_id, change_quantity, reason) VALUES (:product_id, :change_quantity, :reason)');
                            $movementStmt->execute([
                                'product_id' => $productId,
                                'change_quantity' => $difference,
                                'reason' => 'Manual stock count adjustment',
                            ]);
                        }
                    }

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Inventory adjustment saved.']);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Unable to save adjustments: ' . $e->getMessage()]);
                }
                exit;
            }

            if (!empty($payload['reconcile_historical_sales'])) {
                $pdo->beginTransaction();
                try {
                    $saleLedgerStmt = $pdo->prepare(
                        'SELECT si.product_id, si.sale_id, si.quantity_in_packets, si.product_name '
                        . 'FROM sale_items si '
                        . 'JOIN sales s ON s.id = si.sale_id '
                        . 'WHERE s.payment_status IN (\'paid\', \'credit\', \'completed\', \'success\') '
                        . 'ORDER BY si.sale_id, si.product_id LIMIT 500'
                    );
                    $saleLedgerStmt->execute();
                    $saleLines = $saleLedgerStmt->fetchAll(PDO::FETCH_ASSOC);

                    $reconciledCount = 0;
                    foreach ($saleLines as $saleLine) {
                        $productId = (int) $saleLine['product_id'];
                        $saleId = (int) $saleLine['sale_id'];
                        $qty = (float) $saleLine['quantity_in_packets'];
                        $productName = trim((string) ($saleLine['product_name'] ?? ''));
                        $reason = 'Historical sale reconciliation sale #' . $saleId;

                        if ($productId <= 0 || $qty <= 0.000001) {
                            continue;
                        }

                        $historicalReason = 'Historical sale reconciliation sale #' . $saleId;
                        $movementExistsStmt = $pdo->prepare(
                            'SELECT COUNT(*) FROM stock_movements '
                            . 'WHERE product_id = :product_id '
                            . 'AND (reason LIKE CONCAT(\'Completed sale #\', :sale_id, \'%\') OR reason = :historical_reason)'
                        );
                        $movementExistsStmt->execute([
                            'product_id' => $productId,
                            'sale_id' => (string) $saleId,
                            'historical_reason' => $historicalReason,
                        ]);
                        $movementExists = (int) $movementExistsStmt->fetchColumn();

                        if ($movementExists === 0) {
                            $updateProductStmt = $pdo->prepare('UPDATE products SET stock = stock - :stock_change WHERE id = :id');
                            $updateProductStmt->execute([
                                'stock_change' => $qty,
                                'id' => $productId,
                            ]);

                            $movementStmt = $pdo->prepare('INSERT INTO stock_movements (product_id, change_quantity, reason) VALUES (:product_id, :change_quantity, :reason)');
                            $movementStmt->execute([
                                'product_id' => $productId,
                                'change_quantity' => -$qty,
                                'reason' => $historicalReason,
                            ]);

                            $reconciledCount++;
                        }
                    }

                    $pdo->commit();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Historical stock reconciliation completed. ' . $reconciledCount . ' movements backfilled.'
                    ]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Unable to reconcile historical stock: ' . $e->getMessage(),
                    ]);
                }
                exit;
            }
        }
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$searchTerm = $search !== '' ? '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%' : null;
$pageSize = 10;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

$filterWhere = '1=1';
$params = [];
if ($searchTerm !== null) {
  $filterWhere = '(CONCAT(IFNULL(brand, ""), " ", IFNULL(name, "")) LIKE :search_name OR category LIKE :search_category OR base_unit LIKE :search_unit OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id = products.id AND pb.barcode LIKE :search_barcode))';
  $params = [
    'search_name' => $searchTerm,
    'search_category' => $searchTerm,
    'search_unit' => $searchTerm,
    'search_barcode' => $searchTerm,
  ];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE {$filterWhere}");
$countStmt->execute($params);
$totalMatchingProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalMatchingProducts / $pageSize));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $pageSize;

$summaryStmt = $pdo->prepare("SELECT
  COUNT(*) AS total_skus,
  COALESCE(SUM(stock), 0) AS total_quantity,
  COALESCE(SUM(stock * price), 0) AS total_value,
  SUM(stock > 0 AND stock <= 5) AS low_stock_count,
  SUM(stock <= 0) AS out_of_stock_count,
  SUM(expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS expiring_soon_count,
  SUM(expiry_date IS NOT NULL AND expiry_date < CURDATE()) AS expired_count
  FROM products WHERE {$filterWhere}");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$productQuery = "SELECT id, brand, name, category, base_unit, stock, price, retail_price, wholesale_price, packets_per_bale, expiry_date, created_at FROM products WHERE {$filterWhere} ORDER BY brand ASC, name ASC LIMIT {$pageSize} OFFSET {$offset}";
$productStmt = $pdo->prepare($productQuery);
$productStmt->execute($params);
$products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

$printProductsStmt = $pdo->prepare("SELECT id, brand, name, category, base_unit, stock, price, retail_price, wholesale_price, expiry_date FROM products WHERE {$filterWhere} ORDER BY brand ASC, name ASC");
$printProductsStmt->execute($params);
$printProducts = $printProductsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalSkus = (int) ($summary['total_skus'] ?? 0);
$totalQuantity = (float) ($summary['total_quantity'] ?? 0);
$totalValue = (float) ($summary['total_value'] ?? 0);
$lowStockCount = (int) ($summary['low_stock_count'] ?? 0);
$outOfStockCount = (int) ($summary['out_of_stock_count'] ?? 0);
$expiringSoonCount = (int) ($summary['expiring_soon_count'] ?? 0);
$expiredCount = (int) ($summary['expired_count'] ?? 0);

function formatCurrency($amount): string {
    return 'KES ' . number_format(floatval($amount), 2);
}

function expiryStatusLabel(array $product): string {
    $expiryDate = trim((string)($product['expiry_date'] ?? ''));
    if ($expiryDate === '') {
        return '<span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">No expiry</span>';
    }

    $expiryDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $expiryDate);
    if ($expiryDateObj === false) {
        return '<span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Invalid date</span>';
    }

    $today = new DateTimeImmutable('today');
    if ($expiryDateObj < $today) {
        return '<span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700">Expired</span>';
    }
    if ($expiryDateObj <= $today->modify('+7 days')) {
        return '<span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">Expiring soon</span>';
    }

    return '<span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Good</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventory — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --brand-blue: #1683ff;
      --brand-red: #7f1d1d;
    }

    .inventory-print-report {
      display: none;
    }

    @media print {
      @page {
        size: auto;
        margin: 12mm;
      }

      body {
        background: #fff !important;
      }

      body > *:not(.inventory-print-report) {
        display: none !important;
      }

      .inventory-print-report {
        display: block !important;
        color: #14213d;
      }

      .inventory-print-report table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
      }

      .inventory-print-report th {
        background: #123b78 !important;
        color: #fff !important;
        text-align: left;
      }

      .inventory-print-report th,
      .inventory-print-report td {
        border: 1px solid #d8e3f2;
        padding: 7px;
      }

      .inventory-print-report tr:nth-child(even) {
        background: #f6f9fe !important;
      }

      .inventory-print-report tr.inventory-out-of-stock {
        background: #fff0f0 !important;
        color: #991b1b !important;
      }

      .inventory-print-report tr.inventory-expired {
        background: #fff7ed !important;
        color: #9a3412 !important;
      }

      .inventory-print-report tr.inventory-out-of-stock.inventory-expired {
        background: #fee2e2 !important;
        color: #991b1b !important;
      }
    }
  </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <div class="admin-page-shell mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Admin panel</p>
        <h1 class="mt-2 text-3xl font-semibold text-slate-900">Inventory management</h1>
        <p class="mt-1 text-sm text-slate-500">Review and monitor all shelf and stock inventory from the admin section.</p>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="admin.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">Dashboard</a>
          <a href="admin_growth.php" class="rounded-3xl bg-slate-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Growth</a>
          <a href="admin_inventory.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Inventory</a>
          <a href="admin_debts.php" class="rounded-3xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700">Debt monitor</a>
          <a href="purchases_suppliers.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Purchases</a>
          <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-600">Expenses</a>
          <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">Cashiers</a>
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
        <a href="admin_growth.php" class="rounded-3xl bg-slate-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Growth</a>
        <a href="admin_inventory.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Inventory</a>
        <a href="admin_debts.php" class="rounded-3xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700">Debt monitor</a>
        <a href="purchases_suppliers.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Purchases</a>
        <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-600">Expenses</a>
        <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Cashiers</a>
        <a href="logout.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 transition hover:bg-slate-100">Logout</a>
      </div>
    </div>

    <div class="mb-6 grid gap-4 xl:grid-cols-4">
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Total SKUs</p>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?php echo number_format($totalSkus); ?></p>
      </div>
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Quantity on hand</p>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?php echo number_format($totalQuantity, 2); ?></p>
      </div>
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Inventory value</p>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?php echo formatCurrency($totalValue); ?></p>
      </div>
      <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Low / out of stock</p>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?php echo number_format($lowStockCount + $outOfStockCount); ?></p>
        <p class="mt-1 text-sm text-slate-500"><?php echo number_format($outOfStockCount); ?> out of stock</p>
      </div>
    </div>

    <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-900">Product inventory</h2>
          <p class="mt-1 text-sm text-slate-500">Search for a product below. Only 10 products are shown at a time.</p>
        </div>
        <div class="flex w-full items-end gap-3 md:w-auto">
          <form method="get" class="flex w-full items-end gap-3 md:w-auto">
            <label class="flex-1">
              <span class="sr-only">Search inventory</span>
              <input
                type="search"
                name="search"
                value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Search brand, name, category, barcode"
                class="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200"
              />
            </label>
            <button type="submit" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Find products</button>
          </form>
          <div class="flex flex-wrap gap-3">
            <button id="printInventoryReportButton" type="button" class="rounded-3xl bg-[#1683ff] px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-600">Print inventory report</button>
            <button id="reconcileHistoricalSalesButton" type="button" title="Backfill missing stock movements from completed sales" class="rounded-3xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">Fix sales stock history</button>
          </div>
        </div>
      </div>

      <div class="mt-6 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
              <th class="px-4 py-3 font-semibold">SKU</th>
              <th class="px-4 py-3 font-semibold">Product</th>
              <th class="px-4 py-3 font-semibold">Category</th>
              <th class="px-4 py-3 font-semibold">Unit</th>
              <th class="px-4 py-3 font-semibold">Qty</th>
              <th class="px-4 py-3 font-semibold">Price</th>
              <th class="px-4 py-3 font-semibold">Value</th>
              <th class="px-4 py-3 font-semibold">Expiry</th>
              <th class="px-4 py-3 font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 bg-white text-slate-700">
            <?php if (count($products) === 0): ?>
              <tr>
                <td colspan="9" class="px-4 py-6 text-center text-sm text-slate-500">No products found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($products as $product): ?>
                <?php $stockQty = floatval($product['stock'] ?? 0); ?>
                <?php $rowClass = $stockQty <= 0 ? 'bg-rose-50' : ($stockQty <= 5 ? 'bg-amber-50' : ''); ?>
                <tr class="<?php echo $rowClass; ?>">
                  <td class="px-4 py-4 font-medium text-slate-900"><?php echo htmlspecialchars($product['id']); ?></td>
                  <td class="px-4 py-4">
                    <div class="font-semibold text-slate-900"><?php echo htmlspecialchars(trim((string)$product['brand'] . ' ' . $product['name'])); ?></div>
                    <div class="mt-1 text-xs text-slate-500">Created <?php echo htmlspecialchars(date('Y-m-d', strtotime($product['created_at'] ?? 'now'))); ?></div>
                  </td>
                  <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($product['category'] ?? '—'); ?></td>
                  <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($product['base_unit'] ?? '—'); ?></td>
                  <td class="px-4 py-4 text-slate-900"><?php echo number_format($stockQty, 2); ?></td>
                  <td class="px-4 py-4 text-slate-900"><?php echo formatCurrency($product['price'] ?? 0); ?></td>
                  <td class="px-4 py-4 text-slate-900"><?php echo formatCurrency($stockQty * floatval($product['price'] ?? 0)); ?></td>
                  <td class="px-4 py-4"><?php echo expiryStatusLabel($product); ?></td>
                  <td class="px-4 py-4">
                    <div class="flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        class="edit-product-button inline-flex rounded-3xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                        data-product-id="<?php echo htmlspecialchars($product['id']); ?>"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        class="manual-count-button inline-flex rounded-3xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white transition hover:bg-slate-800"
                        data-product-id="<?php echo htmlspecialchars($product['id']); ?>"
                      >
                        Manual count
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
      $firstShown = $totalMatchingProducts > 0 ? $offset + 1 : 0;
      $lastShown = min($offset + $pageSize, $totalMatchingProducts);
      $previousPageUrl = '?search=' . urlencode($search) . '&page=' . max(1, $currentPage - 1);
      $nextPageUrl = '?search=' . urlencode($search) . '&page=' . min($totalPages, $currentPage + 1);
      ?>
      <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-500">
          Showing <span class="font-semibold text-slate-700"><?php echo $firstShown; ?>-<?php echo $lastShown; ?></span>
          of <span class="font-semibold text-slate-700"><?php echo $totalMatchingProducts; ?></span> products
          <span class="text-slate-400">(Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>)</span>
        </p>
        <div class="flex gap-2">
          <a href="<?php echo htmlspecialchars($previousPageUrl); ?>" class="rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold <?php echo $currentPage <= 1 ? 'pointer-events-none opacity-40' : 'text-slate-700 hover:bg-slate-50'; ?>" aria-disabled="<?php echo $currentPage <= 1 ? 'true' : 'false'; ?>">Previous</a>
          <a href="<?php echo htmlspecialchars($nextPageUrl); ?>" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white <?php echo $currentPage >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-slate-800'; ?>" aria-disabled="<?php echo $currentPage >= $totalPages ? 'true' : 'false'; ?>">Next</a>
        </div>
      </div>
    </div>

    <div id="editProductModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 px-4 py-6">
      <div class="w-full max-w-3xl overflow-hidden rounded-[2rem] bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <div>
            <h2 class="text-xl font-semibold text-slate-900">Edit product</h2>
            <p class="mt-1 text-sm text-slate-500">Update the product master details.</p>
          </div>
          <button id="closeEditProductModal" type="button" class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Close</button>
        </div>
        <div class="px-6 py-6">
          <form id="editProductForm">
            <input type="hidden" id="editProductId" />
            <div class="grid gap-4 md:grid-cols-2">
              <label class="block">
                <span class="text-sm font-medium text-slate-600">Brand</span>
                <input id="editProductBrand" type="text" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" required />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-600">Product name</span>
                <input id="editProductName" type="text" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" required />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-600">Category</span>
                <input id="editProductCategory" type="text" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-600">Unit</span>
                <input id="editProductBaseUnit" type="text" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-600">Price</span>
                <input id="editProductPrice" type="number" min="0" step="0.01" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" required />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-600">Retail price</span>
                <input id="editProductRetailPrice" type="number" min="0" step="0.01" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" required />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-600">Wholesale price</span>
                <input id="editProductWholesalePrice" type="number" min="0" step="0.01" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" required />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-600">Packets per bale</span>
                <input id="editProductPacketsPerBale" type="number" min="1" step="1" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" required />
              </label>
              <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-600">Expiry date</span>
                <input id="editProductExpiryDate" type="date" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" />
              </label>
            </div>
            <div class="mt-6 flex items-center justify-end gap-3">
              <button id="cancelEditProduct" type="button" class="rounded-3xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Cancel</button>
              <button id="saveProductEdit" type="submit" class="rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Save changes</button>
            </div>
            <div id="editProductStatus" class="mt-4 text-sm text-slate-500">Ready.</div>
          </form>
        </div>
      </div>
    </div>

    <div id="manualCountModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 px-4 py-6">
      <div class="w-full max-w-5xl overflow-hidden rounded-[2rem] bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <div>
            <h2 class="text-xl font-semibold text-slate-900">Manual stock counting</h2>
            <p class="mt-1 text-sm text-slate-500">Count packets by hand, enter the manual quantity, and compare with the system quantity.</p>
          </div>
          <button id="closeManualCountModal" type="button" class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Close</button>
        </div>
        <div class="px-6 py-6">
          <div class="grid gap-4 lg:grid-cols-3">
            <label class="block">
              <span class="text-sm font-medium text-slate-600">Product</span>
              <select id="manualCountProduct" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200">
                <option value="">Select a product</option>
                <?php foreach ($products as $product): ?>
                  <option value="<?php echo htmlspecialchars($product['id']); ?>"><?php echo htmlspecialchars(trim((string)$product['brand'] . ' ' . $product['name'])); ?> (SKU <?php echo htmlspecialchars($product['id']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-600">System quantity</span>
              <input id="manualSystemQty" type="text" readonly class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm text-slate-700" />
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-600">Manual count</span>
              <input id="manualCountQty" type="number" min="0" step="1" placeholder="Enter counted packets" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200" />
            </label>
          </div>

          <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="rounded-3xl bg-slate-50 p-4">
              <p class="text-sm text-slate-500">Difference</p>
              <p id="manualCountDifference" class="mt-2 text-2xl font-semibold text-slate-900">—</p>
            </div>
            <button id="manualCountAdd" type="button" class="inline-flex items-center justify-center rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Add count</button>
          </div>

          <div class="mt-6">
            <h3 class="text-lg font-semibold text-slate-900">Count worksheet</h3>
            <p class="mt-1 text-sm text-slate-500">Use this list to verify multiple manual counts before updating the system.</p>

            <div class="mt-4 overflow-x-auto">
              <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                  <tr>
                    <th class="px-4 py-3 font-semibold">Product</th>
                    <th class="px-4 py-3 font-semibold">System qty</th>
                    <th class="px-4 py-3 font-semibold">Manual qty</th>
                    <th class="px-4 py-3 font-semibold">Difference</th>
                    <th class="px-4 py-3 font-semibold">Actions</th>
                  </tr>
                </thead>
                <tbody id="manualCountTableBody" class="divide-y divide-slate-200 bg-white text-slate-700">
                  <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">No manual counts yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="mt-6 flex items-center justify-between gap-4">
              <div id="manualCountStatus" class="text-sm text-slate-500">Worksheet is ready.</div>
              <div class="flex items-center gap-3">
                <button id="manualCountReset" type="button" class="rounded-3xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Clear worksheet</button>
                <button id="manualCountApply" type="button" class="rounded-3xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Apply differences</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="successToast" class="fixed bottom-6 right-6 z-[100] hidden">
    <div class="flex items-center gap-3 rounded-3xl border border-emerald-200 bg-white px-5 py-4 shadow-2xl">
      <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 1" />
        </svg>
      </span>
      <div>
        <div class="text-sm font-bold text-slate-900">Success</div>
        <div id="successToastMessage" class="text-xs font-medium text-slate-500">Changes saved.</div>
      </div>
    </div>
  </div>

  <section class="inventory-print-report">
    <div style="border-bottom: 3px solid #7f1d1d; padding-bottom: 12px; margin-bottom: 18px;">
      <div style="display: flex; align-items: center; justify-content: space-between; gap: 18px;">
        <img src="colour_logo.jpg" alt="Smart POS Demo" style="width: 88px; height: 88px; object-fit: cover; border-radius: 14px; border: 1px solid #d8e3f2;">
        <div style="text-align: right;">
          <div style="color: #7f1d1d; font-size: 22px; font-weight: 700; letter-spacing: 0.04em;">Smart POS Demo</div>
          <div style="color: #1683ff; font-size: 13px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase;">Inventory Report</div>
          <div style="color: #64748b; font-size: 11px; margin-top: 4px;">Products currently in stock</div>
        </div>
      </div>
    </div>
    <table>
      <thead>
        <tr>
          <th>SKU</th>
          <th>Product</th>
          <th>Unit</th>
          <th>Quantity</th>
          <th>Retail price</th>
          <th>Wholesale price</th>
          <th>Stock value</th>
          <th>Expiry</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($printProducts as $product): ?>
          <?php $printStock = (float) ($product['stock'] ?? 0); ?>
          <?php $printExpiry = trim((string) ($product['expiry_date'] ?? '')); ?>
          <?php $printExpiryDate = $printExpiry !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $printExpiry) : false; ?>
          <?php $printIsExpired = $printExpiryDate !== false && $printExpiryDate < new DateTimeImmutable('today'); ?>
          <?php $printRowClass = $printStock <= 0 ? 'inventory-out-of-stock' : ($printIsExpired ? 'inventory-expired' : ''); ?>
          <tr class="<?php echo htmlspecialchars($printRowClass); ?>">
            <td><?php echo htmlspecialchars((string) $product['id']); ?></td>
            <td><?php echo htmlspecialchars(trim((string) $product['brand'] . ' ' . $product['name'])); ?></td>
            <td><?php echo htmlspecialchars((string) ($product['base_unit'] ?? '—')); ?></td>
            <td><?php echo number_format($printStock, 2); ?></td>
            <td><?php echo htmlspecialchars(formatCurrency($product['retail_price'] ?? 0)); ?></td>
            <td><?php echo htmlspecialchars(formatCurrency($product['wholesale_price'] ?? 0)); ?></td>
            <td><?php echo htmlspecialchars(formatCurrency($printStock * (float) ($product['price'] ?? 0))); ?></td>
            <td><?php echo htmlspecialchars($printExpiry !== '' ? $printExpiry : 'No expiry'); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($printProducts === []): ?>
      <p style="margin-top: 18px; color: #64748b;">No products currently have stock.</p>
    <?php endif; ?>
  </section>

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

    const products = {
      <?php foreach ($products as $product): ?>
        '<?php echo htmlspecialchars($product['id']); ?>': {
          id: <?php echo json_encode(intval($product['id'] ?? 0)); ?>,
          brand: '<?php echo htmlspecialchars(addslashes((string)($product['brand'] ?? ''))); ?>',
          name: '<?php echo htmlspecialchars(addslashes((string)($product['name'] ?? ''))); ?>',
          category: '<?php echo htmlspecialchars(addslashes((string)($product['category'] ?? ''))); ?>',
          base_unit: '<?php echo htmlspecialchars(addslashes((string)($product['base_unit'] ?? ''))); ?>',
          stock: <?php echo json_encode(floatval($product['stock'] ?? 0)); ?>,
          price: <?php echo json_encode(floatval($product['price'] ?? 0)); ?>,
          retail_price: <?php echo json_encode(floatval($product['retail_price'] ?? 0)); ?>,
          wholesale_price: <?php echo json_encode(floatval($product['wholesale_price'] ?? 0)); ?>,
          packets_per_bale: <?php echo json_encode(intval($product['packets_per_bale'] ?? 24)); ?>,
          expiry_date: '<?php echo htmlspecialchars(addslashes((string)($product['expiry_date'] ?? ''))); ?>'
        },
      <?php endforeach; ?>
    };

    const toast = document.getElementById('successToast');
    const toastMessage = document.getElementById('successToastMessage');

    function showToast(message = 'Changes saved.') {
      if (!toast || !toastMessage) return;
      toastMessage.textContent = message;
      toast.classList.remove('hidden');
      toast.classList.add('block');

      window.clearTimeout(window.__inventoryToastTimer);
      window.__inventoryToastTimer = window.setTimeout(() => {
        toast.classList.add('hidden');
        toast.classList.remove('block');
      }, 3200);
    }

    const pendingToastText = sessionStorage.getItem('admin_inventory_pending_toast');
    if (pendingToastText) {
      showToast(pendingToastText);
      sessionStorage.removeItem('admin_inventory_pending_toast');
    }

    const editProductModal = document.getElementById('editProductModal');
    const editProductForm = document.getElementById('editProductForm');
    const closeEditProductModal = document.getElementById('closeEditProductModal');
    const cancelEditProduct = document.getElementById('cancelEditProduct');
    const editProductStatus = document.getElementById('editProductStatus');
    const editProductButtons = document.querySelectorAll('.edit-product-button');

    function openEditProductModal(productId) {
      const product = products[productId];
      if (!product) return;

      document.getElementById('editProductId').value = product.id;
      document.getElementById('editProductBrand').value = product.brand || '';
      document.getElementById('editProductName').value = product.name || '';
      document.getElementById('editProductCategory').value = product.category || '';
      document.getElementById('editProductBaseUnit').value = product.base_unit || '';
      document.getElementById('editProductPrice').value = Number(product.price || 0);
      document.getElementById('editProductRetailPrice').value = Number(product.retail_price || 0);
      document.getElementById('editProductWholesalePrice').value = Number(product.wholesale_price || 0);
      document.getElementById('editProductPacketsPerBale').value = Number(product.packets_per_bale || 24);
      document.getElementById('editProductExpiryDate').value = product.expiry_date || '';

      editProductModal.classList.remove('hidden');
      editProductModal.classList.add('flex');
      editProductStatus.textContent = 'Ready.';
    }

    function closeEditProductModalDialog() {
      editProductModal.classList.remove('flex');
      editProductModal.classList.add('hidden');
    }

    editProductButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const productId = button.getAttribute('data-product-id');
        openEditProductModal(productId);
      });
    });

    if (closeEditProductModal) {
      closeEditProductModal.addEventListener('click', closeEditProductModalDialog);
    }

    if (cancelEditProduct) {
      cancelEditProduct.addEventListener('click', closeEditProductModalDialog);
    }

    if (editProductForm) {
      editProductForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const productId = Number(document.getElementById('editProductId').value);
        if (!productId) {
          editProductStatus.textContent = 'Invalid product selection.';
          return;
        }

        const payload = {
          product_edit: {
            id: productId,
            brand: document.getElementById('editProductBrand').value,
            name: document.getElementById('editProductName').value,
            category: document.getElementById('editProductCategory').value,
            base_unit: document.getElementById('editProductBaseUnit').value,
            price: Number(document.getElementById('editProductPrice').value || 0),
            retail_price: Number(document.getElementById('editProductRetailPrice').value || 0),
            wholesale_price: Number(document.getElementById('editProductWholesalePrice').value || 0),
            packets_per_bale: Number(document.getElementById('editProductPacketsPerBale').value || 24),
            expiry_date: document.getElementById('editProductExpiryDate').value || ''
          }
        };

        editProductStatus.textContent = 'Saving product changes...';

        try {
          const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
          });

          const result = await response.json();
          if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to save product changes.');
          }

          editProductStatus.textContent = result.message || 'Product saved.';
          closeEditProductModalDialog();
          const saveMessage = result.message || 'Product updated successfully.';
          sessionStorage.setItem('admin_inventory_pending_toast', saveMessage);
          showToast(saveMessage);
          window.location.reload();
        } catch (error) {
          editProductStatus.textContent = error.message || 'Unable to save product changes.';
        }
      });
    }

    const reconcileHistoricalSalesButton = document.getElementById('reconcileHistoricalSalesButton');
    const printInventoryReportButton = document.getElementById('printInventoryReportButton');
    const manualProduct = document.getElementById('manualCountProduct');
    const manualSystemQty = document.getElementById('manualSystemQty');
    const manualCountQty = document.getElementById('manualCountQty');
    const manualDifference = document.getElementById('manualCountDifference');
    const manualAddButton = document.getElementById('manualCountAdd');
    const manualTableBody = document.getElementById('manualCountTableBody');
    const manualCountApplyButton = document.getElementById('manualCountApply');
    const manualCountResetButton = document.getElementById('manualCountReset');
    const manualCountStatus = document.getElementById('manualCountStatus');

    let countRows = [];

    if (printInventoryReportButton) {
      printInventoryReportButton.addEventListener('click', () => window.print());
    }

    if (reconcileHistoricalSalesButton) {
      reconcileHistoricalSalesButton.addEventListener('click', async () => {
        reconcileHistoricalSalesButton.disabled = true;
        reconcileHistoricalSalesButton.classList.add('opacity-50', 'cursor-not-allowed');
        const oldLabel = reconcileHistoricalSalesButton.textContent;
        reconcileHistoricalSalesButton.textContent = 'Reconciling...';

        try {
          const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ reconcile_historical_sales: true })
          });

          const result = await response.json();
          if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to reconcile sales ledger.');
          }

          manualCountStatus.textContent = result.message || 'Historical sales ledger was reconciled.';
          window.location.reload();
        } catch (error) {
          manualCountStatus.textContent = error.message || 'Unable to reconcile sales ledger.';
        } finally {
          reconcileHistoricalSalesButton.disabled = false;
          reconcileHistoricalSalesButton.classList.remove('opacity-50', 'cursor-not-allowed');
          reconcileHistoricalSalesButton.textContent = oldLabel;
        }
      });
    }

    function updateSystemQty() {
      const productId = manualProduct.value;
      if (!productId || !products[productId]) {
        manualSystemQty.value = '';
        manualDifference.textContent = '—';
        return;
      }
      manualSystemQty.value = products[productId].stock.toFixed(2);
      updateDifference();
    }

    function updateDifference() {
      const productId = manualProduct.value;
      const manualQty = parseFloat(manualCountQty.value);
      if (!productId || !products[productId] || Number.isNaN(manualQty)) {
        manualDifference.textContent = '—';
        return;
      }
      const diff = manualQty - products[productId].stock;
      manualDifference.textContent = diff.toFixed(2);
      manualDifference.className = diff === 0 ? 'mt-2 text-2xl font-semibold text-emerald-700' : diff > 0 ? 'mt-2 text-2xl font-semibold text-amber-700' : 'mt-2 text-2xl font-semibold text-rose-700';
    }

    function setManualCountFormEnabled(enabled) {
      manualProduct.disabled = !enabled;
      manualCountQty.disabled = !enabled;
      manualAddButton.disabled = !enabled;
      if (enabled) {
        manualAddButton.classList.remove('opacity-50', 'cursor-not-allowed');
      } else {
        manualAddButton.classList.add('opacity-50', 'cursor-not-allowed');
      }
    }

    function renderCountRows() {
      if (countRows.length === 0) {
        manualTableBody.innerHTML = '<tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">No manual counts yet.</td></tr>';
        manualCountStatus.textContent = 'Worksheet is ready.';
        return;
      }

      manualTableBody.innerHTML = countRows.map((row, index) => `
        <tr class="${row.difference === 0 ? 'bg-emerald-50' : row.difference > 0 ? 'bg-amber-50' : 'bg-rose-50'}">
          <td class="px-4 py-4 font-medium text-slate-900">${row.name}</td>
          <td class="px-4 py-4 text-slate-900">${row.systemQty.toFixed(2)}</td>
          <td class="px-4 py-4 text-slate-900">${row.manualQty.toFixed(2)}</td>
          <td class="px-4 py-4 text-slate-900">${row.difference.toFixed(2)}</td>
          <td class="px-4 py-4">
            <button type="button" data-index="${index}" class="manual-remove inline-flex rounded-3xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-800">Remove</button>
          </td>
        </tr>
      `).join('');

      document.querySelectorAll('.manual-remove').forEach(button => {
        button.addEventListener('click', () => {
          const index = parseInt(button.getAttribute('data-index'), 10);
          countRows.splice(index, 1);
          renderCountRows();
        });
      });

      const pendingDifferences = countRows.filter(row => Math.abs(row.difference) > 0.000001);
      manualCountStatus.textContent = pendingDifferences.length > 0
        ? pendingDifferences.length + ' difference' + (pendingDifferences.length > 1 ? 's' : '') + ' ready to apply'
        : 'No differences to apply.';
    }

    manualProduct.addEventListener('change', updateSystemQty);
    manualCountQty.addEventListener('input', updateDifference);

    manualAddButton.addEventListener('click', () => {
      const productId = manualProduct.value;
      const manualQty = parseFloat(manualCountQty.value);
      if (!productId || !products[productId] || Number.isNaN(manualQty)) {
        alert('Select a product and enter a valid manual quantity.');
        return;
      }

      const systemQty = products[productId].stock;
      countRows.push({
        product_id: productId,
        name: products[productId].name,
        systemQty,
        system_qty: systemQty,
        manualQty,
        manual_qty: manualQty,
        difference: manualQty - systemQty,
      });

      setManualCountFormEnabled(false);
      renderCountRows();
    });

    manualCountResetButton.addEventListener('click', () => {
      countRows = [];
      manualCountQty.value = '';
      manualDifference.textContent = '—';
      manualProduct.value = '';
      manualSystemQty.value = '';
      manualTableBody.innerHTML = '<tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">No manual counts yet.</td></tr>';
      setManualCountFormEnabled(true);
      renderCountRows();
    });

    manualCountApplyButton.addEventListener('click', async () => {
      if (countRows.length === 0) {
        alert('There are no manual counts to apply.');
        return;
      }

      const payload = {
        manual_counts: countRows.map(row => ({
          product_id: row.product_id,
          system_qty: row.systemQty,
          manual_qty: row.manualQty
        }))
      };

      manualCountApplyButton.disabled = true;
      manualCountApplyButton.classList.add('opacity-50', 'cursor-not-allowed');
      manualCountStatus.textContent = 'Applying inventory differences...';

      try {
        const response = await fetch(window.location.href, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        const result = await response.json();
        if (!response.ok || !result.success) {
          throw new Error(result.message || 'Unable to apply adjustments.');
        }

        manualCountStatus.textContent = result.message || 'Inventory adjustments applied.';
        const countMessage = result.message || 'Inventory adjustments applied.';
        sessionStorage.setItem('admin_inventory_pending_toast', countMessage);
        showToast(countMessage);
        countRows = [];
        renderCountRows();
        manualProduct.value = '';
        manualSystemQty.value = '';
        manualCountQty.value = '';
        manualDifference.textContent = '—';
        setManualCountFormEnabled(true);
      } catch (error) {
        manualCountStatus.textContent = error.message || 'Unable to apply adjustments.';
      } finally {
        manualCountApplyButton.disabled = false;
        manualCountApplyButton.classList.remove('opacity-50', 'cursor-not-allowed');
      }
    });

    const manualCountModal = document.getElementById('manualCountModal');
    const openManualCountModal = document.getElementById('openManualCountModal');
    const closeManualCountModal = document.getElementById('closeManualCountModal');
    const manualCountButtons = document.querySelectorAll('.manual-count-button');

    function showManualCountModal(productId = '') {
      manualCountModal.classList.remove('hidden');
      manualCountModal.classList.add('flex');
      setManualCountFormEnabled(true);
      manualCountQty.value = '';
      manualDifference.textContent = '—';

      if (productId) {
        manualProduct.value = productId;
      } else {
        manualProduct.value = '';
      }

      updateSystemQty();
      manualCountQty.focus();
    }

    function hideManualCountModal() {
      manualCountModal.classList.remove('flex');
      manualCountModal.classList.add('hidden');
    }

    if (openManualCountModal) {
      openManualCountModal.addEventListener('click', () => showManualCountModal());
    }

    manualCountButtons.forEach(button => {
      button.addEventListener('click', () => {
        const productId = button.getAttribute('data-product-id');
        showManualCountModal(productId);
      });
    });

    if (closeManualCountModal) {
      closeManualCountModal.addEventListener('click', hideManualCountModal);
    }
  </script>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
