<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/security.php';
requireCashierOrAdminPage();
require __DIR__ . '/db.php';
require __DIR__ . '/stock_batches.php';

function normalizeValue($value) {
    return trim(strtolower(preg_replace('/\s+/', ' ', $value)));
}

function saveReceiptPhotos(): ?string {
    if (empty($_FILES['receipt_photo']['name'])) {
        return null;
    }

    $uploadDir = __DIR__ . '/uploads/receipts';
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0755, true);
    }

    $fileNames = [];
    $names = is_array($_FILES['receipt_photo']['name']) ? $_FILES['receipt_photo']['name'] : [$_FILES['receipt_photo']['name']];
    $tmpNames = is_array($_FILES['receipt_photo']['tmp_name']) ? $_FILES['receipt_photo']['tmp_name'] : [$_FILES['receipt_photo']['tmp_name']];
    $errors = is_array($_FILES['receipt_photo']['error']) ? $_FILES['receipt_photo']['error'] : [$_FILES['receipt_photo']['error']];

    foreach ($names as $index => $name) {
        $tmpName = $tmpNames[$index] ?? null;
        $error = $errors[$index] ?? UPLOAD_ERR_NO_FILE;

        if ((string) $name === '' || $error !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
            continue;
        }

        if (filesize($tmpName) > 10 * 1024 * 1024) {
          continue;
        }

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false || empty($imageInfo['mime'])) {
          continue;
        }

        $allowedMimeTypes = [
          'image/jpeg' => 'jpg',
          'image/png' => 'png',
          'image/webp' => 'webp',
        ];
        $extension = $allowedMimeTypes[$imageInfo['mime']] ?? null;
        if ($extension === null) {
          continue;
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($tmpName, $targetPath)) {
            $fileNames[] = $filename;
        }
    }

    return $fileNames ? implode(',', $fileNames) : null;
}

  function deleteReceiptPhotos($photoList) {
    foreach (array_filter(array_map('trim', explode(',', (string) $photoList))) as $photo) {
      $safePhoto = basename($photo);
      if ($safePhoto === $photo) {
        @unlink(__DIR__ . '/uploads/receipts/' . $safePhoto);
      }
    }
  }

function allocateBarcodeSequence(PDO $pdo, $sequenceName = 'default') {
    $stmt = $pdo->prepare('SELECT next_value FROM barcode_sequences WHERE sequence_name = :sequence_name FOR UPDATE');
    $stmt->execute(['sequence_name' => $sequenceName]);
    $row = $stmt->fetch();

    if (!$row) {
        $stmt = $pdo->prepare('INSERT INTO barcode_sequences (sequence_name, next_value) VALUES (:sequence_name, 2)');
        $stmt->execute(['sequence_name' => $sequenceName]);
        return 1;
    }

    $nextValue = intval($row['next_value']);
    $stmt = $pdo->prepare('UPDATE barcode_sequences SET next_value = next_value + 1 WHERE sequence_name = :sequence_name');
    $stmt->execute(['sequence_name' => $sequenceName]);

    return $nextValue;
}

function generateShortBarcodeCode($sequenceNumber, $prefix = 'CS', $length = 6) {
    return $prefix . str_pad((string) $sequenceNumber, $length, '0', STR_PAD_LEFT);
}

function allocateUniqueBarcode(PDO $pdo) {
  for ($attempt = 0; $attempt < 10; $attempt++) {
    $barcode = generateShortBarcodeCode(allocateBarcodeSequence($pdo));
    $stmt = $pdo->prepare('SELECT id FROM product_barcodes WHERE barcode = :barcode FOR UPDATE');
    $stmt->execute(['barcode' => $barcode]);
    if (!$stmt->fetch()) {
      return $barcode;
    }
  }

  throw new RuntimeException('Unable to allocate a unique barcode after several attempts.');
}

function normalizeBarcode($barcode) {
    return trim(strtoupper(preg_replace('/[^A-Z0-9]/', '', $barcode)));
}

function upsertBarcode(PDO $pdo, $productId, $barcode, $saleMode, $quantity, $retailPrice = 0, $wholesalePrice = 0) {
    $barcode = normalizeBarcode($barcode);
    if ($barcode === '') {
        return;
    }

    $existingStmt = $pdo->prepare('SELECT product_id FROM product_barcodes WHERE barcode = :barcode FOR UPDATE');
    $existingStmt->execute(['barcode' => $barcode]);
    $existingProductId = $existingStmt->fetchColumn();
    if ($existingProductId !== false && (int) $existingProductId !== (int) $productId) {
      throw new Exception('Barcode ' . $barcode . ' is already assigned to another product.');
    }

    $stmt = $pdo->prepare('INSERT INTO product_barcodes (product_id, barcode, sale_mode, quantity, retail_price, wholesale_price) VALUES (:product_id, :barcode, :sale_mode, :quantity, :retail_price, :wholesale_price) ON DUPLICATE KEY UPDATE sale_mode = VALUES(sale_mode), quantity = VALUES(quantity), retail_price = VALUES(retail_price), wholesale_price = VALUES(wholesale_price)');
    $stmt->execute([
        'product_id' => $productId,
        'barcode' => $barcode,
        'sale_mode' => $saleMode,
        'quantity' => $quantity,
        'retail_price' => $retailPrice,
        'wholesale_price' => $wholesalePrice,
    ]);
}

function resolveIntakeBarcode(PDO $pdo, $barcode) {
  $normalized = normalizeBarcode($barcode);
  if ($normalized === '' || preg_match('/^CS[0-9]{6}$/', $normalized)) {
    try {
      return allocateUniqueBarcode($pdo);
    } catch (Throwable $error) {
      error_log('Stock intake barcode allocation skipped: ' . $error->getMessage());
      return '';
    }
  }

  return $normalized;
}

function findExistingProduct(PDO $pdo, $brand, $productName, $variant = '') {
  $brandNorm = normalizeValue($brand);
  $nameNorm = normalizeValue(trim($productName));
  $fullNameNorm = normalizeValue(trim($productName . ' ' . $variant));

  $stmt = $pdo->prepare('SELECT * FROM products WHERE TRIM(LOWER(brand)) = :brand AND (TRIM(LOWER(name)) = :nameNorm OR TRIM(LOWER(name)) = :fullNameNorm) ORDER BY id LIMIT 1');
  $stmt->execute([
    'brand' => $brandNorm,
    'nameNorm' => $nameNorm,
    'fullNameNorm' => $fullNameNorm,
  ]);

  return $stmt->fetch();
}

