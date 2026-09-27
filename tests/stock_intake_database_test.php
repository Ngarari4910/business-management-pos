<?php

if (getenv('POS2_TEST_DSN') === false) {
    echo "Stock intake database integration test skipped: set POS2_TEST_DSN to run it.\n";
    exit(0);
}

$pdo = new PDO(
    getenv('POS2_TEST_DSN'),
    getenv('POS2_TEST_USER') ?: null,
    getenv('POS2_TEST_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$requiredColumns = [
    'products' => ['retail_price', 'wholesale_price', 'packets_per_bale', 'expiry_date', 'expiry_alert_sent_at'],
    'product_barcodes' => ['retail_price', 'wholesale_price'],
    'stock_intakes' => ['receipt_photo'],
];

foreach ($requiredColumns as $table => $columns) {
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
    $actual = array_map(function ($row) { return strtolower($row['Field']); }, $stmt->fetchAll());
    foreach ($columns as $column) {
        if (!in_array($column, $actual, true)) {
            throw new RuntimeException("Missing {$table}.{$column}");
        }
    }
}

$pdo->exec('CREATE TEMPORARY TABLE stock_intake_atomic_test (id INT PRIMARY KEY, stock DECIMAL(15,3) NOT NULL) ENGINE=InnoDB');
$pdo->beginTransaction();
$pdo->exec('INSERT INTO stock_intake_atomic_test (id, stock) VALUES (1, 10.000)');
$pdo->exec('UPDATE stock_intake_atomic_test SET stock = stock + 5 WHERE id = 1');
$pdo->rollBack();

$count = (int) $pdo->query('SELECT COUNT(*) FROM stock_intake_atomic_test')->fetchColumn();
if ($count !== 0) {
    throw new RuntimeException('Atomic rollback invariant failed');
}

echo "Stock intake database integration test passed\n";
