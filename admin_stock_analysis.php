<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

$error = null;
$rows = [];
$performanceRows = [];
$turnoverRows = [];
$pageSize = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$performancePage = max(1, (int) ($_GET['performance_page'] ?? 1));
$volumeProfitPage = max(1, (int) ($_GET['volume_profit_page'] ?? 1));
try {
    $stmt = $pdo->query(
        "SELECT
            p.brand,
            p.name AS product_name,
            p.base_unit,
            COALESCE(batch.current_quantity, 0) AS current_quantity,
            COALESCE(batch.cost_value, 0) AS cost_value,
            COALESCE(batch.retail_value, 0) AS retail_value,
            COALESCE(batch.wholesale_value, 0) AS wholesale_value,
            COALESCE(batch.cartons, 0) AS cartons,
            COALESCE(batch.individual_pieces, 0) AS individual_pieces
        FROM products p
        LEFT JOIN (
            SELECT product_id,
                SUM(base_quantity_remaining) AS current_quantity,
                SUM(base_quantity_remaining * cost_per_package / COALESCE(NULLIF(package_size_value, 0), 1)) AS cost_value,
                SUM(base_quantity_remaining * retail_price / COALESCE(NULLIF(package_size_value, 0), 1)) AS retail_value,
                SUM(base_quantity_remaining * wholesale_price / COALESCE(NULLIF(package_size_value, 0), 1)) AS wholesale_value,
                SUM(quantity_remaining) AS cartons,
                SUM(base_quantity_remaining - (FLOOR(base_quantity_remaining / COALESCE(NULLIF(package_size_value, 0), 1)) * COALESCE(NULLIF(package_size_value, 0), 1))) AS individual_pieces
            FROM stock_batches
            WHERE base_quantity_remaining > 0
              AND (expiry_date IS NULL OR expiry_date > CURDATE())
            GROUP BY product_id
        ) batch ON batch.product_id = p.id
        ORDER BY p.brand, p.name"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $performanceStmt = $pdo->query(
        "SELECT
            p.brand,
            p.name AS product_name,
            p.base_unit,
            COALESCE(SUM(CASE WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN COALESCE(si.quantity_in_packets, si.quantity, 0) ELSE 0 END), 0) AS quantity_sold,
            COALESCE(SUM(CASE
                WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                 AND COALESCE(si.quantity_in_packets, si.quantity, 0) < 6
                THEN COALESCE(si.line_total, si.quantity * si.unit_price, 0)
                ELSE 0
            END), 0) AS retail_sales_revenue,
            COALESCE(SUM(CASE
                WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                 AND COALESCE(si.quantity_in_packets, si.quantity, 0) >= 6
                THEN COALESCE(si.line_total, si.quantity * si.unit_price, 0)
                ELSE 0
            END), 0) AS wholesale_sales_revenue,
            COALESCE(SUM(CASE
                WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                THEN COALESCE(si.line_total, si.quantity * si.unit_price, 0)
                   - COALESCE(NULLIF(si.cost_total, 0), COALESCE(si.quantity_in_packets, si.quantity, 0) * cost.avg_cost_per_unit, 0)
                ELSE 0
            END), 0) AS sales_profit,
            COALESCE(SUM(CASE WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN COALESCE(si.quantity_in_packets, si.quantity, 0) ELSE 0 END), 0) AS recent_quantity,
            COALESCE(SUM(CASE WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                              AND s.created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                              THEN COALESCE(si.quantity_in_packets, si.quantity, 0) ELSE 0 END), 0) AS previous_quantity
        FROM products p
        LEFT JOIN sale_items si ON si.product_id = p.id
        LEFT JOIN sales s ON s.id = si.sale_id
            AND s.payment_status = 'paid'
            AND s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        LEFT JOIN (
            SELECT product_id, SUM(total_cost) / NULLIF(SUM(total_quantity), 0) AS avg_cost_per_unit
            FROM (
                SELECT product_id, total_cost, base_quantity AS total_quantity FROM stock_intake_lines
                UNION ALL
                SELECT product_id, total_cost, quantity AS total_quantity FROM quick_stock_purchase_lines
            ) costs
            GROUP BY product_id
        ) cost ON cost.product_id = p.id
        GROUP BY p.id, p.brand, p.name, p.base_unit
        ORDER BY quantity_sold DESC, p.brand, p.name"
    );
    $performanceRows = $performanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $turnoverStmt = $pdo->query(
        "SELECT
            p.brand,
            p.name AS product_name,
            p.base_unit,
            COALESCE(stock.current_quantity, 0) AS current_quantity,
            COALESCE(purchased.purchased_quantity, 0) AS purchased_quantity,
            COALESCE(sold.sold_quantity, 0) AS sold_quantity
        FROM products p
        LEFT JOIN (
            SELECT product_id, SUM(base_quantity_remaining) AS current_quantity
            FROM stock_batches
            WHERE base_quantity_remaining > 0
              AND (expiry_date IS NULL OR expiry_date > CURDATE())
            GROUP BY product_id
        ) stock ON stock.product_id = p.id
        LEFT JOIN (
            SELECT product_id, SUM(base_quantity) AS purchased_quantity
            FROM (
                SELECT sil.product_id, sil.base_quantity
                FROM stock_intake_lines sil
                JOIN stock_intakes si ON si.id = sil.stock_intake_id
                WHERE si.receipt_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                UNION ALL
                SELECT qpl.product_id, qpl.quantity AS base_quantity
                FROM quick_stock_purchase_lines qpl
                JOIN quick_stock_purchases qp ON qp.id = qpl.quick_purchase_id
                WHERE qp.purchase_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            ) purchases
            GROUP BY product_id
        ) purchased ON purchased.product_id = p.id
        LEFT JOIN (
            SELECT si.product_id, SUM(COALESCE(si.quantity_in_packets, si.quantity, 0)) AS sold_quantity
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            WHERE s.payment_status = 'paid'
              AND s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            GROUP BY si.product_id
        ) sold ON sold.product_id = p.id
        WHERE COALESCE(purchased.purchased_quantity, 0) > 0
           OR COALESCE(sold.sold_quantity, 0) > 0
        ORDER BY purchased_quantity DESC, sold_quantity DESC, p.brand, p.name"
    );
    $turnoverRows = $turnoverStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Stock analysis query error: ' . $e->getMessage());
    $error = 'Stock analysis could not be loaded.';
}