function processPackageEntry(PDO $pdo, $intakeId, $entry) {
  $fullName = trim($entry['product_name'] . ' ' . $entry['variant']);

  $product = findExistingProduct($pdo, $entry['brand'], $entry['product_name'], $entry['variant']);

  $stockToAdd = $entry['packages_received'] * $entry['package_size_value'];

  if ($product) {
    $productId = intval($product['id']);
    $stmt = $pdo->prepare('UPDATE products SET base_unit = :base_unit, packets_per_bale = :packets_per_bale, expiry_date = :expiry_date, stock = stock + :stock WHERE id = :id');
    $stmt->execute([
      'base_unit' => $entry['base_unit'],
      'packets_per_bale' => $entry['package_size_value'],
      'expiry_date' => $entry['expiry_date'] !== '' ? $entry['expiry_date'] : $product['expiry_date'],
      'stock' => $stockToAdd,
      'id' => $productId,
    ]);
  } else {
    $stmt = $pdo->prepare('INSERT INTO products (brand, name, category, base_unit, price, retail_price, wholesale_price, stock, packets_per_bale, expiry_date) VALUES (:brand, :name, :category, :base_unit, :price, :retail_price, :wholesale_price, :stock, :packets_per_bale, :expiry_date)');
    $stmt->execute([
      'brand' => $entry['brand'],
      'name' => $fullName,
      'category' => 'Food',
      'base_unit' => $entry['base_unit'],
      'price' => $entry['retail_price'],
      'retail_price' => $entry['retail_price'],
      'wholesale_price' => $entry['wholesale_price'],
      'stock' => $stockToAdd,
      'packets_per_bale' => $entry['package_size_value'],
      'expiry_date' => $entry['expiry_date'] !== '' ? $entry['expiry_date'] : null,
    ]);
    $productId = $pdo->lastInsertId();
  }

  $entry['barcode_wholesale'] = resolveIntakeBarcode($pdo, $entry['barcode_wholesale']);
  $entry['barcode_package'] = resolveIntakeBarcode($pdo, $entry['barcode_package']);
  $entry['barcode_1kg'] = resolveIntakeBarcode($pdo, $entry['barcode_1kg']);
  $entry['barcode_half_kg'] = resolveIntakeBarcode($pdo, $entry['barcode_half_kg']);
  $entry['barcode_quarter_kg'] = resolveIntakeBarcode($pdo, $entry['barcode_quarter_kg']);
  $entry['barcode_5kg'] = resolveIntakeBarcode($pdo, $entry['barcode_5kg']);

  upsertBarcode($pdo, $productId, $entry['barcode_wholesale'], 'package', 1, 0, $entry['wholesale_price']);
  upsertBarcode($pdo, $productId, $entry['barcode_package'], 'package', 1, $entry['retail_price'], 0);
  upsertBarcode($pdo, $productId, $entry['barcode_1kg'], 'unit', 1, $entry['repack_one_kg_price'] > 0 ? $entry['repack_one_kg_price'] : $entry['retail_price']);
  upsertBarcode($pdo, $productId, $entry['barcode_half_kg'], 'unit', 0.5, $entry['repack_half_kg_price'] > 0 ? $entry['repack_half_kg_price'] : $entry['retail_price']);
  upsertBarcode($pdo, $productId, $entry['barcode_quarter_kg'], 'unit', 0.25, $entry['repack_quarter_kg_price'] > 0 ? $entry['repack_quarter_kg_price'] : $entry['retail_price']);
  upsertBarcode($pdo, $productId, $entry['barcode_5kg'], 'unit', 5, $entry['repack_five_kg_price'] > 0 ? $entry['repack_five_kg_price'] : $entry['retail_price']);

  $stmt = $pdo->prepare('INSERT INTO stock_intake_lines (stock_intake_id, product_id, package_unit, package_size_value, package_size_unit, quantity, base_quantity, cost_per_package, total_cost) VALUES (:intake_id, :product_id, :package_unit, :package_size_value, :package_size_unit, :quantity, :base_quantity, :cost_per_package, :line_total)');
  $stmt->execute([
    'intake_id' => $intakeId,
    'product_id' => $productId,
    'package_unit' => 'package',
    'package_size_value' => $entry['package_size_value'],
    'package_size_unit' => $entry['package_size_unit'],
    'quantity' => $entry['packages_received'],
    'base_quantity' => $stockToAdd,
    'cost_per_package' => $entry['cost_per_package'],
    'line_total' => $entry['cost_per_package'] * $entry['packages_received'],
  ]);
  $lineId = $pdo->lastInsertId();

  addStockBatch($pdo, [
    'product_id' => $productId,
    'stock_intake_id' => $intakeId,
    'stock_intake_line_id' => $lineId,
    'source_type' => 'regular_intake',
    'quantity_received' => $entry['packages_received'],
    'base_quantity_received' => $stockToAdd,
    'package_size_value' => $entry['package_size_value'],
    'package_size_unit' => $entry['package_size_unit'],
    'cost_per_package' => $entry['cost_per_package'],
    'retail_price' => $entry['retail_price'],
    'wholesale_price' => $entry['wholesale_price'],
    'expiry_date' => $entry['expiry_date'],
  ]);

  $stmt = $pdo->prepare('INSERT INTO stock_movements (product_id, stock_intake_line_id, change_quantity, reason) VALUES (:product_id, :line_id, :change_quantity, :reason)');
  $stmt->execute([
    'product_id' => $productId,
    'line_id' => $lineId,
    'change_quantity' => $stockToAdd,
    'reason' => $entry['repack_intake'] ? 'Repack intake' : 'Package intake',
  ]);
}

