<?php
function applyExpiredStockRemoval(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT id, brand, name, stock, expiry_date FROM products WHERE expiry_date IS NOT NULL AND expiry_date <= CURDATE() AND stock > 0 ORDER BY expiry_date ASC, id ASC');
    $stmt->execute();
    $products = $stmt->fetchAll();

    $removedProducts = [];

    foreach ($products as $product) {
        $productId = intval($product['id']);
        $removedQuantity = floatval($product['stock']);
        $productName = trim((string) ($product['brand'] ?? '') . ' ' . (string) ($product['name'] ?? ''));

        $updateStmt = $pdo->prepare('UPDATE products SET stock = 0 WHERE id = :id');
        $updateStmt->execute(['id' => $productId]);

        $movementStmt = $pdo->prepare('INSERT INTO stock_movements (product_id, change_quantity, reason) VALUES (:product_id, :change_quantity, :reason)');
        $movementStmt->execute([
            'product_id' => $productId,
            'change_quantity' => -$removedQuantity,
            'reason' => sprintf('Expired stock removed from active inventory (%s)', $productName !== '' ? trim($productName) : 'product'),
        ]);

        $removedProducts[] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'quantity' => $removedQuantity,
            'expiry_date' => trim((string) ($product['expiry_date'] ?? '')),
        ];
    }

    return $removedProducts;
}

function getExpiredStockLosses(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT sm.product_id, sm.change_quantity, sm.reason, sm.created_at, p.brand, p.name, p.expiry_date FROM stock_movements sm LEFT JOIN products p ON p.id = sm.product_id WHERE sm.reason LIKE :reason ORDER BY sm.created_at DESC LIMIT 500');
    $stmt->execute(['reason' => 'Expired stock removed from active inventory%']);
    $rows = $stmt->fetchAll();

    $losses = [];
    foreach ($rows as $row) {
        $productId = intval($row['product_id']);
        $lostQuantity = abs(floatval($row['change_quantity']));
        $productName = trim((string) ($row['brand'] ?? '') . ' ' . (string) ($row['name'] ?? ''));

        $losses[] = [
            'product_id' => $productId,
            'product_name' => $productName !== '' ? $productName : 'Unknown product',
            'quantity' => $lostQuantity,
            'expiry_date' => trim((string) ($row['expiry_date'] ?? '')),
            'recorded_at' => trim((string) ($row['created_at'] ?? '')),
        ];
    }

    return $losses;
}
?>
