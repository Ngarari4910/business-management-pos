<?php
require __DIR__ . '/../db.php';

$stmt = $pdo->query("SHOW COLUMNS FROM product_barcodes");
$columns = [];
foreach ($stmt as $row) {
    $columns[] = strtolower($row['Field']);
}

if (!in_array('retail_price', $columns, true) || !in_array('wholesale_price', $columns, true)) {
    fwrite(STDERR, "Missing barcode price columns\n");
    exit(1);
}

fwrite(STDOUT, "Barcode price columns present\n");
