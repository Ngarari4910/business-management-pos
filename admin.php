<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';
require __DIR__ . '/stock_batches.php';
$csrfToken = csrfToken();

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

// Helper function to fetch sales stats for a date range
function getSalesStats($pdo, $useCostTotal, $useLineTotal, $dateFilter) {
    $baseQuery = "SELECT
            COUNT(DISTINCT s.id) AS sales_count,
            COALESCE(SUM(s.total_amount), 0) AS revenue,
            COALESCE(SUM(sale_cost.cost_basis), 0) AS cost_basis
        FROM sales s
        LEFT JOIN (
            SELECT
                si.sale_id,
                SUM(
                    CASE
                        WHEN si.cost_total IS NULL OR si.cost_total = 0 THEN si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)
                        ELSE si.cost_total
                    END
                ) AS cost_basis
            FROM sale_items si
            LEFT JOIN (
                SELECT product_id, SUM(total_cost) / NULLIF(SUM(total_quantity), 0) AS avg_cost_per_unit
                FROM (
                    SELECT product_id, total_cost, base_quantity AS total_quantity
                    FROM stock_intake_lines
                    UNION ALL
                    SELECT product_id, total_cost, quantity AS total_quantity
                    FROM quick_stock_purchase_lines
                ) combined
                GROUP BY product_id
            ) cost ON cost.product_id = si.product_id
            GROUP BY si.sale_id
        ) sale_cost ON sale_cost.sale_id = s.id
        WHERE {$dateFilter} AND s.payment_status = 'paid'";

    try {
        $stmt = $pdo->prepare($baseQuery);
        $stmt->execute();
        $result = $stmt->fetch();
        return [
            'sales_count' => intval($result['sales_count'] ?? 0),
            'revenue' => floatval($result['revenue'] ?? 0),
            'cost_basis' => floatval($result['cost_basis'] ?? 0)
        ];
    } catch (Exception $e) {
        error_log('Sales stats query error: ' . $e->getMessage());
        return ['sales_count' => 0, 'revenue' => 0, 'cost_basis' => 0];
    }
}

function buildSparklineSvg(array $values, string $strokeColor, string $fillColor, int $width = 80, int $height = 34): string {
    if ($values === []) {
        return '';
    }

    $minValue = min($values);
    $maxValue = max($values);
    $range = $maxValue - $minValue;

    if ($range == 0) {
        $normalized = array_fill(0, count($values), 0.5);
    } else {
        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = ($value - $minValue) / $range;
        }

    }

    $points = [];
    $fillPoints = [];
    $count = count($normalized);
    foreach ($normalized as $index => $value) {
        $x = ($count === 1) ? $width / 2 : ($index / ($count - 1)) * $width;
        $y = $height - ($value * ($height - 6)) - 3;
        $points[] = $x . ',' . $y;
        $fillPoints[] = $x . ',' . ($height - 2);
    }

    $linePoints = implode(' ', $points);
    $fillPath = 'M ' . $points[0] . ' ' . implode(' L ', $fillPoints) . ' L ' . end($points) . ' Z';

    return '<svg class="h-10 w-16 shrink-0 opacity-80" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" aria-hidden="true">
        <defs>
            <linearGradient id="sparkFill' . md5(implode(',', $values)) . '" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stop-color="' . $fillColor . '" stop-opacity="0.28"/>
                <stop offset="100%" stop-color="' . $fillColor . '" stop-opacity="0.02"/>
            </linearGradient>
        </defs>
        <path d="' . $fillPath . '" fill="url(#sparkFill' . md5(implode(',', $values)) . ')"/>
        <polyline points="' . $linePoints . '" fill="none" stroke="' . $strokeColor . '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>';
}

function buildMonthlyBars(array $labels, array $values, string $barColor): string {
    $maxValue = max($values ?: [0]);
    $bars = [];
    foreach ($labels as $index => $label) {
        $value = (float) ($values[$index] ?? 0);
        $height = $maxValue > 0 ? max(4, ($value / $maxValue) * 88) : 4;
        $bars[] = '<div class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1" title="' .
            htmlspecialchars($label . ': KES ' . number_format($value, 2), ENT_QUOTES, 'UTF-8') . '">' .
            '<div class="w-full rounded-t-lg transition hover:opacity-80" style="height:' . number_format($height, 2, '.', '') . 'px;background-color:' . htmlspecialchars($barColor, ENT_QUOTES, 'UTF-8') . ';"></div>' .
            '<span class="text-[10px] text-slate-400">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' .
            '</div>';
    }
    return '<div class="flex h-28 min-w-[240px] items-end gap-2 border-b border-slate-200 pt-2">' . implode('', $bars) . '</div>';
}

function getSalesDetailRows(PDO $pdo, string $dateFilter): array {
    $query = "SELECT
                s.id AS sale_id,
                s.created_at,
                COALESCE(si.line_total, si.quantity * si.unit_price) AS amount_paid,
                s.amount_tendered,
                s.payment_method,
                COALESCE(u.full_name, s.cashier_id, 'Unassigned') AS employee_name,
                COALESCE(sp.receipt_number, CONCAT('RCP-', DATE_FORMAT(s.created_at, '%Y%m%d%H%i%s'), '-', s.id)) AS receipt_number,
                si.product_name,
                si.quantity,
                si.quantity_in_packets,
                si.line_total,
                CASE WHEN COALESCE(si.quantity_in_packets, si.quantity) >= 6 THEN 1 ELSE 0 END AS is_wholesale,
                COALESCE(si.line_total, si.quantity * si.unit_price) - (
                    CASE
                        WHEN si.cost_total IS NULL OR si.cost_total = 0 THEN si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)
                        ELSE si.cost_total
                    END
                ) AS sale_profit
            FROM sales s
            LEFT JOIN users u ON u.id = s.cashier_id
            LEFT JOIN sale_items si ON si.sale_id = s.id
            LEFT JOIN sale_payments sp ON sp.sale_id = s.id AND sp.status = 'success'
            LEFT JOIN (
                SELECT product_id, SUM(total_cost) / NULLIF(SUM(total_quantity), 0) AS avg_cost_per_unit
                FROM (
                    SELECT product_id, total_cost, base_quantity AS total_quantity
                    FROM stock_intake_lines
                    UNION ALL
                    SELECT product_id, total_cost, quantity AS total_quantity
                    FROM quick_stock_purchase_lines
                ) combined
                GROUP BY product_id
            ) cost ON cost.product_id = si.product_id
            WHERE {$dateFilter} AND s.payment_status IN ('paid', 'credit')
            ORDER BY s.created_at DESC, si.id ASC
            LIMIT 500";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log('Sales details query error: ' . $e->getMessage());
        return [];
    }
}

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge_shift') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
      $_SESSION['shift_message'] = 'Your session expired. Please try again.';
    } else {
      $shiftId = (int) ($_POST['shift_id'] ?? 0);
      if ($shiftId > 0) {
        try {
          $ackStmt = $pdo->prepare('UPDATE cashier_shifts SET manager_acknowledged = 1, reviewed_by = :reviewed_by, reviewed_at = NOW(), review_notes = :review_notes WHERE id = :id');
          $ackStmt->execute([
            'reviewed_by' => $_SESSION['user_name'] ?? 'Administrator',
            'review_notes' => trim((string) ($_POST['review_notes'] ?? '')),
            'id' => $shiftId,
          ]);
          $_SESSION['shift_message'] = 'Shift review acknowledged.';
        } catch (Exception $e) {
          error_log('Shift acknowledge error: ' . $e->getMessage());
          $_SESSION['shift_message'] = 'Unable to acknowledge shift review.';
        }
      }
    }
    header('Location: admin.php');
    exit;
  }

$stockDetailsRequest = isset($_GET['stock_details']) && $_GET['stock_details'] === 'json';
$useCostTotal = tableHasColumn($pdo, 'sale_items', 'cost_total');
$useLineTotal = tableHasColumn($pdo, 'sale_items', 'line_total');
$lowStockThreshold = 5; // Configurable threshold - can be moved to settings table

if (!$stockDetailsRequest) {
// Fetch sales stats using helper function
$todaySalesStats = getSalesStats($pdo, $useCostTotal, $useLineTotal, 'DATE(s.created_at) = CURDATE()');
$todaySalesCount = $todaySalesStats['sales_count'];
$todaySalesRevenue = $todaySalesStats['revenue'];
$todaySalesCost = $todaySalesStats['cost_basis'];
$todaySalesProfit = $todaySalesRevenue - $todaySalesCost;
$todaySalesMargin = $todaySalesRevenue > 0 ? ($todaySalesProfit / $todaySalesRevenue) * 100 : 0;
$todaySalesDetails = getSalesDetailRows($pdo, 'DATE(s.created_at) = CURDATE()');
}

