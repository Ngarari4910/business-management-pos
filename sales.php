<?php
session_start();
require __DIR__ . '/security.php';
requireCashierPage();
require __DIR__ . '/db.php';
require __DIR__ . '/payment_flow.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = intval($_POST['product_id'] ?? 0);
    $saleQuantity = floatval(str_replace(',', '', trim($_POST['sale_quantity'] ?? '0')));
    $unitPrice = floatval(str_replace(',', '', trim($_POST['unit_price'] ?? '0')));
    $amountPaid = floatval(str_replace(',', '', trim($_POST['amount_paid'] ?? '0')));
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');

    if ($productId <= 0) {
        $errors[] = 'Select a product to sell.';
    }
    if ($saleQuantity <= 0) {
        $errors[] = 'Enter a sale quantity greater than zero.';
    }
    if ($unitPrice <= 0) {
        $errors[] = 'Enter a sale price per unit.';
    }
    if (!in_array($paymentMethod, ['cash', 'bank', 'credit'], true)) {
        $paymentMethod = 'cash';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();

        if (!$product) {
            $errors[] = 'Selected product was not found.';
        } elseif ($saleQuantity > floatval($product['stock'])) {
            $errors[] = 'Sale quantity cannot exceed current stock.';
        }
    }

    if (empty($errors)) {
        $totalPrice = $saleQuantity * $unitPrice;
        $balance = max(0, $totalPrice - $amountPaid);
        $saleType = $saleQuantity >= 6 ? 'Wholesale sale' : 'Retail sale';

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('UPDATE products SET stock = stock - :quantity WHERE id = :id');
            $stmt->execute(['quantity' => $saleQuantity, 'id' => $productId]);

            $reason = sprintf(
                '%s of %s %s at KES %.2f (%s paid via %s)',
                $saleType,
                $saleQuantity,
                $product['base_unit'],
                $unitPrice,
                number_format($amountPaid, 2),
                ucfirst($paymentMethod)
            );

            $stmt = $pdo->prepare('INSERT INTO stock_movements (product_id, change_quantity, reason) VALUES (:product_id, :change_quantity, :reason)');
            $stmt->execute([
                'product_id' => $productId,
                'change_quantity' => -$saleQuantity,
                'reason' => $reason,
            ]);

            $pdo->commit();
            header('Location: sales.php?success=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Unable to record sale: ' . $e->getMessage();
        }
    }
}