function processProductDataEntry(PDO $pdo, $intakeId, $productData) {
  if ($productData['product_id'] !== '') {
    $productId = intval($productData['product_id']);
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();

    if (!$product) {
      throw new Exception('Selected product not found.');
    }

    if (isset($productData['expiry_date'])) {
      $stmt = $pdo->prepare('UPDATE products SET expiry_date = :expiry_date WHERE id = :id');
      $stmt->execute([
        'expiry_date' => $productData['expiry_date'] !== '' ? $productData['expiry_date'] : $product['expiry_date'],
        'id' => $productId,
      ]);
    }
  } else {
    $stmt = $pdo->prepare('INSERT INTO products (brand, name, category, base_unit, price, stock, retail_price, wholesale_price, expiry_date) VALUES (:brand, :name, :category, :base_unit, :price, 0, :retail_price, :wholesale_price, :expiry_date)');
    $stmt->execute([
      'brand' => $productData['brand'],
      'name' => $productData['name'],
      'category' => $productData['category'],
      'base_unit' => $productData['base_unit'],
      'price' => $productData['retail_price'],
      'retail_price' => $productData['retail_price'],
      'wholesale_price' => $productData['wholesale_price'],
      'expiry_date' => $productData['expiry_date'] !== '' ? $productData['expiry_date'] : null,
    ]);
    $productId = $pdo->lastInsertId();
  }

  $productStockIncrease = 0;
  foreach ($productData['lines'] as $line) {
    $stmt = $pdo->prepare('INSERT INTO stock_intake_lines (stock_intake_id, product_id, package_unit, package_size_value, package_size_unit, quantity, base_quantity, cost_per_package, total_cost) VALUES (:intake_id, :product_id, :package_unit, :package_size_value, :package_size_unit, :quantity, :base_quantity, :cost_per_package, :line_total)');
    $stmt->execute([
      'intake_id' => $intakeId,
      'product_id' => $productId,
      'package_unit' => $line['package_unit'],
      'package_size_value' => $line['package_size_value'],
      'package_size_unit' => $line['package_size_unit'],
      'quantity' => $line['quantity'],
      'base_quantity' => $line['base_quantity'],
      'cost_per_package' => $line['cost_per_package'],
      'line_total' => $line['line_total'],
    ]);
    $lineId = $pdo->lastInsertId();

    addStockBatch($pdo, [
      'product_id' => $productId,
      'stock_intake_id' => $intakeId,
      'stock_intake_line_id' => $lineId,
      'source_type' => 'regular_intake',
      'quantity_received' => $line['quantity'],
      'base_quantity_received' => $line['base_quantity'],
      'package_size_value' => $line['package_size_value'],
      'package_size_unit' => $line['package_size_unit'],
      'cost_per_package' => $line['cost_per_package'],
      'retail_price' => $productData['retail_price'],
      'wholesale_price' => $productData['wholesale_price'],
      'expiry_date' => $productData['expiry_date'],
    ]);

    $stmt = $pdo->prepare('INSERT INTO stock_movements (product_id, stock_intake_line_id, change_quantity, reason) VALUES (:product_id, :line_id, :change_quantity, :reason)');
    $stmt->execute([
      'product_id' => $productId,
      'line_id' => $lineId,
      'change_quantity' => $line['base_quantity'],
      'reason' => 'Stock intake',
    ]);

    $productStockIncrease += $line['base_quantity'];
  }

  $stmt = $pdo->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :id');
  $stmt->execute(['quantity' => $productStockIncrease, 'id' => $productId]);
}

$suppliers = $pdo->query('SELECT id, name, kra_pin, phone FROM suppliers ORDER BY name LIMIT 250')->fetchAll();
$products = $pdo->query('SELECT id, brand, name, category, base_unit, price, retail_price, wholesale_price, stock, packets_per_bale, expiry_date FROM products ORDER BY brand, name LIMIT 500')->fetchAll();

$success = false;
$errors = [];
$csrfToken = csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
  $errors[] = 'Your form session expired. Refresh the page and try again.';
}

