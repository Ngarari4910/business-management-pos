<?php
require __DIR__ . '/../receipt.php';

$sale = [
    'id' => 123,
    'total_amount' => 300.0,
];

$saleItems = [
    [
        'product_name' => 'Sugar 1kg',
        'quantity' => 2,
        'unit_price' => 150.0,
        'line_total' => 300.0,
    ],
];

$text = generateThermalReceiptText($sale, $saleItems, 'cash', 'INV-000123', 'David', 500.0, 150.0);

$checks = [
    'business header' => strpos($text, 'SMART POS DEMO') !== false,
    'phone number' => strpos($text, '0700000000') !== false,
    'product line' => strpos($text, '2 x 150.00') !== false,
    'payment section' => strpos($text, 'Pay: CASH') !== false,
    'cashier field' => strpos($text, 'Cashier: David') !== false,
    'goods amount' => strpos($text, 'Goods:') !== false && strpos($text, '300.00') !== false,
    'tendered amount' => strpos($text, 'Paid:') !== false && strpos($text, '500.00') !== false,
    'change amount' => strpos($text, 'Change:') !== false && strpos($text, '150.00') !== false,
    'refund policy footer' => strpos($text, 'GOODS ONCE SOLD CANNOT BE REFUNDED') !== false,
    'NovaSoft footer' => strpos($text, 'POWERED BY NOVASOFT SYSTEMS MERU') !== false && strpos($text, date('Y') . ' SMARTPOS') !== false,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Receipt test failed: {$name}\n");
        exit(1);
    }
}

echo "Receipt test passed\n";