$success = isset($_GET['success']) && $_GET['success'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Entry — SMART POS SYSTEM</title>
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
  <div class="container mx-auto px-4 py-6 max-w-5xl">
    <div class="mb-6 flex items-center gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
      <img src="colour_logo.jpg" alt="POS2 logo" class="h-14 w-14 rounded-2xl object-cover shadow-sm">
      <div>
        <p class="text-lg font-semibold text-slate-900">SMART POS SYSTEM</p>
        <p class="text-sm text-slate-500">Sales entry</p>
      </div>
    </div>
    <div class="mb-8">
      <div class="sticky top-0 z-30 -mx-4 mb-4 bg-slate-50/95 px-4 py-3 backdrop-blur-sm shadow-sm shadow-slate-200/30 border-b border-slate-200/70 sm:static sm:mx-0 sm:mb-0 sm:bg-transparent sm:px-0 sm:py-0 sm:shadow-none sm:border-b-0">
        <div class="flex flex-wrap gap-3 justify-center sm:justify-end">
          <a href="index.php" class="rounded-3xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100">Back to dashboard</a>
          <a href="stock_intake.php" class="rounded-3xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">New Stock Intake</a>
        </div>
      </div>
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-4xl font-bold">Record a Sale</h1>
          <p class="text-slate-600 mt-1">Create a retail or wholesale sale and update the stock movement.</p>
        </div>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
        <p class="font-semibold">Sale recorded successfully.</p>
        <p class="text-sm mt-1">Stock and movement records were updated.</p>
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

    <form id="salesForm" method="POST" class="space-y-8 rounded-[2rem] bg-white/95 p-6 shadow-[0_25px_60px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 backdrop-blur-sm transition-all duration-300 hover:-translate-y-0.5 max-w-full overflow-hidden">
      <div class="grid gap-4 grid-cols-1 sm:grid-cols-2">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Choose product</span>
          <select id="productSelect" name="product_id" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
            <option value="">Select a product</option>
            <?php foreach ($products as $product): ?>
              <option value="<?php echo $product['id']; ?>" data-stock="<?php echo $product['stock']; ?>" data-base-unit="<?php echo htmlspecialchars($product['base_unit']); ?>" data-price="<?php echo $product['price']; ?>" data-retail-price="<?php echo $product['retail_price']; ?>" data-wholesale-price="<?php echo $product['wholesale_price']; ?>" data-name="<?php echo htmlspecialchars($product['brand'] . ' ' . $product['name']); ?>">
                <?php echo htmlspecialchars($product['brand'] . ' ' . $product['name'] . ' — Stock: ' . number_format($product['stock'], 3) . ' ' . $product['base_unit']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
          <p class="text-sm font-medium text-slate-700">Current stock</p>
          <p id="currentStockAmount" class="mt-3 text-2xl font-semibold text-slate-900">0</p>
          <p id="currentBaseUnit" class="text-sm text-slate-500">unit</p>
        </div>
      </div>

      <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p class="text-sm uppercase tracking-[0.2em] text-slate-500">Quick quantities</p>
            <p class="mt-2 text-sm text-slate-700">Tap a button to sell retail or wholesale quickly.</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" data-qty="0.25" class="quick-qty rounded-full border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-100">0.25</button>
            <button type="button" data-qty="0.5" class="quick-qty rounded-full border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-100">0.5</button>
            <button type="button" data-qty="1" class="quick-qty rounded-full border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-100">1</button>
            <button type="button" data-qty="6" class="quick-qty rounded-full border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-100">6</button>
            <button type="button" data-qty="25" class="quick-qty rounded-full border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-100">25</button>
          </div>
        </div>
      </div>

      <div class="grid gap-4 grid-cols-1 sm:grid-cols-2">
        <label class="block w-full min-w-0">
          <span class="text-sm font-medium text-slate-700">Quantity to sell</span>
          <input id="saleQuantity" name="sale_quantity" type="number" min="0.25" step="0.25" value="0.25" placeholder="0.25" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
        <div>
          <span class="text-sm font-medium text-slate-700">Unit price</span>
          <input id="saleUnitPriceDisplay" type="text" readonly class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-700 focus:border-slate-400 focus:ring-0" value="KES 0.00">
          <input id="saleUnitPrice" name="unit_price" type="hidden" value="0">
        </div>
      </div>

      <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="text-sm uppercase tracking-[0.2em] text-slate-500">Sale summary</p>
            <p id="saleTypeLabel" class="mt-2 text-slate-700">Retail sale or wholesale sale appears here.</p>
          </div>
          <p class="text-3xl font-semibold text-slate-900" id="saleTotal">KES 0.00</p>
        </div>
      </div>

      <div class="grid gap-4 grid-cols-1 sm:grid-cols-2">
        <label class="block w-full min-w-0">
          <span class="text-sm font-medium text-slate-700">Payment method</span>
          <select name="payment_method" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
            <option value="credit">Credit</option>
          </select>
        </label>
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Amount paid</span>
          <input id="amountPaid" name="amount_paid" type="number" min="0" step="0.01" value="0" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
      </div>

      <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5 text-slate-700">
        <p class="text-sm">Balance due: <span id="saleBalance" class="font-semibold text-slate-900">KES 0.00</span></p>
      </div>

      <div class="flex justify-end">
        <button type="submit" class="rounded-3xl bg-emerald-600 px-8 py-4 text-sm font-semibold text-white transition hover:bg-emerald-700">Record Sale</button>
      </div>
    </form>
  </div>

  <script src="assets/js/sales.js"></script>
  <script>
    window.addEventListener('load', function() {
      const splashScreen = document.getElementById('splashScreen');
      if (splashScreen) {
        splashScreen.classList.add('hidden');
        document.body.classList.remove('splash-loading');
      }
    });
  </script>
  <script src="assets/js/cashier-session.js"></script>
</body>
</html>
