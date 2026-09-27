const productSelect = document.getElementById('productSelect');
const productSearchInput = document.getElementById('productSearchInput');
const productSuggestions = document.getElementById('productSuggestions');
const currentStockAmount = document.getElementById('currentStockAmount');
const currentBaseUnit = document.getElementById('currentBaseUnit');
const selectedProductName = document.getElementById('selectedProductName');
const saleQuantityInput = document.getElementById('saleQuantity');
const saleModeSelect = document.getElementById('saleModeSelect');
const saleUnitPriceInput = document.getElementById('saleUnitPrice');
const barcodeInput = document.getElementById('barcodeInput');
const barcodeScanTrigger = document.getElementById('barcodeScanTrigger');
const cameraScanButton = document.getElementById('cameraScanButton');
const cameraStopButton = document.getElementById('cameraStopButton');
const cameraVideo = document.getElementById('cameraVideo');
const cameraStatus = document.getElementById('cameraStatus');
const cameraScannerContainer = document.getElementById('cameraScannerContainer');
const barcodeMessage = document.getElementById('barcodeMessage');
const saleUnitPriceDisplay = document.getElementById('saleUnitPriceDisplay');
const saleUnitLabel = document.getElementById('saleUnitLabel');
const saleStockNote = document.getElementById('saleStockNote');
const saleTotalLabel = document.getElementById('saleTotal');
const saleTypeLabel = document.getElementById('saleTypeLabel');
let barcodeScanTimer = null;
let cameraStream = null;
let cameraCanvas = null;
let cameraCanvasCtx = null;
let cameraScanLoopTimer = null;
let lastCameraBarcode = '';
let lastCameraScanAt = 0;
let cameraScannerInstance = null;
let cameraBarcodeDetector = null;
let cameraPreviewVideo = null;
const saleItemsList = document.getElementById('saleItemsList');
const salesForm = document.getElementById('salesForm');
const saleGrandTotal = document.getElementById('saleGrandTotal');
const amountPaidInput = document.getElementById('amountPaid');
const saleBalance = document.getElementById('saleBalance');
const quickQtyButtonsContainer = document.getElementById('quickQtyButtons');
const customQuantityInput = document.getElementById('customQuantityInput');
const applyCustomQtyButton = document.getElementById('applyCustomQty');
const addSaleItemButton = document.getElementById('addSaleItem');
const wizardNextToReviewButton = document.getElementById('wizardNextToReviewButton');
const wizardBackToQtyButton = document.getElementById('wizardBackToQtyButton');
const wizardAddAnotherItemButton = document.getElementById('wizardAddAnotherItemButton');
const paymentMethodSelect = document.getElementById('paymentMethodSelect');
const openPriceLookupButton = document.getElementById('openPriceLookupButton');
const closePriceLookupButton = document.getElementById('closePriceLookupButton');
const priceLookupModal = document.getElementById('priceLookupModal');
const priceLookupInput = document.getElementById('priceLookupInput');
const priceLookupResults = document.getElementById('priceLookupResults');
const wizardStepIndicators = Array.from(document.querySelectorAll('[data-step-indicator]'));
const wizardSteps = Array.from(document.querySelectorAll('[data-wizard-step]'));

let selectedBaseUnit = 'packet';
let selectedStock = 0;
let selectedRetailPrice = 0;
let selectedWholesalePrice = 0;
let selectedPacketsPerBale = 24;
const productLookupCache = new Map();
let productSearchTimer = null;
let priceLookupTimer = null;

function productFromLookupRow(row) {
  return {
    id: String(row.id || row.product_id || ''),
    name: `${row.brand || ''} ${row.name || ''}`.trim(),
    stock: parseFloat(row.stock || '0') || 0,
    baseUnit: row.base_unit || 'packet',
    retailPrice: parseFloat(row.batch_retail_price || row.barcode_retail_price || row.retail_price || '0') || 0,
    wholesalePrice: parseFloat(row.batch_wholesale_price || row.barcode_wholesale_price || row.wholesale_price || '0') || 0,
    packetsPerBale: parseInt(row.packets_per_bale || '24', 10) || 24,
    expiryDate: row.expiry_date || '',
    barcode: row.barcode || '',
    saleMode: row.sale_mode || '',
    barcodeQuantity: parseFloat(row.quantity || '0') || 0,
  };

}
async function lookupProducts(params) {
  const searchParams = new URLSearchParams(params);
  const response = await fetch(`product_lookup.php?${searchParams.toString()}`, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  });
  if (!response.ok) throw new Error('Product lookup failed');
  const data = await response.json();
  return (Array.isArray(data.products) ? data.products : []).map(productFromLookupRow);
}

function addProductOption(product) {
  if (!productSelect || !product?.id) return;
  let option = productSelect.querySelector(`option[value="${CSS.escape(String(product.id))}"]`);
  if (!option) {
    option = document.createElement('option');
    option.value = product.id;
    productSelect.appendChild(option);
  }
  option.textContent = `${product.name} — Stock: ${product.stock.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${product.baseUnit}`;
  option.dataset.stock = product.stock;
  option.dataset.baseUnit = product.baseUnit;
  option.dataset.retailPrice = product.retailPrice;
  option.dataset.wholesalePrice = product.wholesalePrice;
  option.dataset.packetsPerBale = product.packetsPerBale;
  option.dataset.name = product.name;
  option.dataset.expiryDate = product.expiryDate;
  productLookupCache.set(String(product.id), product);
  return option;
}

function normalizeSaleMode(mode) {
  return mode === 'package' || mode === 'bale' ? 'bale' : 'packet';
}

function getSaleProducts() {
  if (!productSelect) {
    return [];
  }
  return Array.from(productSelect.options)
    .filter((option) => option.value)
    .map((option) => ({
      id: option.value,
      name: option.dataset.name || option.textContent || '',
      stock: parseFloat(option.dataset.stock || '0') || 0,
      baseUnit: option.dataset.baseUnit || 'packet',
      retailPrice: parseFloat(option.dataset.retailPrice || option.dataset.price || '0') || 0,
      wholesalePrice: parseFloat(option.dataset.wholesalePrice || '0') || 0,
      packetsPerBale: parseInt(option.dataset.packetsPerBale || '24', 10) || 24,
      expiryDate: option.dataset.expiryDate || '',
    }));
}

function findSaleProductById(productId) {
  return getSaleProducts().find((product) => String(product.id) === String(productId)) || null;
}

