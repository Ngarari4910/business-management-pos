const TOTAL_STEPS = 6;
const stepNames = ['Supplier', 'Receipt', 'Product', 'Packaging', 'Cost', 'Review'];
const steps = Array.from({ length: TOTAL_STEPS }, (_, i) => i + 1);
const stepPanels = steps.map((step) => document.getElementById(`step${step}`));
const supplierSearch = document.getElementById('supplierSearch');
const supplierSuggestions = document.getElementById('supplierSuggestions');
const supplierIdInput = document.getElementById('supplierId');
const supplierNameInput = document.getElementById('supplierName');
const newSupplierSection = document.getElementById('newSupplierSection');
const productBlocksContainer = document.getElementById('productBlocks');
const addBaleProductButton = document.getElementById('addBaleProduct');
const receiptDetails = document.getElementById('receiptDetails');
const receiptProvidedInputs = document.querySelectorAll('input[name="receipt_provided"]');
const receiptCameraButton = document.getElementById('openReceiptCameraButton');
const receiptPhotoInput = document.getElementById('receiptPhotoInput');
const receiptPhotoLabel = document.getElementById('receiptPhotoLabel');
const intakeForm = document.getElementById('intakeForm');
const reviewSummary = document.getElementById('reviewSummary');
const productPackageSummary = document.getElementById('productPackageSummary');
const progressFill = document.getElementById('progressFill');
const currentStepLabel = document.getElementById('currentStepLabel');
const currentStepName = document.getElementById('currentStepName');
const totalCostDisplay = document.getElementById('calculatedTotalCost');
const stepBadges = Array.from(document.querySelectorAll('.step-badge'));
const toastContainer = document.getElementById('toastContainer');
const PRODUCTS = Array.isArray(window.STOCK_INTAKE_PRODUCTS) ? window.STOCK_INTAKE_PRODUCTS : [];
const SERVER_ERRORS = Array.isArray(window.STOCK_INTAKE_ERRORS) ? window.STOCK_INTAKE_ERRORS : [];
const INTAKE_SUCCESS = window.STOCK_INTAKE_SUCCESS === true || new URLSearchParams(window.location.search).get('success') === '1';
const SUBMITTED_PACKAGE_ENTRIES = Array.isArray(window.STOCK_INTAKE_SUBMITTED_ENTRIES) ? window.STOCK_INTAKE_SUBMITTED_ENTRIES : [];

