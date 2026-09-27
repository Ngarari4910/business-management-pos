<?php
function addStockBatch(PDO $pdo, array $batch): int {
    $stmt = $pdo->prepare(
        'INSERT INTO stock_batches
         (product_id, stock_intake_id, stock_intake_line_id, quick_purchase_id, source_type,
          quantity_received, quantity_remaining, base_quantity_received, base_quantity_remaining,
          package_size_value, package_size_unit, cost_per_package, retail_price, wholesale_price, expiry_date)
         VALUES (:product_id, :stock_intake_id, :stock_intake_line_id, :quick_purchase_id, :source_type,
          :quantity_received, :quantity_remaining, :base_quantity_received, :base_quantity_remaining,
          :package_size_value, :package_size_unit, :cost_per_package, :retail_price, :wholesale_price, :expiry_date)'
    );
    $stmt->execute([
        'product_id' => (int) $batch['product_id'],
        'stock_intake_id' => $batch['stock_intake_id'] ?? null,
        'stock_intake_line_id' => $batch['stock_intake_line_id'] ?? null,
        'quick_purchase_id' => $batch['quick_purchase_id'] ?? null,
        'source_type' => $batch['source_type'] ?? 'legacy',
        'quantity_received' => $batch['quantity_received'],
        'quantity_remaining' => $batch['quantity_remaining'] ?? $batch['quantity_received'],
        'base_quantity_received' => $batch['base_quantity_received'],
        'base_quantity_remaining' => $batch['base_quantity_remaining'] ?? $batch['base_quantity_received'],
        'package_size_value' => $batch['package_size_value'] ?? 1,
        'package_size_unit' => $batch['package_size_unit'] ?? null,
        'cost_per_package' => $batch['cost_per_package'] ?? 0,
        'retail_price' => $batch['retail_price'] ?? 0,
        'wholesale_price' => $batch['wholesale_price'] ?? 0,
        'expiry_date' => $batch['expiry_date'] !== '' ? ($batch['expiry_date'] ?? null) : null,
    ]);
    return (int) $pdo->lastInsertId();
}

function consumeStockBatches(PDO $pdo, int $productId, float $baseQuantity, string $saleId): void {
    $remaining = $baseQuantity;
    $batchStmt = $pdo->prepare(
        'SELECT id, quantity_remaining, base_quantity_remaining, package_size_value
         FROM stock_batches
         WHERE product_id = :product_id
           AND base_quantity_remaining > 0
           AND (expiry_date IS NULL OR expiry_date > CURDATE())
         ORDER BY id ASC
         FOR UPDATE'
    );
    $batchStmt->execute(['product_id' => $productId]);
    $updateBatch = $pdo->prepare('UPDATE stock_batches SET quantity_remaining = GREATEST(0, quantity_remaining - (:quantity_deduct / NULLIF(package_size_value, 0))), base_quantity_remaining = base_quantity_remaining - :base_quantity_deduct WHERE id = :id');

    while ($remaining > 0.000001 && ($batch = $batchStmt->fetch(PDO::FETCH_ASSOC))) {
        $available = (float) $batch['base_quantity_remaining'];
        $deduct = min($remaining, $available);
        $updateBatch->execute([
            'quantity_deduct' => $deduct,
            'base_quantity_deduct' => $deduct,
            'id' => (int) $batch['id'],
        ]);
        $remaining -= $deduct;
    }

    if ($remaining > 0.000001) {
        throw new RuntimeException("Insufficient non-expired stock for product {$productId} while completing sale {$saleId}.");
    }
}

function getBatchExpiryProducts(PDO $pdo, bool $tomorrowOnly = false): array {
    $where = $tomorrowOnly
        ? 'sb.expiry_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)'
        : 'sb.expiry_date <= CURDATE()';
    $stmt = $pdo->query(
        'SELECT p.id, p.brand, p.name, p.expiry_date, p.stock,
            SUM(sb.base_quantity_remaining) AS batch_stock,
            MIN(sb.expiry_date) AS batch_expiry_date
         FROM stock_batches sb
         JOIN products p ON p.id = sb.product_id
         WHERE sb.base_quantity_remaining > 0 AND ' . $where . '
         GROUP BY p.id
         ORDER BY batch_expiry_date ASC
         LIMIT 100'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['expiry_date'] = $row['batch_expiry_date'] ?? $row['expiry_date'] ?? null;
        $row['stock'] = $row['batch_stock'] ?? $row['stock'] ?? 0;
    }
    unset($row);
    return $rows;
}

function getAvailableBatchQuantity(PDO $pdo, int $productId): float {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(base_quantity_remaining), 0)
         FROM stock_batches
         WHERE product_id = :product_id
           AND base_quantity_remaining > 0
           AND (expiry_date IS NULL OR expiry_date > CURDATE())'
    );
    $stmt->execute(['product_id' => $productId]);
    return (float) $stmt->fetchColumn();
}

function getAvailableBatchPricing(PDO $pdo, int $productId): array {
        $stmt = $pdo->prepare(
                'SELECT retail_price, wholesale_price
                 FROM stock_batches
                 WHERE product_id = :product_id
                     AND base_quantity_remaining > 0
                     AND (expiry_date IS NULL OR expiry_date > CURDATE())
                 ORDER BY id ASC
                 LIMIT 1'
        );
        $stmt->execute(['product_id' => $productId]);
        $prices = $stmt->fetch(PDO::FETCH_ASSOC);
        return $prices ?: ['retail_price' => 0, 'wholesale_price' => 0];
}