async function renderPriceLookupResults(query = '') {
  if (!priceLookupResults) return;
  const normalizedQuery = String(query).trim().toLowerCase();
  if (!normalizedQuery) {
    priceLookupResults.innerHTML = '<p class="rounded-2xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">Type a product name to search.</p>';
    return;
  }
  priceLookupResults.innerHTML = '<p class="p-5 text-center text-sm text-slate-500">Searching...</p>';
  let products = [];
  try {
    products = await lookupProducts({ q: normalizedQuery, limit: 25 });
  } catch (error) {
    priceLookupResults.innerHTML = '<p class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-center text-sm text-rose-700">Unable to search products.</p>';
    return;
  }
  products.forEach(addProductOption);
  if (products.length === 0) {
    priceLookupResults.innerHTML = '<p class="rounded-2xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">No matching products found.</p>';
    return;
  }
  priceLookupResults.innerHTML = products.map((product) => `
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="font-semibold text-slate-900">${escapeHtml(product.name)}</p>
          <p class="mt-1 text-xs text-slate-500">Available stock: ${product.stock.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${escapeHtml(product.baseUnit)}</p>
        </div>
        <button type="button" data-price-lookup-product="${escapeHtml(product.id)}" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">Use product</button>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-3">
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
          <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Retail</p>
          <p class="mt-1 text-lg font-semibold text-emerald-900">${formatCurrency(product.retailPrice)}</p>
        </div>
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-3">
          <p class="text-xs font-semibold uppercase tracking-wide text-sky-700">Wholesale</p>
          <p class="mt-1 text-lg font-semibold text-sky-900">${product.wholesalePrice > 0 ? formatCurrency(product.wholesalePrice) : 'Not set'}</p>
        </div>
      </div>
    </div>
  `).join('');
}

function closePriceLookup() {
  if (!priceLookupModal) return;
  priceLookupModal.classList.add('hidden');
  priceLookupModal.classList.remove('flex');
}

if (openPriceLookupButton && priceLookupModal) {
  openPriceLookupButton.addEventListener('click', () => {
    priceLookupModal.classList.remove('hidden');
    priceLookupModal.classList.add('flex');
    renderPriceLookupResults(priceLookupInput?.value || '');
    priceLookupInput?.focus();
  });
}

closePriceLookupButton?.addEventListener('click', closePriceLookup);
priceLookupModal?.addEventListener('click', (event) => {
  if (event.target === priceLookupModal) closePriceLookup();
});
priceLookupInput?.addEventListener('input', () => {
  clearTimeout(priceLookupTimer);
  priceLookupTimer = setTimeout(() => renderPriceLookupResults(priceLookupInput.value), 180);
});
priceLookupInput?.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closePriceLookup();
});
priceLookupResults?.addEventListener('click', (event) => {
  const button = event.target.closest('[data-price-lookup-product]');
  if (!button) return;
  selectSaleProduct(button.dataset.priceLookupProduct);
  closePriceLookup();
  saleQuantityInput?.focus();
});

function renderProductSuggestions(items) {
  if (!productSuggestions) {
    return;
  }
  if (!items || items.length === 0) {
    productSuggestions.classList.add('hidden');
    productSuggestions.innerHTML = '';
    return;
  }

  productSuggestions.innerHTML = items.slice(0, 8).map((product) => `
    <button type="button" class="w-full text-left px-4 py-3 text-sm text-slate-700 hover:bg-slate-100" data-product-id="${product.id}">
      ${escapeHtml(product.name)}
    </button>
  `).join('');
  productSuggestions.classList.remove('hidden');
}

async function selectSaleProduct(productId) {
  if (!productSelect) {
    return;
  }
  if (!productSelect.querySelector(`option[value="${CSS.escape(String(productId))}"]`)) {
    try {
      const products = await lookupProducts({ id: productId, limit: 1 });
      products.forEach(addProductOption);
    } catch (error) {
      setBarcodeMessage('Unable to load the selected product.', 'error');
      return;
    }
  }
  productSelect.value = String(productId);
  updateProductInfo();
  if (productSearchInput) {
    const product = findSaleProductById(productId);
    productSearchInput.value = product ? product.name : '';
  }
  renderProductSuggestions([]);
}

function hideProductSuggestions() {
  if (!productSuggestions) {
    return;
  }
  productSuggestions.classList.add('hidden');
  productSuggestions.innerHTML = '';
}

async function filterSaleProducts(query) {
  const normalized = String(query || '').trim();
  if (!normalized) return [];
  const products = await lookupProducts({ q: normalized, limit: 8 });
  products.forEach(addProductOption);
  return products;
}

function getProductBarcodePriceOverrides(productId, quantity = 0) {
  const normalizedProductId = String(productId || '');
  const normalizedQuantity = parseFloat(quantity) || 0;
  const barcodeMap = window.BARCODE_MAP || (typeof BARCODE_MAP !== 'undefined' ? BARCODE_MAP : []);
  if (!normalizedProductId || !Array.isArray(barcodeMap)) {
    return null;
  }

  const matchingEntries = barcodeMap.filter((entry) => String(entry.product_id) === normalizedProductId);
  if (matchingEntries.length === 0) {
    return null;
  }

  const exactMatch = matchingEntries.find((entry) => parseFloat(entry.quantity) === normalizedQuantity);
  const fallbackMatch = exactMatch || matchingEntries.find((entry) => parseFloat(entry.quantity) === 1) || matchingEntries[0];
  if (!fallbackMatch) {
    return null;
  }

  return {
    retailPrice: parseFloat(fallbackMatch.retail_price || fallbackMatch.price || '0') || 0,
    wholesalePrice: parseFloat(fallbackMatch.wholesale_price || '0') || 0,
  };
}

function getWholesaleUnitPrice() {
  if (selectedWholesalePrice <= 0) {
    return 0;
  }
  return selectedWholesalePrice;
}

function shouldUseWholesalePricing(quantity = 0) {
  const numericQuantity = parseFloat(quantity) || 0;
  return numericQuantity >= 6 && getWholesaleUnitPrice() > 0;
}

function getUnitPriceForMode(mode, quantity = 0) {
  const normalizedMode = normalizeSaleMode(mode);

  if (shouldUseWholesalePricing(quantity)) {
    return getWholesaleUnitPrice();
  }

  if (normalizedMode === 'bale') {
    return selectedWholesalePrice;
  }

  return selectedRetailPrice;
}

function getEffectiveSaleMode(quantity, fallbackMode = 'unit') {
  if (shouldUseWholesalePricing(quantity)) {
    return 'unit';
  }

  return fallbackMode;
}

function isWholesaleQuantity(quantity) {
  return (parseFloat(quantity) || 0) >= 6;
}

function getQuickQuantityOptions(baseUnit) {
  const normalizedUnit = String(baseUnit || '').trim().toLowerCase();
  if (['kg', 'g', 'l', 'ml', 'litre', 'litres', 'liter', 'liters'].includes(normalizedUnit)) {
    return [0.25, 0.5, 1, 6];
  }
  if (normalizedUnit === 'packet' || normalizedUnit === 'pack') {
    return [1, 6, 24];
  }
  return [1, 6, 12];
}

function renderQuickQuantityButtons() {
  if (!quickQtyButtonsContainer) {
    return;
  }

  quickQtyButtonsContainer.innerHTML = '';
  const quantities = getQuickQuantityOptions(selectedBaseUnit);

  quantities.forEach((qty) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'quick-qty rounded-full border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-100';
    button.textContent = qty.toString();
    button.addEventListener('click', () => {
      if (saleQuantityInput) {
        saleQuantityInput.value = qty;
        updateSaleSummary();
      }
    });
    quickQtyButtonsContainer.appendChild(button);
  });
}

function formatCurrency(value) {
  return `KES ${value.toFixed(2)}`;
}

function escapeHtml(text) {
  return String(text).replace(/[&<>"']/g, (char) => {
    return {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char] || char;
  });
}