function showStep(step) {
  stepPanels.forEach((panel, index) => {
    if (panel) {
      panel.classList.toggle('hidden', index !== step - 1);
    }
  });

  const progress = ((step - 1) / (TOTAL_STEPS - 1)) * 100;
  progressFill.style.width = `${progress}%`;
  currentStepLabel.textContent = step;
  currentStepName.textContent = stepNames[step - 1];

  stepBadges.forEach((badge, index) => {
    const active = index <= step - 1;
    badge.classList.toggle('bg-emerald-400', active);
    badge.classList.toggle('text-white', active);
    badge.classList.toggle('bg-slate-800', !active);
    badge.classList.toggle('text-slate-400', !active);
  });

  const activePanel = stepPanels[step - 1];
  if (activePanel) {
    activePanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // show/hide the toggle-all button only on the Products step (step 3)
  const toggleAllBtn = document.getElementById('toggleAllProductBlocks');
  if (toggleAllBtn) {
    if (step === 3) {
      toggleAllBtn.classList.remove('hidden');
    } else {
      toggleAllBtn.classList.add('hidden');
    }
  }
  // show/hide FAB only on Products step
  const addFab = document.getElementById('addPackageEntryFAB');
  if (addFab) {
    if (step === 3) addFab.classList.remove('hidden'); else addFab.classList.add('hidden');
  }

  if (step === 4) {
    renderProductPackageSummary();
  }

  if (step > 1) {
    showToast(`Moved to ${stepNames[step - 1]}.`, 'info', 1200);
  }
}

if (receiptCameraButton && receiptPhotoInput) {
  receiptCameraButton.addEventListener('click', () => {
    receiptPhotoInput.click();
  });

  receiptPhotoInput.addEventListener('change', (event) => {
    const files = Array.from(event.target.files || []);
    updateReceiptPhotoLabel(files);
    if (files.length) {
      const message = files.length > 1 ? `${files.length} receipt photos selected.` : 'Receipt photo ready to upload.';
      showToast(message, 'success', 1400);
    }
  });
}

function updateReceiptPhotoLabel(files) {
  if (!receiptPhotoLabel) return;

  const selectedFiles = Array.isArray(files) ? files : files ? [files] : [];
  if (selectedFiles.length > 0) {
    const names = selectedFiles
      .map((file) => file && file.name ? file.name : '')
      .filter(Boolean);

    if (names.length > 1) {
      receiptPhotoLabel.textContent = `${names.length} files selected: ${names.slice(0, 2).join(', ')}${names.length > 2 ? '...' : ''}`;
      return;
    }

    receiptPhotoLabel.textContent = `Selected: ${names[0]}`;
    return;
  }

  receiptPhotoLabel.textContent = 'No photo selected yet';
}

function showToast(message, type = 'success', duration = 2400) {
  if (!toastContainer) return;
  const toast = document.createElement('div');
  toast.className = `toast-message ${type}`;
  toast.innerHTML = `
    <div class="flex items-start gap-3">
      <div class="toast-icon">${type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ️'}</div>
      <div class="grow text-sm leading-6">${message}</div>
      <button type="button" class="toast-close text-slate-500 hover:text-slate-700">×</button>
    </div>
  `;

  toastContainer.appendChild(toast);
  setTimeout(() => toast.classList.add('visible'), 20);

  const removeToast = () => {
    toast.classList.remove('visible');
    setTimeout(() => toast.remove(), 300);
  };

  toast.querySelector('.toast-close')?.addEventListener('click', removeToast);
  setTimeout(removeToast, duration);
}

function collapseProductBlock(block) {
  const summary = block.querySelector('.product-summary');
  const body = block.querySelector('.product-body');
  if (!summary || !body) return;
  body.classList.add('hidden');
  summary.classList.remove('hidden');
}

function expandProductBlock(block) {
  const summary = block.querySelector('.product-summary');
  const body = block.querySelector('.product-body');
  if (!summary || !body) return;
  body.classList.remove('hidden');
  summary.classList.add('hidden');
}

function collapseAllProductBlocks() {
  productBlocksContainer.querySelectorAll('.product-block').forEach((block) => collapseProductBlock(block));
}

function updateBlockSummary(block) {
  const summary = block.querySelector('.product-summary');
  if (!summary) return;
  const brand = block.querySelector('[data-product-field][data-field="product_brand"]').value.trim() || 'Untitled';
  const name = block.querySelector('[data-product-field][data-field="product_name"]').value.trim() || 'product';
  const category = block.querySelector('[data-product-field][data-field="product_category"]').value.trim() || 'No category';
  const baseUnit = block.querySelector('[data-product-field][data-field="product_base_unit"]').value.trim() || 'unit';
  const lines = block.querySelector('[data-package-lines]').children.length;
  summary.innerHTML = `
    <div class="font-semibold text-slate-900">${brand} ${name}</div>
    <div class="text-xs text-slate-500">${category} · Base unit: ${baseUnit}</div>
    <div class="mt-2 text-xs text-slate-500">${lines} package line${lines === 1 ? '' : 's'}</div>
    <div class="mt-3 text-xs font-semibold text-slate-700">Click to edit this product</div>
  `;
}

function updateSupplierSelection(supplier) {
  supplierIdInput.value = supplier.id;
  supplierSearch.value = supplier.name;
  supplierNameInput.value = '';
  supplierNameInput.closest('label')?.classList.remove('border-red-500');
  if (newSupplierSection) {
    newSupplierSection.classList.add('hidden');
  }
  showToast(`Supplier selected: ${supplier.name}`, 'success');
}

function syncProductBlockIndexes() {
  Array.from(productBlocksContainer.children).forEach((block, index) => {
    block.dataset.index = index;
    block.querySelectorAll('[data-product-field]').forEach((input) => {
      input.name = `${input.dataset.field}[]`;
    });
    block.querySelectorAll('[data-package-field]').forEach((input) => {
      input.name = `${input.dataset.field}[${index}][]`;
    });
  });
}

function renderPackageLine(block, values = {}) {
  const blockIndex = parseInt(block.dataset.index, 10);
  const line = document.createElement('div');
  line.className = 'rounded-3xl border border-slate-200 bg-slate-50 p-4 mt-4';
  line.innerHTML = `
    <div class="grid gap-4 sm:grid-cols-2">
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Received as</span>
        <input data-package-field data-field="package_unit" name="package_unit[${blockIndex}][]" value="${values.package_unit || ''}" type="text" placeholder="Crate, Bag, Packet" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Package size</span>
        <div class="flex flex-col gap-3 sm:flex-row">
          <input data-package-field data-field="package_size_value" name="package_size_value[${blockIndex}][]" value="${values.package_size_value || ''}" type="number" min="0" step="0.01" placeholder="24" class="mt-2 w-full sm:w-1/2 rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
          <input data-package-field data-field="package_size_unit" name="package_size_unit[${blockIndex}][]" value="${values.package_size_unit || ''}" type="text" placeholder="Bottles, kg" class="mt-2 w-full sm:w-1/2 rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </div>
      </label>
    </div>
    <div class="grid gap-4 sm:grid-cols-3 mt-4">
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Quantity</span>
        <input data-package-field data-field="line_quantity" name="line_quantity[${blockIndex}][]" value="${values.quantity || ''}" type="number" min="0" step="0.01" placeholder="10" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Cost per package</span>
        <input data-package-field data-field="cost_per_package" name="cost_per_package[${blockIndex}][]" value="${values.cost_per_package || ''}" type="number" min="0" step="0.01" placeholder="1200" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <div class="block">
        <span class="text-sm font-medium text-slate-700">Base quantity</span>
        <div class="mt-2 rounded-3xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-700" data-base-qty>0</div>
      </div>
    </div>
    <div class="mt-4 flex justify-between items-center">
      <button type="button" class="remove-package-line rounded-3xl bg-rose-100 px-4 py-2 text-rose-700 transition hover:bg-rose-200">Remove</button>
    </div>
  `;

  block.querySelector('[data-package-lines]').appendChild(line);
  updateLineBaseQty(line, block);
  updateCostSummary();

  line.querySelectorAll('input').forEach((input) => {
    input.addEventListener('input', () => {
      updateLineBaseQty(line, block);
      updateCostSummary();
    });
  });

  line.querySelector('.remove-package-line').addEventListener('click', () => {
    const linesContainer = block.querySelector('[data-package-lines]');
    if (linesContainer.children.length > 1) {
      line.remove();
      updateCostSummary();
    }
  });
}

function updateLineBaseQty(line, block) {
  const sizeValue = parseFloat(line.querySelector('[data-package-field][data-field="package_size_value"]').value) || 0;
  const quantity = parseFloat(line.querySelector('[data-package-field][data-field="line_quantity"]').value) || 0;
  const baseQtyElement = line.querySelector('[data-base-qty]');
  const productBaseUnit = block.querySelector('[data-product-field][data-field="product_base_unit"]').value || '';
  const baseQty = quantity * sizeValue;
  baseQtyElement.textContent = `${baseQty.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${productBaseUnit}`.trim();
}

function calculateTotalCost() {
  let total = 0;

  document.querySelectorAll('[data-package-lines]').forEach((linesContainer) => {
    total += Array.from(linesContainer.children).reduce((lineSum, line) => {
      const quantity = parseFloat(line.querySelector('[data-package-field][data-field="line_quantity"]').value) || 0;
      const costPerPackage = parseFloat(line.querySelector('[data-package-field][data-field="cost_per_package"]').value) || 0;
      return lineSum + quantity * costPerPackage;
    }, 0);
  });

  document.querySelectorAll('.product-block[data-block-type="package"]').forEach((block) => {
    const packageSizeValue = parseFloat(block.querySelector('input[name="package_size_value"]').value) || 0;
    const packagesReceived = parseFloat(block.querySelector('input[name="packages_received"]').value) || 0;
    const costPerPackage = parseFloat(block.querySelector('input[name="cost_per_package"]').value) || 0;
    total += packagesReceived * costPerPackage;
  });

  return total;
}

function updateCostSummary() {
  if (!totalCostDisplay) return;
  const totalCost = calculateTotalCost();
  totalCostDisplay.textContent = `KES ${totalCost.toFixed(2)}`;
}

const INTAKE_DRAFT_KEY = 'pos2-stock-intake-draft';

function setFieldError(field, message) {
  if (!field) return;
  field.classList.toggle('border-rose-500', Boolean(message));
  field.classList.toggle('ring-2', Boolean(message));
  field.classList.toggle('ring-rose-200', Boolean(message));
  field.setAttribute('aria-invalid', message ? 'true' : 'false');
  let error = field.parentElement?.querySelector('[data-inline-error]');
  if (message) {
    if (!error) {
      error = document.createElement('p');
      error.dataset.inlineError = 'true';
      error.className = 'mt-1 text-xs font-medium text-rose-600';
      field.parentElement?.appendChild(error);
    }
    error.textContent = message;
  } else if (error) {
    error.remove();
  }
}

function validateField(field, message, numeric = false) {
  const value = field?.value?.trim() || '';
  const invalid = value === '' || (numeric && (!Number.isFinite(Number(value)) || Number(value) <= 0));
  setFieldError(field, invalid ? message : '');
  return !invalid;
}

function validateIntakeRows(focusFirst = true) {
  const invalidFields = [];
  productBlocksContainer?.querySelectorAll('.product-block').forEach((block) => {
    const packageEntry = block.dataset.blockType === 'package';
    const requiredFields = packageEntry
      ? [
          ['brand', 'Enter the brand.', false],
          ['product_name', 'Enter the product name.', false],
          ['base_unit', 'Enter the base unit.', false],
          ['package_size_value', 'Enter the package size.', true],
          ['packages_received', 'Enter the quantity received.', true],
          ['cost_per_package', 'Enter the cost per package.', true],
          ['retail_price_per_unit', 'Enter the retail price.', true],
          ['wholesale_price_per_package', 'Enter the wholesale price.', true],
        ]
      : [
          ['product_brand', 'Enter the brand.', false],
          ['product_name', 'Enter the product name.', false],
          ['product_category', 'Enter the category.', false],
          ['product_base_unit', 'Enter the base unit.', false],
        ];

    requiredFields.forEach(([name, message, numeric]) => {
      const field = block.querySelector(`[name="${name}"]`);
      if (field && !validateField(field, message, numeric)) invalidFields.push(field);
    });

    if (!packageEntry) {
      const productId = block.querySelector('[name="product_id[]"]')?.value || '';
      if (!productId) {
        ['product_retail_price', 'product_wholesale_price'].forEach((name) => {
          const field = block.querySelector(`[data-field="${name}"]`);
          if (field && !validateField(field, 'Enter a price.', true)) invalidFields.push(field);
        });
      }
      block.querySelectorAll('[data-package-lines] > div').forEach((line) => {
        [['package_unit', 'Enter the package type.', false], ['package_size_value', 'Enter the package size.', true], ['line_quantity', 'Enter the quantity received.', true], ['cost_per_package', 'Enter the cost per package.', true]].forEach(([name, message, numeric]) => {
          const field = line.querySelector(`[data-field="${name}"]`);
          if (field && !validateField(field, message, numeric)) invalidFields.push(field);
        });
      });
    }
  });

  if (focusFirst && invalidFields[0]) {
    const field = invalidFields[0];
    const block = field.closest('.product-block');
    if (block) expandProductBlock(block);
    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
    field.focus({ preventScroll: true });
    showToast('Complete the highlighted field before continuing.', 'error', 2600);
  }
  return invalidFields.length === 0;
}

function validateTouchedField(field) {
  const block = field?.closest('.product-block');
  if (!block) return;
  const name = field.dataset.field || field.name;
  const numeric = ['package_size_value', 'packages_received', 'cost_per_package', 'retail_price_per_unit', 'wholesale_price_per_package', 'product_retail_price', 'product_wholesale_price', 'line_quantity'].includes(name);
  const messages = {
    brand: 'Enter the brand.',
    product_brand: 'Enter the brand.',
    product_name: 'Enter the product name.',
    name: 'Enter the product name.',
    product_category: 'Enter the category.',
    base_unit: 'Enter the base unit.',
    product_base_unit: 'Enter the base unit.',
    package_size_value: 'Enter the package size.',
    packages_received: 'Enter the quantity received.',
    line_quantity: 'Enter the quantity received.',
    cost_per_package: 'Enter the cost per package.',
    retail_price_per_unit: 'Enter the retail price.',
    product_retail_price: 'Enter a price.',
    wholesale_price_per_package: 'Enter the wholesale price.',
    product_wholesale_price: 'Enter a price.',
    package_unit: 'Enter the package type.',
  };
  if (messages[name]) validateField(field, messages[name], numeric);
}

function highlightServerErrors() {
  const fieldsToFocus = [];
  const blocks = Array.from(productBlocksContainer?.querySelectorAll('.product-block') || []);
  SERVER_ERRORS.forEach((error) => {
    const message = String(error).toLowerCase();
    if (message.includes('supplier')) {
      const field = supplierIdInput.value ? supplierSearch : supplierNameInput;
      setFieldError(field, error);
      fieldsToFocus.push(field);
    }
    if (message.includes('receipt number')) {
      const field = document.querySelector('[name="receipt_number"]');
      setFieldError(field, error);
      fieldsToFocus.push(field);
    }
    if (message.includes('receipt date')) {
      const field = document.querySelector('[name="receipt_date"]');
      setFieldError(field, error);
      fieldsToFocus.push(field);
    }

    blocks.forEach((block) => {
      const fields = [];
      if (message.includes('brand')) fields.push(block.querySelector('[name="brand"], [data-field="product_brand"]'));
      if (message.includes('product name') || message.includes('name and variant')) fields.push(block.querySelector('[name="product_name"], [data-field="product_name"]'));
      if (message.includes('category')) fields.push(block.querySelector('[data-field="product_category"]'));
      if (message.includes('base unit')) fields.push(block.querySelector('[name="base_unit"], [data-field="product_base_unit"]'));
      if (message.includes('price')) {
        fields.push(block.querySelector('[name="retail_price_per_unit"], [data-field="product_retail_price"]'));
        fields.push(block.querySelector('[name="wholesale_price_per_package"], [data-field="product_wholesale_price"]'));
      }
      if (message.includes('cost per package')) fields.push(...block.querySelectorAll('[name="cost_per_package"], [data-field="cost_per_package"]'));
      if (message.includes('number of packages') || message.includes('quantity received')) fields.push(block.querySelector('[name="packages_received"], [data-field="line_quantity"]'));
      if (message.includes('size of one package') || message.includes('package size')) fields.push(...block.querySelectorAll('[name="package_size_value"], [data-field="package_size_value"]'));
      fields.filter(Boolean).forEach((field) => {
        setFieldError(field, error);
        fieldsToFocus.push(field);
      });
    });
  });

  const firstField = fieldsToFocus.find(Boolean);
  if (firstField) {
    const block = firstField.closest('.product-block');
    if (block) expandProductBlock(block);
    firstField.scrollIntoView({ behavior: 'smooth', block: 'center' });
    firstField.focus({ preventScroll: true });
  }
}

function captureIntakeDraft() {
  if (!intakeForm) return;
  const fields = {};
  intakeForm.querySelectorAll('[name]').forEach((field) => {
    if (field.type === 'file' || field.name === 'csrf_token' || field.name === 'package_entries_json') return;
    if (field.closest('#productBlocks')) return;
    if (field.type === 'radio' && !field.checked) return;
    if (field.type === 'checkbox') {
      fields[field.name] = field.checked;
    } else {
      fields[field.name] = field.value;
    }
  });

  const blocks = Array.from(productBlocksContainer?.children || []).map((block) => ({
    type: block.dataset.blockType || 'product',
    fields: Object.fromEntries(Array.from(block.querySelectorAll('[name]')).map((field) => [field.name, field.type === 'checkbox' ? field.checked : field.value])),
    lines: Array.from(block.querySelectorAll('[data-package-lines] > div')).map((line) => Object.fromEntries(Array.from(line.querySelectorAll('[name]')).map((field) => [field.dataset.field, field.value]))),
  }));
  sessionStorage.setItem(INTAKE_DRAFT_KEY, JSON.stringify({ fields, blocks }));
}

function restoreIntakeDraft() {
  let draft;
  try {
    draft = JSON.parse(sessionStorage.getItem(INTAKE_DRAFT_KEY) || 'null');
  } catch (error) {
    sessionStorage.removeItem(INTAKE_DRAFT_KEY);
    return false;
  }
  if (!draft) return false;

  Object.entries(draft.fields || {}).forEach(([name, value]) => {
    const fields = document.querySelectorAll(`[name="${name}"]`);
    fields.forEach((field) => {
      if (field.type === 'checkbox') field.checked = Boolean(value);
      else if (field.type === 'radio') field.checked = String(field.value) === String(value);
      else field.value = value;
    });
  });

  (draft.blocks || []).forEach((savedBlock, index) => {
    const block = savedBlock.type === 'package'
      ? createBaleProductBlock(index)
      : createProductBlock(index, savedBlock.fields || {});
    Object.entries(savedBlock.fields || {}).forEach(([name, value]) => {
      const field = block.querySelector(`[name="${name}"]`);
      if (!field) return;
      if (field.type === 'checkbox') field.checked = Boolean(value);
      else if (field.type === 'radio') field.checked = String(field.value) === String(value);
      else field.value = value;
    });
    if (savedBlock.type !== 'package') {
      const lines = block.querySelector('[data-package-lines]');
      (savedBlock.lines || []).slice(1).forEach((line) => renderPackageLine(block, line));
      if (lines) lines.querySelectorAll('[data-package-field]').forEach((field) => {
        const value = savedBlock.lines?.[0]?.[field.dataset.field];
        if (value !== undefined) field.value = value;
      });
    }
    if (savedBlock.type === 'package') {
      generatePackageBarcodes(block);
      updateRepackagingUi(block);
    }
    if (savedBlock.type !== 'package') updateBlockSummary(block);
  });
  syncProductBlockIndexes();
  updateCostSummary();
  document.querySelector('input[name="receipt_provided"]:checked')?.dispatchEvent(new Event('change'));
  showToast('Your previous intake has been restored. Correct the highlighted field and resubmit.', 'info', 4200);
  return true;
}

function restoreSubmittedPackageEntries(entries) {
  if (!Array.isArray(entries) || entries.length === 0 || !productBlocksContainer) {
    return false;
  }

  productBlocksContainer.innerHTML = '';
  entries.forEach((entry, index) => {
    const block = createBaleProductBlock(index);
    const setField = (name, value) => {
      const field = block.querySelector(`[name="${name}"]`);
      if (!field) return;
      if (field.type === 'checkbox') field.checked = Boolean(value);
      else if (field.type === 'radio') field.checked = String(field.value) === String(value);
      else field.value = value ?? '';
    };

    setField('brand', entry.brand || '');
    setField('product_name', entry.product_name || '');
    setField('variant', entry.variant || '');
    setField('base_unit', entry.base_unit || '');
    setField('expiry_date', entry.expiry_date || '');
    setField('package_size_value', entry.package_size_value || '');
    setField('package_size_unit', entry.package_size_unit || '');
    setField('packages_received', entry.packages_received || '');
    setField('cost_per_package', entry.cost_per_package || '');
    setField('retail_price_per_unit', entry.retail_price_per_unit || '');
    setField('wholesale_price_per_package', entry.wholesale_price_per_package || '');
    setField('barcode_package', entry.barcode_package || '');
    setField('barcode_wholesale', entry.barcode_wholesale || '');
    setField('barcode_1kg', entry.barcode_1kg || '');
    setField('barcode_0_5kg', entry.barcode_0_5kg || '');
    setField('barcode_0_25kg', entry.barcode_0_25kg || '');
    setField('barcode_5kg', entry.barcode_5kg || '');
    setField('repack_intake', entry.repack_intake === '1' || entry.repack_intake === true ? '1' : '0');

    const repackPriceFields = {
      'retail_price_0_25kg': entry.repack_price_0_25kg ?? entry.repack_quarter_kg_price ?? '',
      'retail_price_0_5kg': entry.repack_price_0_5kg ?? entry.repack_half_kg_price ?? '',
      'retail_price_1kg': entry.repack_price_1kg ?? entry.repack_one_kg_price ?? '',
      'retail_price_5kg': entry.repack_price_5kg ?? entry.repack_five_kg_price ?? '',
    };
    Object.entries(repackPriceFields).forEach(([name, value]) => setField(name, value));
    generatePackageBarcodes(block);
    updateRepackagingUi(block);
    updateCostSummary();
  });

  syncProductBlockIndexes();
  updateCostSummary();
  return true;
}

// right-side "Add to last product" FAB removed — use left FAB or header controls

function createFloatingAddPackageEntryButton() {
  if (document.getElementById('addPackageEntryFAB')) return;

  const btn = document.createElement('button');
  btn.id = 'addPackageEntryFAB';
  btn.type = 'button';
  btn.title = 'Add package entry';
  btn.className = 'fixed z-50 left-6 bottom-6 w-14 h-14 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-lg hover:scale-105 transition-transform hidden';
  btn.innerHTML = '+';

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    // behave like top addBaleProduct button: collapse others, create new bale product, bring into view
    collapseAllProductBlocks();
    const index = productBlocksContainer.children.length;
    const block = createBaleProductBlock(index);
    expandProductBlock(block);
    block.scrollIntoView({ behavior: 'smooth', block: 'center' });
    // focus first input (brand) if present
    const firstInput = block.querySelector('input[name="brand"]');
    if (firstInput) firstInput.focus();
    showToast('Added new package entry', 'success', 1000);
  });

  document.body.appendChild(btn);
}

/* Paste receipt import modal and parser */
function openPasteReceiptModal() {
  if (document.getElementById('pasteReceiptModal')) return;
  const modal = document.createElement('div');
  modal.id = 'pasteReceiptModal';
  modal.className = 'fixed inset-0 z-60 flex items-start justify-center pt-20';
  modal.innerHTML = `
    <div class="bg-black/40 absolute inset-0"></div>
    <div class="relative z-10 w-full max-w-3xl rounded-2xl bg-white p-6 shadow-lg" style="max-height:80vh; overflow:auto;">
      <h3 class="text-lg font-semibold">Paste WhatsApp receipt text</h3>
      <p class="text-sm text-slate-500 mt-1">Paste the supplier message here. We'll parse product lines and suggest package entries.</p>
      <textarea id="pasteReceiptTextarea" class="mt-4 w-full h-48 rounded-xl border border-slate-200 p-3" placeholder="Paste message here..."></textarea>
      <div class="mt-4 flex items-center gap-3">
        <button id="parseReceiptBtn" class="rounded-3xl bg-emerald-600 px-4 py-2 text-white">Parse</button>
        <button id="closePasteModalBtn" class="rounded-3xl border border-slate-200 px-4 py-2">Close</button>
      </div>
      <div id="pasteReceiptPreview" class="mt-4 space-y-3"></div>
    </div>
  `;

  document.body.appendChild(modal);

  document.getElementById('closePasteModalBtn').addEventListener('click', () => modal.remove());
  document.getElementById('parseReceiptBtn').addEventListener('click', () => {
    const text = document.getElementById('pasteReceiptTextarea').value || '';
    const parsed = parseWhatsAppReceiptText(text);
    renderPastePreview(parsed);
  });
}