$submittedPackageEntries = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['package_entries_json'])) {
    $decodedEntries = json_decode(trim((string) $_POST['package_entries_json']), true);
    if (is_array($decodedEntries)) {
        $submittedPackageEntries = $decodedEntries;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simple_bale_intake'])) {
    $packageEntriesJson = trim((string) ($_POST['package_entries_json'] ?? ''));
    $packageEntries = [];

    if ($packageEntriesJson !== '') {
        $decodedEntries = json_decode($packageEntriesJson, true);
        if (is_array($decodedEntries)) {
            $packageEntries = $decodedEntries;
        }
    }

    if (empty($packageEntries)) {
        $packageEntries[] = [
            'brand' => trim((string) ($_POST['brand'] ?? '')),
            'product_name' => trim((string) ($_POST['product_name'] ?? '')),
            'variant' => trim((string) ($_POST['variant'] ?? '')),
            'base_unit' => trim((string) ($_POST['base_unit'] ?? 'unit')),
            'expiry_date' => trim((string) ($_POST['expiry_date'] ?? '')),
            'package_size_value' => trim((string) ($_POST['package_size_value'] ?? ($_POST['packets_per_bale'] ?? '0'))),
            'package_size_unit' => trim((string) ($_POST['package_size_unit'] ?? 'units')),
            'packages_received' => trim((string) ($_POST['packages_received'] ?? ($_POST['bales_received'] ?? '0'))),
            'cost_per_package' => trim((string) ($_POST['cost_per_package'] ?? ($_POST['cost_per_bale'] ?? '0'))),
            'retail_price_per_unit' => trim((string) ($_POST['retail_price_per_unit'] ?? ($_POST['retail_price_per_packet'] ?? '0'))),
            'wholesale_price_per_package' => trim((string) ($_POST['wholesale_price_per_package'] ?? ($_POST['wholesale_price_per_bale'] ?? '0'))),
            'barcode_package' => trim((string) ($_POST['barcode_package'] ?? '')),
            'barcode_wholesale' => trim((string) ($_POST['barcode_wholesale'] ?? '')),
            'barcode_1kg' => trim((string) ($_POST['barcode_1kg'] ?? '')),
            'barcode_0_5kg' => trim((string) ($_POST['barcode_0_5kg'] ?? '')),
            'barcode_0_25kg' => trim((string) ($_POST['barcode_0_25kg'] ?? '')),
            'barcode_5kg' => trim((string) ($_POST['barcode_5kg'] ?? '')),
            'repack_intake' => isset($_POST['repack_intake']) && $_POST['repack_intake'] === '1' ? '1' : '0',
            'repack_price_0_25kg' => trim((string) ($_POST['retail_price_0_25kg'] ?? '0')),
            'repack_price_0_5kg' => trim((string) ($_POST['retail_price_0_5kg'] ?? '0')),
            'repack_price_1kg' => trim((string) ($_POST['retail_price_1kg'] ?? '0')),
            'repack_price_5kg' => trim((string) ($_POST['retail_price_5kg'] ?? '0')),
        ];
    }

    $validatedEntries = [];
    foreach ($packageEntries as $entry) {
        $brand = trim((string) ($entry['brand'] ?? ''));
        $productName = trim((string) ($entry['product_name'] ?? ''));
        $variant = trim((string) ($entry['variant'] ?? ''));
        $baseUnit = trim((string) ($entry['base_unit'] ?? 'unit'));
        $expiryDate = trim((string) ($entry['expiry_date'] ?? ''));
        $packageSizeValue = floatval(str_replace(',', '', trim((string) ($entry['package_size_value'] ?? '0'))));
        $packageSizeUnit = trim((string) ($entry['package_size_unit'] ?? 'units'));
        $packagesReceived = floatval(str_replace(',', '', trim((string) ($entry['packages_received'] ?? '0'))));
        $costPerPackage = floatval(str_replace(',', '', trim((string) ($entry['cost_per_package'] ?? '0'))));
        $retailPrice = floatval(str_replace(',', '', trim((string) ($entry['retail_price_per_unit'] ?? '0'))));
        $wholesalePrice = floatval(str_replace(',', '', trim((string) ($entry['wholesale_price_per_package'] ?? '0'))));
        $barcodePackage = trim((string) ($entry['barcode_package'] ?? ''));
        $barcodeWholesale = trim((string) ($entry['barcode_wholesale'] ?? ''));
        $barcode1kg = trim((string) ($entry['barcode_1kg'] ?? ''));
        $barcodeHalfKg = trim((string) ($entry['barcode_0_5kg'] ?? ''));
        $barcodeQuarterKg = trim((string) ($entry['barcode_0_25kg'] ?? ''));
        $barcode5kg = trim((string) ($entry['barcode_5kg'] ?? ''));
        $repackIntake = isset($entry['repack_intake']) && ((string) $entry['repack_intake'] === '1' || (string) $entry['repack_intake'] === 'true');
        $repackQuarterKgPrice = floatval(str_replace(',', '', trim((string) ($entry['repack_price_0_25kg'] ?? '0'))));
        $repackHalfKgPrice = floatval(str_replace(',', '', trim((string) ($entry['repack_price_0_5kg'] ?? '0'))));
        $repackOneKgPrice = floatval(str_replace(',', '', trim((string) ($entry['repack_price_1kg'] ?? '0'))));
        $repackFiveKgPrice = floatval(str_replace(',', '', trim((string) ($entry['repack_price_5kg'] ?? '0'))));

        if ($brand === '' || $productName === '') {
            $errors[] = 'Please enter the brand and product name for each package intake entry.';
            continue;
        }

        if ($baseUnit === '') {
            $errors[] = 'Please enter the retail base unit for each package intake entry.';
            continue;
        }

        if ($packagesReceived <= 0) {
            $errors[] = 'Enter the number of packages received for each intake entry.';
            continue;
        }

        if ($packageSizeValue <= 0) {
            $errors[] = 'Enter the size of one package for each intake entry.';
            continue;
        }

        if ($retailPrice <= 0 || $wholesalePrice <= 0) {
            $errors[] = 'Enter both a retail price per unit and a wholesale amount for 1 product (used for 6+ sales) for each intake entry.';
            continue;
        }

        if ($costPerPackage <= 0) {
            $errors[] = 'Enter the cost per package for each intake entry.';
            continue;
        }

        $validatedEntries[] = [
            'brand' => $brand,
            'product_name' => $productName,
            'variant' => $variant,
            'base_unit' => $baseUnit,
            'expiry_date' => $expiryDate,
            'package_size_value' => $packageSizeValue,
            'package_size_unit' => $packageSizeUnit,
            'packages_received' => $packagesReceived,
            'cost_per_package' => $costPerPackage,
            'retail_price' => $retailPrice,
            'wholesale_price' => $wholesalePrice,
            'barcode_package' => $barcodePackage,
            'barcode_wholesale' => $barcodeWholesale,
            'barcode_1kg' => $barcode1kg,
            'barcode_half_kg' => $barcodeHalfKg,
            'barcode_quarter_kg' => $barcodeQuarterKg,
            'barcode_5kg' => $barcode5kg,
            'repack_intake' => $repackIntake,
            'repack_quarter_kg_price' => $repackQuarterKgPrice,
            'repack_half_kg_price' => $repackHalfKgPrice,
            'repack_one_kg_price' => $repackOneKgPrice,
            'repack_five_kg_price' => $repackFiveKgPrice,
        ];
    }

    $packageReceiptProvided = isset($_POST['receipt_provided']) && $_POST['receipt_provided'] === 'yes';
    if ($packageReceiptProvided && trim((string) ($_POST['receipt_number'] ?? '')) === '') {
      $errors[] = 'Please enter the receipt number.';
    }
    if ($packageReceiptProvided && trim((string) ($_POST['receipt_date'] ?? '')) === '') {
      $errors[] = 'Please enter the receipt date.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $existingSupplierId = trim((string) ($_POST['supplier_id'] ?? ''));
            if ($existingSupplierId === '') {
                $supplierSearchValue = trim((string) ($_POST['supplier_search'] ?? ''));
                if ($supplierSearchValue !== '') {
                    foreach ($suppliers as $supplierCandidate) {
                        if (strcasecmp((string) ($supplierCandidate['name'] ?? ''), $supplierSearchValue) === 0) {
                            $existingSupplierId = (string) $supplierCandidate['id'];
                            break;
                        }
                    }
                }
            }

            $supplierId = null;
            if (!empty($existingSupplierId)) {
                $supplierId = intval($existingSupplierId);
            }

            if ($supplierId === null) {
                throw new Exception('No supplier selected for stock intake.');
            }

            $receiptProvided = isset($_POST['receipt_provided']) && $_POST['receipt_provided'] === 'yes';
            $receiptNumber = trim((string) ($_POST['receipt_number'] ?? ''));
            $receiptDate = trim((string) ($_POST['receipt_date'] ?? ''));
            $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'cash'));
            $amountPaid = floatval(str_replace(',', '', trim((string) ($_POST['amount_paid'] ?? '0'))));
            $receiptPhotoFilename = saveReceiptPhotos();
            if (!$receiptProvided && $receiptPhotoFilename !== null) {
                $receiptProvided = true;
            }

            $totalCost = 0;
            foreach ($validatedEntries as $entry) {
                $totalCost += $entry['cost_per_package'] * $entry['packages_received'];
            }
            $balance = max(0, $totalCost - $amountPaid);
            $status = 'paid';
            if ($amountPaid <= 0) {
                $status = 'credit';
            } elseif ($balance > 0) {
                $status = 'partial';
            }

            $stmt = $pdo->prepare('INSERT INTO stock_intakes (supplier_id, receipt_provided, receipt_number, receipt_date, receipt_photo, total_cost, payment_method, amount_paid, balance, status) VALUES (:supplier_id, :receipt_provided, :receipt_number, :receipt_date, :receipt_photo, :total_cost, :payment_method, :amount_paid, :balance, :status)');
            $stmt->execute([
                'supplier_id' => $supplierId,
                'receipt_provided' => $receiptProvided ? 1 : 0,
                'receipt_number' => $receiptProvided ? $receiptNumber : null,
                'receipt_date' => $receiptProvided ? $receiptDate : null,
                'receipt_photo' => $receiptPhotoFilename,
                'total_cost' => $totalCost,
                'payment_method' => $paymentMethod,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'status' => $status,
            ]);
            $intakeId = $pdo->lastInsertId();

            foreach ($validatedEntries as $entry) {
                processPackageEntry($pdo, $intakeId, $entry);
            }
            $pdo->commit();
            $success = true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
          deleteReceiptPhotos($receiptPhotoFilename ?? null);
            $errors[] = 'Unable to save package intake: ' . $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $existingSupplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';
    if ($existingSupplierId === '') {
        $supplierSearchValue = trim((string) ($_POST['supplier_search'] ?? ''));
        if ($supplierSearchValue !== '') {
            foreach ($suppliers as $supplierCandidate) {
                if (strcasecmp((string) ($supplierCandidate['name'] ?? ''), $supplierSearchValue) === 0) {
                    $existingSupplierId = (string) $supplierCandidate['id'];
                    break;
                }
            }
        }
    }
    $newSupplierName = trim($_POST['supplier_name'] ?? '');
    $newSupplierKra = trim($_POST['supplier_kra'] ?? '');
    $newSupplierPhone = trim($_POST['supplier_phone'] ?? '');

    $receiptProvided = isset($_POST['receipt_provided']) && $_POST['receipt_provided'] === 'yes';
    $receiptNumber = trim($_POST['receipt_number'] ?? '');
    $receiptDate = trim($_POST['receipt_date'] ?? '');

    $productIds = $_POST['product_id'] ?? [];
    $productBrands = $_POST['product_brand'] ?? [];
    $productNames = $_POST['product_name'] ?? [];
    $productCategories = $_POST['product_category'] ?? [];
    $productBaseUnits = $_POST['product_base_unit'] ?? [];
    $productRetailPrices = $_POST['product_retail_price'] ?? [];
    $productWholesalePrices = $_POST['product_wholesale_price'] ?? [];
    $productExpiryDates = $_POST['expiry_date'] ?? [];

    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $amountPaid = floatval(str_replace(',', '', trim($_POST['amount_paid'] ?? '0')));

    $packageUnits = $_POST['package_unit'] ?? [];
    $packageSizeValues = $_POST['package_size_value'] ?? [];
    $packageSizeUnits = $_POST['package_size_unit'] ?? [];
    $lineQuantities = $_POST['line_quantity'] ?? [];
    $costPerPackage = $_POST['cost_per_package'] ?? [];

    $validProducts = [];
    $productCount = max(
        count($productIds),
        count($productBrands),
        count($productNames),
        count($productCategories),
        count($productBaseUnits),
        count($productRetailPrices),
        count($productWholesalePrices)
    );
    for ($i = 0; $i < $productCount; $i++) {
        $selectedProductId = trim($productIds[$i] ?? '');
        $brand = trim($productBrands[$i] ?? '');
        $name = trim($productNames[$i] ?? '');
        $category = trim($productCategories[$i] ?? '');
        $baseUnit = trim($productBaseUnits[$i] ?? '');
        $retailPrice = floatval(str_replace(',', '', trim($productRetailPrices[$i] ?? '0')));
        $wholesalePrice = floatval(str_replace(',', '', trim($productWholesalePrices[$i] ?? '0')));
        $expiryDate = trim($productExpiryDates[$i] ?? '');

        if ($selectedProductId === '' && $brand !== '' && $name !== '') {
            $existingProduct = findExistingProduct($pdo, $brand, $name);
            if ($existingProduct) {
                $selectedProductId = $existingProduct['id'];
                $category = $category !== '' ? $category : $existingProduct['category'];
                $baseUnit = $baseUnit !== '' ? $baseUnit : $existingProduct['base_unit'];
                if ($retailPrice <= 0) {
                    $retailPrice = floatval($existingProduct['retail_price'] ?: $existingProduct['price'] ?? 0);
                }
                if ($wholesalePrice <= 0) {
                    $wholesalePrice = floatval($existingProduct['wholesale_price'] ?? 0);
                }
            }
        }

        $productPackageUnits = $packageUnits[$i] ?? [];
        $productPackageSizes = $packageSizeValues[$i] ?? [];
        $productPackageSizeUnits = $packageSizeUnits[$i] ?? [];
        $productLineQuantities = $lineQuantities[$i] ?? [];
        $productCostPerPackage = $costPerPackage[$i] ?? [];

        $productLines = [];
        for ($j = 0; $j < count($productPackageUnits); $j++) {
            $unit = trim($productPackageUnits[$j] ?? '');
            $sizeValue = floatval(str_replace(',', '', trim($productPackageSizes[$j] ?? '0')));
            $sizeUnit = trim($productPackageSizeUnits[$j] ?? '');
            $quantity = floatval(str_replace(',', '', trim($productLineQuantities[$j] ?? '0')));
            $cost = floatval(str_replace(',', '', trim($productCostPerPackage[$j] ?? '0')));

            if ($unit === '' || $sizeValue <= 0 || $quantity <= 0) {
                continue;
            }

            $baseQty = $quantity * $sizeValue;
            $lineTotal = $quantity * $cost;
            $productLines[] = [
                'package_unit' => $unit,
                'package_size_value' => $sizeValue,
                'package_size_unit' => $sizeUnit,
                'quantity' => $quantity,
                'cost_per_package' => $cost,
                'base_quantity' => $baseQty,
                'line_total' => $lineTotal,
            ];
        }

        if ($selectedProductId === '' && $brand === '' && $name === '' && $category === '' && $baseUnit === '' && count($productLines) === 0) {
            continue;
        }

        if (count($productLines) === 0) {
            $errors[] = 'Please add at least one packaging line for each product.';
        }

        if ($selectedProductId === '' && ($brand === '' || $name === '' || $category === '' || $baseUnit === '')) {
            $errors[] = 'Please enter brand, name, category, and base unit for each new product.';
        }

        if ($selectedProductId === '' && ($retailPrice <= 0 || $wholesalePrice <= 0)) {
            $errors[] = 'Please enter retail and wholesale unit prices for each new product.';
        }

        if (count($productLines) > 0) {
            $validProducts[] = [
                'product_id' => $selectedProductId,
                'brand' => $brand,
                'name' => $name,
                'category' => $category,
                'base_unit' => $baseUnit,
                'retail_price' => $retailPrice,
                'wholesale_price' => $wholesalePrice,
                'expiry_date' => $expiryDate,
                'lines' => $productLines,
            ];
        }
    }

    if (count($validProducts) === 0) {
        $errors[] = 'Please add at least one product with packaging lines.';
    }

    $totalCost = 0;
    foreach ($validProducts as $product) {
        foreach ($product['lines'] as $line) {
            $totalCost += $line['line_total'];
        }
    }

    if (empty($existingSupplierId) && $newSupplierName === '') {
        $errors[] = 'Please select an existing supplier or create a new one.';
    }

    if ($receiptProvided) {
        if ($receiptNumber === '') {
            $errors[] = 'Please enter the receipt number.';
        }
        if ($receiptDate === '') {
            $errors[] = 'Please enter the receipt date.';
        }
    }

    $balance = max(0, $totalCost - $amountPaid);
    $status = 'paid';
    if ($amountPaid <= 0) {
        $status = 'credit';
    } elseif ($balance > 0) {
        $status = 'partial';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($existingSupplierId !== '') {
                $supplierId = intval($existingSupplierId);
            } else {
                $stmt = $pdo->prepare('INSERT INTO suppliers (name, kra_pin, phone) VALUES (:name, :kra, :phone)');
                $stmt->execute(['name' => $newSupplierName, 'kra' => $newSupplierKra, 'phone' => $newSupplierPhone]);
                $supplierId = $pdo->lastInsertId();
            }

            $receiptPhotoFilename = saveReceiptPhotos();

            if (!$receiptProvided && $receiptPhotoFilename !== null) {
                $receiptProvided = true;
            }

            $stmt = $pdo->prepare('INSERT INTO stock_intakes (supplier_id, receipt_provided, receipt_number, receipt_date, receipt_photo, total_cost, payment_method, amount_paid, balance, status) VALUES (:supplier_id, :receipt_provided, :receipt_number, :receipt_date, :receipt_photo, :total_cost, :payment_method, :amount_paid, :balance, :status)');
            $stmt->execute([
                'supplier_id' => $supplierId,
                'receipt_provided' => $receiptProvided ? 1 : 0,
                'receipt_number' => $receiptProvided ? $receiptNumber : null,
                'receipt_date' => $receiptProvided ? $receiptDate : null,
                'receipt_photo' => $receiptPhotoFilename,
                'total_cost' => $totalCost,
                'payment_method' => $paymentMethod,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'status' => $status,
            ]);
            $intakeId = $pdo->lastInsertId();

            // Check PHP input limits before creating any database records.
            $maxInputVars = intval(ini_get('max_input_vars')) ?: 1000;
            $estimatedPerProductVars = 25;
            $estimatedOverhead = 200;
            $estimatedNeeded = $productCount * $estimatedPerProductVars + $estimatedOverhead;
            if ($maxInputVars > 0 && $estimatedNeeded > $maxInputVars) {
              throw new Exception('Form is too large for the current PHP input limit. Increase max_input_vars before submitting this intake.');
            }

            foreach ($validProducts as $productData) {
                processProductDataEntry($pdo, $intakeId, $productData);
            }
            $pdo->commit();
            header('Location: stock_intake.php?success=1');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
          deleteReceiptPhotos($receiptPhotoFilename ?? null);
            $errors[] = 'Unable to save stock intake: ' . $e->getMessage();
        }
    }
}