// Simple toast helper: non-blocking notification in bottom-right
function showToast(message, duration = 2000, type = 'success') {
  try {
    let container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      container.style.position = 'fixed';
      container.style.left = '1rem';
      container.style.bottom = '1rem';
      container.style.zIndex = '9999';
      container.style.display = 'flex';
      container.style.flexDirection = 'column';
      container.style.gap = '0.5rem';
      container.style.alignItems = 'flex-end';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.textContent = message;
    // style by type
    if (type === 'success') {
      toast.style.background = '#059669';
    } else if (type === 'error') {
      toast.style.background = '#dc2626';
    } else {
      toast.style.background = '#111827';
    }
    toast.style.color = 'white';
    toast.style.padding = '0.5rem 0.75rem';
    toast.style.borderRadius = '0.5rem';
    toast.style.boxShadow = '0 6px 18px rgba(0,0,0,0.12)';
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 150ms ease-in-out, transform 150ms ease-in-out';
    toast.style.transform = 'translateY(6px)';

    container.appendChild(toast);

    // force reflow then show
    void toast.offsetWidth;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(6px)';
      setTimeout(() => toast.remove(), 200);
    }, duration);
  } catch (e) {
    // fallback to alert if anything unexpected happens
    try { alert(message); } catch (ee) { /* ignore */ }
  }
}

function getSaleGrandTotal() {
  if (!saleItemsList) {
    return 0;
  }

  return Array.from(saleItemsList.querySelectorAll('[data-line-total]')).reduce((sum, element) => {
    return sum + parseFloat(element.dataset.lineTotal || '0');
  }, 0);
}

function updateSaleSummary() {
  const quantity = parseFloat(saleQuantityInput.value) || 0;
  const paid = parseFloat(amountPaidInput.value) || 0;
  const fallbackMode = saleModeSelect?.value || 'unit';
  const effectiveMode = getEffectiveSaleMode(quantity, fallbackMode);
  const normalizedMode = normalizeSaleMode(effectiveMode);
  const unitPrice = getUnitPriceForMode(effectiveMode, quantity);

  if (saleModeSelect && saleModeSelect.value !== effectiveMode) {
    saleModeSelect.value = effectiveMode;
  }

  saleUnitPriceInput.value = unitPrice.toFixed(2);
  saleUnitPriceDisplay.value = unitPrice > 0 ? formatCurrency(unitPrice) : 'KES 0.00';
  if (saleUnitLabel) {
    const normalizedUnit = selectedBaseUnit || 'unit';
    if (shouldUseWholesalePricing(quantity) && selectedWholesalePrice > 0 && normalizedMode !== 'bale') {
      saleUnitLabel.textContent = `Wholesale price per ${normalizedUnit}`;
    } else {
      saleUnitLabel.textContent = `Price per ${normalizedUnit}`;
    }
  }
  const total = quantity * unitPrice;
  const grandTotal = getSaleGrandTotal();
  const balance = Math.max(0, grandTotal - paid);

  if (saleTotalLabel) {
    saleTotalLabel.textContent = formatCurrency(total);
  }
  if (saleBalance) {
    saleBalance.textContent = formatCurrency(balance);
  }

  if (saleStockNote) {
    const packageCount = selectedStock > 0 ? Math.floor(selectedStock / selectedPacketsPerBale) : 0;
    const stockLabel = selectedBaseUnit || 'unit';
    saleStockNote.textContent = `${selectedStock.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${stockLabel} available (${packageCount} full package${packageCount === 1 ? '' : 's'})`;
  }

  if (saleTypeLabel) {
    if (normalizedMode === 'bale') {
      saleTypeLabel.textContent = `Whole-package sale — ${quantity.toLocaleString(undefined, { maximumFractionDigits: 3 })} package${quantity === 1 ? '' : 's'}`;
    } else if (shouldUseWholesalePricing(quantity) && selectedWholesalePrice > 0) {
      saleTypeLabel.textContent = `Wholesale unit sale — ${quantity.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${selectedBaseUnit}`;
    } else {
      saleTypeLabel.textContent = `Retail unit sale — ${quantity.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${selectedBaseUnit}`;
    }
  }
}

function normalizeBarcodeValue(value) {
  return String(value || '')
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '');
}

async function lookupBarcode(barcode) {
  if (!barcode) return null;
  const products = await lookupProducts({ barcode });
  return products[0] || null;
}

function setBarcodeMessage(message, type = 'default') {
  if (!barcodeMessage) return;
  barcodeMessage.textContent = message;
  barcodeMessage.className = type === 'error' ? 'mt-2 text-xs text-rose-600' : type === 'success' ? 'mt-2 text-xs text-emerald-600' : 'mt-2 text-xs text-slate-500';
}

async function handleBarcodeScan() {
  if (!barcodeInput) return;
  const barcode = barcodeInput.value.trim();
  if (!barcode) {
    setBarcodeMessage('Scan a barcode to add a sale item.', 'default');
    return;
  }

  const normalizedBarcode = barcode.toUpperCase();
  if (normalizedBarcode.includes('-') || normalizedBarcode.includes(' ')) {
    setBarcodeMessage('Scanning a text-based product code. Continue with the current value.', 'default');
  }

  let barcodeEntry;
  try {
    barcodeEntry = await lookupBarcode(barcode);
  } catch (error) {
    setBarcodeMessage('Unable to search the barcode right now.', 'error');
    return;
  }
  if (!barcodeEntry) {
    setBarcodeMessage('Barcode not found. Check product barcode mapping.', 'error');
    return;
  }

  if (productSelect) {
    addProductOption(barcodeEntry);
    productSelect.value = barcodeEntry.id;
  }

  if (saleQuantityInput) {
    saleQuantityInput.value = barcodeEntry.barcodeQuantity;
  }

  updateProductInfo();

  const barcodeRetailPrice = barcodeEntry.retailPrice;
  const barcodeWholesalePrice = barcodeEntry.wholesalePrice;
  if (barcodeRetailPrice > 0) {
    selectedRetailPrice = barcodeRetailPrice;
  } else {
    selectedRetailPrice = parseFloat(productSelect?.selectedOptions?.[0]?.dataset.retailPrice || productSelect?.selectedOptions?.[0]?.dataset.price || '0') || 0;
  }
  if (barcodeWholesalePrice > 0) {
    selectedWholesalePrice = barcodeWholesalePrice;
  } else {
    selectedWholesalePrice = parseFloat(productSelect?.selectedOptions?.[0]?.dataset.wholesalePrice || '0') || 0;
  }

  if (saleModeSelect) {
    // Prefer unit sales for ambiguous barcodes: if the barcode record is 'package' but
    // the barcode label doesn't indicate WHOLE/WHOLESALE and quantity is 1, assume unit.
    let desiredMode = barcodeEntry.saleMode || 'unit';
    try {
      const bcText = String(barcodeEntry.barcode || '').toUpperCase();
      const qty = barcodeEntry.barcodeQuantity;
      if (desiredMode === 'package' && qty === 1 && !bcText.includes('WHOLE') && !bcText.includes('WHOLESALE')) {
        desiredMode = 'unit';
      }
      if (qty >= 6 && selectedWholesalePrice > 0) {
        desiredMode = 'unit';
      }
    } catch (e) {
      // ignore and fall back to stored mode
    }
    saleModeSelect.value = desiredMode;
  }
  updateSaleSummary();

  setBarcodeMessage(`Scanned ${barcodeEntry.barcode || barcode} — item added.`, 'success');
  addSaleItem();

  barcodeInput.value = '';
  barcodeInput.focus();
}