// Total stock value
$stockValue = 0.0;
if (!$stockDetailsRequest) {
try {
    $stockValue = floatval($pdo->query('SELECT COALESCE(SUM(stock * price), 0) AS total FROM products')->fetchColumn());
} catch (Exception $e) {
    error_log('Stock value query error: ' . $e->getMessage());
    $stockValue = 0.0;
}
  }

$regularStockValueDetails = [];
try {
    $regularStockValueStmt = $pdo->query("SELECT
            p.id,
            p.brand,
            p.name,
            p.stock,
            p.base_unit,
            GREATEST(p.packets_per_bale, 1) AS packets_per_bale,
            COALESCE(intake_cost.cost_per_package, 0) AS cost_per_package,
            p.wholesale_price,
            p.retail_price,
            p.price,
            CASE WHEN GREATEST(p.packets_per_bale, 1) > 0 THEN FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) ELSE 0 END AS package_qty,
            GREATEST(p.packets_per_bale, 1) AS package_size_value,
            CASE WHEN GREATEST(p.packets_per_bale, 1) > 0 THEN (
                (FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) * (COALESCE(p.retail_price, p.price, 0) * GREATEST(p.packets_per_bale, 1)))
                + ((p.stock % GREATEST(p.packets_per_bale, 1)) * COALESCE(p.retail_price, p.price, 0))
            ) - (
                (FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) * COALESCE(intake_cost.cost_per_package, 0))
                + ((p.stock % GREATEST(p.packets_per_bale, 1)) * (COALESCE(intake_cost.cost_per_package, 0) / GREATEST(p.packets_per_bale, 1)))
            ) ELSE 0 END AS retail_profit,
            CASE WHEN GREATEST(p.packets_per_bale, 1) > 0 THEN (
                (FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) * (COALESCE(p.wholesale_price, 0) * GREATEST(p.packets_per_bale, 1)))
                + ((p.stock % GREATEST(p.packets_per_bale, 1)) * COALESCE(p.wholesale_price, 0))
            ) - (
                (FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) * COALESCE(intake_cost.cost_per_package, 0))
                + ((p.stock % GREATEST(p.packets_per_bale, 1)) * (COALESCE(intake_cost.cost_per_package, 0) / GREATEST(p.packets_per_bale, 1)))
            ) ELSE 0 END AS wholesale_profit,
            CASE WHEN GREATEST(p.packets_per_bale, 1) > 0 THEN COALESCE(p.wholesale_price, 0) * GREATEST(p.packets_per_bale, 1) ELSE 0 END AS wholesale_package_value
        FROM products p
        LEFT JOIN (
          SELECT product_id, AVG(cost_per_package) AS cost_per_package
          FROM stock_intake_lines
          GROUP BY product_id
        ) intake_cost ON intake_cost.product_id = p.id
        WHERE p.stock > 0
          AND EXISTS (
            SELECT 1 FROM stock_batches sb
            WHERE sb.product_id = p.id
              AND sb.source_type IN ('legacy', 'regular_intake')
              AND sb.base_quantity_remaining > 0
          )
        ORDER BY p.brand, p.name
        LIMIT 500");
    $regularStockValueDetails = $regularStockValueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('Regular stock value detail query error: ' . $e->getMessage());
    $regularStockValueDetails = [];
}

$quickStockValueDetails = [];
try {
    $quickStockValueStmt = $pdo->query("SELECT
            p.id,
            p.brand,
            p.name,
            p.stock,
            p.base_unit,
            GREATEST(p.packets_per_bale, 1) AS packets_per_bale,
            COALESCE(quick_cost.cost_price * GREATEST(p.packets_per_bale, 1), 0) AS cost_per_package,
            p.wholesale_price,
            p.retail_price,
            p.price,
            CASE WHEN GREATEST(p.packets_per_bale, 1) > 0 THEN FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) ELSE 0 END AS package_qty,
            GREATEST(p.packets_per_bale, 1) AS package_size_value,
            CASE WHEN GREATEST(p.packets_per_bale, 1) > 0 THEN (
                (FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) * (COALESCE(p.retail_price, p.price, 0) * GREATEST(p.packets_per_bale, 1)))
                + ((p.stock % GREATEST(p.packets_per_bale, 1)) * COALESCE(p.retail_price, p.price, 0))
            ) - (
                (FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) * COALESCE(quick_cost.cost_price * GREATEST(p.packets_per_bale, 1), 0))
                + ((p.stock % GREATEST(p.packets_per_bale, 1)) * (COALESCE(quick_cost.cost_price * GREATEST(p.packets_per_bale, 1), 0) / GREATEST(p.packets_per_bale, 1)))
            ) ELSE 0 END AS retail_profit,
            CASE WHEN GREATEST(p.packets_per_bale, 1) > 0 THEN (
                (FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) * (COALESCE(p.wholesale_price, 0) * GREATEST(p.packets_per_bale, 1)))
                + ((p.stock % GREATEST(p.packets_per_bale, 1)) * COALESCE(p.wholesale_price, 0))
            ) - (
                (FLOOR(p.stock / GREATEST(p.packets_per_bale, 1)) * COALESCE(quick_cost.cost_price * GREATEST(p.packets_per_bale, 1), 0))
                + ((p.stock % GREATEST(p.packets_per_bale, 1)) * (COALESCE(quick_cost.cost_price * GREATEST(p.packets_per_bale, 1), 0) / GREATEST(p.packets_per_bale, 1)))
            ) ELSE 0 END AS wholesale_profit,
            CASE WHEN GREATEST(p.packets_per_bale, 1) > 0 THEN COALESCE(p.wholesale_price, 0) * GREATEST(p.packets_per_bale, 1) ELSE 0 END AS wholesale_package_value
        FROM products p
        LEFT JOIN (
          SELECT product_id, AVG(cost_price) AS cost_price
          FROM quick_stock_purchase_lines
          GROUP BY product_id
        ) quick_cost ON quick_cost.product_id = p.id
        WHERE p.stock > 0
          AND EXISTS (SELECT 1 FROM quick_stock_purchase_lines qpl WHERE qpl.product_id = p.id)
        ORDER BY p.brand, p.name
        LIMIT 500");
    $quickStockValueDetails = $quickStockValueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('Quick stock value detail query error: ' . $e->getMessage());
    $quickStockValueDetails = [];
}

