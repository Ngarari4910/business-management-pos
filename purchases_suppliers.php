<?php
session_start();
require __DIR__ . '/security.php';
requireAdminPage();
require __DIR__ . '/db.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;
$productSearch = trim((string) ($_GET['product_search'] ?? ''));

$purchaseFilterSql = '';
$purchaseFilterParams = [];
if ($productSearch !== '') {
    $searchTerms = preg_split('/\s+/', strtolower($productSearch), -1, PREG_SPLIT_NO_EMPTY);
    $searchConditions = [];
    foreach ($searchTerms as $index => $searchTerm) {
        $parameterName = 'product_search_' . $index;
        $searchConditions[] = 'LOWER(CONCAT_WS(" ", search_p.brand, search_p.name)) LIKE :' . $parameterName;
        $purchaseFilterParams[$parameterName] = '%' . $searchTerm . '%';
    }
    $purchaseFilterSql = ' WHERE EXISTS (
        SELECT 1
        FROM stock_intake_lines search_sil
        JOIN products search_p ON search_p.id = search_sil.product_id
        WHERE search_sil.stock_intake_id = si.id
          AND ' . implode(' AND ', $searchConditions) . '
    )';
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_intakes si' . $purchaseFilterSql);
$countStmt->execute($purchaseFilterParams);
$totalPurchasesCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalPurchasesCount / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

function formatCurrency($amount): string {
    return 'KES ' . number_format(floatval($amount), 2);
}

