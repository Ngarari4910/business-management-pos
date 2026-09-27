<?php
session_start();
require __DIR__ . '/security.php';
requireCashierPage();
require __DIR__ . '/db.php';

$stmt = $pdo->query(
    "SELECT p.id, p.brand, p.name, p.category, p.base_unit, p.stock, p.retail_price, p.wholesale_price,
            COALESCE((SELECT SUM(sb.base_quantity_remaining)
                      FROM stock_batches sb
                      WHERE sb.product_id = p.id
                        AND sb.base_quantity_remaining > 0
                        AND (sb.expiry_date IS NULL OR sb.expiry_date > CURDATE())), 0) AS available_stock
     FROM products p
     WHERE p.stock > 0
       AND NOT EXISTS (
         SELECT 1
         FROM stock_batches sb
         WHERE sb.product_id = p.id
           AND sb.base_quantity_remaining > 0
           AND (sb.expiry_date IS NULL OR sb.expiry_date > CURDATE())
     )
     ORDER BY p.brand ASC, p.name ASC
     LIMIT 500"
);
$outOfStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Out of Stock — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <header class="mb-6 flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p class="text-sm uppercase tracking-[0.25em] text-slate-400">Cashier inventory</p>
        <h1 class="mt-2 text-3xl font-semibold text-slate-900">Out of stock products</h1>
        <p class="mt-1 text-sm text-slate-500">Products with no remaining non-expired stock available for sale.</p>
      </div>
      <nav class="flex flex-wrap gap-2">
        <a href="index.php" class="rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">Back to sales</a>
        <a href="cashier_shift.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">My shift</a>
      </nav>
    </header>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="mb-5 flex items-center justify-between gap-3">
        <div>
          <h2 class="text-xl font-semibold text-slate-900">Stock list</h2>
          <p class="mt-1 text-sm text-slate-500"><?php echo number_format(count($outOfStockProducts)); ?> product<?php echo count($outOfStockProducts) === 1 ? '' : 's'; ?> unavailable</p>
        </div>
      </div>

      <?php if ($outOfStockProducts === []): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-800">All products currently have sellable stock.</div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-left text-sm text-slate-700">
            <thead class="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 font-semibold">Product</th>
                <th class="px-4 py-3 font-semibold">Category</th>
                <th class="px-4 py-3 font-semibold">Current stock</th>
                <th class="px-4 py-3 font-semibold">Retail price</th>
                <th class="px-4 py-3 font-semibold">Wholesale price</th>
                <th class="px-4 py-3 font-semibold">Reason</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <?php foreach ($outOfStockProducts as $product): ?>
                <?php $reason = (float) ($product['stock'] ?? 0) > 0 ? 'Remaining stock is expired' : 'No stock remaining'; ?>
                <tr>
                  <td class="px-4 py-4 font-semibold text-slate-900"><?php echo htmlspecialchars(trim(($product['brand'] ?? '') . ' ' . ($product['name'] ?? ''))); ?></td>
                  <td class="px-4 py-4"><?php echo htmlspecialchars($product['category'] ?? '—'); ?></td>
                  <td class="px-4 py-4"><?php echo number_format((float) ($product['stock'] ?? 0), 3); ?> <?php echo htmlspecialchars($product['base_unit'] ?? 'unit'); ?></td>
                  <td class="px-4 py-4">KES <?php echo number_format((float) ($product['retail_price'] ?? 0), 2); ?></td>
                  <td class="px-4 py-4">KES <?php echo number_format((float) ($product['wholesale_price'] ?? 0), 2); ?></td>
                  <td class="px-4 py-4 text-rose-700"><?php echo htmlspecialchars($reason); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <script src="assets/js/cashier-session.js"></script>
</body>
</html>