function startBarcodeScanWatch() {
  if (!barcodeInput) return;
  if (barcodeScanTimer) {
    clearTimeout(barcodeScanTimer);
  }
  barcodeScanTimer = setTimeout(() => {
    if (barcodeInput.value.trim()) {
      handleBarcodeScan();
    }
    barcodeScanTimer = null;
  }, 100);
}

function setCameraStatus(text, type = 'info') {
  if (!cameraStatus) return;
  cameraStatus.textContent = text;
  cameraStatus.className = type === 'error' ? 'text-sm text-rose-600' : 'text-sm text-slate-600';
}

function clearCameraScanLoop() {
  if (cameraScanLoopTimer) {
    clearTimeout(cameraScanLoopTimer);
    cameraScanLoopTimer = null;
  }
}

function resetCameraScanState() {
  lastCameraBarcode = '';
  lastCameraScanAt = 0;
}

function createCameraPreviewVideo() {
  if (!cameraVideo) return null;
  if (cameraPreviewVideo && cameraVideo.contains(cameraPreviewVideo)) {
    return cameraPreviewVideo;
  }

  const video = document.createElement('video');
  video.autoplay = true;
  video.muted = true;
  video.playsInline = true;
  video.style.width = '100%';
  video.style.height = '100%';
  video.style.objectFit = 'cover';
  video.style.borderRadius = '1rem';
  video.style.display = 'block';
  cameraVideo.innerHTML = '';
  cameraVideo.appendChild(video);
  cameraPreviewVideo = video;
  return video;
}

async function stopCameraScanner() {
  clearCameraScanLoop();
  resetCameraScanState();

  if (window.Quagga) {
    try {
      window.Quagga.offDetected();
      window.Quagga.offProcessed();
    } catch (err) {
      console.warn('Unable to clear Quagga event handlers.', err);
    }
    try {
      window.Quagga.stop();
    } catch (err) {
      console.warn('Unable to stop Quagga cleanly.', err);
    }
  }

  if (cameraBarcodeDetector) {
    cameraBarcodeDetector = null;
  }

  cameraScannerInstance = null;

  if (cameraPreviewVideo) {
    cameraPreviewVideo.pause?.();
    cameraPreviewVideo.srcObject = null;
    cameraPreviewVideo.remove();
    cameraPreviewVideo = null;
  }

  if (cameraVideo) {
    cameraVideo.innerHTML = '';
  }

  if (cameraStream) {
    cameraStream.getTracks().forEach((track) => track.stop());
    cameraStream = null;
  }
  if (cameraScannerContainer) {
    cameraScannerContainer.classList.add('hidden');
  }
  if (cameraScanButton) {
    cameraScanButton.disabled = false;
  }
  if (cameraStopButton) {
    cameraStopButton.disabled = true;
  }
  setCameraStatus('Camera scanner stopped.');
}

function createCameraCanvas() {
  if (!cameraCanvas) {
    cameraCanvas = document.createElement('canvas');
    cameraCanvasCtx = cameraCanvas.getContext('2d');
  }
  return cameraCanvasCtx;
}

async function startCameraScanner() {
  if (!barcodeInput) {
    return;
  }

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setCameraStatus('Camera access is not available in this browser.', 'error');
    return;
  }

  if (cameraScannerContainer) {
    cameraScannerContainer.classList.remove('hidden');
  }
  setCameraStatus('Opening the camera...'); console.debug('[sales] startCameraScanner: opening camera');

  if (cameraScanButton) {
    cameraScanButton.disabled = true;
  }
  if (cameraStopButton) {
    cameraStopButton.disabled = false;
  }

  try {
    if (!cameraVideo) {
      setCameraStatus('Camera preview element not available.', 'error');
      return;
    }

    cameraStream = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: 'environment',
        width: { min: 640 },
        height: { min: 480 }
      }
    });

    const video = createCameraPreviewVideo();
    if (!video) {
      setCameraStatus('Unable to create camera preview.', 'error');
      return;
    }
    video.srcObject = cameraStream;
    await video.play();

    if (window.BarcodeDetector) {
      const supportedFormats = await window.BarcodeDetector.getSupportedFormats();
      const formats = supportedFormats.filter((format) => ['code_128', 'ean_13', 'ean_8', 'code_39', 'upc_a', 'upc_e'].includes(format));
      if (formats.length > 0) {
        cameraBarcodeDetector = new window.BarcodeDetector({ formats });
        setCameraStatus('Scanning using native BarcodeDetector. Point camera at a barcode...');

        const scanFrame = async () => {
          if (!cameraBarcodeDetector || !cameraPreviewVideo) {
            return;
          }
          if (cameraPreviewVideo.readyState < 2) {
            cameraScanLoopTimer = setTimeout(scanFrame, 150);
            return;
          }
          const canvasCtx = createCameraCanvas();
          if (!canvasCtx) {
            cameraScanLoopTimer = setTimeout(scanFrame, 150);
            return;
          }
          const canvas = canvasCtx.canvas;
          canvas.width = cameraPreviewVideo.videoWidth;
          canvas.height = cameraPreviewVideo.videoHeight;
          canvasCtx.drawImage(cameraPreviewVideo, 0, 0, canvas.width, canvas.height);
          try {
            const detections = await cameraBarcodeDetector.detect(canvas);
            if (detections && detections.length > 0) {
              const barcodeValue = String(detections[0].rawValue || '').trim();
              if (barcodeValue && barcodeValue !== lastCameraBarcode) {
                lastCameraBarcode = barcodeValue;
                barcodeInput.value = barcodeValue;
                setCameraStatus(`Scanned: ${barcodeValue}`);
                handleBarcodeScan();
                stopCameraScanner();
                return;
              }
            }
          } catch (detectErr) {
            console.warn('BarcodeDetector detect error', detectErr);
          }
          cameraScanLoopTimer = setTimeout(scanFrame, 150);
        };
        scanFrame();
        return;
      }
    }

    if (!window.Quagga) {
      setCameraStatus('Camera ready, but barcode decoding is unavailable in this browser.', 'error');
      return;
    }

    cameraVideo.innerHTML = '';
    await new Promise((resolve, reject) => {
      const quaggaConfig = {
        inputStream: {
          name: 'Live',
          type: 'LiveStream',
          target: cameraVideo,
          constraints: {
            facingMode: 'environment',
            width: { min: 640 },
            height: { min: 480 }
          }
        },
        locator: {
          patchSize: 'medium',
          halfSample: true
        },
        decoder: {
          readers: ['code_128_reader', 'ean_reader', 'ean_8_reader', 'code_39_reader', 'upc_reader', 'upc_e_reader'],
          multiple: false
        },
        locate: true,
        numOfWorkers: 1,
        frequency: 10
      };
      console.debug('[sales] Quagga config:', quaggaConfig);
      window.Quagga.init(quaggaConfig, (err) => {
        if (err) {
          reject(err);
          return;
        }
        resolve();
      });
    });

    window.Quagga.onProcessed((result) => {
      if (!result) {
        return;
      }
      const drawingCtx = window.Quagga.canvas.ctx.overlay;
      const drawingCanvas = window.Quagga.canvas.dom.overlay;
      if (drawingCtx && drawingCanvas) {
        drawingCtx.clearRect(0, 0, drawingCanvas.width, drawingCanvas.height);
        if (result.boxes) {
          result.boxes.filter((box) => box !== result.box).forEach((box) => {
            window.Quagga.ImageDebug.drawPath(box, { x: 0, y: 1 }, drawingCtx, { color: 'green', lineWidth: 2 });
          });
        }
        if (result.box) {
          window.Quagga.ImageDebug.drawPath(result.box, { x: 0, y: 1 }, drawingCtx, { color: '#00F', lineWidth: 2 });
        }
        if (result.codeResult && result.codeResult.code) {
          window.Quagga.ImageDebug.drawPath(result.line, { x: 'x', y: 'y' }, drawingCtx, { color: 'red', lineWidth: 3 });
        }
      }
    });

    window.Quagga.onDetected((result) => {
      const barcodeValue = result && result.codeResult && result.codeResult.code ? String(result.codeResult.code).trim() : '';
      console.debug('[sales] Quagga detected:', barcodeValue, result);
      if (!barcodeValue || barcodeValue === lastCameraBarcode) {
        return;
      }
      lastCameraBarcode = barcodeValue;
      barcodeInput.value = barcodeValue;
      setCameraStatus(`Scanned: ${barcodeValue}`);
      handleBarcodeScan();
      stopCameraScanner();
    });

    await window.Quagga.start();
    setCameraStatus('Point the camera at a barcode. Scanning now...');
  } catch (err) {
    const message = err && err.name === 'NotAllowedError'
      ? 'Camera permission denied. Allow camera access and try again.'
      : 'Unable to start camera. Allow permission and try again.';
    setCameraStatus(message, 'error');
    console.error(err);
  } finally {
    if (cameraScanButton) {
      cameraScanButton.disabled = false;
    }
  }
}