$success = $success || (isset($_GET['success']) && $_GET['success'] == '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stock Intake — SMART POS SYSTEM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/splash-screen.css">
  <script>
    window.STOCK_INTAKE_PRODUCTS = <?php echo json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  </script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen overflow-x-hidden splash-loading">
  <div class="splash-screen" id="splashScreen">
    <div class="splash-screen-logo">
      <img src="colour_logo.jpg" alt="Logo">
    </div>
    <div class="splash-screen-spinner"></div>
    <div class="splash-screen-text">LOADING</div>
  </div>
  <div class="container mx-auto px-4 py-6 max-w-full sm:max-w-6xl">
      <div id="toastContainer" class="fixed left-4 bottom-4 z-50 space-y-3"></div>
    <div class="mb-6 flex items-center gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
      <img src="colour_logo.jpg" alt="POS2 logo" class="h-14 w-14 rounded-2xl object-cover shadow-sm">
      <div>
        <p class="text-lg font-semibold text-slate-900">SMART POS SYSTEM</p>
        <p class="text-sm text-slate-500">Stock intake</p>
      </div>
    </div>
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <a href="index.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100">Back to dashboard</a>
    </div>

    <div class="mb-6 rounded-3xl bg-slate-900 p-4 sm:p-6 shadow-xl text-white">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Stock intake wizard</p>
          <h2 class="mt-2 text-2xl font-semibold">Step <span id="currentStepLabel">1</span> of 6</h2>
        </div>
        <div class="text-right">
          <p class="text-sm text-slate-300">Current stage</p>
          <p id="currentStepName" class="mt-1 font-medium text-white">Supplier</p>
        </div>
      </div>

      <div class="mt-6">
        <div class="relative h-2 overflow-hidden rounded-full bg-slate-700">
          <div id="progressFill" class="absolute left-0 top-0 h-full w-0 rounded-full bg-emerald-400 transition-all duration-500 ease-out"></div>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-2 text-[10px] uppercase tracking-[0.3em] text-slate-400 sm:grid-cols-3 xl:grid-cols-6">
          <span class="step-badge rounded-full bg-emerald-400 px-3 py-2 text-white">Supplier</span>
          <span class="step-badge rounded-full bg-slate-800 px-3 py-2">Receipt</span>
          <span class="step-badge rounded-full bg-slate-800 px-3 py-2">Product</span>
          <span class="step-badge rounded-full bg-slate-800 px-3 py-2">Packaging</span>
          <span class="step-badge rounded-full bg-slate-800 px-3 py-2">Cost</span>
          <span class="step-badge rounded-full bg-slate-800 px-3 py-2">Review</span>
        </div>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
        <p class="font-semibold">Stock intake saved successfully.</p>
        <p class="text-sm mt-1">The product inventory and supplier intake have been recorded.</p>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="mb-6 rounded-3xl border border-rose-200 bg-rose-50 p-5 text-rose-900">
        <ul class="list-disc space-y-2 pl-5">
          <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form id="intakeForm" method="POST" enctype="multipart/form-data" novalidate class="space-y-8 max-w-full">
      <input type="hidden" name="supplier_id" id="supplierId">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

<div class="rounded-[2rem] bg-white/95 p-6 shadow-[0_25px_60px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 backdrop-blur-sm transition-all duration-300 hover:-translate-y-0.5">
      <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold uppercase tracking-[0.28em] text-emerald-700">Step 1</span>
          <h2 class="mt-3 text-2xl font-semibold tracking-tight text-slate-900">Supplier</h2>
        </div>
        <button type="button" data-step="1" class="step-nav w-full sm:w-auto rounded-full bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-lg transition hover:bg-slate-800">Go to step</button>
        </div>

        <div class="space-y-6" id="step1">
          <div class="relative">
            <label class="block text-sm font-semibold text-slate-700">Search supplier</label>
            <input id="supplierSearch" name="supplier_search" type="text" value="<?php echo htmlspecialchars($_POST['supplier_search'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search supplier..." class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0" autocomplete="off">
            <div id="supplierSuggestions" class="absolute left-0 right-0 z-20 mt-2 hidden rounded-3xl border border-slate-200 bg-white shadow-xl"></div>
          </div>

          <div class="grid gap-4 grid-cols-1 sm:grid-cols-2">
            <?php foreach ($suppliers as $supplier): ?>
              <button type="button" class="supplier-item rounded-[1.75rem] border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-5 text-left shadow-[0_18px_50px_rgba(15,23,42,0.06)] transition duration-300 hover:-translate-y-0.5 hover:border-slate-300 w-full min-w-0 text-left" data-supplier-id="<?php echo $supplier['id']; ?>" data-supplier-name="<?php echo htmlspecialchars($supplier['name']); ?>" data-supplier-kra="<?php echo htmlspecialchars($supplier['kra_pin']); ?>" data-supplier-phone="<?php echo htmlspecialchars($supplier['phone']); ?>">
                <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($supplier['name']); ?></p>
                <p class="mt-1 text-sm text-slate-500">KRA: <?php echo htmlspecialchars($supplier['kra_pin'] ?: '—'); ?></p>
                <p class="text-sm text-slate-500">Phone: <?php echo htmlspecialchars($supplier['phone'] ?: '—'); ?></p>
              </button>
            <?php endforeach; ?>
          </div>

          <div id="newSupplierSection" class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <h3 class="text-lg font-semibold">Create New Supplier</h3>
            <div class="mt-4 grid gap-4 grid-cols-1 sm:grid-cols-2">
              <label class="block w-full min-w-0">
                <span class="text-sm font-medium text-slate-700">Supplier name</span>
                <input name="supplier_name" id="supplierName" type="text" value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-700">KRA PIN</span>
                <input name="supplier_kra" type="text" value="<?php echo htmlspecialchars($_POST['supplier_kra'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
              </label>
              <label class="block sm:col-span-2">
                <span class="text-sm font-medium text-slate-700">Phone / contact</span>
                <input name="supplier_phone" type="text" value="<?php echo htmlspecialchars($_POST['supplier_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
              </label>
            </div>
            <div class="mt-4">
              <button type="button" id="createSupplierButton" class="w-full sm:w-auto rounded-3xl bg-emerald-600 px-5 py-3 text-white font-semibold">Create supplier</button>
            </div>
          </div>

          <div class="flex flex-col gap-3 sm:flex-row sm:justify-between step-actions">
            <button type="button" class="next-step w-full sm:w-auto rounded-3xl bg-slate-900 px-6 py-3 text-white transition hover:bg-slate-800" data-next="2">Next →</button>
          </div>
        </div>
      </div>

      <div id="step2" class="hidden rounded-3xl bg-white p-6 shadow-sm space-y-6">
          <div class="grid gap-4 grid-cols-1 sm:grid-cols-2">
            <label class="group rounded-[1.75rem] border border-slate-200 bg-white p-5 text-center transition duration-300 hover:border-emerald-400 hover:bg-emerald-50 cursor-pointer">
              <input type="radio" name="receipt_provided" value="yes" class="mr-2" <?php echo (($_POST['receipt_provided'] ?? 'yes') === 'yes') ? 'checked' : ''; ?>>
              <span class="block text-lg font-semibold text-slate-900">Receipt Provided</span>
            </label>
            <label class="group rounded-[1.75rem] border border-slate-200 bg-white p-5 text-center transition duration-300 hover:border-slate-500 hover:bg-slate-100 cursor-pointer">
              <input type="radio" name="receipt_provided" value="no" class="mr-2" <?php echo (($_POST['receipt_provided'] ?? 'yes') === 'no') ? 'checked' : ''; ?>>
              <span class="block text-lg font-semibold text-slate-900">No Receipt Provided</span>
            </label>
          </div>

          <div id="receiptDetails" class="space-y-4">
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Receipt number</span>
              <input name="receipt_number" type="text" value="<?php echo htmlspecialchars($_POST['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
            </label>
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Receipt date</span>
              <input name="receipt_date" type="date" value="<?php echo htmlspecialchars($_POST['receipt_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
            </label>
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Take receipt photo</span>
              <div class="mt-2 flex flex-col gap-3 rounded-3xl border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center">
                <button type="button" id="openReceiptCameraButton" class="rounded-3xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">Open camera</button>
                <span id="receiptPhotoLabel" class="text-sm text-slate-600">No photo selected yet</span>
                <input id="receiptPhotoInput" name="receipt_photo[]" type="file" accept="image/*" capture="environment" multiple class="hidden">
              </div>
            </label>
          </div>

          <div class="flex flex-col gap-3 sm:flex-row sm:justify-between step-actions">
            <button type="button" class="prev-step w-full sm:w-auto rounded-3xl border border-slate-300 bg-white px-6 py-3 text-slate-700 transition hover:bg-slate-50" data-prev="1">← Back</button>
            <button type="button" class="next-step w-full sm:w-auto rounded-3xl bg-slate-900 px-6 py-3 text-white transition hover:bg-slate-800" data-next="3">Next →</button>
          </div>
      </div>

      <div id="step3" class="hidden rounded-3xl bg-white p-6 shadow-sm space-y-6">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
              <h2 class="text-2xl font-semibold text-slate-900">Products on this receipt</h2>
              <p class="text-sm text-slate-500">Add each product line separately, then add packaging details for that product.</p>
            </div>
            <div class="flex flex-wrap gap-3">
              <button type="button" id="addBaleProduct" class="rounded-full border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100">+ Add package entry</button>
              <button type="button" id="pasteReceiptBtn" class="rounded-full border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 transition hover:bg-slate-50">Paste receipt text</button>
            </div>
          </div>

          <div id="productBlocks" class="space-y-6"></div>

          <div class="flex flex-col gap-3 sm:flex-row sm:justify-between step-actions">
            <button type="button" class="prev-step w-full sm:w-auto rounded-3xl border border-slate-300 bg-white px-6 py-3 text-slate-700 transition hover:bg-slate-50" data-prev="2">← Back</button>
            <button type="button" class="next-step w-full sm:w-auto rounded-3xl bg-slate-900 px-6 py-3 text-white transition hover:bg-slate-800" data-next="4">Next →</button>
          </div>
      </div>

      <div id="step4" class="hidden rounded-3xl bg-white p-6 shadow-sm space-y-6">
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <h3 class="text-lg font-semibold">Products & packaging summary</h3>
            <div id="productPackageSummary" class="mt-4 space-y-4"></div>
          </div>

          <div class="flex flex-col gap-3 sm:flex-row sm:justify-between step-actions">
            <button type="button" class="prev-step w-full sm:w-auto rounded-3xl border border-slate-300 bg-white px-6 py-3 text-slate-700 transition hover:bg-slate-50" data-prev="3">← Back</button>
            <button type="button" class="next-step w-full sm:w-auto rounded-3xl bg-slate-900 px-6 py-3 text-white transition hover:bg-slate-800" data-next="5">Next →</button>
          </div>
      </div>

      <div id="step5" class="hidden rounded-3xl bg-white p-6 shadow-sm space-y-6">
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5 text-slate-700">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="text-sm uppercase tracking-[0.2em] text-slate-500">Total amount due</p>
                <p id="calculatedTotalCost" class="mt-2 text-3xl font-semibold text-slate-900">KES 0.00</p>
              </div>
              <div class="text-right text-sm text-slate-500">
                <p>Total Amount from packaging lines.</p>
              </div>
            </div>
          </div>

          <div class="grid gap-4 grid-cols-1 sm:grid-cols-2">
            <label class="block w-full min-w-0">
              <span class="text-sm font-medium text-slate-700">Payment method</span>
              <select name="payment_method" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
                <option value="cash" <?php echo (($_POST['payment_method'] ?? 'cash') === 'cash') ? 'selected' : ''; ?>>Cash</option>
                <option value="mpesa" <?php echo (($_POST['payment_method'] ?? 'cash') === 'mpesa') ? 'selected' : ''; ?>>M-Pesa</option>
                <option value="bank" <?php echo (($_POST['payment_method'] ?? 'cash') === 'bank') ? 'selected' : ''; ?>>Bank</option>
                <option value="credit" <?php echo (($_POST['payment_method'] ?? 'cash') === 'credit') ? 'selected' : ''; ?>>Credit</option>
              </select>
            </label>
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Amount paid</span>
              <input name="amount_paid" type="number" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($_POST['amount_paid'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
            </label>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5 text-slate-700">
            <p>Total purchase cost is calculated from the intake lines and shown above.</p>
          </div>

          <div class="flex flex-col gap-3 sm:flex-row sm:justify-between step-actions">
            <button type="button" class="prev-step w-full sm:w-auto rounded-3xl border border-slate-300 bg-white px-6 py-3 text-slate-700 transition hover:bg-slate-50" data-prev="4">← Back</button>
            <button type="button" class="next-step w-full sm:w-auto rounded-3xl bg-slate-900 px-6 py-3 text-white transition hover:bg-slate-800" data-next="6">Next →</button>
          </div>
      </div>

      <div id="step6" class="hidden rounded-3xl bg-white p-6 shadow-sm space-y-6">
          <div id="reviewSummary" class="space-y-6"></div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5 text-slate-700">
            <p>Review generated barcode labels before confirming stock intake.</p>
            <p class="mt-2 text-sm text-slate-500">When you press Print Barcode Labels, load the label printer paper and select the CS30 printer in the print dialog. Press OK to continue printing.</p>
          </div>
          <div class="flex flex-col gap-3 sm:flex-row sm:justify-between step-actions">
            <button type="button" class="prev-step w-full sm:w-auto rounded-3xl border border-slate-300 bg-white px-6 py-3 text-slate-700 transition hover:bg-slate-50" data-prev="5">← Back</button>
            <button type="button" id="printBarcodeLabelsButton" class="w-full sm:w-auto rounded-3xl border border-slate-300 bg-slate-900 px-6 py-3 text-white transition hover:bg-slate-800">Print Barcode Labels</button>
            <button type="submit" id="confirmStockIntakeButton" class="w-full sm:w-auto rounded-3xl bg-emerald-600 px-6 py-3 text-white transition hover:bg-emerald-700">✓ Confirm Stock Intake</button>
          </div>
      </div>
    </form>
  </div>

  <script>
    const SUPPLIERS = <?php echo json_encode($suppliers, JSON_HEX_TAG); ?>;
    window.STOCK_INTAKE_ERRORS = <?php echo json_encode($errors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.STOCK_INTAKE_SUCCESS = <?php echo $success ? 'true' : 'false'; ?>;
  </script>
  <?php $stockIntakeJsPath = 'assets/js/stock-intake.js'; ?>
  <script src="<?= $stockIntakeJsPath ?>?v=<?= file_exists($stockIntakeJsPath) ? filemtime($stockIntakeJsPath) : time() ?>"></script>
  <script>
    window.addEventListener('load', function() {
      const splashScreen = document.getElementById('splashScreen');
      if (splashScreen) {
        splashScreen.classList.add('hidden');
        document.body.classList.remove('splash-loading');
      }
    });
  </script>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
