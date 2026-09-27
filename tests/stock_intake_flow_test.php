<?php

$root = dirname(__DIR__);
$stockIntake = file_get_contents($root . '/stock_intake.php');
$gallery = file_get_contents($root . '/purchases_suppliers.php');
$ajaxSupplier = file_get_contents($root . '/ajax_create_supplier.php');
$csrfCheck = 'verifyCsrfToken($_POST[\'csrf_token\'] ?? null)';

$checks = [
    'intake requires cashier or admin page guard' => strpos($stockIntake, "requireCashierOrAdminPage();") !== false,
    'intake verifies csrf' => strpos($stockIntake, $csrfCheck) !== false,
    'intake uses one package transaction' => strpos($stockIntake, 'array_chunk($validatedEntries') === false,
    'barcode collision is rejected' => strpos($stockIntake, 'already assigned to another product') !== false,
    'receipt MIME is checked' => strpos($stockIntake, 'getimagesize($tmpName)') !== false,
    'runtime schema mutation removed' => strpos($stockIntake, 'ensureProductColumns($pdo)') === false,
    'supplier endpoint verifies csrf' => strpos($ajaxSupplier, $csrfCheck) !== false,
    'modal splits receipt photos' => strpos($gallery, "receipt_photo.split(',')") !== false,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Stock intake flow test failed: {$name}\n");
        exit(1);
    }
}

echo "Stock intake flow contract test passed\n";