function normalizeText(s) {
  return (s || '').replace(/\s+/g, ' ').trim();
}

function parseNumber(s) {
  if (!s) return 0;
  return parseFloat(String(s).replace(/[^0-9.\-]/g, '').replace(/\.(?=.*\.)/, '')) || 0;
}

function parseWhatsAppReceiptText(text) {
  const lines = String(text).split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  const entries = [];
  const lineRegex = /^(?:\d+\.|\-)?\s*(.+?)\s+Qty[:\s]*([0-9.,]+)\s+Amount[:\s]*([0-9,\.]+)/i;
  for (const raw of lines) {
    const m = raw.match(lineRegex);
    if (m) {
      const productRaw = normalizeText(m[1]);
      const isBale = /\bBALE\b/i.test(productRaw) || /\bBAG\b/i.test(productRaw);
      const qty = parseNumber(m[2]);
      const amount = parseNumber(m[3]);
      // attempt to extract package_size like 24*1KG or 12*2KG or 500G
      let package_size_value = '';
      let package_size_unit = '';
      const multMatch = productRaw.match(/(\d+)\s*\*\s*(\d+(?:\.\d+)?)\s*(kg|g|kgs|KG|G|KGS|ltr|l|ml)?/i);
      if (multMatch) {
        // form: <count>*<size><unit> e.g. 12*2KG meaning 12 packets of 2KG each
        const count = parseInt(multMatch[1], 10);
        const sizeNum = multMatch[2];
        const sizeUnit = (multMatch[3] || '').toUpperCase();
        package_size_value = String(count);
        package_size_unit = `${sizeNum}${sizeUnit}`.toUpperCase();
        // if the raw mentions BALE, treat this as bale units
        if (isBale) package_unit = 'bale';
      } else {
        const sizeMatch = productRaw.match(/(\d+(?:\.\d+)?)\s*(KG|KGS|G|LTR|L|ML)/i);
        const isBale = /\bBALE\b/i.test(productRaw) || /\bBAG\b/i.test(productRaw);
        if (sizeMatch) {
          const sizeNum = parseFloat(sizeMatch[1]);
          const sizeUnit = (sizeMatch[2] || '').toUpperCase();
          // If it's a BALE without explicit multiplier, apply domain rules:
          // - 1 KG bale -> 24 packets of 1KG each
          // - 2 KG bale -> 12 packets of 2KG each
          if (isBale && (sizeUnit === 'KG' || sizeUnit === 'KGS')) {
            if (Math.abs(sizeNum - 1) < 0.001) {
              package_size_value = '24';
              package_size_unit = '1KG';
              package_unit = 'bale';
            } else if (Math.abs(sizeNum - 2) < 0.001) {
              package_size_value = '12';
              package_size_unit = '2KG';
              package_unit = 'bale';
            } else {
              // fallback: record the size as-is
              package_size_value = String(sizeNum);
              package_size_unit = sizeUnit;
            }
          } else {
            package_size_value = String(sizeNum);
            package_size_unit = sizeUnit;
          }
        }
      }

      // split brand vs name heuristically: take first token up to first space that looks like brand (all-caps token)
      let brand = '';
      let productName = productRaw;
      const tokens = productRaw.split(/\s+/);
      if (tokens.length > 1 && /^[A-Z0-9\-/]{2,}$/.test(tokens[0])) {
        brand = tokens[0];
        productName = tokens.slice(1).join(' ');
      } else {
        // try first word as brand if it is short uppercase
        if (tokens.length > 1 && /^[A-Z]{2,6}$/.test(tokens[0])) {
          brand = tokens[0];
          productName = tokens.slice(1).join(' ');
        }
      }

      const matchedProduct = fuzzyMatchProduct(productRaw);
      if (!brand && matchedProduct?.product?.brand) {
        brand = matchedProduct.product.brand;
      }
      if (productName === productRaw && matchedProduct?.product?.name) {
        productName = matchedProduct.product.name;
      }

      entries.push({
        raw: raw,
        brand: brand,
        product_name: productName,
        variant: package_size_unit || (isBale ? 'bale' : 'package'),
        package_size_value: package_size_value,
        package_size_unit: package_size_unit,
        package_unit: (typeof package_unit !== 'undefined' ? package_unit : (isBale ? 'bale' : 'package')),
        packages_received: qty,
        cost_per_package: qty > 0 ? amount / qty : amount,
        total_amount: amount,
        _match: matchedProduct,
      });
    }
  }
  return entries;
}

function fuzzyMatchProduct(input) {
  const needle = normalizeText(input).toLowerCase();
  if (!needle) return null;
  let best = null;
  for (const p of PRODUCTS) {
    const hay = normalizeText(((p.brand || '') + ' ' + (p.name || '')).trim()).toLowerCase();
    const hayTokens = hay.split(/\s+/);
    const needleTokens = needle.split(/\s+/);
    let score = 0;
    for (const t of needleTokens) {
      if (!t) continue;
      for (const ht of hayTokens) {
        if (!ht) continue;
        if (ht.includes(t) || t.includes(ht)) score += 1;
      }
    }
    if (!best || score > best.score) {
      best = { score, product: p };
    }
  }
  if (best && best.score > 0) return { product: best.product, score: best.score };
  return null;
}

function renderPastePreview(entries) {
  const preview = document.getElementById('pasteReceiptPreview');
  if (!preview) return;
  preview.innerHTML = '';
  if (!entries || entries.length === 0) {
    preview.innerHTML = '<div class="text-sm text-slate-500">No parsable lines found. Make sure each line contains "Qty:" and "Amount:".</div>';
    return;
  }
  entries.forEach((e, idx) => {
    const card = document.createElement('div');
    card.className = 'rounded-2xl border border-slate-200 p-3 bg-slate-50';
    const matchText = e._match ? `Matched: ${e._match.product.brand || ''} ${e._match.product.name || ''} (score ${e._match.score})` : 'No match';
    card.innerHTML = `
      <div class="text-sm text-slate-700 font-semibold">${escapeHtml(e.product_name)}</div>
      <div class="text-xs text-slate-500">${escapeHtml(e.raw)}</div>
      <div class="mt-2 grid grid-cols-2 gap-3">
        <input data-preview-field="brand" data-idx="${idx}" class="p-2 rounded border" value="${escapeHtml(e.brand)}" placeholder="Brand">
        <input data-preview-field="product_name" data-idx="${idx}" class="p-2 rounded border" value="${escapeHtml(e.product_name)}" placeholder="Product name">
        <input data-preview-field="variant" data-idx="${idx}" class="p-2 rounded border" value="${escapeHtml(e.variant || '')}" placeholder="Variant (optional)">
        <input data-preview-field="package_size_value" data-idx="${idx}" class="p-2 rounded border" value="${escapeHtml(e.package_size_value)}" placeholder="Package size">
        <input data-preview-field="package_size_unit" data-idx="${idx}" class="p-2 rounded border" value="${escapeHtml(e.package_size_unit)}" placeholder="Unit">
        <input data-preview-field="packages_received" data-idx="${idx}" class="p-2 rounded border" value="${escapeHtml(String(e.packages_received))}" placeholder="Qty">
        <input data-preview-field="cost_per_package" data-idx="${idx}" class="p-2 rounded border" value="${escapeHtml(String(e.cost_per_package.toFixed ? e.cost_per_package.toFixed(2) : e.cost_per_package))}" placeholder="Cost per package">
      </div>
      <div class="mt-2 text-xs text-slate-500">${escapeHtml(matchText)}</div>
    `;
    preview.appendChild(card);
  });

  const actions = document.createElement('div');
  actions.className = 'mt-4 flex gap-3';
  actions.innerHTML = `
    <button id="applyParsedBtn" class="rounded-3xl bg-emerald-600 px-4 py-2 text-white">Apply to form</button>
    <button id="cancelParsedBtn" class="rounded-3xl border border-slate-200 px-4 py-2">Cancel</button>
  `;
  preview.appendChild(actions);

  document.getElementById('cancelParsedBtn').addEventListener('click', () => {
    document.getElementById('pasteReceiptModal')?.remove();
  });

  document.getElementById('applyParsedBtn').addEventListener('click', () => {
    // gather edited values
    const edited = entries.map((e, idx) => {
      const brand = document.querySelector(`[data-preview-field=brand][data-idx="${idx}"]`)?.value || e.brand;
      const product_name = document.querySelector(`[data-preview-field=product_name][data-idx="${idx}"]`)?.value || e.product_name;
      const variant = document.querySelector(`[data-preview-field=variant][data-idx="${idx}"]`)?.value || e.variant || '';
      const package_size_value = document.querySelector(`[data-preview-field=package_size_value][data-idx="${idx}"]`)?.value || e.package_size_value;
      const package_size_unit = document.querySelector(`[data-preview-field=package_size_unit][data-idx="${idx}"]`)?.value || e.package_size_unit;
      const packages_received = parseNumber(document.querySelector(`[data-preview-field=packages_received][data-idx="${idx}"]`)?.value) || e.packages_received;
      const cost_per_package = parseNumber(document.querySelector(`[data-preview-field=cost_per_package][data-idx="${idx}"]`)?.value) || e.cost_per_package;
      const package_unit = document.querySelector(`[data-preview-field=package_unit][data-idx="${idx}"]`)?.value || e.package_unit || '';
      return { brand, product_name, variant, package_size_value, package_size_unit, packages_received, cost_per_package, package_unit };
    });
    applyParsedEntries(edited);
    document.getElementById('pasteReceiptModal')?.remove();
  });
}

function applyParsedEntries(entries) {
  if (!entries || entries.length === 0) return;
  // switch to step 3 in case not already
  showStep(3);
  // create bale product blocks for each parsed entry
  entries.forEach((e) => {
    const idx = productBlocksContainer.children.length;
    const block = createBaleProductBlock(idx);
    // fill fields
    const set = (selector, value) => { const el = block.querySelector(selector); if (el) el.value = value; };
    set('input[name="brand"]', e.brand || '');
    set('input[name="product_name"]', e.product_name || '');
    // set variant from parsed package unit/size when possible to satisfy server validation
    let inferredVariant = '';
    if ((e.package_unit || '').toLowerCase() === 'bale' && e.package_size_unit) {
      inferredVariant = e.package_size_unit;
    } else if (e.package_size_unit) {
      inferredVariant = e.package_size_unit;
    }
    set('input[name="variant"]', e.variant || inferredVariant || '');
    set('input[name="base_unit"]', 'unit');
    set('input[name="package_size_value"]', e.package_size_value || '');
    set('input[name="package_size_unit"]', e.package_size_unit || '');
    set('input[name="packages_received"]', String(e.packages_received || ''));
    set('input[name="cost_per_package"]', String(e.cost_per_package || ''));
    // infer retail and wholesale if missing: retail per unit = cost_per_package / package_size_value
    const sizeVal = parseFloat(e.package_size_value) || 0;
    const costVal = parseFloat(e.cost_per_package) || 0;
    if (sizeVal > 0 && costVal > 0) {
      const inferredRetail = (costVal / sizeVal).toFixed(2);
      set('input[name="retail_price_per_unit"]', inferredRetail);
      // set wholesale price for full package to cost (can be adjusted by user)
      set('input[name="wholesale_price_per_package"]', String(costVal.toFixed(2)));
    }
    // generate one package line if none
    const packageLines = block.querySelector('[data-package-lines]');
    if (packageLines && packageLines.children.length === 0) renderPackageLine(block, { package_unit: e.package_unit || 'package', package_size_value: e.package_size_value || '', package_size_unit: e.package_size_unit || '', quantity: e.packages_received || 0, cost_per_package: e.cost_per_package || 0 });
    updateCostSummary();
  });
  syncProductBlockIndexes();
}

function escapeHtml(str) {
  return String(str || '').replace(/[&"'<>]/g, (s) => ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'}[s]));
}

function createToggleAllButton() {
  if (document.getElementById('toggleAllProductBlocks')) return;
  // find the header actions container in step3
  const step3 = document.getElementById('step3');
  if (!step3) return;
  const actionsWrapper = step3.querySelector('.flex.flex-wrap.gap-3') || step3.querySelector('.flex.flex-wrap.items-center.justify-between.gap-4 .flex');
  // fallback: place near addBaleProduct button
  const addWrapper = step3.querySelector('#addBaleProduct')?.parentElement || null;

  const btn = document.createElement('button');
  btn.id = 'toggleAllProductBlocks';
  btn.type = 'button';
  btn.title = 'Collapse all product blocks';
  btn.className = 'rounded-full border border-slate-200 bg-white px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50 hidden';
  btn.textContent = 'Collapse all';

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    const currentlyCollapsed = btn.dataset.collapsed === '1';
    if (currentlyCollapsed) {
      // expand all
      productBlocksContainer.querySelectorAll('.product-block').forEach((b) => expandProductBlock(b));
      btn.textContent = 'Collapse all';
      btn.dataset.collapsed = '0';
      showToast('Expanded all product blocks', 'info', 1000);
    } else {
      // collapse all
      collapseAllProductBlocks();
      btn.textContent = 'Expand all';
      btn.dataset.collapsed = '1';
      showToast('Collapsed all product blocks', 'info', 1000);
    }
  });

  if (addWrapper) {
    addWrapper.appendChild(btn);
  } else if (actionsWrapper) {
    actionsWrapper.appendChild(btn);
  } else {
    // fallback to placing next to add button
    const addBtn = document.getElementById('addBaleProduct');
    if (addBtn && addBtn.parentElement) addBtn.parentElement.appendChild(btn);
    else document.body.appendChild(btn);
  }
}

