const cart = [];
const cartList = document.getElementById('cartList');
const cartTotal = document.getElementById('cartTotal');
const checkoutBtn = document.getElementById('checkoutBtn');

function formatCurrency(value) {
  return `$${value.toFixed(2)}`;
}

function renderCart() {
  cartList.innerHTML = '';

  if (cart.length === 0) {
    cartList.innerHTML = '<p class="text-slate-500">Your cart is empty.</p>';
    cartTotal.textContent = '$0.00';
    return;
  }

  let total = 0;

  cart.forEach((item, index) => {
    total += item.price * item.quantity;

    const itemRow = document.createElement('div');
    itemRow.className = 'flex items-center justify-between rounded-3xl border border-slate-200 bg-slate-50 p-4';
    itemRow.innerHTML = `
      <div>
        <p class="font-semibold text-slate-900">${item.name}</p>
        <p class="text-sm text-slate-500">Qty: ${item.quantity}</p>
      </div>
      <span class="text-slate-900">${formatCurrency(item.price * item.quantity)}</span>
    `;

    cartList.appendChild(itemRow);
  });

  cartTotal.textContent = formatCurrency(total);
}

function addToCart(product) {
  const existing = cart.find((item) => item.id === product.id);
  if (existing) {
    existing.quantity += 1;
  } else {
    cart.push({ ...product, quantity: 1 });
  }
  renderCart();
}

document.querySelectorAll('.add-to-cart').forEach((button) => {
  button.addEventListener('click', () => {
    const product = {
      id: Number(button.dataset.id),
      name: button.dataset.name,
      price: Number(button.dataset.price),
    };
    addToCart(product);
  });
});

checkoutBtn.addEventListener('click', () => {
  if (cart.length === 0) {
    alert('Add some products to the cart first.');
    return;
  }

  alert('Checkout placeholder: implement order submission with PHP and MySQL.');
});

renderCart();