function updateProductInfo() {
  if (!productSelect) {
    return;
  }

  const selectedOption = productSelect.selectedOptions[0];
  const quantity = parseFloat(saleQuantityInput?.value || '0') || 0;
  if (!selectedOption || !selectedOption.value) {
    if (currentStockAmount) currentStockAmount.textContent = '0';
    if (currentBaseUnit) currentBaseUnit.textContent = 'packet';
    if (selectedProductName) selectedProductName.textContent = 'None';
    if (productSearchInput) {
      productSearchInput.value = '';
    }
    selectedBaseUnit = 'packet';
    selectedStock = 0;
    selectedRetailPrice = 0;
    selectedWholesalePrice = 0;
    selectedPacketsPerBale = 24;
    setBarcodeMessage('Select a product to continue.', 'default');
    updateSaleSummary();
    return;
  }

  selectedStock = parseFloat(selectedOption.dataset.stock) || 0;
  selectedBaseUnit = selectedOption.dataset.baseUnit || 'packet';
  const productBarcodePriceOverrides = getProductBarcodePriceOverrides(selectedOption.value, quantity);
  selectedRetailPrice = productBarcodePriceOverrides?.retailPrice > 0
    ? productBarcodePriceOverrides.retailPrice
    : parseFloat(selectedOption.dataset.retailPrice || selectedOption.dataset.price) || 0;
  selectedWholesalePrice = productBarcodePriceOverrides?.wholesalePrice > 0
    ? productBarcodePriceOverrides.wholesalePrice
    : parseFloat(selectedOption.dataset.wholesalePrice) || 0;
  selectedPacketsPerBale = parseInt(selectedOption.dataset.packetsPerBale || '24', 10) || 24;

  const expiryDate = (selectedOption.dataset.expiryDate || '').trim();
  const productName = selectedOption.dataset.name || 'Selected product';
  if (selectedProductName) {
    selectedProductName.textContent = productName;
  }
  if (currentStockAmount) {
    currentStockAmount.textContent = `${selectedStock.toLocaleString(undefined, { maximumFractionDigits: 3 })}`;
  }
  if (currentBaseUnit) {
    currentBaseUnit.textContent = selectedBaseUnit;
  }

  if (productSearchInput) {
    productSearchInput.value = productName;
  }

  if (selectedStock <= 0) {
    setBarcodeMessage(`${productName} is out of stock and cannot be sold.`, 'error');
  }

  if (expiryDate) {
    const expiryTimestamp = Date.parse(expiryDate);
    if (!Number.isNaN(expiryTimestamp)) {
      const now = Date.now();
      const msUntilExpiry = expiryTimestamp - now;
      const daysLeft = Math.ceil(msUntilExpiry / 86400000);
      if (msUntilExpiry <= 0) {
        setBarcodeMessage(`${productName} expired on ${expiryDate} and cannot be sold.`, 'error');
      } else if (msUntilExpiry <= 86400000) {
        setBarcodeMessage(`${productName} expires in ${daysLeft} day${daysLeft === 1 ? '' : 's'} on ${expiryDate}.`, 'default');
      } else {
        setBarcodeMessage(`${productName} expires on ${expiryDate}.`, 'success');
      }
    }
  }

  renderQuickQuantityButtons();
  updateSaleSummary();
}

function getActiveWizardStep() {
  const visibleStep = wizardSteps.findIndex((panel) => !panel.classList.contains('hidden')) + 1;
  return visibleStep > 0 ? visibleStep : 1;
}

function updateWizardSummary() {
  const itemCount = saleItemsList ? saleItemsList.querySelectorAll('input[name="product_id[]"]').length : 0;
  const grandTotal = getSaleGrandTotal();
  const paid = parseFloat(amountPaidInput?.value || '0');
  const balance = Math.max(0, grandTotal - paid);

  if (saleGrandTotal) {
    saleGrandTotal.textContent = formatCurrency(grandTotal);
  }

  const wizardSummaryItems = document.getElementById('wizardSummaryItems');
  const wizardSummaryTotal = document.getElementById('wizardSummaryTotal');
  const wizardSummaryBalance = document.getElementById('wizardSummaryBalance');

  if (wizardSummaryItems) {
    wizardSummaryItems.textContent = `${itemCount} item${itemCount === 1 ? '' : 's'} ready for checkout`;
  }
  if (wizardSummaryTotal) {
    wizardSummaryTotal.textContent = `Total: ${formatCurrency(grandTotal)}`;
  }
  if (wizardSummaryBalance) {
    wizardSummaryBalance.textContent = formatCurrency(balance);
  }
}

function updateGrandTotal() {
  if (!saleItemsList || !saleGrandTotal) {
    return;
  }

  const grandTotal = Array.from(saleItemsList.querySelectorAll('[data-line-total]')).reduce((sum, element) => {
    return sum + parseFloat(element.dataset.lineTotal || '0');
  }, 0);

  saleGrandTotal.textContent = formatCurrency(grandTotal);
  updateWizardSummary();
}

function renderEmptyPlaceholder() {
  if (!saleItemsList) {
    return;
  }

  if (saleItemsList.children.length === 0) {
    saleItemsList.innerHTML = `
      <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 p-4 text-slate-500">
        No items added yet. Add sale items to checkout.
      </div>
    `;
    if (amountPaidInput) {
      amountPaidInput.value = '0';
    }
    if (saleBalance) {
      saleBalance.textContent = formatCurrency(0);
    }
  }
}