function collectPackageEntryBlocksForSubmission() {
  if (!productBlocksContainer) return [];

  return Array.from(productBlocksContainer.querySelectorAll('.product-block[data-block-type="package"]')).map((block) => {
    const brand = block.querySelector('input[name="brand"]')?.value.trim() || '';
    const productName = block.querySelector('input[name="product_name"]')?.value.trim() || '';
    const variant = block.querySelector('input[name="variant"]')?.value.trim() || '';
    const baseUnit = block.querySelector('input[name="base_unit"]')?.value.trim() || '';
    const expiryDate = block.querySelector('input[name="expiry_date"]')?.value.trim() || '';
    const packageSizeValue = parseFloat(block.querySelector('input[name="package_size_value"]')?.value) || 0;
    const packageSizeUnit = block.querySelector('input[name="package_size_unit"]')?.value.trim() || '';
    const packagesReceived = parseFloat(block.querySelector('input[name="packages_received"]')?.value) || 0;
    const costPerPackage = parseFloat(block.querySelector('input[name="cost_per_package"]')?.value) || 0;
    const retailPrice = parseFloat(block.querySelector('input[name="retail_price_per_unit"]')?.value) || 0;
    const wholesalePrice = parseFloat(block.querySelector('input[name="wholesale_price_per_package"]')?.value) || 0;
    const repackIntake = block.querySelector('input[name="repack_intake"]')?.checked || false;
    const repackQuarterKgPrice = parseFloat(block.querySelector('input[name="retail_price_0_25kg"]')?.value) || 0;
    const repackHalfKgPrice = parseFloat(block.querySelector('input[name="retail_price_0_5kg"]')?.value) || 0;
    const repackOneKgPrice = parseFloat(block.querySelector('input[name="retail_price_1kg"]')?.value) || 0;
    const repackFiveKgPrice = parseFloat(block.querySelector('input[name="retail_price_5kg"]')?.value) || 0;

    return {
      brand,
      product_name: productName,
      variant,
      base_unit: baseUnit,
      expiry_date: expiryDate,
      package_size_value: packageSizeValue,
      package_size_unit: packageSizeUnit,
      packages_received: packagesReceived,
      cost_per_package: costPerPackage,
      retail_price_per_unit: retailPrice,
      wholesale_price_per_package: wholesalePrice,
      barcode_package: block.querySelector('input[name="barcode_package"]')?.value.trim() || '',
      barcode_wholesale: block.querySelector('input[name="barcode_wholesale"]')?.value.trim() || '',
      barcode_1kg: block.querySelector('input[name="barcode_1kg"]')?.value.trim() || '',
      barcode_0_5kg: block.querySelector('input[name="barcode_0_5kg"]')?.value.trim() || '',
      barcode_0_25kg: block.querySelector('input[name="barcode_0_25kg"]')?.value.trim() || '',
      barcode_5kg: block.querySelector('input[name="barcode_5kg"]')?.value.trim() || '',
      repack_intake: repackIntake ? '1' : '0',
      repack_price_0_25kg: repackQuarterKgPrice,
      repack_price_0_5kg: repackHalfKgPrice,
      repack_price_1kg: repackOneKgPrice,
      repack_price_5kg: repackFiveKgPrice,
    };
  }).filter((entry) => entry.brand || entry.product_name || entry.variant || entry.package_size_value > 0 || entry.packages_received > 0);
}

function attachPackageEntrySubmission() {
  const intakeForm = document.getElementById('intakeForm');
  if (!intakeForm) return;
  const confirmButton = document.getElementById('confirmStockIntakeButton');
  let submissionStarted = false;
  let reenableTimer = null;

  const clearReenable = () => {
    if (reenableTimer) {
      clearTimeout(reenableTimer);
      reenableTimer = null;
    }
  };

  intakeForm.addEventListener('submit', (event) => {
    if (submissionStarted) {
      event.preventDefault();
      return;
    }
    if (!validateIntakeRows(true)) {
      event.preventDefault();
      return;
    }
    const existing = intakeForm.querySelector('input[name="package_entries_json"]');
    if (existing) {
      existing.remove();
    }

    const hiddenPayload = document.createElement('input');
    hiddenPayload.type = 'hidden';
    hiddenPayload.name = 'package_entries_json';
    hiddenPayload.value = JSON.stringify(collectPackageEntryBlocksForSubmission());
    intakeForm.appendChild(hiddenPayload);

    submissionStarted = true;
    if (confirmButton) {
      confirmButton.disabled = true;
      confirmButton.classList.add('cursor-wait', 'opacity-70');
      confirmButton.textContent = 'Saving stock intake...';
    }

    // Failsafe: if the form submission does not navigate the page (e.g. blocked by popup blocker, network issue,
    // or a runtime error), re-enable the button after a timeout so the user can retry.
    clearReenable();
    reenableTimer = setTimeout(() => {
      submissionStarted = false;
      if (confirmButton) {
        confirmButton.disabled = false;
        confirmButton.classList.remove('cursor-wait', 'opacity-70');
        confirmButton.textContent = '✓ Confirm Stock Intake';
      }
      showToast('Submission did not complete — button re-enabled so you can retry. Check your network or the console for errors.', 'error', 6000);
    }, 15000);
  });

  // If the page is unloading (successful navigation), clear the re-enable timer so it doesn't re-enable after navigation.
  window.addEventListener('beforeunload', () => {
    clearReenable();
  });
}


let nextGeneratedBarcodeNumber = 0;

function generateShortBarcodeCode(prefix = 'CS', length = 6) {
  nextGeneratedBarcodeNumber += 1;
  return `${prefix}${String(nextGeneratedBarcodeNumber).padStart(length, '0')}`;
}

function normalizeBarcodeText(value) {
  return value
    .trim()
    .replace(/\s+/g, '-')
    .replace(/[^A-Za-z0-9\-]/g, '')
    .replace(/-+/g, '-')
    .replace(/(^-+)|(-+$)/g, '')
    .toUpperCase();
}

function generatePackageBarcodes(block) {
  const brand = block.querySelector('input[name="brand"]').value.trim();
  const productName = block.querySelector('input[name="product_name"]').value.trim();
  const variant = block.querySelector('input[name="variant"]').value.trim();
  const packageSizeValue = block.querySelector('input[name="package_size_value"]').value.trim();
  const packageSizeUnit = block.querySelector('input[name="package_size_unit"]').value.trim().toUpperCase();
  const generateQuarterKg = block.querySelector('input[name="generate_0_25kg_barcode"]')?.checked;
  const generateHalfKg = block.querySelector('input[name="generate_0_5kg_barcode"]')?.checked;
  const generate1kg = block.querySelector('input[name="generate_1kg_barcode"]')?.checked;
  const generate5kg = block.querySelector('input[name="generate_5kg_barcode"]')?.checked;

  const labelBase = [brand, productName, variant].filter(Boolean).join(' ').trim();
  if (!labelBase) {
    return;
  }

  const sizeSegment = packageSizeValue ? normalizeBarcodeText(`${packageSizeValue}${packageSizeUnit}`) : '';
  const packageBarcode = sizeSegment ? `${labelBase} ${sizeSegment}` : labelBase;
  const wholesaleBarcode = sizeSegment ? `${labelBase} WHOLE-${sizeSegment}` : `${labelBase} WHOLE`;
  const repackTargets = getRepackTargets(packageSizeUnit);
  const packageField = block.querySelector('input[name="barcode_package"]');
  const wholesaleField = block.querySelector('input[name="barcode_wholesale"]');
  const quarterKgField = block.querySelector('input[name="barcode_0_25kg"]');
  const halfKgField = block.querySelector('input[name="barcode_0_5kg"]');
  const oneKgField = block.querySelector('input[name="barcode_1kg"]');
  const fiveKgField = block.querySelector('input[name="barcode_5kg"]');

  const quarterKgBarcode = `${labelBase} ${repackTargets[0].display}`;
  const halfKgBarcode = `${labelBase} ${repackTargets[1].display}`;
  const oneKgBarcode = `${labelBase} ${repackTargets[2].display}`;
  const fiveKgBarcode = `${labelBase} ${repackTargets[3].display}`;

  if (packageField && !packageField.dataset.manual) {
    packageField.value = generateShortBarcodeCode();
  }
  if (wholesaleField && !wholesaleField.dataset.manual) {
    wholesaleField.value = generateShortBarcodeCode();
  }
  if (quarterKgField) {
    if (generateQuarterKg) {
      if (!quarterKgField.dataset.manual) {
        quarterKgField.value = generateShortBarcodeCode();
      }
    } else {
      quarterKgField.value = '';
    }
  }
  if (halfKgField) {
    if (generateHalfKg) {
      if (!halfKgField.dataset.manual) {
        halfKgField.value = generateShortBarcodeCode();
      }
    } else {
      halfKgField.value = '';
    }
  }
  if (oneKgField) {
    if (generate1kg) {
      if (!oneKgField.dataset.manual) {
        oneKgField.value = generateShortBarcodeCode();
      }
    } else {
      oneKgField.value = '';
    }
  }
  if (fiveKgField) {
    if (generate5kg) {
      if (!fiveKgField.dataset.manual) {
        fiveKgField.value = generateShortBarcodeCode();
      }
    } else {
      fiveKgField.value = '';
    }
  }
}

function normalizePackageUnit(packageSizeUnit) {
  const unit = (packageSizeUnit || '').trim().toLowerCase();
  const clean = unit.replace(/\./g, '');

  if (/\bkg\b/.test(clean) || clean.includes('kilogram')) return 'kg';
  if (/\b(g|gram|grams)\b/.test(clean)) return 'g';
  if (/\b(ml|milliliter|millilitre|milliliters|millilitres)\b/.test(clean)) return 'ml';
  if (/\b(l|liter|litre|liters|litres)\b/.test(clean) && !clean.includes('ml')) return 'l';
  if (/\b(packet|pack)\b/.test(clean)) return 'packet';

  return 'other';
}

function getRepackTargets(packageSizeUnit) {
  const unitType = normalizePackageUnit(packageSizeUnit);

  if (unitType === 'kg') {
    return [
      { display: '1/4KG', label: '1/4kg packet', size: 0.25, unit: 'kg' },
      { display: '1/2KG', label: '1/2kg packet', size: 0.5, unit: 'kg' },
      { display: '1KG', label: '1kg packet', size: 1, unit: 'kg' },
      { display: '5KG', label: '5kg packet', size: 5, unit: 'kg' },
    ];
  }

  if (unitType === 'g') {
    return [
      { display: '250G', label: '250g packet', size: 250, unit: 'g' },
      { display: '500G', label: '500g packet', size: 500, unit: 'g' },
      { display: '1000G', label: '1000g packet', size: 1000, unit: 'g' },
      { display: '5000G', label: '5000g packet', size: 5000, unit: 'g' },
    ];
  }

  if (unitType === 'l') {
    return [
      { display: '1/4LTR', label: '1/4ltr bottle', size: 0.25, unit: 'l' },
      { display: '1/2LTR', label: '1/2ltr bottle', size: 0.5, unit: 'l' },
      { display: '1LTR', label: '1ltr bottle', size: 1, unit: 'l' },
      { display: '5LTR', label: '5ltr bottle', size: 5, unit: 'l' },
    ];
  }

  if (unitType === 'ml') {
    return [
      { display: '250ML', label: '250mL bottle', size: 250, unit: 'ml' },
      { display: '500ML', label: '500mL bottle', size: 500, unit: 'ml' },
      { display: '1000ML', label: '1000mL bottle', size: 1000, unit: 'ml' },
      { display: '5000ML', label: '5000mL bottle', size: 5000, unit: 'ml' },
    ];
  }

  return [
    { display: '0.25UNIT', label: '0.25 unit package', size: 0.25, unit: 'unit' },
    { display: '0.5UNIT', label: '0.5 unit package', size: 0.5, unit: 'unit' },
    { display: '1UNIT', label: '1 unit package', size: 1, unit: 'unit' },
    { display: '5UNIT', label: '5 unit package', size: 5, unit: 'unit' },
  ];
}

function getRepackTargetsForBlock(block) {
  const packageSizeUnit = block.querySelector('input[name="package_size_unit"]').value.trim();
  const targets = getRepackTargets(packageSizeUnit);
  const selectors = ['barcode_0_25kg', 'barcode_0_5kg', 'barcode_1kg', 'barcode_5kg'];
  return targets.map((target, index) => ({
    ...target,
    selector: selectors[index],
  }));
}

function getRepackagingConfig(block) {
  const baseUnitInput = block.querySelector('input[name="base_unit"]');
  const packageSizeUnitInput = block.querySelector('input[name="package_size_unit"]');
  const unitType = normalizePackageUnit((baseUnitInput?.value || packageSizeUnitInput?.value || '').trim());

  if (unitType === 'kg') {
    return [
      { key: 'generate_0_25kg_barcode', label: '1/4 KG', fieldName: 'barcode_0_25kg', fieldLabel: 'Barcode — 1/4 KG' },
      { key: 'generate_0_5kg_barcode', label: '1/2 KG', fieldName: 'barcode_0_5kg', fieldLabel: 'Barcode — 1/2 KG' },
      { key: 'generate_1kg_barcode', label: '1 KG', fieldName: 'barcode_1kg', fieldLabel: 'Barcode — 1 KG' },
      { key: 'generate_5kg_barcode', label: '5 KG', fieldName: 'barcode_5kg', fieldLabel: 'Barcode — 5 KG' },
    ];
  }

  if (unitType === 'l') {
    return [
      { key: 'generate_0_25kg_barcode', label: '1/4 LTR', fieldName: 'barcode_0_25kg', fieldLabel: 'Barcode — 1/4 LTR' },
      { key: 'generate_0_5kg_barcode', label: '1/2 LTR', fieldName: 'barcode_0_5kg', fieldLabel: 'Barcode — 1/2 LTR' },
      { key: 'generate_1kg_barcode', label: '1 LTR', fieldName: 'barcode_1kg', fieldLabel: 'Barcode — 1 LTR' },
      { key: 'generate_5kg_barcode', label: '5 LTR', fieldName: 'barcode_5kg', fieldLabel: 'Barcode — 5 LTR' },
    ];
  }

  return null;
}