$money = static fn($value): string => 'KES ' . number_format((float) $value, 2);
$stockValue = array_sum(array_map(static fn(array $row): float => (float) $row['cost_value'], $rows));
$retailValue = array_sum(array_map(static fn(array $row): float => (float) $row['retail_value'], $rows));
$wholesaleValue = array_sum(array_map(static fn(array $row): float => (float) $row['wholesale_value'], $rows));
$lowStockCount = count(array_filter($rows, static fn(array $row): bool => (float) $row['current_quantity'] <= 0));
$totalPages = max(1, (int) ceil(count($rows) / $pageSize));
$page = min($page, $totalPages);
$visibleRows = array_slice($rows, ($page - 1) * $pageSize, $pageSize);
$performanceDays = 90;
$performanceMonths = 3;
$performanceTotalPages = max(1, (int) ceil(count($performanceRows) / $pageSize));
$performancePage = min($performancePage, $performanceTotalPages);
$visiblePerformanceRows = array_slice($performanceRows, ($performancePage - 1) * $pageSize, $pageSize);
$averageQuantitySold = count($performanceRows) > 0
    ? array_sum(array_map(static fn(array $row): float => (float) $row['quantity_sold'], $performanceRows)) / count($performanceRows)
    : 0;
$averageSalesProfit = count($performanceRows) > 0
    ? array_sum(array_map(static fn(array $row): float => (float) $row['sales_profit'], $performanceRows)) / count($performanceRows)
    : 0;