function setWizardStep(step) {
  const activeStep = Math.min(Math.max(step, 1), 2);
  wizardSteps.forEach((panel, index) => {
    const panelStep = index + 1;
    panel.classList.toggle('hidden', panelStep !== activeStep);
  });

  wizardStepIndicators.forEach((indicator, index) => {
    const indicatorStep = index + 1;
    const label = indicatorStep === 1 ? 'Product' : 'Review';
    if (indicatorStep < activeStep) {
      indicator.className = 'rounded-full bg-emerald-600 px-3 py-1 font-semibold text-white';
      indicator.textContent = `✓ ${indicatorStep}. ${label}`;
    } else if (indicatorStep === activeStep) {
      indicator.className = 'rounded-full bg-slate-900 px-3 py-1 font-semibold text-white';
      indicator.textContent = `${indicatorStep}. ${label}`;
    } else {
      indicator.className = 'rounded-full bg-white px-3 py-1 font-semibold text-slate-700';
      indicator.textContent = `${indicatorStep}. ${label}`;
    }
  });
}

function createSaleItemLine(productId, productName, quantity, unitPrice, stock, saleMode) {
  if (!saleItemsList) {
    return null;
  }

  const lineTotal = quantity * unitPrice;
  const line = document.createElement('div');
  line.className = 'sale-item-line rounded-3xl border border-slate-200 bg-white p-4 shadow-sm';
  line.innerHTML = `
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <p class="font-semibold text-slate-900">${productName}</p>
        <p class="text-sm text-slate-500">Qty: ${quantity} · Unit: ${selectedBaseUnit} · Stock: ${stock}</p>
      </div>
      <button type="button" class="remove-sale-item rounded-full bg-rose-100 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-200">Remove</button>
    </div>
    <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
      <span>Price: ${formatCurrency(unitPrice)}</span>
      <span>Total: ${formatCurrency(lineTotal)}</span>
    </div>
    <input type="hidden" name="product_id[]" value="${escapeHtml(productId)}">
    <input type="hidden" name="product_name[]" value="${escapeHtml(productName)}">
    <input type="hidden" name="sale_quantity[]" value="${escapeHtml(quantity)}">
    <input type="hidden" name="sale_mode[]" value="${escapeHtml(saleMode)}">
    <input type="hidden" name="unit_price[]" value="${escapeHtml(unitPrice)}">
    <div data-line-total="${escapeHtml(lineTotal)}"></div>
  `;

  line.querySelector('.remove-sale-item')?.addEventListener('click', () => {
    line.remove();
    renderEmptyPlaceholder();
    updateGrandTotal();
  });

  return line;
}

function getExistingSaleLine(productId, quantity, saleMode) {
  if (!saleItemsList) {
    return null;
  }
  return Array.from(saleItemsList.querySelectorAll('div')).find((line) => {
    const lineProductId = line.querySelector('input[name="product_id[]"]')?.value;
    const lineQuantity = parseFloat(line.querySelector('input[name="sale_quantity[]"]')?.value || '0');
    const lineSaleMode = line.querySelector('input[name="sale_mode[]"]')?.value;
    return lineProductId === productId && lineQuantity === quantity && lineSaleMode === saleMode;
  }) || null;
}

function getExistingSaleLineForProduct(productId, saleMode) {
  if (!saleItemsList) {
    return null;
  }
  return Array.from(saleItemsList.querySelectorAll('div')).find((line) => {
    const lineProductId = line.querySelector('input[name="product_id[]"]')?.value;
    const lineSaleMode = line.querySelector('input[name="sale_mode[]"]')?.value;
    return lineProductId === productId && lineSaleMode === saleMode;
  }) || null;
}

function addSaleItem() {
  if (!productSelect || !saleQuantityInput || !saleItemsList) {
    return;
  }

  const selectedOption = productSelect.selectedOptions[0];
  if (!selectedOption || !selectedOption.value) {
    showToast('Please select a product before adding a sale item.', 2400, 'error');
    return;
  }

  const productId = selectedOption.value;
  const productName = selectedOption.dataset.name || 'Product';
  const quantity = parseFloat(saleQuantityInput.value) || 0;
  const expiryDate = (selectedOption.dataset.expiryDate || '').trim();
  const saleMode = getEffectiveSaleMode(quantity, saleModeSelect?.value || 'unit');
  const unitPrice = getUnitPriceForMode(saleMode, quantity);

  if (expiryDate) {
    const expiryTimestamp = Date.parse(expiryDate);
    if (!Number.isNaN(expiryTimestamp) && expiryTimestamp <= Date.now()) {
      showToast(`${productName} has expired on ${expiryDate} and cannot be sold.`, 2800, 'error');
      return;
    }
  }

  if (quantity <= 0) {
    showToast('Enter a valid quantity before adding the item.', 2400, 'error');
    return;
  }
  if (selectedStock <= 0) {
    showToast(`Cannot sell ${productName} because it is out of stock.`, 2800, 'error');
    return;
  }
  if (unitPrice <= 0) {
    showToast('The selected product does not have a valid price.', 2400, 'error');
    return;
  }
  const normalizedSaleMode = normalizeSaleMode(saleMode);
  const maxAllowed = normalizedSaleMode === 'bale' ? Math.floor(selectedStock / selectedPacketsPerBale) : selectedStock;
  if (quantity > maxAllowed) {
    if (!confirm('Quantity exceeds available stock. Add item anyway?')) {
      return;
    }
  }

  const existingLine = getExistingSaleLineForProduct(productId, normalizedSaleMode);
  if (existingLine) {
    const existingQuantity = parseFloat(existingLine.querySelector('input[name="sale_quantity[]"]')?.value || '0');
    const newQuantity = existingQuantity + quantity;
    const effectiveMode = getEffectiveSaleMode(newQuantity, normalizedSaleMode);
    const newUnitPrice = getUnitPriceForMode(effectiveMode, newQuantity);
    const newLineTotal = newQuantity * newUnitPrice;

    existingLine.querySelector('input[name="sale_quantity[]"]').value = newQuantity;
    existingLine.querySelector('input[name="sale_mode[]"]').value = effectiveMode;
    existingLine.querySelector('input[name="unit_price[]"]').value = newUnitPrice;
    existingLine.querySelector('[data-line-total]').dataset.lineTotal = newLineTotal;
    existingLine.querySelector('.text-sm.text-slate-500').textContent = `Qty: ${newQuantity} · Unit: ${selectedBaseUnit} · Stock: ${selectedStock}`;
    existingLine.querySelector('.mt-3 span:nth-child(1)').textContent = `Price: ${formatCurrency(newUnitPrice)}`;
    existingLine.querySelector('.mt-3 span:nth-child(2)').textContent = `Total: ${formatCurrency(newLineTotal)}`;

    updateGrandTotal();
    showToast(`Updated ${productName} quantity to ${newQuantity} and applied ${effectiveMode === 'package' ? 'wholesale' : 'retail'} pricing.`, 2200, 'success');
    return;
  }

  const line = createSaleItemLine(productId, productName, quantity, unitPrice, selectedStock, saleMode);
  if (!line) {
    return;
  }

  if (saleItemsList.children.length === 1 && saleItemsList.children[0].classList.contains('border-dashed')) {
    saleItemsList.innerHTML = '';
  }

  saleItemsList.appendChild(line);
  updateGrandTotal();

  const friendlyMode = saleMode === 'package' ? 'whole package' : shouldUseWholesalePricing(quantity) ? 'wholesale unit' : 'retail unit';
  // success feedback
  showToast(`Added ${quantity} ${friendlyMode} of ${productName} to the cart.`, 1800, 'success');

  // prepare form for next item: clear selection, reset quantity/pricing, focus barcode, and scroll to top
  try {
    if (productSelect) productSelect.value = '';
    if (productSearchInput) productSearchInput.value = '';
    hideProductSuggestions();
    updateProductInfo();
    if (saleQuantityInput) saleQuantityInput.value = '0.25';
    if (saleModeSelect) saleModeSelect.value = 'unit';
    if (saleUnitPriceInput) saleUnitPriceInput.value = '0';
    if (saleUnitPriceDisplay) saleUnitPriceDisplay.value = 'KES 0.00';
    if (barcodeInput) {
      barcodeInput.value = '';
      barcodeInput.focus();
      barcodeInput.select();
    }
    // smooth scroll to top so cashier immediately sees the product panel
    if (typeof window !== 'undefined' && window.scrollTo) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  } catch (e) {
    // ignore non-critical UI reset errors
  }

}