function updateRepackagingUi(block) {
  const config = getRepackagingConfig(block);
  const optionsWrapper = block.querySelector('[data-repackaging-options]');
  const section = block.querySelector('[data-packet-barcodes-section]');

  if (!optionsWrapper || !section) {
    return;
  }

  if (!config) {
    optionsWrapper.classList.add('hidden');
    section.classList.add('hidden');
    return;
  }

  optionsWrapper.classList.remove('hidden');

  const labels = optionsWrapper.querySelectorAll('[data-repack-option-label]');
  labels.forEach((label, index) => {
    if (config[index]) {
      label.textContent = config[index].label;
    }
  });

  const fieldLabels = section.querySelectorAll('[data-repack-field-label]');
  fieldLabels.forEach((label, index) => {
    if (config[index]) {
      label.textContent = config[index].fieldLabel;
    }
  });

  updatePacketBarcodeSection(block);
}

function getRetailBarcodeCount(block) {
  const packagesReceived = parseFloat(block.querySelector('input[name="packages_received"]').value) || 0;
  const packageSizeValue = parseFloat(block.querySelector('input[name="package_size_value"]').value) || 0;
  const packageSizeUnit = block.querySelector('input[name="package_size_unit"]').value || '';

  if (packagesReceived <= 0 || packageSizeValue <= 0) {
    return 0;
  }

  if (normalizePackageUnit(packageSizeUnit) === 'packet') {
    return Math.max(0, Math.round(packagesReceived * packageSizeValue));
  }

  return Math.max(0, Math.round(packagesReceived));
}

function getPacketLabelCount(block, targetSize, targetUnit) {
  const packagesReceived = parseFloat(block.querySelector('input[name="packages_received"]').value) || 0;
  const packageSizeValue = parseFloat(block.querySelector('input[name="package_size_value"]').value) || 0;
  const packageSizeUnit = block.querySelector('input[name="package_size_unit"]').value || '';

  if (packagesReceived <= 0 || packageSizeValue <= 0) {
    return 0;
  }

  const sourceUnit = normalizePackageUnit(packageSizeUnit);
  const destUnit = normalizePackageUnit(targetUnit || 'kg');

  const convert = (value, from, to) => {
    if (from === to) return value;
    if (from === 'kg' && to === 'g') return value * 1000;
    if (from === 'g' && to === 'kg') return value / 1000;
    if (from === 'l' && to === 'ml') return value * 1000;
    if (from === 'ml' && to === 'l') return value / 1000;
    return null;
  };

  if (sourceUnit === 'packet') {
    const packetCount = packagesReceived * packageSizeValue;
    return Math.max(0, Math.round(packetCount / targetSize));
  }

  if (sourceUnit === 'other' || destUnit === 'other') {
    return Math.max(0, Math.round(packagesReceived * (packageSizeValue / targetSize)));
  }

  if ((sourceUnit === 'kg' || sourceUnit === 'g') && (destUnit === 'kg' || destUnit === 'g')) {
    const sourceValue = convert(packageSizeValue, sourceUnit, destUnit);
    if (sourceValue === null) return 0;
    return Math.max(0, Math.round(packagesReceived * (sourceValue / targetSize)));
  }

  if ((sourceUnit === 'l' || sourceUnit === 'ml') && (destUnit === 'l' || destUnit === 'ml')) {
    const sourceValue = convert(packageSizeValue, sourceUnit, destUnit);
    if (sourceValue === null) return 0;
    return Math.max(0, Math.round(packagesReceived * (sourceValue / targetSize)));
  }

  return 0;
}

function updatePacketBarcodeSection(block) {
  const packetSection = block.querySelector('[data-packet-barcodes-section]');
  const optionsWrapper = block.querySelector('[data-repackaging-options]');
  const config = getRepackagingConfig(block);
  const anyChecked = config ? [
    'generate_0_25kg_barcode',
    'generate_0_5kg_barcode',
    'generate_1kg_barcode',
    'generate_5kg_barcode',
  ].some((name) => block.querySelector(`input[name="${name}"]`)?.checked) : false;

  if (!packetSection || !optionsWrapper) {
    return;
  }

  if (!config) {
    optionsWrapper.classList.add('hidden');
    packetSection.classList.add('hidden');
    return;
  }

  optionsWrapper.classList.remove('hidden');
  packetSection.classList.toggle('hidden', !anyChecked);
}

function attachBarcodeAutoGeneration(block) {
  const watchInputs = block.querySelectorAll('input[name="brand"], input[name="product_name"], input[name="variant"], input[name="base_unit"], input[name="package_size_value"], input[name="package_size_unit"]');
  watchInputs.forEach((input) => {
    input.addEventListener('input', () => {
      generatePackageBarcodes(block);
      updateRepackagingUi(block);
    });
  });

  const packetCheckboxes = block.querySelectorAll('input[name="generate_0_25kg_barcode"], input[name="generate_0_5kg_barcode"], input[name="generate_1kg_barcode"], input[name="generate_5kg_barcode"]');
  packetCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      updatePacketBarcodeSection(block);
      generatePackageBarcodes(block);
    });
  });

  const barcodeFields = block.querySelectorAll('input[name="barcode_package"], input[name="barcode_wholesale"], input[name="barcode_0_25kg"], input[name="barcode_0_5kg"], input[name="barcode_1kg"], input[name="barcode_5kg"]');
  barcodeFields.forEach((input) => {
    input.addEventListener('input', () => {
      if (input.value.trim() === '') {
        delete input.dataset.manual;
      } else {
        input.dataset.manual = 'true';
      }
    });
  });
}

function collectBarcodeLabels() {
  const labels = [];

  // Match review inputs by barcode value only — types/labels may vary in wording.
  const getReviewBarcodeCount = (barcode/*, type*/) => {
    const selector = `[data-review-barcode]`;
    const reviewInputs = document.querySelectorAll(selector);
    for (const reviewInput of reviewInputs) {
      if (String(reviewInput.dataset.reviewBarcode).trim() === String(barcode).trim()) {
        return Math.max(0, parseInt(reviewInput.value, 10) || 0);
      }
    }
    return null;
  };

  document.querySelectorAll('.product-block[data-block-type="package"]').forEach((block) => {
    const brand = block.querySelector('input[name="brand"]').value.trim();
    const name = block.querySelector('input[name="product_name"]').value.trim();
    const variant = block.querySelector('input[name="variant"]').value.trim();
    const productName = [brand, name, variant].filter(Boolean).join(' ');

    const packagesReceived = Math.max(0, parseFloat(block.querySelector('input[name="packages_received"]')?.value) || 0);
    const packageRepackTargets = getRepackTargetsForBlock(block);
    const retailCount = getRetailBarcodeCount(block);
    const fields = [
      { type: 'Whole sale', selector: 'barcode_wholesale', count: packagesReceived },
      { type: 'Packets', selector: 'barcode_package', count: retailCount },
      ...packageRepackTargets.map((target) => ({
        type: target.label,
        selector: target.selector,
        count: getPacketLabelCount(block, target.size, target.unit),
      })),
    ];

    fields.forEach(({ type, selector, count }) => {
      const field = block.querySelector(`input[name="${selector}"]`);
      const barcode = field ? field.value.trim() : '';
      if (!barcode) {
        return;
      }

      const reviewCount = getReviewBarcodeCount(barcode, type);
      const effectiveCount = reviewCount !== null ? reviewCount : count;
      if (effectiveCount <= 0) {
        return;
      }

      for (let i = 0; i < effectiveCount; i += 1) {
        labels.push({ productName: productName || 'Unnamed product', type, barcode });
      }
    });
  });
  return labels;
}

