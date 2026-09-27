<?php
require __DIR__ . '/security.php';
require __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_logged_in']) || !in_array($_SESSION['user_role'] ?? '', ['cashier', 'admin'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

if (isSessionExpired()) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['error' => 'Session expired.']);
    exit;
}

refreshSessionActivity();

$query = trim((string) ($_GET['q'] ?? ''));
$barcode = trim((string) ($_GET['barcode'] ?? ''));
$productId = (int) ($_GET['id'] ?? 0);
$limit = min(25, max(1, (int) ($_GET['limit'] ?? 8)));
$includeAll = ($_GET['include_all'] ?? '') === '1';

try {
    if ($barcode !== '') {
        $stmt = $pdo->prepare(
            'SELECT pb.barcode, pb.product_id, pb.sale_mode, pb.quantity, pb.retail_price AS barcode_retail_price,
                    pb.wholesale_price AS barcode_wholesale_price,
                    p.brand, p.name, p.base_unit, p.retail_price, p.wholesale_price,
                    COALESCE((SELECT sb.retail_price FROM stock_batches sb WHERE sb.product_id=p.id AND sb.base_quantity_remaining > 0 AND (sb.expiry_date IS NULL OR sb.expiry_date > CURDATE()) ORDER BY sb.id ASC LIMIT 1), p.retail_price) AS batch_retail_price,
                    COALESCE((SELECT sb.wholesale_price FROM stock_batches sb WHERE sb.product_id=p.id AND sb.base_quantity_remaining > 0 AND (sb.expiry_date IS NULL OR sb.expiry_date > CURDATE()) ORDER BY sb.id ASC LIMIT 1), p.wholesale_price) AS batch_wholesale_price,
                    p.packets_per_bale,
                    p.expiry_date,
                    COALESCE((SELECT SUM(sb.base_quantity_remaining)
                              FROM stock_batches sb
                              WHERE sb.product_id = p.id
                                AND sb.base_quantity_remaining > 0
                                AND (sb.expiry_date IS NULL OR sb.expiry_date > CURDATE())), 0) AS stock
             FROM product_barcodes pb
             JOIN products p ON p.id = pb.product_id
             WHERE UPPER(REPLACE(REPLACE(REPLACE(pb.barcode, "-", ""), " ", ""), ".", "")) =
                 UPPER(REPLACE(REPLACE(REPLACE(:barcode_value, "-", ""), " ", ""), ".", ""))
             LIMIT 1'
        );
          $stmt->execute(['barcode_value' => $barcode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['products' => $row ? [$row] : []], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    $where = [];
    if (!$includeAll) {
        $where[] = 'p.stock > 0';
        $where[] = 'EXISTS (SELECT 1 FROM stock_batches sbx WHERE sbx.product_id = p.id AND sbx.base_quantity_remaining > 0 AND (sbx.expiry_date IS NULL OR sbx.expiry_date > CURDATE()))';
    }
    $params = [];

    if ($productId > 0) {
        $where[] = 'p.id = :product_id';
        $params['product_id'] = $productId;
    } elseif ($query !== '') {
        $where[] = '(LOWER(p.brand) LIKE :query_brand OR LOWER(p.name) LIKE :query_name OR LOWER(CONCAT(p.brand, " ", p.name)) LIKE :query_full)';
        $normalizedQuery = '%' . strtolower($query) . '%';
        $params['query_brand'] = $normalizedQuery;
        $params['query_name'] = $normalizedQuery;
        $params['query_full'] = $normalizedQuery;
    } else {
        echo json_encode(['products' => []]);
        exit;
    }

    $sql = 'SELECT p.id, p.brand, p.name, p.base_unit, p.retail_price, p.wholesale_price,
                    COALESCE((SELECT sb.retail_price FROM stock_batches sb WHERE sb.product_id=p.id AND sb.base_quantity_remaining > 0 AND (sb.expiry_date IS NULL OR sb.expiry_date > CURDATE()) ORDER BY sb.id ASC LIMIT 1), p.retail_price) AS batch_retail_price,
                    COALESCE((SELECT sb.wholesale_price FROM stock_batches sb WHERE sb.product_id=p.id AND sb.base_quantity_remaining > 0 AND (sb.expiry_date IS NULL OR sb.expiry_date > CURDATE()) ORDER BY sb.id ASC LIMIT 1), p.wholesale_price) AS batch_wholesale_price,
                   p.packets_per_bale, p.expiry_date,
                                     p.stock AS stock
            FROM products p
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.brand, p.name
            LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['products' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Throwable $error) {
    error_log('Product lookup failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Product lookup failed.']);
}