if (isset($_GET['stock_details']) && $_GET['stock_details'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'regular' => $regularStockValueDetails,
        'quick' => $quickStockValueDetails,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

$weekSalesStats = getSalesStats($pdo, $useCostTotal, $useLineTotal, 'YEARWEEK(s.created_at, 1) = YEARWEEK(CURDATE(), 1)');
$weekSalesRevenue = $weekSalesStats['revenue'];
$weekSalesCost = $weekSalesStats['cost_basis'];
$weekSalesProfit = $weekSalesRevenue - $weekSalesCost;
$weekSalesDetails = getSalesDetailRows($pdo, 'YEARWEEK(s.created_at, 1) = YEARWEEK(CURDATE(), 1)');

$monthSalesStats = getSalesStats($pdo, $useCostTotal, $useLineTotal, 'YEAR(s.created_at) = YEAR(CURDATE()) AND MONTH(s.created_at) = MONTH(CURDATE())');
$monthSalesRevenue = $monthSalesStats['revenue'];
$monthSalesCost = $monthSalesStats['cost_basis'];
$monthSalesProfit = $monthSalesRevenue - $monthSalesCost;
$monthSalesDetails = getSalesDetailRows($pdo, 'YEAR(s.created_at) = YEAR(CURDATE()) AND MONTH(s.created_at) = MONTH(CURDATE())');

$retailMonthlySales = [];
$wholesaleMonthlySales = [];
$expenseMonthlyTotals = [];
try {
    $retailMonthlyStmt = $pdo->query("SELECT
            DATE_FORMAT(s.created_at, '%Y-%m') AS month_key,
            COALESCE(SUM(COALESCE(si.line_total, si.quantity * si.unit_price)), 0) AS retail_revenue
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        WHERE s.created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
          AND s.payment_status = 'paid'
          AND COALESCE(si.quantity_in_packets, si.quantity) < 6
        GROUP BY DATE_FORMAT(s.created_at, '%Y-%m')
        ORDER BY month_key");
    foreach ($retailMonthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $retailMonthlySales[(string) $row['month_key']] = (float) $row['retail_revenue'];
    }
    $wholesaleMonthlyStmt = $pdo->query("SELECT
            DATE_FORMAT(s.created_at, '%Y-%m') AS month_key,
            COALESCE(SUM(COALESCE(si.line_total, si.quantity * si.unit_price)), 0) AS wholesale_revenue
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        WHERE s.created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
          AND s.payment_status = 'paid'
          AND COALESCE(si.quantity_in_packets, si.quantity) >= 6
        GROUP BY DATE_FORMAT(s.created_at, '%Y-%m')
        ORDER BY month_key");
    foreach ($wholesaleMonthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $wholesaleMonthlySales[(string) $row['month_key']] = (float) $row['wholesale_revenue'];
    }
    $expenseMonthlyStmt = $pdo->query("SELECT
            DATE_FORMAT(expense_date, '%Y-%m') AS month_key,
            COALESCE(SUM(amount), 0) AS expense_total
        FROM business_expenses
        WHERE expense_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
        GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
        ORDER BY month_key");
    foreach ($expenseMonthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $expenseMonthlyTotals[(string) $row['month_key']] = (float) $row['expense_total'];
    }
} catch (Exception $e) {
    error_log('Monthly retail, wholesale, and expense query error: ' . $e->getMessage());
}

$retailMonthlyLabels = [];
$retailMonthlyValues = [];
$wholesaleMonthlyLabels = [];
$wholesaleMonthlyValues = [];
$expenseMonthlyLabels = [];
$expenseMonthlyValues = [];
$retailCurrentMonthKey = date('Y-m');
$wholesaleCurrentMonthKey = $retailCurrentMonthKey;
$retailMonthCursor = new DateTimeImmutable('first day of -5 months');
for ($monthIndex = 0; $monthIndex < 6; $monthIndex++) {
    $monthKey = $retailMonthCursor->format('Y-m');
    $retailMonthlyLabels[] = $retailMonthCursor->format('M');
    $retailMonthlyValues[] = $retailMonthlySales[$monthKey] ?? 0.0;
    $wholesaleMonthlyLabels[] = $retailMonthCursor->format('M');
    $wholesaleMonthlyValues[] = $wholesaleMonthlySales[$monthKey] ?? 0.0;
    $expenseMonthlyLabels[] = $retailMonthCursor->format('M');
    $expenseMonthlyValues[] = $expenseMonthlyTotals[$monthKey] ?? 0.0;
    $retailMonthCursor = $retailMonthCursor->modify('+1 month');
}
$retailCurrentMonthSales = $retailMonthlySales[$retailCurrentMonthKey] ?? 0.0;
$wholesaleCurrentMonthSales = $wholesaleMonthlySales[$wholesaleCurrentMonthKey] ?? 0.0;
$expenseCurrentMonthTotal = $expenseMonthlyTotals[$retailCurrentMonthKey] ?? 0.0;
$expenseProfitPercentage = $monthSalesProfit > 0
    ? ($expenseCurrentMonthTotal / $monthSalesProfit) * 100
    : 0.0;
$expensePiePercentage = min(100, max(0, $expenseProfitPercentage));
$expenseRemainingPercentage = 100 - $expensePiePercentage;

$todaySalesSpark = max(0, $todaySalesRevenue) > 0
    ? [0, $todaySalesRevenue * 0.2, $todaySalesRevenue * 0.45, $todaySalesRevenue * 0.7, $todaySalesRevenue]
    : [0, 0, 0, 0, 0];
$todayProfitSpark = max(0, $todaySalesProfit) > 0
    ? [0, $todaySalesProfit * 0.25, $todaySalesProfit * 0.55, $todaySalesProfit * 0.78, $todaySalesProfit]
    : [0, 0, 0, 0, 0];
$stockValueSpark = max(0, $stockValue) > 0
    ? [0, $stockValue * 0.25, $stockValue * 0.5, $stockValue * 0.75, $stockValue]
    : [0, 0, 0, 0, 0];
$weekSpark = max(0, $weekSalesRevenue) > 0
    ? [0, $weekSalesRevenue * 0.3, $weekSalesRevenue * 0.55, $weekSalesRevenue * 0.8, $weekSalesRevenue]
    : [0, 0, 0, 0, 0];
$monthSpark = max(0, $monthSalesRevenue) > 0
    ? [0, $monthSalesRevenue * 0.2, $monthSalesRevenue * 0.45, $monthSalesRevenue * 0.75, $monthSalesRevenue]
    : [0, 0, 0, 0, 0];

$topProductRevenueExpr = $useLineTotal
    ? 'COALESCE(si.line_total, si.quantity * si.unit_price)'
    : 'si.quantity * si.unit_price';

$costExpression = $useCostTotal
    ? 'COALESCE(si.cost_total, si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0))'
    : 'si.quantity_in_packets * COALESCE(cost.avg_cost_per_unit, 0)';

try {
    $topProductsStmt = $pdo->prepare("SELECT si.product_name,
                  SUM(si.quantity_in_packets) AS qty_sold,
                  COALESCE(SUM({$topProductRevenueExpr}), 0) AS revenue,
                  COALESCE(SUM({$costExpression}), 0) AS cost_basis,
                  COALESCE(SUM({$topProductRevenueExpr}) - SUM({$costExpression}), 0) AS profit
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN (
                 SELECT product_id, SUM(total_cost) / NULLIF(SUM(base_quantity), 0) AS avg_cost_per_unit
                 FROM stock_intake_lines
                 GROUP BY product_id
             ) cost ON cost.product_id = si.product_id
             WHERE YEAR(s.created_at) = YEAR(CURDATE())
               AND MONTH(s.created_at) = MONTH(CURDATE())
               AND s.payment_status = 'paid'
             GROUP BY si.product_name
             ORDER BY profit DESC
             LIMIT 5");
    $topProductsStmt->execute();
    $topProducts = $topProductsStmt->fetchAll();
} catch (Exception $e) {
    error_log('Top products query error: ' . $e->getMessage());
    $topProducts = [];
}

$topProductProfit = (float) ($topProducts[0]['profit'] ?? 0);
$topProductProfitPercentage = $monthSalesProfit > 0
    ? ($topProductProfit / $monthSalesProfit) * 100
    : 0.0;
$topProductPiePercentage = min(100, max(0, $topProductProfitPercentage));

try {
    $paymentBreakdownStmt = $pdo->query("SELECT s.payment_method,
                  COUNT(*) AS tx_count,
                  COALESCE(SUM(s.total_amount), 0) AS amount
             FROM sales s
             WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND s.payment_status = 'paid'
             GROUP BY s.payment_method
             ORDER BY amount DESC");
    $paymentBreakdown = $paymentBreakdownStmt->fetchAll();
} catch (Exception $e) {
    error_log('Payment breakdown query error: ' . $e->getMessage());
    $paymentBreakdown = [];
}

// Low stock and out of stock
$lowStockThreshold = 5; // editable threshold
try {
    $lowStock = $pdo->prepare('SELECT * FROM products WHERE stock > 0 AND stock < :th ORDER BY stock ASC LIMIT 20');
    $lowStock->execute(['th' => $lowStockThreshold]);
    $lowStockRows = $lowStock->fetchAll();
} catch (Exception $e) {
    error_log('Low stock query error: ' . $e->getMessage());
    $lowStockRows = [];
}

try {
    $outOfStock = $pdo->query('SELECT * FROM products WHERE stock <= 0 ORDER BY id LIMIT 20')->fetchAll();
} catch (Exception $e) {
    error_log('Out of stock query error: ' . $e->getMessage());
    $outOfStock = [];
}

// Keep the dashboard total scalar instead of loading every purchase row.
try {
  $purchasesStmt = $pdo->prepare('SELECT COALESCE(SUM(total_cost), 0) FROM stock_intakes WHERE DATE(created_at)=CURDATE()');
    $purchasesStmt->execute();
  $todayPurchases = [];
  $todayPurchasesTotal = (float) $purchasesStmt->fetchColumn();
} catch (Exception $e) {
    error_log('Today purchases query error: ' . $e->getMessage());
    $todayPurchases = [];
    $todayPurchasesTotal = 0.0;
}

// Outstanding supplier balances
try {
    $supplierBalances = $pdo->query('SELECT s.id, s.name, COALESCE(SUM(si.balance),0) AS outstanding FROM suppliers s JOIN stock_intakes si ON si.supplier_id = s.id WHERE si.balance > 0 GROUP BY s.id ORDER BY outstanding DESC LIMIT 100')->fetchAll();
} catch (Exception $e) {
    error_log('Supplier balances query error: ' . $e->getMessage());
    $supplierBalances = [];
}

// Recent stock movements and sales
try {
    $recentStockMovements = $pdo->query('SELECT * FROM stock_movements ORDER BY created_at DESC LIMIT 12')->fetchAll();
} catch (Exception $e) {
    error_log('Recent stock movements query error: ' . $e->getMessage());
    $recentStockMovements = [];
}

try {
    $recentSales = $pdo->query('SELECT s.id, s.total_amount, s.payment_method, s.payment_status, s.created_at FROM sales s WHERE DATE(s.created_at) = CURDATE() ORDER BY s.created_at DESC LIMIT 12')->fetchAll();
} catch (Exception $e) {
    error_log('Recent sales query error: ' . $e->getMessage());
    $recentSales = [];
}

try {
    $recentRepack = $pdo->query("SELECT * FROM stock_movements WHERE reason LIKE '%repack%' ORDER BY created_at DESC LIMIT 12")->fetchAll();
} catch (Exception $e) {
    error_log('Recent repack query error: ' . $e->getMessage());
    $recentRepack = [];
}

try {
  $shiftAlerts = $pdo->query("SELECT * FROM cashier_shifts WHERE status = 'closed_with_variance' AND manager_acknowledged = 0 ORDER BY created_at DESC LIMIT 100")->fetchAll();
} catch (Exception $e) {
  error_log('Shift alerts query error: ' . $e->getMessage());
  $shiftAlerts = [];
}

try {
    $expiringSoonProducts = getBatchExpiryProducts($pdo, true);
} catch (Exception $e) {
    error_log('Expiry alerts query error: ' . $e->getMessage());
    $expiringSoonProducts = [];
}

try {
    $expiredProducts = getBatchExpiryProducts($pdo, false);
} catch (Exception $e) {
    error_log('Expired products query error: ' . $e->getMessage());
    $expiredProducts = [];
}

try {
    $shiftReviewRows = $pdo->query("SELECT * FROM cashier_shifts ORDER BY created_at DESC LIMIT 20")->fetchAll();
} catch (Exception $e) {
    error_log('Shift review query error: ' . $e->getMessage());
    $shiftReviewRows = [];
}
$shiftMessage = $_SESSION['shift_message'] ?? '';
unset($_SESSION['shift_message']);

?>
<!DOCTYPE html>
<html lang="en">
<head>  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — SMART POS SYSTEM</title>
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
    <div class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
      <div class="flex items-center gap-3">
        <img src="colour_logo.jpg" alt="POS2 logo" class="h-14 w-14 rounded-2xl object-cover shadow-sm">
        <div>
          <p class="text-lg font-semibold text-slate-900">SMART POS SYSTEM</p>
          <p class="text-sm text-slate-500">Admin control center</p>
        </div>
      </div>
      <div class="flex items-center gap-2 text-sm text-slate-500">
        <span class="rounded-full bg-slate-100 px-3 py-1 font-medium">Live overview</span>
      </div>
    </div>
    <header class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 sm:text-3xl">Admin Dashboard</h1>
        <p class="mt-1 text-sm text-slate-500 sm:text-base">High-level business overview</p>
      </div>
      <div class="flex items-center gap-3">
        <button id="mobileMenuToggle" type="button" class="sm:hidden rounded-3xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Menu</button>
        <div id="desktopHeaderButtons" class="hidden sm:flex flex-wrap gap-2 sm:gap-3">
          <a href="index.php" class="rounded-3xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">Dashboard</a>
          <a href="admin_shifts.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">Shifts</a>
          <a href="admin_growth.php" class="rounded-3xl bg-slate-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">Growth report</a>
          <a href="admin_inventory.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">Inventory</a>
          <a href="admin_debts.php" class="rounded-3xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700">Debt monitor</a>
          <a href="purchases_suppliers.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">View purchases</a>
          <a href="equity_reconciliation.php" class="rounded-3xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700">Equity reconciliation</a>
          <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-600">Expenses</a>
          <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">Staff / Cashiers</a>
          <a href="admin_payroll.php" class="rounded-3xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700">Payroll</a>
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
        <a href="index.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Dashboard</a>
        <a href="admin_shifts.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Shifts</a>
        <a href="admin_growth.php" class="rounded-3xl bg-slate-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Growth report</a>
        <a href="admin_inventory.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Inventory</a>
        <a href="admin_inventory.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Inventory</a>
        <a href="admin_debts.php" class="rounded-3xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700">Debt monitor</a>
        <a href="purchases_suppliers.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">View purchases</a>
        <a href="equity_reconciliation.php" class="rounded-3xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700">Equity reconciliation</a>
        <a href="admin_expenses.php" class="rounded-3xl bg-amber-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-600">Expenses</a>
        <a href="admin_cashiers.php" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Staff / Cashiers</a>
        <a href="admin_payroll.php" class="rounded-3xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700">Payroll</a>
        <a href="logout.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 transition hover:bg-slate-100">Logout</a>
      </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" data-sales-modal="today" tabindex="0" role="button" aria-label="View today's sales details">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-slate-500">Today's sales</p>
            <div class="mt-3 text-2xl font-semibold text-slate-900"><?php echo number_format($todaySalesCount); ?> tx</div>
            <div class="mt-1 text-sm text-slate-600">Revenue: KES <?php echo number_format($todaySalesRevenue, 2); ?></div>
          </div>
          <?php echo buildSparklineSvg($todaySalesSpark, '#10b981', '#34d399'); ?>
        </div>
      </div>

      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" data-sales-modal="today" tabindex="0" role="button" aria-label="View today's profit details">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-slate-500">Today's profit</p>
            <div class="mt-3 text-2xl font-semibold text-slate-900">KES <?php echo number_format($todaySalesProfit, 2); ?></div>
            <div class="mt-1 text-sm text-slate-600">Margin: <?php echo number_format($todaySalesMargin, 2); ?>%</div>
          </div>
          <?php echo buildSparklineSvg($todayProfitSpark, '#3b82f6', '#60a5fa'); ?>
        </div>
      </div>

      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" data-stock-modal="stock" tabindex="0" role="button" aria-label="View total stock details">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-slate-500">Total stock value</p>
            <div class="mt-3 text-2xl font-semibold text-slate-900">KES <?php echo number_format(floatval($stockValue), 2); ?></div>
            <div class="mt-1 text-sm text-slate-600">Sum of price × stock</div>
          </div>
          <?php echo buildSparklineSvg($stockValueSpark, '#f59e0b', '#fbbf24'); ?>
        </div>
      </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" data-sales-modal="week" tabindex="0" role="button" aria-label="View this week's sales details">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-slate-500">This week</p>
            <div class="mt-3 text-2xl font-semibold text-slate-900">KES <?php echo number_format($weekSalesRevenue, 2); ?></div>
            <div class="mt-1 text-sm text-slate-600">Profit: KES <?php echo number_format($weekSalesProfit, 2); ?></div>
          </div>
          <?php echo buildSparklineSvg($weekSpark, '#22c55e', '#34d399'); ?>
        </div>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" data-sales-modal="month" tabindex="0" role="button" aria-label="View this month's sales details">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-slate-500">This month</p>
            <div class="mt-3 text-2xl font-semibold text-slate-900">KES <?php echo number_format($monthSalesRevenue, 2); ?></div>
            <div class="mt-1 text-sm text-slate-600">Profit: KES <?php echo number_format($monthSalesProfit, 2); ?></div>
          </div>
          <?php echo buildSparklineSvg($monthSpark, '#8b5cf6', '#a78bfa'); ?>
        </div>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm font-medium text-slate-500">Top product — current month</p>
            <?php if (count($topProducts) > 0): ?>
              <div class="mt-3 text-lg font-semibold text-slate-900"><?php echo htmlspecialchars($topProducts[0]['product_name']); ?></div>
              <div class="mt-1 text-sm text-slate-600">Profit: KES <?php echo number_format($topProductProfit, 2); ?></div>
              <div class="mt-1 text-sm text-slate-600">Qty: <?php echo number_format($topProducts[0]['qty_sold'], 2); ?></div>
            <?php else: ?>
              <div class="mt-3 text-lg font-semibold text-slate-500">No sales yet</div>
            <?php endif; ?>
          </div>
          <div class="relative h-24 w-24 shrink-0 rounded-full" style="background: conic-gradient(#10b981 0% <?php echo number_format($topProductPiePercentage, 2, '.', ''); ?>%, #dbeafe <?php echo number_format($topProductPiePercentage, 2, '.', ''); ?>% 100%);" role="img" aria-label="<?php echo number_format($topProductProfitPercentage, 1); ?> percent of current month profit">
            <div class="absolute inset-3 flex items-center justify-center rounded-full bg-white text-center">
              <span class="text-base font-bold text-slate-900"><?php echo number_format($topProductProfitPercentage, 1); ?>%</span>
            </div>
          </div>
        </div>
        <p class="mt-2 text-xs text-slate-500">Share of total current-month profit</p>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm font-medium text-slate-500">Retail shop — current month</p>
            <div class="mt-3 text-2xl font-semibold text-slate-900">KES <?php echo number_format($retailCurrentMonthSales, 2); ?></div>
            <div class="mt-1 text-sm text-slate-600">Sales from quantities below 6 units</div>
          </div>
          <?php echo buildMonthlyBars($retailMonthlyLabels, $retailMonthlyValues, '#0ea5e9'); ?>
        </div>
        <p class="mt-2 text-xs text-slate-500">Monthly retail sales comparison</p>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm font-medium text-slate-500">Wholesale shop — current month</p>
            <div class="mt-3 text-2xl font-semibold text-slate-900">KES <?php echo number_format($wholesaleCurrentMonthSales, 2); ?></div>
            <div class="mt-1 text-sm text-slate-600">Sales from quantities of 6 units or more</div>
          </div>
          <?php echo buildMonthlyBars($wholesaleMonthlyLabels, $wholesaleMonthlyValues, '#7c3aed'); ?>
        </div>
        <p class="mt-2 text-xs text-slate-500">Monthly wholesale sales comparison</p>
      </div>
      <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm font-medium text-slate-500">Expenses incurred — current month</p>
            <div class="mt-3 text-2xl font-semibold text-slate-900">KES <?php echo number_format($expenseCurrentMonthTotal, 2); ?></div>
            <div class="mt-1 text-sm text-slate-600">Gross profit: KES <?php echo number_format($monthSalesProfit, 2); ?></div>
          </div>
          <div class="relative h-28 w-28 shrink-0 rounded-full" style="background: conic-gradient(#f59e0b 0% <?php echo number_format($expensePiePercentage, 2, '.', ''); ?>%, #dbeafe <?php echo number_format($expensePiePercentage, 2, '.', ''); ?>% 100%);" role="img" aria-label="<?php echo number_format($expenseProfitPercentage, 1); ?> percent of current month profit spent on expenses">
            <div class="absolute inset-4 flex items-center justify-center rounded-full bg-white text-center">
              <span class="text-lg font-bold text-slate-900"><?php echo number_format($expenseProfitPercentage, 1); ?>%</span>
            </div>
          </div>
        </div>
        <div class="mt-3 flex items-center gap-4 text-xs text-slate-600">
          <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-amber-500"></i>Expenses</span>
          <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-blue-100"></i>Remaining profit</span>
        </div>
        <p class="mt-2 text-xs text-slate-500">Percentage of current-month gross profit spent on expenses</p>
      </div>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
      <section class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold text-slate-900">Shift alerts</h2>
        <?php if (count($shiftAlerts) === 0): ?>
          <p class="text-slate-500">No shift variances pending review.</p>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach ($shiftAlerts as $alert): ?>
                  <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                      <div>
                        <p class="font-semibold text-amber-900">Shift closed with variance</p>
                        <p class="text-sm text-amber-800">Cashier: <?php echo htmlspecialchars($alert['cashier_name']); ?> · Variance: KES <?php echo number_format(floatval($alert['variance']), 2); ?></p>
                        <?php if (!empty($alert['variance_reason'])): ?>
                          <p class="text-xs text-amber-700 mt-1">Reason: <?php echo htmlspecialchars($alert['variance_reason']); ?></p>
                        <?php endif; ?>
                      </div>
                      <a href="admin.php?review_shift=<?php echo (int) $alert['id']; ?>" class="rounded-full bg-amber-600 px-3 py-1 text-sm font-semibold text-white">Review</a>
                    </div>
                  </div>
                <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if (isset($_GET['review_shift'])):
          $reviewId = (int)$_GET['review_shift'];
          $shiftRow = $pdo->prepare('SELECT * FROM cashier_shifts WHERE id = :id LIMIT 1');
          $shiftRow->execute(['id' => $reviewId]);
          $shift = $shiftRow->fetch(PDO::FETCH_ASSOC);
          if ($shift): ?>
        <div id="reviewModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div class="mx-4 w-full max-w-3xl rounded-xl bg-white p-6">
            <h3 class="text-xl font-semibold">Review Shift #<?php echo (int)$shift['id']; ?></h3>
            <p class="mt-2 text-sm text-gray-600">Cashier: <?php echo htmlspecialchars($shift['cashier_name']); ?></p>
            <p class="mt-1 text-sm text-gray-600">Expected: KES <?php echo number_format(floatval($shift['expected_cash']), 2); ?> · Counted: KES <?php echo number_format(floatval($shift['counted_cash']), 2); ?> · Variance: KES <?php echo number_format(floatval($shift['variance']), 2); ?></p>
            <div class="mt-3 text-sm text-gray-700">
              <p><strong>Variance reason:</strong></p>
              <p><?php echo nl2br(htmlspecialchars($shift['variance_reason'] ?? '')); ?></p>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-4 text-sm text-gray-700">
              <div>
                <p><strong>Closed at</strong></p>
                <p><?php echo htmlspecialchars($shift['closed_at']); ?></p>
              </div>
              <div>
                <p><strong>Safe drop</strong></p>
                <p>KES <?php echo number_format(floatval($shift['safe_drop_amount'] ?? 0), 2); ?></p>
              </div>
            </div>
            <form method="POST" class="mt-4">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="action" value="acknowledge_shift">
              <input type="hidden" name="shift_id" value="<?php echo (int)$shift['id']; ?>">
              <label class="block text-sm font-medium text-gray-700">Optional review notes</label>
              <textarea name="review_notes" placeholder="Optional review notes" class="mt-1 w-full rounded-md border p-2"></textarea>
              <div class="mt-4 flex items-center gap-2">
                <button type="submit" class="rounded bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">Acknowledge</button>
                <a href="admin.php" class="rounded bg-gray-200 px-3 py-2 text-sm">Close</a>
              </div>
            </form>
          </div>
        </div>
        <script>
          document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('reviewModal');
            if (modal) modal.scrollIntoView({behavior: 'smooth'});
          });
        </script>
      <?php endif; endif; ?>

      <section class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold text-slate-900">Top products last 30 days</h2>
          <a href="admin_stock_analysis.php" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">View stock analysis</a>
        </div>
        <?php if (count($topProducts) === 0): ?>
          <p class="text-slate-500">No recent sales to report.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-700">
              <thead class="border-b border-slate-200 text-slate-500">
                <tr>
                  <th class="py-3 pe-4">Product</th>
                  <th class="py-3 pe-4">Qty sold</th>
                  <th class="py-3 pe-4">Revenue</th>
                  <th class="py-3 pe-4">Cost</th>
                  <th class="py-3 pe-4">Profit</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                <?php foreach ($topProducts as $product): ?>
                  <tr>
                    <td class="py-3 pe-4 font-medium"><?php echo htmlspecialchars($product['product_name']); ?></td>
                    <td class="py-3 pe-4"><?php echo number_format($product['qty_sold'], 2); ?></td>
                    <td class="py-3 pe-4">KES <?php echo number_format($product['revenue'], 2); ?></td>
                    <td class="py-3 pe-4">KES <?php echo number_format($product['cost_basis'], 2); ?></td>
                    <td class="py-3 pe-4">KES <?php echo number_format($product['revenue'] - $product['cost_basis'], 2); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    </div>

    <div class="grid gap-6 lg:grid-cols-2">
      <section class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold text-slate-900">Stock alerts</h2>
        <?php if (!empty($expiringSoonProducts) || !empty($expiredProducts)): ?>
          <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">Expiry notices</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-800">
              <?php foreach ($expiringSoonProducts as $product): ?>
                <li><?php echo htmlspecialchars($product['brand'] . ' ' . $product['name']); ?> expires on <?php echo htmlspecialchars($product['expiry_date']); ?>.</li>
              <?php endforeach; ?>
              <?php foreach ($expiredProducts as $product): ?>
                <li><?php echo htmlspecialchars($product['brand'] . ' ' . $product['name']); ?> expired on <?php echo htmlspecialchars($product['expiry_date']); ?>.</li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <p class="text-sm text-slate-500 mb-3">Low stock (threshold: <?php echo $lowStockThreshold; ?>)</p>
        <?php if (count($lowStockRows) === 0): ?>
          <p class="text-slate-500">No low-stock products.</p>
        <?php else: ?>
          <ul class="space-y-2">
            <?php foreach ($lowStockRows as $p): ?>
              <li class="flex items-center justify-between">
                <div>
                  <div class="font-semibold"><?php echo htmlspecialchars($p['brand'] . ' ' . $p['name']); ?></div>
                  <div class="text-xs text-slate-500"><?php echo htmlspecialchars($p['category']); ?></div>
                </div>
                <div class="text-sm font-semibold"><?php echo number_format($p['stock'], 3) . ' ' . htmlspecialchars($p['base_unit']); ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <hr class="my-4">
        <h3 class="text-sm font-semibold mb-2">Out of stock</h3>
        <?php if (count($outOfStock) === 0): ?>
          <p class="text-slate-500">No out-of-stock products.</p>
        <?php else: ?>
          <ul class="space-y-2">
            <?php foreach ($outOfStock as $p): ?>
              <li class="flex items-center justify-between">
                <div><?php echo htmlspecialchars($p['brand'] . ' ' . $p['name']); ?></div>
                <div class="text-sm font-semibold">0 <?php echo htmlspecialchars($p['base_unit']); ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <section class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
        <h3 class="mb-3 text-lg font-semibold text-slate-900">Recent shift reviews</h3>
        <?php if (count($shiftReviewRows) === 0): ?>
          <p class="text-slate-500">No shift records yet.</p>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach ($shiftReviewRows as $shift): ?>
              <li class="rounded-2xl border border-slate-200 p-4 text-sm text-slate-700">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="font-semibold"><?php echo htmlspecialchars($shift['cashier_name']); ?></div>
                    <div class="text-xs text-slate-500">Expected: KES <?php echo number_format(floatval($shift['expected_cash']), 2); ?> · Counted: KES <?php echo number_format(floatval($shift['counted_cash']), 2); ?> · Variance: KES <?php echo number_format(floatval($shift['variance']), 2); ?></div>
                  </div>
                  <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-700"><?php echo htmlspecialchars($shift['status']); ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h3 class="text-lg font-semibold mb-3">Recent stock movements</h3>
        <ul class="space-y-3">
          <?php foreach ($recentStockMovements as $m): ?>
            <li class="text-sm text-slate-700">
              <div class="flex items-start justify-between">
                <div>
                  <div class="font-semibold"><?php echo htmlspecialchars($m['reason']); ?></div>
                  <div class="text-xs text-slate-500"><?php echo htmlspecialchars($m['created_at']); ?></div>
                </div>
                <div class="text-sm font-semibold"><?php echo number_format($m['change_quantity'], 3); ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h3 class="text-lg font-semibold mb-3">Recent sales (inferred)</h3>
        <ul class="space-y-3">
          <?php foreach ($recentSales as $s): ?>
            <li class="text-sm text-slate-700">
              <div class="flex items-start justify-between">
                <div>
                  <div class="font-semibold">Sale #<?php echo intval($s['id']); ?> — <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $s['payment_method']))); ?></div>
                  <div class="text-xs text-slate-500"><?php echo htmlspecialchars($s['created_at']); ?> • <?php echo htmlspecialchars(ucwords($s['payment_status'])); ?></div>
                </div>
                <div class="text-sm font-semibold">KES <?php echo number_format(floatval($s['total_amount']), 2); ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>

  </div>
  <div id="salesDetailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-5xl rounded-2xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <h3 id="salesDetailTitle" class="text-xl font-semibold text-slate-800">Sales details</h3>
        <div class="flex items-center gap-2">
          <a id="dailyOperationsReportLink" href="admin_daily_report.php" class="hidden rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700">Daily operations report</a>
          <a id="monthlyOperationsReportLink" href="admin_daily_report.php?view=month" class="hidden rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700">Monthly operations report</a>
          <button type="button" id="closeSalesModal" class="rounded-full border border-slate-200 px-3 py-1 text-lg text-slate-600 hover:bg-slate-100">×</button>
        </div>
      </div>
      <div class="max-h-[70vh] overflow-auto px-4 py-4">
        <section class="mb-8 border-b border-slate-200 pb-6">
          <h4 class="mb-3 text-lg font-semibold text-slate-800">Overall sales summary</h4>
          <div id="overallSalesSummary" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"></div>
          <h4 class="mb-3 mt-6 text-lg font-semibold text-slate-800">Cashier breakdown</h4>
          <div class="overflow-x-auto">
            <table class="w-full border-separate border-spacing-y-2 text-left text-sm text-slate-700">
              <thead>
                <tr>
                  <th class="px-3 py-2 font-semibold">Cashier</th>
                  <th class="px-3 py-2 font-semibold">Retail sales</th>
                  <th class="px-3 py-2 font-semibold">Wholesale sales</th>
                  <th class="px-3 py-2 font-semibold">Total sales</th>
                  <th class="px-3 py-2 font-semibold">Cash sales</th>
                  <th class="px-3 py-2 font-semibold">Equity sales</th>
                  <th class="px-3 py-2 font-semibold">Credit sales</th>
                  <th class="px-3 py-2 font-semibold">Details</th>
                </tr>
              </thead>
              <tbody id="cashierSalesBreakdownRows"></tbody>
            </table>
          </div>
        </section>
        <div id="salesDetailSections" class="hidden">
        <section>
          <h4 id="retailSalesHeading" class="mb-2 text-lg font-semibold text-slate-800">Retail sales details</h4>
          <table class="w-full border-separate border-spacing-y-2 text-left text-sm text-slate-700">
            <thead>
              <tr>
                <th class="px-3 py-2 font-semibold">Receipt No</th>
                <th class="px-3 py-2 font-semibold">Package</th>
                <th class="px-3 py-2 font-semibold">Qty</th>
                <th class="px-3 py-2 font-semibold">Amount paid</th>
                <th class="px-3 py-2 font-semibold">Time</th>
                <th class="px-3 py-2 font-semibold">Mode</th>
              </tr>
            </thead>
            <tbody id="retailSalesDetailRows"></tbody>
          </table>
          <div id="retailSalesSummary" class="mt-2"></div>
        </section>
        <section class="mt-8">
          <h4 class="mb-2 text-lg font-semibold text-slate-800">Wholesale sales details (quantity 6 or more)</h4>
          <table class="w-full border-separate border-spacing-y-2 text-left text-sm text-slate-700">
            <thead>
              <tr>
                <th class="px-3 py-2 font-semibold">Receipt No</th>
                <th class="px-3 py-2 font-semibold">Package</th>
                <th class="px-3 py-2 font-semibold">Qty</th>
                <th class="px-3 py-2 font-semibold">Amount paid</th>
                <th class="px-3 py-2 font-semibold">Time</th>
                <th class="px-3 py-2 font-semibold">Mode</th>
              </tr>
            </thead>
            <tbody id="wholesaleSalesDetailRows"></tbody>
          </table>
          <div id="wholesaleSalesSummary" class="mt-2"></div>
        </section>
        </div>
      </div>
    </div>
  </div>

  <div id="stockDetailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-7xl rounded-2xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <h3 id="stockDetailTitle" class="text-xl font-semibold text-slate-800">Stock details</h3>
        <button type="button" id="closeStockModal" class="rounded-full border border-slate-200 px-3 py-1 text-lg text-slate-600 hover:bg-slate-100">×</button>
      </div>
      <div class="max-h-[70vh] overflow-auto px-4 py-4 space-y-8">
        <section>
          <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h4 class="text-lg font-semibold text-slate-800">Regular stock details</h4>
              <p class="text-sm text-slate-500">Stock coming from regular stock intake.</p>
            </div>
            <label class="block w-full sm:max-w-sm">
              <span class="text-sm font-medium text-slate-700">Search stock products</span>
              <input id="stockDetailsSearch" type="search" placeholder="Search by brand or product name" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200">
            </label>
          </div>
          <table class="w-full border-separate border-spacing-y-2 text-left text-sm text-slate-700">
            <thead>
              <tr>
                <th class="px-3 py-2 font-semibold">Product</th>
                <th class="px-3 py-2 font-semibold">Packets/Kgs/Ltrs remaining</th>
                <th class="px-3 py-2 font-semibold">Bags/Bales remaining</th>
                <th class="px-3 py-2 font-semibold">Units per bag/bale</th>
                <th class="px-3 py-2 font-semibold">Cost per package</th>
                <th class="px-3 py-2 font-semibold">Wholesale selling price</th>
                <th class="px-3 py-2 font-semibold">Retail selling price</th>
                <th class="px-3 py-2 font-semibold">Projected retail profit</th>
                <th class="px-3 py-2 font-semibold">Projected wholesale profit</th>
              </tr>
            </thead>
            <tbody id="regularStockDetailRows"></tbody>
          </table>
          <div id="regularStockPager" class="mt-4 flex items-center justify-between gap-3 text-sm text-slate-600"></div>
        </section>
        <section>
          <div class="mb-4 flex items-center justify-between gap-4">
            <div>
              <h4 class="text-lg font-semibold text-slate-800">Quick stock details</h4>
              <p class="text-sm text-slate-500">Stock additions recorded through quick purchase.</p>
            </div>
          </div>
          <table class="w-full border-separate border-spacing-y-2 text-left text-sm text-slate-700">
            <thead>
              <tr>
                <th class="px-3 py-2 font-semibold">Product</th>
                <th class="px-3 py-2 font-semibold">Packets/Kgs/Ltrs remaining</th>
                <th class="px-3 py-2 font-semibold">Bags/Bales remaining</th>
                <th class="px-3 py-2 font-semibold">Units per bag/bale</th>
                <th class="px-3 py-2 font-semibold">Cost per package</th>
                <th class="px-3 py-2 font-semibold">Wholesale selling price</th>
                <th class="px-3 py-2 font-semibold">Retail selling price</th>
                <th class="px-3 py-2 font-semibold">Projected retail profit</th>
                <th class="px-3 py-2 font-semibold">Projected wholesale profit</th>
              </tr>
            </thead>
            <tbody id="quickStockDetailRows"></tbody>
          </table>
          <div id="quickStockPager" class="mt-4 flex items-center justify-between gap-3 text-sm text-slate-600"></div>
        </section>
      </div>
    </div>
  </div>

  <script>
    const salesDetailData = {
      today: <?php echo json_encode($todaySalesDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
      week: <?php echo json_encode($weekSalesDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
      month: <?php echo json_encode($monthSalesDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    };

    let regularStockDetailData = <?php echo json_encode($regularStockValueDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let quickStockDetailData = <?php echo json_encode($quickStockValueDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    const salesDetailModal = document.getElementById('salesDetailModal');
    const salesDetailTitle = document.getElementById('salesDetailTitle');
    const retailSalesDetailRows = document.getElementById('retailSalesDetailRows');
    const wholesaleSalesDetailRows = document.getElementById('wholesaleSalesDetailRows');
    const retailSalesSummary = document.getElementById('retailSalesSummary');
    const wholesaleSalesSummary = document.getElementById('wholesaleSalesSummary');
    const overallSalesSummary = document.getElementById('overallSalesSummary');
    const cashierSalesBreakdownRows = document.getElementById('cashierSalesBreakdownRows');
    const salesDetailSections = document.getElementById('salesDetailSections');
    const dailyOperationsReportLink = document.getElementById('dailyOperationsReportLink');
    const monthlyOperationsReportLink = document.getElementById('monthlyOperationsReportLink');
    const closeSalesModal = document.getElementById('closeSalesModal');
    const stockDetailModal = document.getElementById('stockDetailModal');
    const regularStockDetailRows = document.getElementById('regularStockDetailRows');
    const quickStockDetailRows = document.getElementById('quickStockDetailRows');
    const regularStockPager = document.getElementById('regularStockPager');
    const quickStockPager = document.getElementById('quickStockPager');
    const closeStockModalButton = document.getElementById('closeStockModal');
    const stockDetailsSearch = document.getElementById('stockDetailsSearch');
    const stockPageSize = 10;
    const stockPages = { regular: 1, quick: 1 };
    let stockSearchTerm = '';

    function formatMoney(value) {
      const amount = Number(value || 0);
      return 'KES ' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDateTime(value) {
      if (!value) return '—';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    }

    function renderSalesDetail(period) {
      const rows = salesDetailData[period] || [];
      const titleMap = {
        today: "Today's sales details",
        week: "This week's sales details",
        month: "This month's sales details"
      };

      salesDetailTitle.textContent = titleMap[period] || 'Sales details';
      dailyOperationsReportLink.classList.toggle('hidden', period !== 'today');
      monthlyOperationsReportLink.classList.toggle('hidden', period !== 'month');

      const retailRows = rows.filter((row) => Number(row.is_wholesale) !== 1);
      const wholesaleRows = rows.filter((row) => Number(row.is_wholesale) === 1);

      const renderRows = (target, summaryTarget, groupedRows) => {
        if (!groupedRows.length) {
          target.innerHTML = '<tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">No sales found in this section.</td></tr>';
          summaryTarget.innerHTML = '';
          return;
        }
        const totalAmount = groupedRows.reduce((sum, row) => sum + Number(row.amount_paid || row.amount_tendered || row.total_amount || 0), 0);
        const employeeGroups = {};
        groupedRows.forEach((row) => {
          const employee = row.employee_name || 'Unassigned';
          if (!employeeGroups[employee]) employeeGroups[employee] = [];
          employeeGroups[employee].push(row);
        });
        target.innerHTML = Object.entries(employeeGroups)
          .sort(([employeeA], [employeeB]) => employeeA.localeCompare(employeeB))
          .map(([employee, employeeRows]) => `
            <tr class="bg-slate-200">
              <td colspan="6" class="px-3 py-2 font-semibold text-slate-800">Cashier: ${employee}</td>
            </tr>
            ${employeeRows.map((row) => {
              const productName = row.product_name ? row.product_name : 'Unspecified product';
              const packets = Number(row.quantity_in_packets || 0);
              const quantity = Number(row.quantity || 0);
              const receiptNo = row.receipt_number || `RCP-${row.sale_id || 'N/A'}`;
              return `
                <tr class="rounded-xl bg-slate-50 align-top">
                  <td class="px-3 py-3 font-medium text-slate-800">${receiptNo}</td>
                  <td class="px-3 py-3 font-medium text-slate-800">${productName}</td>
                  <td class="px-3 py-3">${packets > 0 ? packets.toLocaleString(undefined, { maximumFractionDigits: 3 }) : quantity.toLocaleString(undefined, { maximumFractionDigits: 3 })}</td>
                  <td class="px-3 py-3 font-semibold text-slate-800">${formatMoney(row.amount_paid || row.amount_tendered || row.total_amount || 0)}</td>
                  <td class="px-3 py-3">${formatDateTime(row.created_at)}</td>
                  <td class="px-3 py-3">${(row.payment_method || 'cash').replace(/_/g, ' ')}</td>
                </tr>
              `;
            }).join('')}
          `).join('');
        summaryTarget.innerHTML = `
          <div class="flex justify-end">
            <div class="w-full max-w-xs rounded-xl border border-slate-200 bg-slate-50 p-3 shadow-sm">
              <div class="mb-2 border-b border-slate-200 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Section total</div>
              <div class="flex items-center justify-between gap-4 text-sm">
                <span class="text-slate-500">Total sales</span>
                <span class="font-semibold text-slate-800">${formatMoney(totalAmount)}</span>
              </div>
            </div>
          </div>
        `;
      };

      renderRows(retailSalesDetailRows, retailSalesSummary, retailRows);
      renderRows(wholesaleSalesDetailRows, wholesaleSalesSummary, wholesaleRows);

      const amountFor = (row) => Number(row.amount_paid || row.amount_tendered || row.total_amount || 0);
      const totalAmount = rows.reduce((sum, row) => sum + amountFor(row), 0);
      const totalProfit = rows.reduce((sum, row) => sum + Number(row.sale_profit || 0), 0);
      const retailAmount = retailRows.reduce((sum, row) => sum + amountFor(row), 0);
      const wholesaleAmount = wholesaleRows.reduce((sum, row) => sum + amountFor(row), 0);
      overallSalesSummary.innerHTML = [
        ['Total sales', formatMoney(totalAmount)],
        ['Retail sales', formatMoney(retailAmount)],
        ['Wholesale sales', formatMoney(wholesaleAmount)],
      ].map(([label, value]) => `
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
          <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">${label}</div>
          <div class="mt-1 text-lg font-semibold text-slate-800">${value}</div>
        </div>
      `).join('');

      const employeeGroups = {};
      rows.forEach((row) => {
        const employee = row.employee_name || 'Unassigned';
        if (!employeeGroups[employee]) {
          employeeGroups[employee] = { retailAmount: 0, wholesaleAmount: 0, cashAmount: 0, equityAmount: 0, creditAmount: 0 };
        }
        const group = employeeGroups[employee];
        const amount = amountFor(row);
        const paymentMethod = (row.payment_method || '').toLowerCase();
        if (paymentMethod === 'cash') group.cashAmount += amount;
        if (paymentMethod === 'equity') group.equityAmount += amount;
        if (paymentMethod === 'credit' || paymentMethod === 'debt') group.creditAmount += amount;
        if (Number(row.is_wholesale) === 1) {
          group.wholesaleAmount += amount;
        } else {
          group.retailAmount += amount;
        }
      });
      const employeeEntries = Object.entries(employeeGroups);
      cashierSalesBreakdownRows.innerHTML = employeeEntries.length
        ? employeeEntries.map(([employee, group], index) => `
          <tr class="rounded-xl bg-slate-50 align-top">
            <td class="px-3 py-3 font-semibold text-slate-800">${employee}</td>
            <td class="px-3 py-3">${formatMoney(group.retailAmount)}</td>
            <td class="px-3 py-3">${formatMoney(group.wholesaleAmount)}</td>
            <td class="px-3 py-3 font-semibold">${formatMoney(group.retailAmount + group.wholesaleAmount)}</td>
            <td class="px-3 py-3">${formatMoney(group.cashAmount)}</td>
            <td class="px-3 py-3">${formatMoney(group.equityAmount)}</td>
            <td class="px-3 py-3">${formatMoney(group.creditAmount)}</td>
            <td class="px-3 py-3">
              <button type="button" class="view-employee-sales rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700" data-employee-index="${index}">View sales</button>
            </td>
          </tr>
        `).join('')
        : '<tr><td colspan="8" class="px-3 py-6 text-center text-slate-500">No cashier sales found.</td></tr>';

      salesDetailSections.classList.add('hidden');
      cashierSalesBreakdownRows.querySelectorAll('.view-employee-sales').forEach((button) => {
        button.addEventListener('click', () => {
          const employeeEntry = employeeEntries[Number(button.dataset.employeeIndex)];
          if (!employeeEntry) return;
          window.location.href = `admin_employee_sales.php?period=${encodeURIComponent(period)}&employee=${encodeURIComponent(employeeEntry[0])}`;
        });
      });
    }

    async function refreshStockDetailData() {
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('stock_details', 'json');
        const response = await fetch(url.toString(), { headers: { 'X-Requested-With': 'admin-stock-refresh' } });
        if (!response.ok) {
          throw new Error('Failed to refresh stock details');
        }
        const json = await response.json();
        regularStockDetailData = Array.isArray(json.regular) ? json.regular : [];
        quickStockDetailData = Array.isArray(json.quick) ? json.quick : [];
      } catch (error) {
        console.warn('Stock refresh failed:', error.message);
      }
    }

    function renderStockRows(container, rows, emptyLabel, pager, pageKey) {
      const filteredRows = rows.filter((row) => `${row.brand || ''} ${row.name || ''}`.toLowerCase().includes(stockSearchTerm));
      if (!filteredRows.length) {
        container.innerHTML = '<tr><td colspan="9" class="px-3 py-6 text-center text-slate-500">' + emptyLabel + '</td></tr>';
        if (pager) pager.textContent = '';
        return;
      }

      const totalPages = Math.max(1, Math.ceil(filteredRows.length / stockPageSize));
      stockPages[pageKey] = Math.min(Math.max(1, stockPages[pageKey]), totalPages);
      const pageStart = (stockPages[pageKey] - 1) * stockPageSize;
      const visibleRows = filteredRows.slice(pageStart, pageStart + stockPageSize);
      container.innerHTML = visibleRows.map((row) => {
        const brand = row.brand || '—';
        const productName = row.name || '—';
        const stockUnits = Number(row.stock || row.stock_units || row.quantity || 0);
        const packageQty = Number(row.package_qty || 0);
        const packageSize = Number(row.package_size_value || row.packets_per_bale || 0);
        const costPerPackage = Number(row.cost_per_package || 0);
        const wholesalePrice = Number(row.wholesale_price || 0);
        const retailPrice = Number(row.retail_price || row.price || 0);
        const retailProfit = Number(row.retail_profit || 0);
        const wholesaleProfit = Number(row.wholesale_profit || 0);

        return `
          <tr class="rounded-xl bg-slate-50 align-top">
            <td class="px-3 py-3 font-medium text-slate-800">${brand} ${productName}</td>
            <td class="px-3 py-3 font-semibold text-slate-900">${stockUnits.toLocaleString(undefined, { maximumFractionDigits: 3 })}</td>
            <td class="px-3 py-3 font-semibold text-slate-900">${packageQty.toLocaleString(undefined, { maximumFractionDigits: 3 })}</td>
            <td class="px-3 py-3">${packageSize.toLocaleString(undefined, { maximumFractionDigits: 3 })}</td>
            <td class="px-3 py-3">${formatMoney(costPerPackage)}</td>
            <td class="px-3 py-3">${formatMoney(wholesalePrice)}</td>
            <td class="px-3 py-3">${formatMoney(retailPrice)}</td>
            <td class="px-3 py-3 text-emerald-700">${formatMoney(retailProfit)}</td>
            <td class="px-3 py-3 text-emerald-700">${formatMoney(wholesaleProfit)}</td>
          </tr>
        `;
      }).join('');
      if (pager) {
        pager.innerHTML = '';
        const summary = document.createElement('span');
        summary.textContent = `Page ${stockPages[pageKey]} of ${totalPages} (${filteredRows.length} products)`;
        const actions = document.createElement('span');
        actions.className = 'flex gap-2';
        [['Previous', -1], ['Next', 1]].forEach(([label, direction]) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.textContent = label;
          button.disabled = direction < 0 ? stockPages[pageKey] === 1 : stockPages[pageKey] === totalPages;
          button.className = 'rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40';
          button.addEventListener('click', () => {
            stockPages[pageKey] += direction;
            renderStockDetail();
          });
          actions.appendChild(button);
        });
        pager.append(summary, actions);
      }
    }

    function renderStockDetail() {
      renderStockRows(regularStockDetailRows, regularStockDetailData, 'No regular stock items found.', regularStockPager, 'regular');
      renderStockRows(quickStockDetailRows, quickStockDetailData, 'No quick stock items found.', quickStockPager, 'quick');
    }

    stockDetailsSearch?.addEventListener('input', () => {
      stockSearchTerm = stockDetailsSearch.value.trim().toLowerCase();
      stockPages.regular = 1;
      stockPages.quick = 1;
      renderStockDetail();
    });

    document.querySelectorAll('[data-sales-modal]').forEach((card) => {
      const openModal = () => {
        const period = card.dataset.salesModal;
        renderSalesDetail(period);
        salesDetailModal.classList.remove('hidden');
        salesDetailModal.classList.add('flex');
      };

      card.addEventListener('click', openModal);
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openModal();
        }
      });
    });

    document.querySelectorAll('[data-stock-modal]').forEach((card) => {
      const openModal = async () => {
        stockSearchTerm = '';
        stockPages.regular = 1;
        stockPages.quick = 1;
        if (stockDetailsSearch) stockDetailsSearch.value = '';
        await refreshStockDetailData();
        renderStockDetail();
        stockDetailModal.classList.remove('hidden');
        stockDetailModal.classList.add('flex');
      };

      card.addEventListener('click', openModal);
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openModal();
        }
      });
    });

    function closeModal() {
      salesDetailModal.classList.add('hidden');
      salesDetailModal.classList.remove('flex');
    }

    function closeStockModal() {
      stockDetailModal.classList.add('hidden');
      stockDetailModal.classList.remove('flex');
    }

    closeSalesModal.addEventListener('click', closeModal);
    salesDetailModal.addEventListener('click', (event) => {
      if (event.target === salesDetailModal) {
        closeModal();
      }
    });

    closeStockModalButton.addEventListener('click', closeStockModal);
    stockDetailModal.addEventListener('click', (event) => {
      if (event.target === stockDetailModal) {
        closeStockModal();
      }
    });

    function hideSplashScreen() {
      const splashScreen = document.getElementById('splashScreen');
      if (splashScreen) {
        splashScreen.classList.add('hidden');
        splashScreen.style.display = 'none';
      }
      document.body.classList.remove('splash-loading');
    }

    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(hideSplashScreen, 200);
      setInterval(async () => {
        await refreshStockDetailData();
        if (stockDetailModal && stockDetailModal.classList.contains('flex')) {
          renderStockDetail();
        }
      }, 15000);
    });

    window.addEventListener('load', hideSplashScreen);

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
  </script>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