function printBarcodeLabels() {
  const labels = collectBarcodeLabels();
  if (labels.length === 0) {
    showToast('No generated barcodes available to print. Fill in barcode fields first.', 'error', 3200);
    return;
  }

  const confirmed = window.confirm('Load the CS30 label printer paper and select the CS30 printer in the print dialog.\n\nPress OK to continue to print labels, or Cancel to return.');
  if (!confirmed) {
    return;
  }

  const rows = labels.map((label) => `
    <div class="label-card">
      <div class="label-title">${label.productName}</div>
      <div class="label-type">${label.type}</div>
      <svg class="barcode-svg" data-code="${label.barcode}"></svg>
      <div class="label-text">${label.barcode}</div>
      <div class="label-footer">SMART POS DEMO</div>
    </div>
  `).join('');

  const printScriptUrlLocal = `${window.location.origin}${window.location.pathname.replace(/\/[^/]*$/, '')}/node_modules/jsbarcode/dist/JsBarcode.all.min.js`;
  const printScriptUrlCdn = 'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js';
  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Barcode Labels</title>
<style>
  /* Layout tuned for narrow 58mm thermal paper */
  body { font-family: Arial, sans-serif; margin: 0; padding: 6px; background: #f8fafc; color: #111827; }
  .print-wrapper { width: 58mm; max-width: 58mm; margin: 0 auto; }
  h1 { font-size: 16px; margin: 6px 0 8px; text-align: center; }
  .label-grid { display: grid; grid-template-columns: 1fr; gap: 6px; }
  .label-card { border: 1px solid #d1d5db; border-radius: 6px; background: white; padding: 8px; display: flex; flex-direction: column; gap: 6px; align-items: center; }
  .label-title { font-size: 12px; font-weight: 700; margin-bottom: 0; text-align: center; }
  .label-type { font-size: 11px; color: #475569; margin-bottom: 0; text-align: center; }
  .label-text { font-size: 10px; color: #334155; word-break: break-all; text-align: center; }
  .label-footer { font-size: 9px; font-style: italic; color: #64748b; text-align: center; margin-top: 2px; }
  .barcode-svg { width: 100%; height: auto; }
  .error-banner { display: none; margin-bottom: 8px; padding: 8px; border-radius: 8px; background: #fee2e2; color: #991b1b; font-weight: 700; }
  @media print {
    @page { size: 58mm auto; margin: 2mm; }
    body { margin: 0; padding: 0; }
    .print-wrapper { width: 58mm; max-width: 58mm; }
    .label-grid { gap: 4px; }
    .label-card { box-shadow: none; padding: 4px; page-break-inside: avoid; border-radius: 2px; border: none; }
    h1 { display: none; }
    .label-title { font-size: 11px; }
    .label-type { font-size: 10px; }
    .label-text { font-size: 9px; }
  }
</style>
</head>
<body>
  <div id="barcodeLoadError" class="error-banner">Unable to render barcodes. Check the printer script path or open the developer console for details.</div>
  <div class="print-wrapper">
    <h1>Barcode labels</h1>
    <div class="label-grid">${rows}</div>
  </div>
  <script>
    function loadScript(src, onLoad, onError) {
      const script = document.createElement('script');
      script.src = src;
      script.onload = onLoad;
      script.onerror = onError;
      document.head.appendChild(script);
    }

    function showBarcodeError(message) {
      const errorBanner = document.getElementById('barcodeLoadError');
      if (!errorBanner) return;
      errorBanner.textContent = message;
      errorBanner.style.display = 'block';
    }

    function renderBarcodes() {
      const barcodeElements = document.querySelectorAll('.barcode-svg');
      let succeeded = false;

      barcodeElements.forEach((svg) => {
        const code = svg.dataset.code;
        try {
          // Tweak rendering to fit 58mm paper: smaller height and narrower bars
          window.JsBarcode(svg, code, {
            format: 'CODE128',
            displayValue: true,
            fontSize: 10,
            height: 36,
            width: 1,
            margin: 0,
          });
          succeeded = true;
        } catch (error) {
          console.error('JsBarcode render failed', error);
        }
      });

      if (!succeeded || typeof window.JsBarcode !== 'function') {
        showBarcodeError('Barcode generator failed to run. The text values are still visible, but the printed labels may not be scannable.');
      }
    }

    function initializeBarcodeRender() {
      if (typeof window.JsBarcode === 'function') {
        renderBarcodes();
        return;
      }

      showBarcodeError('Barcode script is loading...');
      loadScript('${printScriptUrlLocal}', () => {
        if (typeof window.JsBarcode === 'function') {
          renderBarcodes();
        } else {
          showBarcodeError('Local barcode script loaded but did not initialize. Trying CDN fallback...');
          loadScript('${printScriptUrlCdn}', fallbackSuccess, fallbackFailure);
        }
      }, () => {
        showBarcodeError('Local script load failed. Trying CDN fallback...');
        loadScript('${printScriptUrlCdn}', fallbackSuccess, fallbackFailure);
      });
    }

    function fallbackSuccess() {
      if (typeof window.JsBarcode === 'function') {
        renderBarcodes();
      } else {
        showBarcodeError('CDN script loaded but JsBarcode is unavailable.');
      }
    }

    function fallbackFailure() {
      showBarcodeError('CDN fallback load failed. Printing without rendered barcode art.');
    }

    function notifyParentReady() {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'print-ready', iframeId: 'barcodePrintIframe' }, '*');
      }
    }

    function requestPrint() {
      if (window.print) {
        window.print();
      }
    }

    window.addEventListener('DOMContentLoaded', () => {
      initializeBarcodeRender();
      window.setTimeout(() => {
        if (typeof window.JsBarcode === 'function') {
          renderBarcodes();
          requestPrint();
        }
      }, 500);
    });
  </script>
</body>
</html>`;

  // Before opening a preview, refresh the review panel so counts reflect the latest edits
  try {
    prepareReview();
  } catch (e) {
    console.warn('[stock-intake] prepareReview failed before preview', e);
  }

  // Try opening a visible preview window so the user can inspect labels before printing.
  let popupWin = null;
  try {
    popupWin = window.open('', `barcode_preview_${Date.now()}`, 'toolbar=0,location=0,status=0,menubar=0,width=900,height=700');
  } catch (e) {
    popupWin = null;
  }

  if (popupWin) {
    try {
      popupWin.document.open();
      popupWin.document.write(html);
      popupWin.document.close();
      popupWin.focus();
      // Let the inner script render barcodes; user can inspect then use browser print.
      console.debug('[stock-intake] opened visible preview window for barcode labels');
      return;
    } catch (err) {
      console.warn('[stock-intake] popup preview failed, falling back to hidden iframe', err);
      try { popupWin.close(); } catch (e) { /* ignore */ }
    }
  }

  // Fallback: use a hidden iframe and auto-print like before
  const iframeId = 'barcodePrintIframe';
  const existingFrame = document.getElementById(iframeId);
  if (existingFrame) existingFrame.remove();
  const iframe = document.createElement('iframe');
  iframe.id = iframeId;
  iframe.style.position = 'fixed';
  iframe.style.left = '0';
  iframe.style.top = '0';
  iframe.style.width = '1px';
  iframe.style.height = '1px';
  iframe.style.opacity = '0';
  iframe.style.pointerEvents = 'none';
  iframe.style.border = '0';
  document.body.appendChild(iframe);

  try { iframe.srcdoc = html; } catch (err) {
    const printDoc = iframe.contentWindow?.document;
    if (!printDoc) { showToast('Unable to create print frame.', 'error', 3200); iframe.remove(); return; }
    printDoc.open(); printDoc.write(html); printDoc.close();
  }

  iframe.addEventListener('load', () => {
    try {
      const win = iframe.contentWindow;
      setTimeout(() => { try { win.focus(); if (typeof win.print === 'function') win.print(); } catch (e) { /* ignore */ } finally { setTimeout(() => iframe.remove(), 800); } }, 600);
    } catch (e) { setTimeout(() => iframe.remove(), 800); }
  }, { once: true });

  // Cleanup in case load never fires
  setTimeout(() => { try { iframe.remove(); } catch (e) { /* ignore */ } }, 10000);
}

function filterSuppliers(query) {
  const normalized = query.trim().toLowerCase();
  document.querySelectorAll('.supplier-item').forEach((item) => {
    const name = item.dataset.supplierName.toLowerCase();
    item.style.display = name.includes(normalized) ? 'block' : 'none';
  });
}

function renderSupplierSuggestions(query) {
  if (!supplierSuggestions) return;
  const normalized = query.trim().toLowerCase();
  if (!normalized) {
    supplierSuggestions.classList.add('hidden');
    supplierSuggestions.innerHTML = '';
    return;
  }

  const matches = SUPPLIERS.filter((supplier) => supplier.name.toLowerCase().includes(normalized));
  if (matches.length === 0) {
    supplierSuggestions.innerHTML = '<div class="px-4 py-4 text-sm text-slate-500">No suppliers found.</div>';
    supplierSuggestions.classList.remove('hidden');
    return;
  }

  supplierSuggestions.innerHTML = matches.map((supplier) => `
    <button type="button" class="w-full px-4 py-3 text-left transition hover:bg-slate-100" data-supplier-id="${supplier.id}" data-supplier-name="${supplier.name}">
      <span class="font-medium text-slate-900">${supplier.name}</span>
      <span class="block text-xs text-slate-500">${supplier.kra_pin || 'No KRA PIN'} · ${supplier.phone || 'No phone'}</span>
    </button>
  `).join('');
  supplierSuggestions.classList.remove('hidden');
}

function hideSupplierSuggestions() {
  if (!supplierSuggestions) return;
  supplierSuggestions.classList.add('hidden');
}

function renderProductSuggestions(block, query) {
  const suggestions = block.querySelector('.product-suggestions');
  if (!suggestions) return;

  const normalized = query.trim().toLowerCase();
  if (!normalized) {
    suggestions.classList.add('hidden');
    suggestions.innerHTML = '';
    return;
  }

  const matches = PRODUCTS.filter((product) => {
    const normalizedBrand = product.brand.trim().toLowerCase();
    const normalizedName = product.name.trim().toLowerCase();
    return (
      normalizedBrand.includes(normalized) ||
      normalizedName.includes(normalized) ||
      `${normalizedBrand} ${normalizedName}`.includes(normalized)
    );
  }).slice(0, 8);

  if (matches.length === 0) {
    suggestions.innerHTML = '<div class="px-4 py-4 text-sm text-slate-500">No products found.</div>';
    suggestions.classList.remove('hidden');
    return;
  }

  suggestions.innerHTML = matches.map((product) => `
    <button type="button" class="w-full px-4 py-3 text-left transition hover:bg-slate-100" data-product-id="${product.id}" data-product-brand="${product.brand}" data-product-name="${product.name}" data-product-category="${product.category}" data-product-base-unit="${product.base_unit}" data-product-retail-price="${product.retail_price || product.price || ''}" data-product-wholesale-price="${product.wholesale_price || ''}">
      <div class="font-medium text-slate-900">${product.brand} ${product.name}</div>
      <div class="text-xs text-slate-500">${product.category || 'No category'} · Base unit: ${product.base_unit || '—'}</div>
      <div class="mt-1 text-xs text-slate-500">Price: KES ${Number(product.retail_price || product.price || 0).toFixed(2)} · Stock: ${product.stock || 0}</div>
    </button>
  `).join('');
  suggestions.classList.remove('hidden');
}

function hideProductSuggestions(block) {
  const suggestions = block.querySelector('.product-suggestions');
  if (!suggestions) return;
  suggestions.classList.add('hidden');
}

function searchProduct(block) {
  const productBrandInput = block.querySelector('[data-product-field][data-field="product_brand"]');
  const productNameInput = block.querySelector('[data-product-field][data-field="product_name"]');
  const productCategoryInput = block.querySelector('[data-product-field][data-field="product_category"]');
  const productBaseUnitInput = block.querySelector('[data-product-field][data-field="product_base_unit"]');
  const productRetailPriceInput = block.querySelector('[data-product-field][data-field="product_retail_price"]');
  const productWholesalePriceInput = block.querySelector('[data-product-field][data-field="product_wholesale_price"]');
  const productIdInput = block.querySelector('[data-product-field][data-field="product_id"]');
  const productMatch = block.querySelector('.product-match');

  const rawBrand = productBrandInput.value.trim();
  const rawName = productNameInput.value.trim();
  const normalize = (value) => value.trim().toLowerCase().replace(/\s+/g, ' ');
  const brand = normalize(rawBrand);
  const name = normalize(rawName);

  if (!brand || !name) {
    productMatch.classList.add('hidden');
    productIdInput.value = '';
    return;
  }

  const found = PRODUCTS.find((product) => {
    const productBrand = normalize(product.brand);
    const productName = normalize(product.name);
    return productBrand === brand && productName === name;
  });

  if (found) {
    productMatch.innerHTML = `
      <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-4 text-slate-900">
        <p class="font-semibold">Existing Product Found ✓</p>
        <p class="mt-2">${found.brand}<br>${found.name}<br>Category: ${found.category || '—'}<br>Base Unit: ${found.base_unit}</p>
      </div>
    `;
    productMatch.classList.remove('hidden');
    productIdInput.value = found.id;
    productCategoryInput.value = found.category;
    productBaseUnitInput.value = found.base_unit;
    productRetailPriceInput.value = found.retail_price || found.price || '';
    productWholesalePriceInput.value = found.wholesale_price || '';
  } else {
    productMatch.innerHTML = `
      <div class="rounded-3xl border border-slate-300 bg-slate-50 p-4 text-slate-700">
        <p class="font-semibold">No exact product match yet</p>
        <p class="mt-2">Continue typing or choose one of the suggestions.</p>
      </div>
    `;
    productMatch.classList.remove('hidden');
    productIdInput.value = '';
  }
}

function createProductBlock(index = 0, values = {}) {
  const block = document.createElement('div');
  block.className = 'product-block rounded-[2rem] border border-slate-200 bg-slate-50 p-6 shadow-sm';
  block.dataset.index = index;
  block.innerHTML = `
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h3 class="text-xl font-semibold text-slate-900">Product ${index + 1}</h3>
        <p class="text-sm text-slate-500">Search existing product or enter new product details.</p>
        <div class="product-summary mt-3 rounded-3xl bg-slate-100 px-4 py-3 text-slate-600 hidden"></div>
      </div>
      <button type="button" class="remove-product-block rounded-full border border-rose-200 bg-rose-50 px-5 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">Remove product</button>
    </div>
    <input data-product-field data-field="product_id" type="hidden" name="product_id[]" value="${values.product_id || ''}">
    <div class="product-body">
    <div class="grid gap-4 sm:grid-cols-2 mt-6 product-details">
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Brand</span>
        <input data-product-field data-field="product_brand" name="product_brand[]" value="${values.brand || ''}" type="text" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Name</span>
        <input data-product-field data-field="product_name" name="product_name[]" value="${values.name || ''}" type="text" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Category</span>
        <input data-product-field data-field="product_category" name="product_category[]" value="${values.category || ''}" type="text" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Base unit</span>
        <input data-product-field data-field="product_base_unit" name="product_base_unit[]" value="${values.base_unit || ''}" type="text" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Expiry date</span>
        <input data-product-field data-field="product_expiry_date" name="expiry_date[]" value="${values.expiry_date || ''}" type="date" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Retail price</span>
        <input data-product-field data-field="product_retail_price" name="product_retail_price[]" value="${values.retail_price || ''}" type="number" min="0" step="0.01" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Wholesale amount for 1 product (used for 6+ sales)</span>
        <input data-product-field data-field="product_wholesale_price" name="product_wholesale_price[]" value="${values.wholesale_price || ''}" type="number" min="0" step="0.01" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
    </div>
    <div class="mt-4 product-match"></div>
    <div class="product-suggestions absolute z-30 mt-2 w-full rounded-3xl border border-slate-200 bg-white shadow-lg hidden"></div>
    <div class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 product-details">
      <div class="flex items-center justify-between gap-4">
        <div>
          <p class="text-sm uppercase tracking-[0.2em] text-slate-500">Packaging lines</p>
          <p class="text-sm text-slate-500">Each package line adds inventory for this product.</p>
        </div>
        <button type="button" class="add-package-line rounded-full bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">+ Add package line</button>
      </div>
      <div data-package-lines class="mt-4"></div>
    </div>
  `;

  productBlocksContainer.appendChild(block);
  syncProductBlockIndexes();

  // per-block collapse toggle removed: use single control in Products header

  const summary = block.querySelector('.product-summary');
  const details = block.querySelectorAll('.product-details');

  const brandInput = block.querySelector('[data-product-field][data-field="product_brand"]');
  const nameInput = block.querySelector('[data-product-field][data-field="product_name"]');
  const retailInput = block.querySelector('[data-product-field][data-field="product_retail_price"]');
  const wholesaleInput = block.querySelector('[data-product-field][data-field="product_wholesale_price"]');

  const suggestions = block.querySelector('.product-suggestions');
  const productIdInput = block.querySelector('[data-product-field][data-field="product_id"]');
  const productCategoryField = block.querySelector('[data-product-field][data-field="product_category"]');
  const productBaseUnitField = block.querySelector('[data-product-field][data-field="product_base_unit"]');
  const productRetailPriceField = block.querySelector('[data-product-field][data-field="product_retail_price"]');
  const productWholesalePriceField = block.querySelector('[data-product-field][data-field="product_wholesale_price"]');

  [brandInput, nameInput].forEach((input) => {
    input.addEventListener('input', () => {
      const query = `${brandInput.value} ${nameInput.value}`.trim();
      renderProductSuggestions(block, query);
      searchProduct(block);
      updateSummary();
    });
    input.addEventListener('focus', () => {
      const query = `${brandInput.value} ${nameInput.value}`.trim();
      renderProductSuggestions(block, query);
      searchProduct(block);
    });
    input.addEventListener('blur', () => {
      setTimeout(() => hideProductSuggestions(block), 150);
    });
  });

  suggestions.addEventListener('click', (event) => {
    const target = event.target.closest('[data-product-id]');
    if (!target) return;

    brandInput.value = target.dataset.productBrand;
    nameInput.value = target.dataset.productName;
    productCategoryField.value = target.dataset.productCategory || '';
    productBaseUnitField.value = target.dataset.productBaseUnit || '';
    productRetailPriceField.value = target.dataset.productRetailPrice || '';
    productWholesalePriceField.value = target.dataset.productWholesalePrice || '';
    productIdInput.value = target.dataset.productId;
    hideProductSuggestions(block);
    searchProduct(block);
    const packageLines = block.querySelector('[data-package-lines]');
    if (packageLines && packageLines.children.length === 0) {
      const packageLineButton = block.querySelector('.add-package-line');
      packageLineButton?.click();
    }
  });

  // Allow updating retail and wholesale price freely for a selected product.
  [retailInput, wholesaleInput].forEach((input) => {
    input.addEventListener('input', () => {
      // Do not auto-search on price changes to avoid overwriting manually entered values.
    });
  });

  [productCategoryField, productBaseUnitField].forEach((input) => {
    input.addEventListener('input', () => {
      if (productIdInput.value !== '') {
        searchProduct(block);
      }
      updateSummary();
    });
  });

  block.querySelector('.add-package-line').addEventListener('click', () => {
    renderPackageLine(block);
    updateSummary();
  });

  renderPackageLine(block, values.lines?.[0] || {});
  updateSummary();

  summary.addEventListener('click', () => {
    expandProductBlock(block);
  });

  return block;

  function updateSummary() {
    const brand = brandInput.value.trim() || 'Untitled';
    const name = nameInput.value.trim() || 'product';
    const category = productCategoryField.value.trim() || 'No category';
    const baseUnit = productBaseUnitField.value.trim() || 'unit';
    const lines = block.querySelector('[data-package-lines]').children.length;
    summary.innerHTML = `
      <div class="font-semibold text-slate-900">${brand} ${name}</div>
      <div class="text-xs text-slate-500">${category} · Base unit: ${baseUnit}</div>
      <div class="mt-2 text-xs text-slate-500">${lines} package line${lines === 1 ? '' : 's'}</div>
      <div class="mt-3 text-xs font-semibold text-slate-700">Click to edit this product</div>
    `;
  }
}

function createBaleProductBlock(index = 0) {
  const block = document.createElement('div');
  block.className = 'product-block rounded-[2rem] border border-emerald-200 bg-emerald-50/70 p-6 shadow-sm';
  block.dataset.index = index;
  block.dataset.blockType = 'package';
  block.innerHTML = `
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h3 class="text-xl font-semibold text-slate-900">Package entry ${index + 1}</h3>
        <p class="text-sm text-slate-500">Use this for stock received in a larger package and sold either by unit or as a whole package.</p>
      </div>
    </div>

    <input type="hidden" name="simple_bale_intake" value="1">

    <div class="relative mt-6">
      <div class="grid gap-4 grid-cols-1 md:grid-cols-2">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Brand</span>
          <input name="brand" type="text" value="" placeholder="Brand" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Product name</span>
          <input name="product_name" type="text" value="" placeholder="Product name" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
      </div>
      <div class="product-suggestions absolute left-0 right-0 top-full z-30 mt-2 hidden rounded-3xl border border-slate-200 bg-white shadow-lg"></div>
    </div>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Variant</span>
        <input name="variant" type="text" value="" placeholder="Variant" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="flex items-center gap-3 rounded-3xl border border-emerald-200 bg-white/80 px-4 py-3 text-sm text-slate-700">
        <input name="repack_intake" type="checkbox" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600">
        <span>Receiving package that will be repacked</span>
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Base unit</span>
        <input name="base_unit" type="text" value="" placeholder="kg, packet, litre" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Expiry date</span>
        <input name="expiry_date" type="date" value="" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Package size value</span>
        <input name="package_size_value" type="number" min="1" step="0.01" value="" placeholder="25" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Package size unit</span>
        <input name="package_size_unit" type="text" value="" placeholder="kg, packet, litre" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Packages received</span>
        <input name="packages_received" type="number" min="1" step="1" value="" placeholder="1" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Cost per package</span>
        <input name="cost_per_package" type="number" min="0" step="0.01" value="" placeholder="3000" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Retail price per unit</span>
        <input name="retail_price_per_unit" type="number" min="0" step="0.01" value="" placeholder="150" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Wholesale amount for 1 product (used for 6+ sales)</span>
        <input name="wholesale_price_per_package" type="number" min="0" step="0.01" value="" placeholder="3200" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
      </label>
      <div class="col-span-full rounded-3xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Whole-package barcodes</p>
        <p class="text-sm text-slate-500">Use one barcode for the whole box, crate, or bag at wholesale price, and one barcode for each single item inside it at retail price.</p>
        <label class="block mt-3">
          <span class="text-sm font-medium text-slate-700">Wholesale barcode for the full package</span>
          <input name="barcode_wholesale" type="text" value="" placeholder="Barcode for the whole package" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
        <label class="block mt-3">
          <span class="text-sm font-medium text-slate-700">Retail barcode for each item in the package</span>
          <input name="barcode_package" type="text" value="" placeholder="Barcode for one item inside the package" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
      </div>
      <div class="col-span-full rounded-3xl border border-slate-200 bg-slate-50 p-4" data-repackaging-options>
        <p class="text-sm font-semibold text-slate-700">Repackaging barcodes</p>
        <p class="text-sm text-slate-500">Select the repackaging sizes you will create. This section appears for kg or litre-based products.</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <label class="inline-flex items-center gap-2 rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
            <input type="checkbox" name="generate_0_25kg_barcode" class="h-4 w-4 rounded border-slate-300 text-emerald-600">
            <span data-repack-option-label="0">1/4 KG</span>
          </label>
          <label class="inline-flex items-center gap-2 rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
            <input type="checkbox" name="generate_0_5kg_barcode" class="h-4 w-4 rounded border-slate-300 text-emerald-600">
            <span data-repack-option-label="1">1/2 KG</span>
          </label>
          <label class="inline-flex items-center gap-2 rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
            <input type="checkbox" name="generate_1kg_barcode" class="h-4 w-4 rounded border-slate-300 text-emerald-600">
            <span data-repack-option-label="2">1 KG</span>
          </label>
          <label class="inline-flex items-center gap-2 rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
            <input type="checkbox" name="generate_5kg_barcode" class="h-4 w-4 rounded border-slate-300 text-emerald-600">
            <span data-repack-option-label="3">5 KG</span>
          </label>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <label class="block rounded-3xl border border-slate-200 bg-white px-4 py-3">
            <span class="text-sm font-medium text-slate-700">1/4 KG retail price</span>
            <input name="retail_price_0_25kg" type="number" min="0" step="0.01" value="" placeholder="Price" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
          </label>
          <label class="block rounded-3xl border border-slate-200 bg-white px-4 py-3">
            <span class="text-sm font-medium text-slate-700">1/2 KG retail price</span>
            <input name="retail_price_0_5kg" type="number" min="0" step="0.01" value="" placeholder="Price" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
          </label>
          <label class="block rounded-3xl border border-slate-200 bg-white px-4 py-3">
            <span class="text-sm font-medium text-slate-700">1 KG retail price</span>
            <input name="retail_price_1kg" type="number" min="0" step="0.01" value="" placeholder="Price" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
          </label>
          <label class="block rounded-3xl border border-slate-200 bg-white px-4 py-3">
            <span class="text-sm font-medium text-slate-700">5 KG retail price</span>
            <input name="retail_price_5kg" type="number" min="0" step="0.01" value="" placeholder="Price" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
          </label>
        </div>
      </div>
      <div class="col-span-full hidden space-y-4" data-packet-barcodes-section>
        <label class="block">
          <span class="text-sm font-medium text-slate-700" data-repack-field-label="0">Barcode — 1/4 KG</span>
          <input name="barcode_0_25kg" type="text" value="" placeholder="Barcode for 1/4 KG" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
        <label class="block">
          <span class="text-sm font-medium text-slate-700" data-repack-field-label="1">Barcode — 1/2 KG</span>
          <input name="barcode_0_5kg" type="text" value="" placeholder="Barcode for 1/2 KG" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
        <label class="block">
          <span class="text-sm font-medium text-slate-700" data-repack-field-label="2">Barcode — 1 KG</span>
          <input name="barcode_1kg" type="text" value="" placeholder="Barcode for 1 KG" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
        <label class="block">
          <span class="text-sm font-medium text-slate-700" data-repack-field-label="3">Barcode — 5 KG</span>
          <input name="barcode_5kg" type="text" value="" placeholder="Barcode for 5 KG" class="mt-2 w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 focus:border-slate-400 focus:ring-0">
        </label>
      </div>
    </div>
  `;

  productBlocksContainer.appendChild(block);
  syncProductBlockIndexes();

  // Auto-generate barcodes for the new package intake block.
  attachBarcodeAutoGeneration(block);
  updateRepackagingUi(block);
  generatePackageBarcodes(block);

  const brandInput = block.querySelector('input[name="brand"]');
  const productNameInput = block.querySelector('input[name="product_name"]');
  const baseUnitInput = block.querySelector('input[name="base_unit"]');
  const retailPriceInput = block.querySelector('input[name="retail_price_per_unit"]');
  const wholesalePriceInput = block.querySelector('input[name="wholesale_price_per_package"]');
  const suggestions = block.querySelector('.product-suggestions');

  [brandInput, productNameInput].forEach((input) => {
    input.addEventListener('input', () => {
      const query = `${brandInput.value} ${productNameInput.value}`.trim();
      renderProductSuggestions(block, query);
    });
    input.addEventListener('focus', () => {
      const query = `${brandInput.value} ${productNameInput.value}`.trim();
      renderProductSuggestions(block, query);
    });
    input.addEventListener('blur', () => {
      setTimeout(() => hideProductSuggestions(block), 150);
    });
  });

  suggestions.addEventListener('click', (event) => {
    const target = event.target.closest('[data-product-id]');
    if (!target) return;

    brandInput.value = target.dataset.productBrand || '';
    productNameInput.value = target.dataset.productName || '';
    baseUnitInput.value = target.dataset.productBaseUnit || '';
    retailPriceInput.value = target.dataset.productRetailPrice || '';
    wholesalePriceInput.value = target.dataset.productWholesalePrice || '';
    hideProductSuggestions(block);
    generatePackageBarcodes(block);
    updateRepackagingUi(block);
  });

  // Update total cost when simple package intake fields change
  const packageInputs = block.querySelectorAll('input[name="package_size_value"], input[name="packages_received"], input[name="cost_per_package"]');
  packageInputs.forEach((input) => {
    input.addEventListener('input', () => {
      updateCostSummary();
    });
  });

  return block;
}

function prepareReview() {
  const supplier = supplierSearch.value || supplierNameInput.value;
  const supplierText = supplier || 'Not selected';
  const receiptProvided = document.querySelector('input[name="receipt_provided"]:checked')?.value === 'yes';
  const receiptNumber = document.querySelector('input[name="receipt_number"]').value;
  const receiptDate = document.querySelector('input[name="receipt_date"]').value;
  const paymentMethod = document.querySelector('select[name="payment_method"]').value;
  const amountPaid = parseFloat(document.querySelector('input[name="amount_paid"]').value) || 0;

  const products = Array.from(productBlocksContainer.children).map((block) => {
    if (block.dataset.blockType === 'package') {
      const brand = block.querySelector('input[name="brand"]').value;
      const name = block.querySelector('input[name="product_name"]').value;
      const baseUnit = block.querySelector('input[name="base_unit"]').value || 'unit';
      const repackIntake = block.querySelector('input[name="repack_intake"]')?.checked || false;
      const quantity = parseFloat(block.querySelector('input[name="packages_received"]').value) || 0;
      const sizeValue = parseFloat(block.querySelector('input[name="package_size_value"]').value) || 0;
      const sizeUnit = block.querySelector('input[name="package_size_unit"]').value || 'units';
      const costPerPackage = parseFloat(block.querySelector('input[name="cost_per_package"]').value) || 0;
      const totalBase = quantity * sizeValue;
      const totalCost = quantity * costPerPackage;

      const packageRepackTargets = getRepackTargetsForBlock(block);
      const retailCount = getRetailBarcodeCount(block);
      const labelCounts = [
        { label: 'Wholesale', type: 'package', selector: 'barcode_wholesale', count: quantity },
        { label: 'Retail', type: 'package', selector: 'barcode_package', count: retailCount },
        ...packageRepackTargets.map((target) => ({
          label: target.label,
          type: target.unit === 'unit' ? 'unit' : target.unit,
          selector: target.selector,
          count: getPacketLabelCount(block, target.size, target.unit),
        })),
      ].map((item) => {
        const barcodeInput = block.querySelector(`input[name="${item.selector}"]`);
        return {
          label: item.label,
          type: item.type,
          barcode: barcodeInput ? barcodeInput.value.trim() : '',
          count: item.count,
        };
      }).filter((item) => item.barcode);

      // prefer the package_unit value from the first package line if present
      const firstLineUnit = block.querySelector('[data-package-field][data-field="package_unit"]')?.value;
      const resolvedPackageUnit = firstLineUnit || 'package';

      return {
        brand,
        name,
        baseUnit,
        repackIntake,
        lines: [{ packageUnit: resolvedPackageUnit, sizeValue, sizeUnit, quantity, costPerPackage, totalBase, totalCost }],
        labelCounts,
      };
    }

    const brand = block.querySelector('[data-product-field][data-field="product_brand"]').value;
    const name = block.querySelector('[data-product-field][data-field="product_name"]').value;
    const baseUnit = block.querySelector('[data-product-field][data-field="product_base_unit"]').value;
    const lines = Array.from(block.querySelector('[data-package-lines]').children).map((line) => {
      const packageUnit = line.querySelector('[data-package-field][data-field="package_unit"]').value;
      const sizeValue = parseFloat(line.querySelector('[data-package-field][data-field="package_size_value"]').value) || 0;
      const sizeUnit = line.querySelector('[data-package-field][data-field="package_size_unit"]').value;
      const quantity = parseFloat(line.querySelector('[data-package-field][data-field="line_quantity"]').value) || 0;
      const costPerPackage = parseFloat(line.querySelector('[data-package-field][data-field="cost_per_package"]').value) || 0;
      const totalBase = quantity * sizeValue;
      const totalCost = quantity * costPerPackage;
      return { packageUnit, sizeValue, sizeUnit, quantity, costPerPackage, totalBase, totalCost };
    }).filter((line) => line.packageUnit && line.sizeValue > 0 && line.quantity > 0);

    return { brand, name, baseUnit, lines };
  });

  const totalBase = products.flatMap((product) => product.lines).reduce((sum, line) => sum + line.totalBase, 0);
  const totalCost = products.flatMap((product) => product.lines).reduce((sum, line) => sum + line.totalCost, 0);
  const balance = Math.max(0, totalCost - amountPaid);

  reviewSummary.innerHTML = `
    <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
      <h3 class="text-lg font-semibold">Supplier</h3>
      <p class="mt-2">${supplierText}</p>
    </div>
    <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
      <h3 class="text-lg font-semibold">Receipt</h3>
      <p class="mt-2">${receiptProvided ? `Receipt: ${receiptNumber || '—'}<br>Date: ${receiptDate || '—'}` : 'No receipt provided'}</p>
    </div>
    ${products.map((product, index) => `
      <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5" data-review-product-name="${product.brand} ${product.name}">
        <h3 class="text-lg font-semibold">Product ${index + 1}</h3>
        <p class="mt-2">${product.brand || '—'}<br>${product.name || '—'}<br>Base Unit: ${product.baseUnit || '—'}<br>${product.repackIntake ? 'Intake type: Repacking package' : 'Intake type: Standard package'}</p>
        ${product.lines.map((line) => `
          <div class="mt-3 rounded-3xl bg-white p-4 border border-slate-200">
            <p class="font-semibold">${line.quantity} ${line.packageUnit}${line.quantity !== 1 ? 's' : ''}</p>
            <p class="text-sm text-slate-600">1 ${line.packageUnit} = ${line.sizeValue} ${line.sizeUnit}</p>
            <p class="mt-2 text-sm text-slate-700">Total base: ${line.totalBase.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${product.baseUnit}</p>
          </div>
        `).join('')}
        ${product.labelCounts ? `
          <div class="mt-4 rounded-3xl bg-white p-4 border border-slate-200">
            <p class="font-semibold">Barcode printing counts</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              ${product.labelCounts.map((label) => `
                <label class="block">
                  <span class="text-sm font-medium text-slate-700">${label.label}</span>
                  <input type="number" min="0" step="1" value="${label.count}" data-review-barcode-count data-review-barcode="${label.barcode}" data-review-barcode-type="${label.type}" class="mt-2 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-slate-400 focus:ring-0">
                  <p class="text-xs text-slate-500 mt-1">${label.barcode}</p>
                </label>
              `).join('')}
            </div>
            <p class="mt-3 text-sm text-slate-500">Edit counts before printing barcode labels, then press the print button again.</p>
          </div>
        ` : ''}
      </div>
    `).join('')}
    <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
      <h3 class="text-lg font-semibold">Cost & payment</h3>
      <p class="mt-2">Total cost: KES ${totalCost.toFixed(2)}<br>Payment method: ${paymentMethod}<br>Paid: KES ${amountPaid.toFixed(2)}<br>Balance: KES ${balance.toFixed(2)}</p>
    </div>
  `;
}

function renderProductPackageSummary() {
  if (!productPackageSummary) return;

  const products = Array.from(productBlocksContainer.children).map((block, index) => {
    if (block.dataset.blockType === 'package') {
      const brand = block.querySelector('input[name="brand"]').value;
      const name = block.querySelector('input[name="product_name"]').value;
      const baseUnit = block.querySelector('input[name="base_unit"]').value || 'unit';
      const repackIntake = block.querySelector('input[name="repack_intake"]')?.checked || false;
      const quantity = parseFloat(block.querySelector('input[name="packages_received"]').value) || 0;
      const sizeValue = parseFloat(block.querySelector('input[name="package_size_value"]').value) || 0;
      const sizeUnit = block.querySelector('input[name="package_size_unit"]').value || 'units';
      const costPerPackage = parseFloat(block.querySelector('input[name="cost_per_package"]').value) || 0;
      const totalBase = sizeValue * quantity;
      const totalCost = costPerPackage * quantity;
      const firstLineUnit = block.querySelector('[data-package-field][data-field="package_unit"]')?.value;
      const resolvedPackageUnit = firstLineUnit || 'package';
      return {
        brand,
        name,
        baseUnit,
        repackIntake,
        lines: [{ packageUnit: resolvedPackageUnit, sizeValue, sizeUnit, quantity, totalBase, totalCost }],
      };
    }

    const brand = block.querySelector('[data-product-field][data-field="product_brand"]').value;
    const name = block.querySelector('[data-product-field][data-field="product_name"]').value;
    const baseUnit = block.querySelector('[data-product-field][data-field="product_base_unit"]').value;
    const lines = Array.from(block.querySelector('[data-package-lines]').children).map((line) => {
      const packageUnit = line.querySelector('[data-package-field][data-field="package_unit"]').value;
      const sizeValue = parseFloat(line.querySelector('[data-package-field][data-field="package_size_value"]').value) || 0;
      const sizeUnit = line.querySelector('[data-package-field][data-field="package_size_unit"]').value;
      const quantity = parseFloat(line.querySelector('[data-package-field][data-field="line_quantity"]').value) || 0;
      const totalBase = sizeValue * quantity;
      const totalCost = parseFloat(line.querySelector('[data-package-field][data-field="cost_per_package"]').value) * quantity || 0;
      return { packageUnit, sizeValue, sizeUnit, quantity, totalBase, totalCost };
    }).filter((line) => line.packageUnit && line.quantity > 0 && line.sizeValue > 0);

    return { brand, name, baseUnit, lines };
  });

  productPackageSummary.innerHTML = products.map((product, index) => `
    <div class="rounded-3xl border border-slate-200 bg-white p-5">
      <h3 class="text-lg font-semibold">Product ${index + 1}</h3>
      <p class="text-sm text-slate-500">${product.brand || '—'} ${product.name || ''}</p>
      <p class="mt-2 text-sm text-amber-700">${product.repackIntake ? 'Marked for repacking' : 'Standard package intake'}</p>
      ${product.lines.map((line) => `
        <div class="mt-4 rounded-3xl bg-slate-50 p-4 border border-slate-200">
          <p class="font-semibold">${line.quantity} ${line.packageUnit}${line.quantity !== 1 ? 's' : ''}</p>
          <p class="text-sm text-slate-500">1 ${line.packageUnit} = ${line.sizeValue} ${line.sizeUnit}</p>
          <p class="mt-2 text-sm text-slate-700">Total base: ${line.totalBase.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${product.baseUnit}</p>
          <p class="mt-1 text-sm text-slate-700">Line cost: KES ${line.totalCost.toFixed(2)}</p>
        </div>
      `).join('')}
    </div>
  `).join('');
}

function resetWizardState() {
  if (productBlocksContainer) {
    productBlocksContainer.innerHTML = '';
  }
}

function init() {
  resetWizardState();
  if (INTAKE_SUCCESS) {
    sessionStorage.removeItem(INTAKE_DRAFT_KEY);
    sessionStorage.removeItem('pos2-stock-intake-submitted-entries');
    window.setTimeout(() => window.alert('Stock intake saved successfully. The form is ready for another intake.'), 0);
  } else {
    const restoredDraft = restoreIntakeDraft();
    if (!restoredDraft && SUBMITTED_PACKAGE_ENTRIES.length > 0) {
      restoreSubmittedPackageEntries(SUBMITTED_PACKAGE_ENTRIES);
    }
  }
  showStep(1);

  document.querySelectorAll('.step-nav').forEach((button) => {
    button.addEventListener('click', () => {
      const step = Number(button.dataset.step);
      showStep(step);
    });
  });

  supplierSearch.addEventListener('input', (event) => {
    const query = event.target.value;
    filterSuppliers(query);
    renderSupplierSuggestions(query);
  });

  supplierSearch.addEventListener('focus', (event) => {
    renderSupplierSuggestions(event.target.value);
  });

  supplierSearch.addEventListener('blur', () => {
    setTimeout(hideSupplierSuggestions, 150);
  });

  const printButton = document.getElementById('printBarcodeLabelsButton');
  if (!printButton) {
    console.error('[stock-intake] print button not found');
  } else {
    printButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      console.debug('[stock-intake] printBarcodeLabels button clicked');
      printBarcodeLabels();
    });
  }

  // Create supplier button handler (AJAX)
  const createSupplierButton = document.getElementById('createSupplierButton');
  if (createSupplierButton) {
    createSupplierButton.addEventListener('click', async (e) => {
      e.preventDefault();
      const name = (supplierNameInput && supplierNameInput.value || '').trim();
      const kra = document.querySelector('input[name="supplier_kra"]')?.value.trim() || '';
      const phone = document.querySelector('input[name="supplier_phone"]')?.value.trim() || '';
      if (!name) {
        showToast('Please enter a supplier name before creating.', 'error', 3000);
        supplierNameInput?.closest('label')?.classList.add('border-red-500');
        return;
      }

      try {
        createSupplierButton.disabled = true;
        createSupplierButton.textContent = 'Creating...';
        const form = new FormData();
        form.append('name', name);
        form.append('kra', kra);
        form.append('phone', phone);
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput) {
          form.append('csrf_token', csrfInput.value);
        }

        const resp = await fetch('ajax_create_supplier.php', { method: 'POST', body: form });
        const data = await resp.json();
        if (!resp.ok) {
          throw new Error(data.error || 'Failed to create supplier');
        }
        // update UI
        supplierIdInput.value = data.id;
        supplierSearch.value = data.name;
        supplierNameInput.value = '';
        if (newSupplierSection) newSupplierSection.classList.add('hidden');
        showToast('Supplier created and selected.', 'success', 3000);
      } catch (err) {
        console.error('Create supplier failed', err);
        showToast('Failed to create supplier: ' + (err.message || ''), 'error', 4000);
      } finally {
        createSupplierButton.disabled = false;
        createSupplierButton.textContent = 'Create supplier';
      }
    });
  }

  document.addEventListener('click', (event) => {
    const supplierCard = event.target.closest('.supplier-item');
    if (supplierCard) {
      const supplier = {
        id: supplierCard.dataset.supplierId,
        name: supplierCard.dataset.supplierName,
        kra: supplierCard.dataset.supplierKra || '',
        phone: supplierCard.dataset.supplierPhone || '',
      };
      updateSupplierSelection(supplier);
      return;
    }

    const supplierSuggestion = event.target.closest('[data-supplier-id]');
    if (supplierSuggestion && supplierSuggestion.closest('#supplierSuggestions')) {
      const supplier = {
        id: supplierSuggestion.dataset.supplierId,
        name: supplierSuggestion.dataset.supplierName,
        kra: supplierSuggestion.dataset.supplierKra || '',
        phone: supplierSuggestion.dataset.supplierPhone || '',
      };
      updateSupplierSelection(supplier);
      hideSupplierSuggestions();
      return;
    }

    const nextButton = event.target.closest('.next-step');
    if (nextButton) {
      const next = Number(nextButton.dataset.next);
      if ((next === 4 || next === 6) && !validateIntakeRows(true)) return;
      if (next === 4) {
        renderProductPackageSummary();
      }
      if (next === 6) {
        prepareReview();
      }
      showStep(next);
      return;
    }

    const printButton = event.target.closest('#printBarcodeLabelsButton');
    if (printButton) {
      event.preventDefault();
      printBarcodeLabels();
      return;
    }

    const prevButton = event.target.closest('.prev-step');
    if (prevButton) {
      showStep(Number(prevButton.dataset.prev));
      return;
    }

    const removeButton = event.target.closest('.remove-product-block');
    if (removeButton) {
      const block = removeButton.closest('.product-block');
      if (block && productBlocksContainer.children.length > 1) {
        block.remove();
        syncProductBlockIndexes();
        updateCostSummary();
      }
      return;
    }

    const summaryToggle = event.target.closest('.product-summary');
    if (summaryToggle) {
      const block = summaryToggle.closest('.product-block');
      if (block) {
        expandProductBlock(block);
      }
      return;
    }

    if (!supplierSuggestions.contains(event.target) && event.target !== supplierSearch) {
      hideSupplierSuggestions();
    }
  });

  receiptProvidedInputs.forEach((input) => {
    input.addEventListener('change', () => {
      receiptDetails.classList.toggle('hidden', input.value === 'no');
    });
  });

  addBaleProductButton?.addEventListener('click', () => {
    collapseAllProductBlocks();
    const block = createBaleProductBlock(productBlocksContainer.children.length);
    block.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  const pasteBtn = document.getElementById('pasteReceiptBtn');
  if (pasteBtn) {
    pasteBtn.addEventListener('click', (e) => { e.preventDefault(); openPasteReceiptModal(); });
  }

  // create the toggle-all button inside Products header (hidden by default)
  createToggleAllButton();
  // create floating Add Package Entry button so users can add new package entries without scrolling
  createFloatingAddPackageEntryButton();

  attachPackageEntrySubmission();

  intakeForm?.addEventListener('input', (event) => {
    const field = event.target.closest('input, select, textarea');
    if (field) {
      captureIntakeDraft();
    }
    if (field?.getAttribute('aria-invalid') === 'true') {
      setFieldError(field, '');
    }
  });

  intakeForm?.addEventListener('change', () => {
    captureIntakeDraft();
  });

  intakeForm?.addEventListener('blur', (event) => {
    const field = event.target.closest('input, select, textarea');
    if (!field || field.type === 'file') return;
    const block = field.closest('.product-block');
    if (!block) return;
    validateTouchedField(field);
  }, true);

  if (SERVER_ERRORS.length > 0) {
    requestAnimationFrame(() => {
      validateIntakeRows(false);
      highlightServerErrors();
    });
  }
}

init();