$bestSellingProduct = null;
$improvedProduct = null;
$deterioratedProduct = null;
foreach ($performanceRows as $performanceRow) {
    $change = (float) $performanceRow['recent_quantity'] - (float) $performanceRow['previous_quantity'];
    $performanceRow['sales_change'] = $change;
    if ($bestSellingProduct === null || (float) $performanceRow['quantity_sold'] > (float) $bestSellingProduct['quantity_sold']) {
        $bestSellingProduct = $performanceRow;
    }
    if ($improvedProduct === null || $change > (float) $improvedProduct['sales_change']) {
        $improvedProduct = $performanceRow;
    }
    if ($deterioratedProduct === null || $change < (float) $deterioratedProduct['sales_change']) {
        $deterioratedProduct = $performanceRow;
    }
}
$volumeProfitTotalPages = max(1, (int) ceil(count($performanceRows) / $pageSize));
$volumeProfitPage = min($volumeProfitPage, $volumeProfitTotalPages);
$visibleVolumeProfitRows = array_slice($performanceRows, ($volumeProfitPage - 1) * $pageSize, $pageSize);
$turnoverPage = max(1, (int) ($_GET['turnover_page'] ?? 1));
$turnoverTotalPages = max(1, (int) ceil(count($turnoverRows) / $pageSize));
$turnoverPage = min($turnoverPage, $turnoverTotalPages);
$visibleTurnoverRows = array_slice($turnoverRows, ($turnoverPage - 1) * $pageSize, $pageSize);
$performanceMaxQuantity = max(1, ...array_map(static fn(array $row): float => (float) $row['quantity_sold'], $performanceRows));
$performanceMaxChange = max(1, ...array_map(static fn(array $row): float => abs((float) $row['recent_quantity'] - (float) $row['previous_quantity']), $performanceRows));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stock Analysis — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @media print {
      @page { size: A4 landscape; margin: 8mm; }
      body { background: #fff !important; }
      .no-print { display: none !important; }
      main { max-width: none !important; padding: 0 !important; }
      table { width: 96% !important; max-width: 96% !important; margin: 0 auto !important; font-size: 8px !important; }
      th, td { padding: 3px 4px !important; }
      section { box-shadow: none !important; }
    }
  </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <main class="mx-auto max-w-7xl p-4 sm:p-8">
    <section class="mb-6 border-t-[5px] border-[#7f1d1d] bg-gradient-to-r from-white to-[#eef5ff] p-5 shadow-sm">
      <div class="flex items-center justify-between gap-4">
        <img src="colour_logo.jpg" alt="Smart POS Demo" class="h-20 w-20 rounded-2xl border border-slate-200 object-cover">
        <div class="text-right">
          <p class="text-xl font-bold tracking-wide text-[#7f1d1d]">Smart POS Demo</p>
          <p class="mt-1 text-xs font-semibold uppercase tracking-[0.2em] text-[#1683ff]">Products on Stock Analysis</p>
        </div>
      </div>
      <div class="mt-5 border-b-2 border-slate-900 pb-2 text-center">
        <h1 class="text-2xl font-bold uppercase tracking-[0.25em]">Products on Stock Analysis</h1>
        <p class="mt-2 text-sm text-slate-500">Current stock position and the cash currently locked in inventory.</p>
      </div>
    </section>
    <div class="no-print mb-6 flex gap-2">
      <button type="button" onclick="window.print()" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Print report</button>
      <a href="admin.php" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold">Back to dashboard</a>
    </div>
    <?php if ($error !== null): ?>
      <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
      <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Products analysed</div><div class="mt-1 text-2xl font-bold"><?php echo count($rows); ?></div></div>
        <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Inventory locked at cost</div><div class="mt-1 text-2xl font-bold"><?php echo $money($stockValue); ?></div></div>
        <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Inventory at retail value</div><div class="mt-1 text-2xl font-bold"><?php echo $money($retailValue); ?></div></div>
        <div class="rounded-xl bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">Inventory at wholesale value</div><div class="mt-1 text-2xl font-bold"><?php echo $money($wholesaleValue); ?></div></div>
      </div>
      <div id="current-stock-position" class="overflow-x-auto rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <table class="w-full min-w-[1100px] text-left text-sm">
          <thead class="bg-slate-900 text-white">
            <tr><th colspan="7" class="px-3 py-3 text-left text-lg">Current Stock Position</th></tr>
            <tr>
            <th class="px-3 py-3">Product</th><th class="px-3 py-3">Current quantity</th><th class="px-3 py-3">Value at cost</th><th class="px-3 py-3">Retail selling value</th><th class="px-3 py-3">Wholesale selling value</th><th class="px-3 py-3">Cartons</th><th class="px-3 py-3">Individual pieces</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
          <?php foreach ($visibleRows as $row): ?>
            <tr class="odd:bg-white even:bg-sky-50"><td class="px-3 py-3 font-semibold"><?php echo htmlspecialchars(trim(($row['brand'] ?? '') . ' ' . ($row['product_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td><td class="px-3 py-3"><?php echo number_format((float) $row['current_quantity'], 3); ?> <?php echo htmlspecialchars($row['base_unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td><td class="px-3 py-3"><?php echo $money($row['cost_value']); ?></td><td class="px-3 py-3"><?php echo $money($row['retail_value']); ?></td><td class="px-3 py-3"><?php echo $money($row['wholesale_value']); ?></td><td class="px-3 py-3"><?php echo number_format((float) $row['cartons'], 3); ?></td><td class="px-3 py-3"><?php echo number_format((float) $row['individual_pieces'], 3); ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
        <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?> · <?php echo count($rows); ?> products</span>
        <div class="flex gap-2">
          <?php if ($page > 1): ?><a href="?page=<?php echo $page - 1; ?>#current-stock-position" class="rounded-lg border border-slate-300 bg-white px-3 py-2">Previous</a><?php endif; ?>
          <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page + 1; ?>#current-stock-position" class="rounded-lg bg-slate-900 px-3 py-2 text-white">Next</a><?php endif; ?>
        </div>
      </div>
      <section id="sales-performance" class="mt-8 overflow-x-auto rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-4">
          <h2 class="text-xl font-bold text-slate-900">Sales Performance</h2>
          <p class="mt-1 text-sm text-slate-500">Paid sales over the last 90 days. Trend compares the latest 30 days with the preceding 30 days.</p>
        </div>
        <table class="w-full min-w-[1050px] text-left text-sm">
          <thead class="bg-slate-900 text-white">
            <tr>
              <th class="px-3 py-3">Product</th>
              <th class="px-3 py-3">Quantity sold</th>
              <th class="px-3 py-3">Retail sales revenue</th>
              <th class="px-3 py-3">Wholesale sales revenue</th>
              <th class="px-3 py-3">Average units/day</th>
              <th class="px-3 py-3">Average units/month</th>
              <th class="px-3 py-3">Trend</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
          <?php foreach ($visiblePerformanceRows as $performance): ?>
            <?php
              $recentQuantity = (float) $performance['recent_quantity'];
              $previousQuantity = (float) $performance['previous_quantity'];
              $trend = $recentQuantity > $previousQuantity ? 'Growing' : ($recentQuantity < $previousQuantity ? 'Declining' : 'Stable');
              $trendClass = $trend === 'Growing' ? 'text-emerald-700' : ($trend === 'Declining' ? 'text-red-600' : 'text-slate-600');
            ?>
            <tr class="odd:bg-white even:bg-sky-50">
              <td class="px-3 py-3 font-semibold"><?php echo htmlspecialchars(trim(($performance['brand'] ?? '') . ' ' . ($performance['product_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-3 py-3"><?php echo number_format((float) $performance['quantity_sold'], 3); ?> <?php echo htmlspecialchars($performance['base_unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-3 py-3"><?php echo $money($performance['retail_sales_revenue']); ?></td>
              <td class="px-3 py-3"><?php echo $money($performance['wholesale_sales_revenue']); ?></td>
              <td class="px-3 py-3"><?php echo number_format((float) $performance['quantity_sold'] / $performanceDays, 3); ?></td>
              <td class="px-3 py-3"><?php echo number_format((float) $performance['quantity_sold'] / $performanceMonths, 3); ?></td>
              <td class="px-3 py-3 font-semibold <?php echo $trendClass; ?>"><?php echo $trend; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
          <span>Page <?php echo $performancePage; ?> of <?php echo $performanceTotalPages; ?> · <?php echo count($performanceRows); ?> products</span>
          <div class="flex gap-2">
            <?php if ($performancePage > 1): ?><a href="?page=<?php echo $page; ?>&performance_page=<?php echo $performancePage - 1; ?>#sales-performance" class="rounded-lg border border-slate-300 bg-white px-3 py-2">Previous</a><?php endif; ?>
            <?php if ($performancePage < $performanceTotalPages): ?><a href="?page=<?php echo $page; ?>&performance_page=<?php echo $performancePage + 1; ?>#sales-performance" class="rounded-lg bg-slate-900 px-3 py-2 text-white">Next</a><?php endif; ?>
          </div>
        </div>
      </section>
      <section id="sales-volume-profit" class="mt-8 overflow-x-auto rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-4">
          <h2 class="text-xl font-bold text-slate-900">Sales Volume vs Profit</h2>
          <p class="mt-1 text-sm text-slate-500">Products are classified using the average quantity sold and average profit over the last 90 days.</p>
          <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
            <div class="rounded-lg bg-slate-50 p-3">High sales threshold: <strong><?php echo number_format($averageQuantitySold, 3); ?> units</strong></div>
            <div class="rounded-lg bg-slate-50 p-3">High profit threshold: <strong><?php echo $money($averageSalesProfit); ?></strong></div>
          </div>
        </div>
        <div class="mb-6 grid gap-4 md:grid-cols-3">
          <?php
            $summaryCards = [
                ['title' => 'Best-selling product', 'product' => $bestSellingProduct, 'metric' => '90-day units', 'value' => $bestSellingProduct['quantity_sold'] ?? 0, 'color' => 'bg-emerald-500', 'width' => $bestSellingProduct !== null ? ((float) $bestSellingProduct['quantity_sold'] / $performanceMaxQuantity) * 100 : 0],
                ['title' => 'Sales improved', 'product' => $improvedProduct, 'metric' => 'Recent vs previous 30 days', 'value' => $improvedProduct['sales_change'] ?? 0, 'color' => 'bg-blue-500', 'width' => $improvedProduct !== null ? (abs((float) $improvedProduct['sales_change']) / $performanceMaxChange) * 100 : 0],
                ['title' => 'Sales deteriorated', 'product' => $deterioratedProduct, 'metric' => 'Recent vs previous 30 days', 'value' => $deterioratedProduct['sales_change'] ?? 0, 'color' => 'bg-red-500', 'width' => $deterioratedProduct !== null ? (abs((float) $deterioratedProduct['sales_change']) / $performanceMaxChange) * 100 : 0],
            ];
          ?>
          <?php foreach ($summaryCards as $card): ?>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="text-sm font-semibold text-slate-500"><?php echo $card['title']; ?></div>
              <div class="mt-1 truncate text-lg font-bold"><?php echo $card['product'] !== null ? htmlspecialchars(trim(($card['product']['brand'] ?? '') . ' ' . ($card['product']['product_name'] ?? '')), ENT_QUOTES, 'UTF-8') : 'No sales data'; ?></div>
              <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
                <span><?php echo $card['metric']; ?></span>
                <strong><?php echo number_format((float) $card['value'], 3); ?> units</strong>
              </div>
              <div class="mt-2 h-2 rounded-full bg-slate-200"><div class="<?php echo $card['color']; ?> h-2 rounded-full" style="width: <?php echo min(100, max(0, (float) $card['width'])); ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
        <table class="w-full min-w-[1100px] text-left text-sm">
          <thead class="bg-slate-900 text-white"><tr>
            <th class="px-3 py-3">Product</th><th class="px-3 py-3">Quantity sold</th><th class="px-3 py-3">Sales revenue</th><th class="px-3 py-3">Profit</th><th class="px-3 py-3">Category</th><th class="px-3 py-3">Decision</th>
          </tr></thead>
          <tbody class="divide-y divide-slate-100">
          <?php foreach ($visibleVolumeProfitRows as $performance): ?>
            <?php
              $highSales = (float) $performance['quantity_sold'] >= $averageQuantitySold;
              $highProfit = (float) $performance['sales_profit'] >= $averageSalesProfit;
              if ($highSales && $highProfit) {
                  $category = 'Star Products';
                  $decision = 'Keep in stock, increase levels, promote, and expand similar products.';
                  $categoryClass = 'text-emerald-700';
              } elseif ($highSales) {
                  $category = 'Traffic Products';
                  $decision = 'Negotiate supplier prices, review pricing, and sell complementary products.';
                  $categoryClass = 'text-amber-600';
              } elseif ($highProfit) {
                  $category = 'Opportunity Products';
                  $decision = 'Improve marketing, display, and salesperson recommendations.';
                  $categoryClass = 'text-blue-700';
              } else {
                  $category = 'Dead Weight Products';
                  $decision = 'Stop ordering, discount, clear stock, and replace where necessary.';
                  $categoryClass = 'text-red-600';
              }
            ?>
            <tr class="odd:bg-white even:bg-sky-50">
              <td class="px-3 py-3 font-semibold"><?php echo htmlspecialchars(trim(($performance['brand'] ?? '') . ' ' . ($performance['product_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-3 py-3"><?php echo number_format((float) $performance['quantity_sold'], 3); ?> <?php echo htmlspecialchars($performance['base_unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-3 py-3"><?php echo $money((float) $performance['retail_sales_revenue'] + (float) $performance['wholesale_sales_revenue']); ?></td>
              <td class="px-3 py-3"><?php echo $money($performance['sales_profit']); ?></td>
              <td class="px-3 py-3 font-semibold <?php echo $categoryClass; ?>"><?php echo $category; ?></td>
              <td class="px-3 py-3"><?php echo $decision; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
          <span>Page <?php echo $volumeProfitPage; ?> of <?php echo $volumeProfitTotalPages; ?> · <?php echo count($performanceRows); ?> products</span>
          <div class="flex gap-2">
            <?php if ($volumeProfitPage > 1): ?><a href="?page=<?php echo $page; ?>&performance_page=<?php echo $performancePage; ?>&volume_profit_page=<?php echo $volumeProfitPage - 1; ?>#sales-volume-profit" class="rounded-lg border border-slate-300 bg-white px-3 py-2">Previous</a><?php endif; ?>
            <?php if ($volumeProfitPage < $volumeProfitTotalPages): ?><a href="?page=<?php echo $page; ?>&performance_page=<?php echo $performancePage; ?>&volume_profit_page=<?php echo $volumeProfitPage + 1; ?>#sales-volume-profit" class="rounded-lg bg-slate-900 px-3 py-2 text-white">Next</a><?php endif; ?>
          </div>
        </div>
      </section>
      <section id="stock-turnover" class="mt-8 overflow-x-auto rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-4">
          <h2 class="text-xl font-bold text-slate-900">Stock Turnover</h2>
          <p class="mt-1 text-sm text-slate-500">Shows how quickly products turn into cash using purchases and paid sales from the last 90 days.</p>
        </div>
        <table class="w-full min-w-[1000px] text-left text-sm">
          <thead class="bg-slate-900 text-white"><tr>
            <th class="px-3 py-3">Product</th><th class="px-3 py-3">Stock purchased</th><th class="px-3 py-3">Units sold</th><th class="px-3 py-3">Current stock</th><th class="px-3 py-3">Average daily sales</th><th class="px-3 py-3">Days of stock remaining</th><th class="px-3 py-3">Sell-through</th><th class="px-3 py-3">Estimated days to turn</th><th class="px-3 py-3">Assessment</th>
          </tr></thead>
          <tbody class="divide-y divide-slate-100">
          <?php foreach ($visibleTurnoverRows as $turnover): ?>
            <?php
              $purchasedQuantity = (float) $turnover['purchased_quantity'];
              $soldQuantity = (float) $turnover['sold_quantity'];
              $currentQuantity = (float) $turnover['current_quantity'];
              $averageDailySales = $soldQuantity / 90;
              $daysOfStockRemaining = $averageDailySales > 0 ? $currentQuantity / $averageDailySales : null;
              $sellThrough = $purchasedQuantity > 0 ? ($soldQuantity / $purchasedQuantity) * 100 : 0;
              $estimatedDays = $soldQuantity > 0 ? ($purchasedQuantity / $soldQuantity) * 90 : null;
              if ($sellThrough >= 75) {
                  $assessment = 'Excellent turnover';
                  $assessmentClass = 'text-emerald-700';
              } elseif ($sellThrough >= 30) {
                  $assessment = 'Healthy turnover';
                  $assessmentClass = 'text-blue-700';
              } elseif ($soldQuantity > 0) {
                  $assessment = 'Cash is slowing';
                  $assessmentClass = 'text-amber-600';
              } else {
                  $assessment = 'Cash is stuck';
                  $assessmentClass = 'text-red-600';
              }
            ?>
            <tr class="odd:bg-white even:bg-sky-50">
              <td class="px-3 py-3 font-semibold"><?php echo htmlspecialchars(trim(($turnover['brand'] ?? '') . ' ' . ($turnover['product_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-3 py-3"><?php echo number_format($purchasedQuantity, 3); ?> <?php echo htmlspecialchars($turnover['base_unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-3 py-3"><?php echo number_format($soldQuantity, 3); ?> <?php echo htmlspecialchars($turnover['base_unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-3 py-3"><?php echo number_format($currentQuantity, 3); ?> <?php echo htmlspecialchars($turnover['base_unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-3 py-3"><?php echo number_format($averageDailySales, 3); ?> <?php echo htmlspecialchars($turnover['base_unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>/day</td>
              <td class="px-3 py-3"><?php echo $daysOfStockRemaining === null ? 'No sales' : number_format($daysOfStockRemaining, 1) . ' days'; ?></td>
              <td class="px-3 py-3"><?php echo number_format($sellThrough, 1); ?>%</td>
              <td class="px-3 py-3"><?php echo $estimatedDays === null ? 'Not sold' : number_format($estimatedDays, 1) . ' days'; ?></td>
              <td class="px-3 py-3 font-semibold <?php echo $assessmentClass; ?>"><?php echo $assessment; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
          <span>Page <?php echo $turnoverPage; ?> of <?php echo $turnoverTotalPages; ?> · <?php echo count($turnoverRows); ?> products</span>
          <div class="flex gap-2">
            <?php if ($turnoverPage > 1): ?><a href="?page=<?php echo $page; ?>&performance_page=<?php echo $performancePage; ?>&volume_profit_page=<?php echo $volumeProfitPage; ?>&turnover_page=<?php echo $turnoverPage - 1; ?>#stock-turnover" class="rounded-lg border border-slate-300 bg-white px-3 py-2">Previous</a><?php endif; ?>
            <?php if ($turnoverPage < $turnoverTotalPages): ?><a href="?page=<?php echo $page; ?>&performance_page=<?php echo $performancePage; ?>&volume_profit_page=<?php echo $volumeProfitPage; ?>&turnover_page=<?php echo $turnoverPage + 1; ?>#stock-turnover" class="rounded-lg bg-slate-900 px-3 py-2 text-white">Next</a><?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </main>
  <style>@media print { @page { size: A4 landscape; margin: 8mm; } body { background: #fff !important; } a, button { display: none !important; } main { max-width: none !important; padding: 0 !important; } table { min-width: 0 !important; font-size: 8px !important; } th, td { padding: 3px !important; } }</style>
</body>
</html>