try {
    $stmt = $pdo->prepare(
        'SELECT si.*, s.name AS supplier_name
         FROM stock_intakes si
         LEFT JOIN suppliers s ON s.id = si.supplier_id'
         . $purchaseFilterSql
         . ' ORDER BY si.created_at DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($purchaseFilterParams as $name => $value) {
        $stmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('Purchases query error: ' . $e->getMessage());
    $purchases = [];
}

$stockIntakeIds = array_map(static fn ($purchase) => (int) ($purchase['id'] ?? 0), $purchases);
$purchaseProductRows = [];
if ($stockIntakeIds !== []) {
    $placeholders = implode(',', array_fill(0, count($stockIntakeIds), '?'));
    $stockIntakeLineStmt = $pdo->prepare(
        'SELECT sil.stock_intake_id,
                sil.product_id,
                p.brand,
                p.name,
                p.category,
                p.base_unit,
                sil.package_unit,
                sil.package_size_value,
                sil.package_size_unit,
                sil.quantity,
                sil.base_quantity,
                sil.cost_per_package,
                sil.total_cost
         FROM stock_intake_lines sil
         JOIN products p ON p.id = sil.product_id
        WHERE sil.stock_intake_id IN (' . $placeholders . ')
        ORDER BY sil.stock_intake_id ASC, p.brand ASC, p.name ASC'
    );
    $stockIntakeLineStmt->execute($stockIntakeIds);
    $purchaseProductRows = $stockIntakeLineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$purchaseProductMap = [];
foreach ($purchaseProductRows as $line) {
    $stockIntakeId = (int) ($line['stock_intake_id'] ?? 0);
    if ($stockIntakeId <= 0) {
        continue;
    }

    $purchaseProductMap[$stockIntakeId][] = [
        'product_id' => (int) ($line['product_id'] ?? 0),
        'brand' => (string) ($line['brand'] ?? ''),
        'name' => (string) ($line['name'] ?? ''),
        'category' => (string) ($line['category'] ?? ''),
        'base_unit' => (string) ($line['base_unit'] ?? ''),
        'package_unit' => (string) ($line['package_unit'] ?? ''),
        'package_size_value' => (float) ($line['package_size_value'] ?? 0),
        'package_size_unit' => (string) ($line['package_size_unit'] ?? ''),
        'quantity' => (float) ($line['quantity'] ?? 0),
        'base_quantity' => (float) ($line['base_quantity'] ?? 0),
        'cost_per_package' => (float) ($line['cost_per_package'] ?? 0),
        'total_cost' => (float) ($line['total_cost'] ?? 0),
    ];
}

$purchaseLookup = [];
foreach ($purchases as $purchase) {
    $purchaseId = (int) ($purchase['id'] ?? 0);
    if ($purchaseId > 0) {
        $purchaseLookup[$purchaseId] = [
            'receipt_number' => (string) ($purchase['receipt_number'] ?? ''),
            'status' => (string) ($purchase['status'] ?? ''),
            'receipt_photo' => (string) ($purchase['receipt_photo'] ?? ''),
        ];
    }
}

$groupedPurchases = [];
$totalPurchases = 0.0;
$totalOutstanding = 0.0;
$totalPaid = 0.0;
$receiptCount = 0;

foreach ($purchases as $purchase) {
    $supplierName = trim((string) ($purchase['supplier_name'] ?? '')) !== '' ? $purchase['supplier_name'] : 'Unassigned supplier';
    $groupedPurchases[$supplierName][] = $purchase;
    $totalPurchases += floatval($purchase['total_cost'] ?? 0);
    $totalOutstanding += floatval($purchase['balance'] ?? 0);
    $totalPaid += floatval($purchase['amount_paid'] ?? 0);
    if (!empty($purchase['receipt_provided']) || !empty($purchase['receipt_number'])) {
        $receiptCount++;
    }
}

ksort($groupedPurchases);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchases & Suppliers — SMART POS SYSTEM</title>
  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
      <div>
        <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Admin</p>
        <h1 class="mt-2 text-2xl font-semibold">Purchases & suppliers</h1>
        <p class="mt-1 text-sm text-slate-500">View all purchase receipts, supplier balances, and grouped supplier activity.</p>
      </div>
      <div class="flex flex-wrap gap-2 sm:gap-3">
        <a href="admin.php" class="rounded-3xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Back to dashboard</a>
        <a href="stock_intake.php" class="rounded-3xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">New intake</a>
      </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
      <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm text-slate-500">Total purchases</p>
        <p class="mt-2 text-2xl font-semibold"><?php echo htmlspecialchars(formatCurrency($totalPurchases)); ?></p>
      </div>
      <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm text-slate-500">Outstanding balance</p>
        <p class="mt-2 text-2xl font-semibold"><?php echo htmlspecialchars(formatCurrency($totalOutstanding)); ?></p>
      </div>
      <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm text-slate-500">Receipts recorded</p>
        <p class="mt-2 text-2xl font-semibold"><?php echo htmlspecialchars((string) $receiptCount); ?></p>
      </div>
    </div>

    <form method="get" class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <label for="productSearch" class="block text-sm font-semibold text-slate-700">Find supplier by product</label>
      <div class="mt-2 flex flex-col gap-2 sm:flex-row">
        <input id="productSearch" type="search" name="product_search" value="<?php echo htmlspecialchars($productSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Type a product name, e.g. MT Kenya milk" class="min-w-0 flex-1 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-700 focus:border-slate-900 focus:outline-none">
        <button type="submit" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Search</button>
        <?php if ($productSearch !== ''): ?>
          <a href="purchases_suppliers.php" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-center text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Clear</a>
        <?php endif; ?>
      </div>
      <?php if ($productSearch !== ''): ?>
        <p class="mt-2 text-sm text-slate-500">Showing purchase receipts containing “<?php echo htmlspecialchars($productSearch, ENT_QUOTES, 'UTF-8'); ?>”.</p>
      <?php endif; ?>
    </form>

    <?php if (empty($groupedPurchases)): ?>
      <div class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <p class="text-lg font-semibold text-slate-800"><?php echo $productSearch !== '' ? 'No matching purchase records found.' : 'No purchase records found.'; ?></p>
        <p class="mt-2 text-sm text-slate-500"><?php echo $productSearch !== '' ? 'Try a different product name.' : 'Stock intake receipts will appear here once purchases are created.'; ?></p>
      </div>
    <?php else: ?>
      <div class="space-y-6">
        <?php foreach ($groupedPurchases as $supplierName => $supplierPurchases): ?>
          <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h2 class="text-xl font-semibold text-slate-900"><?php echo htmlspecialchars($supplierName); ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?php echo count($supplierPurchases); ?> purchase record<?php echo count($supplierPurchases) === 1 ? '' : 's'; ?></p>
              </div>
              <div class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                <span class="font-semibold text-slate-900">Total:</span> <?php echo htmlspecialchars(formatCurrency(array_sum(array_column($supplierPurchases, 'total_cost')))); ?>
              </div>
            </div>

            <div class="mt-4 space-y-4">
              <?php foreach ($supplierPurchases as $purchase): ?>
                <?php $receiptPhotos = array_values(array_filter(array_map('trim', explode(',', (string) ($purchase['receipt_photo'] ?? ''))), function ($photo) { return $photo !== ''; })); ?>
                <article class="receipt-card rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:bg-slate-100 hover:shadow-sm cursor-pointer" data-purchase-id="<?php echo (int) ($purchase['id'] ?? 0); ?>">
                  <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                      <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-lg font-semibold text-slate-900">Receipt <?php echo htmlspecialchars((string) ($purchase['receipt_number'] ?: '—')); ?></h3>
                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">
                          <?php echo htmlspecialchars(strtoupper((string) ($purchase['status'] ?: 'pending'))); ?>
                        </span>
                      </div>
                      <p class="mt-2 text-sm text-slate-500">
                        Date: <?php echo htmlspecialchars(date('d M Y H:i', strtotime($purchase['created_at']))); ?>
                      </p>
                      <?php if (!empty($purchase['receipt_provided']) && !empty($purchase['receipt_date'])): ?>
                        <p class="mt-1 text-sm text-slate-500">Receipt date: <?php echo htmlspecialchars(date('d M Y', strtotime($purchase['receipt_date']))); ?></p>
                      <?php endif; ?>
                    </div>
                    <div class="text-sm text-slate-600">
                      <p><span class="font-semibold text-slate-900">Total cost:</span> <?php echo htmlspecialchars(formatCurrency($purchase['total_cost'] ?? 0)); ?></p>
                      <p><span class="font-semibold text-slate-900">Paid:</span> <?php echo htmlspecialchars(formatCurrency($purchase['amount_paid'] ?? 0)); ?></p>
                      <p><span class="font-semibold text-slate-900">Balance:</span> <?php echo htmlspecialchars(formatCurrency($purchase['balance'] ?? 0)); ?></p>
                    </div>
                  </div>

                  <div class="mt-4 flex flex-wrap gap-3 text-sm">
                    <?php if (!empty($purchase['receipt_provided'])): ?>
                      <span class="rounded-full bg-slate-200 px-3 py-1 text-slate-700">Receipt provided</span>
                    <?php else: ?>
                      <span class="rounded-full bg-amber-100 px-3 py-1 text-amber-700">Receipt not provided</span>
                    <?php endif; ?>
                    <?php if (!empty($receiptPhotos)): ?>
                      <span class="rounded-full bg-emerald-100 px-3 py-1 font-semibold text-emerald-700"><?php echo count($receiptPhotos); ?> receipt photo<?php echo count($receiptPhotos) === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($receiptPhotos)): ?>
                    <div class="mt-4 rounded-3xl border border-slate-200 bg-white p-4">
                      <p class="mb-3 text-sm font-semibold text-slate-700">Receipt photo preview</p>
                      <div class="grid gap-4 md:grid-cols-2">
                        <?php foreach ($receiptPhotos as $receiptPhoto): ?>
                          <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-50">
                            <img src="uploads/receipts/<?php echo rawurlencode($receiptPhoto); ?>" alt="Receipt photo" class="max-h-64 w-full rounded-3xl object-contain" loading="lazy">
                            <div class="p-2 text-center">
                              <a href="uploads/receipts/<?php echo rawurlencode($receiptPhoto); ?>" target="_blank" rel="noopener" class="inline-block rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-emerald-700">Open image</a>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
      <nav aria-label="Purchase pagination" class="mt-6 flex items-center justify-center gap-2">
        <?php if ($page > 1): ?>
          <a href="?page=<?php echo max(1, $page - 1); ?>&amp;product_search=<?php echo rawurlencode($productSearch); ?>" class="rounded-full border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Previous</a>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="?page=<?php echo $i; ?>&amp;product_search=<?php echo rawurlencode($productSearch); ?>" class="rounded-full px-3 py-2 text-sm font-medium <?php echo $i === $page ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100'; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <a href="?page=<?php echo min($totalPages, $page + 1); ?>&amp;product_search=<?php echo rawurlencode($productSearch); ?>" class="rounded-full border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Next</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>

  <div id="receiptProductModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-6xl rounded-2xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <div>
          <h3 id="receiptProductTitle" class="text-xl font-semibold text-slate-800">Receipt products</h3>
          <p id="receiptProductSubtitle" class="text-sm text-slate-500">Products bought on this receipt</p>
        </div>
        <button type="button" id="closeReceiptProductModal" class="rounded-full border border-slate-200 px-3 py-1 text-lg text-slate-600 hover:bg-slate-100">×</button>
      </div>
      <div class="max-h-[70vh] overflow-auto px-4 py-4">
        <div class="grid gap-6 md:grid-cols-2">
          <div class="overflow-x-auto">
            <table class="w-full border-separate border-spacing-y-2 text-left text-sm text-slate-700">
              <thead>
                <tr>
                  <th class="px-3 py-2 font-semibold">Product</th>
                  <th class="px-3 py-2 font-semibold">Category</th>
                  <th class="px-3 py-2 font-semibold">Package</th>
                  <th class="px-3 py-2 font-semibold">Package size</th>
                  <th class="px-3 py-2 font-semibold">Quantity</th>
                  <th class="px-3 py-2 font-semibold">Base quantity</th>
                  <th class="px-3 py-2 font-semibold">Cost / package</th>
                  <th class="px-3 py-2 font-semibold">Total cost</th>
                </tr>
              </thead>
              <tbody id="receiptProductRows"></tbody>
            </table>
          </div>
          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
            <div class="mb-3 flex items-center justify-between">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Receipt image</p>
                <p id="receiptPhotoLabel" class="mt-1 text-sm font-semibold text-slate-700">No upload</p>
              </div>
              <a id="openReceiptPhotoLink" href="#" target="_blank" rel="noopener" class="hidden rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-emerald-700">Open</a>
            </div>
            <div class="flex min-h-[320px] items-center justify-center rounded-3xl border border-slate-200 bg-white p-3">
              <div id="receiptPhotoGallery" class="grid w-full gap-3 sm:grid-cols-2"></div>
              <div id="receiptPhotoEmpty" class="text-center text-sm text-slate-500">No receipt image attached.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    const purchaseProductData = <?php echo json_encode($purchaseProductMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const purchaseLookup = <?php echo json_encode($purchaseLookup, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    const receiptProductModal = document.getElementById('receiptProductModal');
    const receiptProductTitle = document.getElementById('receiptProductTitle');
    const receiptProductSubtitle = document.getElementById('receiptProductSubtitle');
    const receiptProductRows = document.getElementById('receiptProductRows');
    const receiptPhotoGallery = document.getElementById('receiptPhotoGallery');
    const receiptPhotoEmpty = document.getElementById('receiptPhotoEmpty');
    const receiptPhotoLabel = document.getElementById('receiptPhotoLabel');
    const openReceiptPhotoLink = document.getElementById('openReceiptPhotoLink');
    const closeReceiptProductModal = document.getElementById('closeReceiptProductModal');

    function formatMoney(value) {
      const amount = Number(value || 0);
      return 'KES ' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function openReceiptProductModal(purchaseId) {
      const purchaseRows = purchaseProductData[purchaseId] || [];
      const purchase = purchaseLookup[purchaseId] || null;

      const receiptNumber = purchase && purchase.receipt_number
        ? purchase.receipt_number
        : 'Receipt ' + purchaseId;

      receiptProductTitle.textContent = 'Receipt ' + receiptNumber;
      receiptProductSubtitle.textContent = 'Products bought on this receipt';

      if (!purchaseRows.length) {
        receiptProductRows.innerHTML = '<tr><td colspan="8" class="px-3 py-6 text-center text-slate-500">No product rows were recorded for this receipt.</td></tr>';
      } else {
        receiptProductRows.innerHTML = purchaseRows.map((row) => `
          <tr class="rounded-xl bg-slate-50 align-top">
            <td class="px-3 py-3 font-medium text-slate-800">${row.brand || '—'} ${row.name || '—'}</td>
            <td class="px-3 py-3 text-slate-700">${row.category || '—'}</td>
            <td class="px-3 py-3 font-semibold text-slate-900">${row.package_unit || '—'}</td>
            <td class="px-3 py-3">${Number(row.package_size_value || 0).toLocaleString(undefined, { maximumFractionDigits: 3 })} ${row.package_size_unit || ''}</td>
            <td class="px-3 py-3">${Number(row.quantity || 0).toLocaleString(undefined, { maximumFractionDigits: 3 })}</td>
            <td class="px-3 py-3">${Number(row.base_quantity || 0).toLocaleString(undefined, { maximumFractionDigits: 3 })}</td>
            <td class="px-3 py-3">${formatMoney(row.cost_per_package || 0)}</td>
            <td class="px-3 py-3 font-semibold text-slate-800">${formatMoney(row.total_cost || 0)}</td>
          </tr>
        `).join('');
      }

      const photoNames = purchase && purchase.receipt_photo
        ? purchase.receipt_photo.split(',').map((name) => name.trim()).filter(Boolean)
        : [];
      if (photoNames.length) {
        receiptPhotoGallery.innerHTML = photoNames.map((photoName) => {
          const imagePath = 'uploads/receipts/' + encodeURIComponent(photoName);
          return `<a href="${imagePath}" target="_blank" rel="noopener" class="block rounded-2xl border border-slate-200 bg-white p-2"><img src="${imagePath}" alt="Receipt photo" class="max-h-[420px] w-full rounded-xl object-contain" loading="lazy"></a>`;
        }).join('');
        receiptPhotoGallery.classList.remove('hidden');
        receiptPhotoEmpty.classList.add('hidden');
        receiptPhotoLabel.textContent = `${photoNames.length} photo${photoNames.length === 1 ? '' : 's'}`;
        openReceiptPhotoLink.href = 'uploads/receipts/' + encodeURIComponent(photoNames[0]);
        openReceiptPhotoLink.classList.remove('hidden');
      } else {
        receiptPhotoGallery.innerHTML = '';
        receiptPhotoGallery.classList.add('hidden');
        receiptPhotoEmpty.classList.remove('hidden');
        receiptPhotoLabel.textContent = 'No upload';
        openReceiptPhotoLink.href = '#';
        openReceiptPhotoLink.classList.add('hidden');
      }

      receiptProductModal.classList.remove('hidden');
      receiptProductModal.classList.add('flex');
    }

    function closeReceiptModal() {
      receiptProductModal.classList.add('hidden');
      receiptProductModal.classList.remove('flex');
    }

    document.querySelectorAll('[data-purchase-id]').forEach((card) => {
      card.addEventListener('click', () => {
        const purchaseId = card.getAttribute('data-purchase-id');
        openReceiptProductModal(purchaseId);
      });
    });

    if (closeReceiptProductModal) {
      closeReceiptProductModal.addEventListener('click', closeReceiptModal);
    }

    receiptProductModal.addEventListener('click', (event) => {
      if (event.target === receiptProductModal) {
        closeReceiptModal();
      }
    });

    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mobileMenuClose = document.getElementById('mobileMenuClose');
    const mobileMenu = document.getElementById('mobileMenu');

    if (mobileMenuToggle && mobileMenu) {
      mobileMenuToggle.addEventListener('click', () => {
        mobileMenu.classList.toggle('hidden');
        document.body.classList.toggle('overflow-hidden', !mobileMenu.classList.contains('hidden'));
      });
    }

    if (mobileMenuClose && mobileMenu) {
      mobileMenuClose.addEventListener('click', () => {
        mobileMenu.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      });
    }
  </script>
  <script src="assets/js/admin-session.js"></script>
</body>
</html>