if (productSelect) {
  productSelect.addEventListener('change', updateProductInfo);
}

if (productSearchInput) {
  productSearchInput.addEventListener('input', () => {
    const query = productSearchInput.value.trim();
    if (!query) {
      hideProductSuggestions();
      return;
    }
    clearTimeout(productSearchTimer);
    productSearchTimer = setTimeout(async () => {
      try {
        const matches = await filterSaleProducts(query);
        renderProductSuggestions(matches);
      } catch (error) {
        hideProductSuggestions();
      }
    }, 180);
  });

  productSearchInput.addEventListener('keydown', async (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      const query = productSearchInput.value.trim();
      let matches = [];
      try {
        matches = await filterSaleProducts(query);
      } catch (error) {
        return;
      }
      if (matches.length > 0) {
        await selectSaleProduct(matches[0].id);
        if (saleQuantityInput) {
          saleQuantityInput.focus();
        }
      }
    }
  });

  productSearchInput.addEventListener('blur', () => {
    setTimeout(hideProductSuggestions, 150);
  });
}

if (productSuggestions) {
  productSuggestions.addEventListener('click', (event) => {
    const button = event.target.closest('[data-product-id]');
    if (!button) {
      return;
    }
    const productId = button.getAttribute('data-product-id');
    if (productId) {
      selectSaleProduct(productId);
      if (saleQuantityInput) {
        saleQuantityInput.focus();
      }
    }
  });
}

if (saleQuantityInput) {
  saleQuantityInput.addEventListener('input', updateSaleSummary);
  saleQuantityInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      addSaleItem();
    }
  });
}

if (saleModeSelect) {
  saleModeSelect.addEventListener('change', updateSaleSummary);
}

if (barcodeInput) {
  barcodeInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      handleBarcodeScan();
    }
  });

  barcodeInput.addEventListener('input', () => {
    if (barcodeScanTimer) {
      clearTimeout(barcodeScanTimer);
    }
    barcodeScanTimer = setTimeout(() => {
      if (barcodeInput.value.trim()) {
        handleBarcodeScan();
      }
      barcodeScanTimer = null;
    }, 100);
  });
}

if (barcodeScanTrigger) {
  barcodeScanTrigger.addEventListener('click', () => {
    if (barcodeInput && barcodeInput.value.trim()) {
      handleBarcodeScan();
    } else {
      barcodeInput?.focus();
      barcodeInput?.select();
    }
  });
}

if (cameraScanButton) {
  cameraScanButton.addEventListener('click', () => {
    console.log('cameraScanButton clicked');
    startCameraScanner();
  });
}

if (cameraStopButton) {
  cameraStopButton.addEventListener('click', () => {
    console.log('cameraStopButton clicked');
    stopCameraScanner();
  });
}

if (amountPaidInput) {
    amountPaidInput.addEventListener('input', () => {
      updateSaleSummary();
      updateWizardSummary();
    });

}

if (salesForm) {
  salesForm.addEventListener('submit', (event) => {
    if (salesForm.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }

    const method = paymentMethodSelect?.value || 'cash';
    const total = getSaleGrandTotal();
    const paid = parseFloat(amountPaidInput?.value || '0') || 0;
    const cashApplied = parseFloat(document.getElementById('equityCashAmountModal')?.value || '0') || 0;
    const equityAmount = parseFloat(document.getElementById('equityPaymentAmountInput')?.value || '0') || 0;
    const transactionCode = document.getElementById('transactionCodeInput')?.value.trim() || '';
    if (method === 'equity' && !transactionCode) {
      event.preventDefault();
      const modal = document.getElementById('equityPaymentModal');
      modal?.classList.remove('hidden');
      modal?.classList.add('flex');
      document.getElementById('equityTransactionCodeModal')?.focus();
      return;
    }
    if (method === 'cash' && paid < total) {
      event.preventDefault();
      amountPaidInput?.focus();
      showToast('Cash received cannot be less than the sale total.', 2600, 'error');
      return;
    }
    if (method === 'equity' && (paid + 0.01 < cashApplied || Math.abs((cashApplied + equityAmount) - total) > 0.01)) {
      event.preventDefault();
      showToast('Cash and Equity amounts must equal the sale total.', 2600, 'error');
      return;
    }

    salesForm.dataset.submitting = '1';
    salesForm.querySelectorAll('button[type="submit"]').forEach((button) => {
      button.disabled = true;
      button.classList.add('opacity-60', 'cursor-not-allowed');
    });
  });
}

function resetSalePaymentField() {
  if (!amountPaidInput) {
    return;
  }

  amountPaidInput.value = '0';
  amountPaidInput.readOnly = paymentMethodSelect?.value === 'credit';
  amountPaidInput.classList.toggle('bg-slate-100', paymentMethodSelect?.value === 'credit');
}

function updateCreditFieldVisibility() {
  const isCredit = paymentMethodSelect?.value === 'credit';
  const isEquity = paymentMethodSelect?.value === 'equity';
  const creditCustomerField = document.getElementById('creditCustomerField');
  const equityPaymentModal = document.getElementById('equityPaymentModal');
  const equityPaymentAmount = document.getElementById('equityPaymentAmount');
  const equityPaymentAmountInput = document.getElementById('equityPaymentAmountInput');
  const equityCashAmountModal = document.getElementById('equityCashAmountModal');
  const equityCashTenderedModal = document.getElementById('equityCashTenderedModal');
  const equityTransactionCodeModal = document.getElementById('equityTransactionCodeModal');
  const transactionCodeInput = document.getElementById('transactionCodeInput');
  if (creditCustomerField) {
    creditCustomerField.classList.toggle('hidden', !isCredit);
  }

  if (amountPaidInput) {
    if (isCredit) {
      amountPaidInput.value = '0';
      amountPaidInput.readOnly = true;
      amountPaidInput.classList.add('bg-slate-100');
    } else if (isEquity) {
      amountPaidInput.value = '0.00';
      amountPaidInput.readOnly = true;
      amountPaidInput.classList.add('bg-slate-100');
    } else {
      amountPaidInput.readOnly = false;
      amountPaidInput.classList.remove('bg-slate-100');
    }
  }

  if (isEquity && equityPaymentModal) {
    const total = getSaleGrandTotal();
    if (equityPaymentAmount) {
      equityPaymentAmount.textContent = `KES ${total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
    if (equityPaymentAmountInput) {
      equityPaymentAmountInput.value = total.toFixed(2);
    }
    if (equityCashAmountModal) {
      equityCashAmountModal.value = '0.00';
    }
    if (equityCashTenderedModal) {
      equityCashTenderedModal.value = '0.00';
    }
    equityPaymentModal.classList.remove('hidden');
    equityPaymentModal.classList.add('flex');
    equityTransactionCodeModal?.focus();
  }
}

function resetSaleCartState() {
  if (salesForm) {
    salesForm.dataset.submitting = '0';
    salesForm.querySelectorAll('button[type="submit"]').forEach((button) => {
      button.disabled = false;
      button.classList.remove('opacity-60', 'cursor-not-allowed');
    });
  }

  if (paymentMethodSelect) {
    paymentMethodSelect.value = 'cash';
  }

  if (productSelect) {
    productSelect.value = '';
  }
  if (productSearchInput) {
    productSearchInput.value = '';
  }
  if (saleQuantityInput) {
    saleQuantityInput.value = '0.25';
  }
  if (saleModeSelect) {
    saleModeSelect.value = 'unit';
  }
  if (saleUnitPriceInput) {
    saleUnitPriceInput.value = '0';
  }
  if (saleUnitPriceDisplay) {
    saleUnitPriceDisplay.value = 'KES 0.00';
  }
  if (barcodeInput) {
    barcodeInput.value = '';
  }
  if (saleItemsList) {
    saleItemsList.innerHTML = '';
    renderEmptyPlaceholder();
  }

  resetSalePaymentField();
  updateCreditFieldVisibility();
  updateProductInfo();
  updateSaleSummary();
  updateGrandTotal();
}

if (paymentMethodSelect) {
  paymentMethodSelect.addEventListener('change', () => {
    updateCreditFieldVisibility();
    updateSaleSummary();
    updateWizardSummary();
  });
  updateCreditFieldVisibility();
}

function closeEquityPaymentModal() {
  const modal = document.getElementById('equityPaymentModal');
  modal?.classList.add('hidden');
  modal?.classList.remove('flex');
}

document.getElementById('confirmEquityPaymentModal')?.addEventListener('click', () => {
  const modalCode = document.getElementById('equityTransactionCodeModal');
  const transactionCodeInput = document.getElementById('transactionCodeInput');
  const code = modalCode?.value.trim() || '';
  const total = getSaleGrandTotal();
  const cashAmount = parseFloat(document.getElementById('equityCashAmountModal')?.value || '0') || 0;
  const cashTendered = parseFloat(document.getElementById('equityCashTenderedModal')?.value || '0') || 0;
  const equityAmount = parseFloat(document.getElementById('equityPaymentAmountInput')?.value || '0') || 0;
  if (!code) {
    modalCode?.focus();
    showToast('Enter the Equity transaction number.', 2600, 'error');
    return;
  }
  if (cashAmount < 0 || equityAmount < 0 || cashTendered < cashAmount || Math.abs((cashAmount + equityAmount) - total) > 0.01) {
    showToast('Cash and Equity amounts must equal the sale total.', 2600, 'error');
    return;
  }
  if (amountPaidInput) {
    amountPaidInput.value = cashTendered.toFixed(2);
  }
  if (transactionCodeInput) {
    transactionCodeInput.value = code;
  }
  closeEquityPaymentModal();
});

document.getElementById('closeEquityPaymentModal')?.addEventListener('click', () => {
  const transactionCodeInput = document.getElementById('transactionCodeInput');
  if (transactionCodeInput) {
    transactionCodeInput.value = '';
  }
  if (paymentMethodSelect) {
    paymentMethodSelect.value = 'cash';
    paymentMethodSelect.dispatchEvent(new Event('change'));
  }
  closeEquityPaymentModal();
});

document.getElementById('cancelEquityPaymentModal')?.addEventListener('click', () => {
  const transactionCodeInput = document.getElementById('transactionCodeInput');
  if (transactionCodeInput) {
    transactionCodeInput.value = '';
  }
  if (paymentMethodSelect) {
    paymentMethodSelect.value = 'cash';
    paymentMethodSelect.dispatchEvent(new Event('change'));
  }
  closeEquityPaymentModal();
});

window.addEventListener('pageshow', (event) => {
  const restoredFromCache = event.persisted || performance.getEntriesByType('navigation')[0]?.type === 'back_forward';
  if (restoredFromCache) {
    resetSaleCartState();
  }
});

window.addEventListener('load', () => {
  resetSaleCartState();
});

if (applyCustomQtyButton) {
  applyCustomQtyButton.addEventListener('click', () => {
    if (!customQuantityInput || !saleQuantityInput) {
      return;
    }
    const value = parseFloat(customQuantityInput.value);
    if (Number.isFinite(value) && value > 0) {
      saleQuantityInput.value = value;
      updateSaleSummary();
      customQuantityInput.value = '';
    }
  });
}

function hasSelectedProductForWizard() {
  if (!productSelect) {
    return false;
  }

  const selectedValue = String(productSelect.value || '').trim();
  const selectedOption = productSelect.selectedOptions?.[0];
  const selectedName = String(selectedOption?.dataset?.name || '').trim();
  const productLabel = String(selectedProductName?.textContent || '').trim();

  return selectedValue !== '' || selectedName !== '' || (productLabel !== '' && productLabel.toLowerCase() !== 'none');
}

function hasSaleItemsInCart() {
  return !!saleItemsList && saleItemsList.querySelectorAll('input[name="product_id[]"]').length > 0;
}

if (wizardNextToReviewButton) {
  wizardNextToReviewButton.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();

    if (!hasSaleItemsInCart()) {
      if (!hasSelectedProductForWizard()) {
        showToast('Select a product first, then continue.', 1800, 'error');
        return;
      }
      addSaleItem();
    }

    if (!hasSaleItemsInCart()) {
      showToast('Select a product first, then continue.', 1800, 'error');
      return;
    }

    setWizardStep(2);
  });
}

if (wizardBackToQtyButton) {
  wizardBackToQtyButton.addEventListener('click', () => {
    setWizardStep(1);
  });
}

if (wizardAddAnotherItemButton) {
  wizardAddAnotherItemButton.addEventListener('click', () => {
    setWizardStep(1);
    // focus barcode input to allow quick scanning/entry
    if (barcodeInput) {
      barcodeInput.focus();
      barcodeInput.select();
    }
  });
}

setWizardStep(1);

if (addSaleItemButton) {
  addSaleItemButton.addEventListener('click', addSaleItem);
}
else {
  // Fallback: delegate clicks in case the button is rendered later or reflowed
  document.addEventListener('click', (ev) => {
    const target = ev.target;
    if (target && target.id === 'addSaleItem') {
      addSaleItem();
    }
  });
}

renderEmptyPlaceholder();
updateProductInfo();
updateSaleSummary();
updateGrandTotal();
