(function () {
  'use strict';

  const BEE       = window.BEE || {};
  const user      = BEE.user || null;
  const CART_KEY  = user ? ('bee_cart_' + user.email) : null;
  const DELIVERY  = {
    pickup:  { name: 'Самовывоз',        price: 0   },
    courier: { name: 'Курьер по городу', price: 250 },
    cdek:    { name: 'СДЭК',             price: 450 },
    post:    { name: 'Почта России',     price: 350 },
  };

  // ============================================================
  // МОДАЛЬНЫЕ ОКНА
  // ============================================================
  const authModal = document.getElementById('authModal');
  const cartModal = document.getElementById('cartModal');

  function openModal(modal) {
    if (!modal) return;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('active');
    document.body.style.overflow = '';
  }

  // ---- Auth modal ----
  const loginBtn = document.getElementById('loginBtn');
  if (loginBtn) loginBtn.addEventListener('click', () => openModal(authModal));

  const authClose = document.getElementById('authModalClose');
  if (authClose) authClose.addEventListener('click', () => closeModal(authModal));

  // ---- Cart modal ----
  const cartBtn = document.getElementById('cartBtn');
  if (cartBtn) cartBtn.addEventListener('click', () => {
    renderCart();
    openModal(cartModal);
  });

  const cartClose = document.getElementById('cartModalClose');
  if (cartClose) cartClose.addEventListener('click', () => closeModal(cartModal));

  // ---- Закрытие по клику на overlay ----
  [authModal, cartModal].forEach(m => {
    if (!m) return;
    m.addEventListener('click', e => { if (e.target === m) closeModal(m); });
  });

  // ---- Закрытие по Esc ----
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      if (authModal) closeModal(authModal);
      if (cartModal) closeModal(cartModal);
    }
  });

  // ---- Табы в auth modal ----
  document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const name = tab.dataset.tab;
      document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
      const lf = document.getElementById('loginForm');
      const rf = document.getElementById('registerForm');
      if (lf) lf.classList.toggle('hidden', name !== 'login');
      if (rf) rf.classList.toggle('hidden', name !== 'register');
    });
  });

  // ---- Если был flash с ошибкой — открываем модалку ----
  if (BEE.openModal && authModal) {
    setTimeout(() => openModal(authModal), 100);
  }

  // ---- Автозакрытие flash ----
  const flash = document.getElementById('flash');
  if (flash) {
    setTimeout(() => {
      flash.style.transition = 'opacity .5s';
      flash.style.opacity = '0';
      setTimeout(() => flash.remove(), 600);
    }, 4000);
  }

  // ============================================================
  // АККАУНТ-ПАНЕЛЬ
  // ============================================================
  const accountBtn   = document.getElementById('accountBtn');
  const accountPanel = document.getElementById('accountPanel');

  if (accountBtn && accountPanel) {
    accountBtn.addEventListener('click', e => {
      e.stopPropagation();
      accountPanel.classList.toggle('active');
    });
    document.addEventListener('click', e => {
      if (!accountPanel.contains(e.target) && e.target !== accountBtn) {
        accountPanel.classList.remove('active');
      }
    });
  }

  // ============================================================
  // КОРЗИНА
  // ============================================================
  let cart = [];

  function loadCart() {
    if (!CART_KEY) return [];
    try { return JSON.parse(localStorage.getItem(CART_KEY)) || []; }
    catch (e) { return []; }
  }
  function saveCart() {
    if (CART_KEY) localStorage.setItem(CART_KEY, JSON.stringify(cart));
    updateCartCount();
  }
  function updateCartCount() {
    const el = document.getElementById('cartCount');
    if (!el) return;
    const total = cart.reduce((s, i) => s + i.qty, 0);
    el.textContent = total;
  }
  function addToCart(id, name, price, icon, image) {
    const found = cart.find(i => i.id === id);
    if (found) found.qty++;
    else cart.push({ id, name, price, icon, image: image || '', qty: 1 });
    saveCart();
  }
  function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    saveCart();
    renderCart();
  }
  function changeQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (!item) return;
    item.qty += delta;
    if (item.qty <= 0) removeFromCart(id);
    else { saveCart(); renderCart(); }
  }
  function getSubtotal() {
    return cart.reduce((s, i) => s + i.price * i.qty, 0);
  }
  function formatMoney(n) {
    return n.toLocaleString('ru-RU') + ' ₽';
  }

  // ---- Кнопки "В корзину" ----
  document.querySelectorAll('.buy-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (!user) {
        openModal(authModal);
        return;
      }
      addToCart(
        parseInt(btn.dataset.id, 10),
        btn.dataset.name,
        parseInt(btn.dataset.price, 10),
        btn.dataset.icon,
        btn.dataset.image
      );
      btn.textContent = '✓ Добавлено';
      setTimeout(() => { btn.textContent = 'В корзину'; }, 800);
    });
  });

  // ---- Рендер корзины ----
  function renderCart() {
    const wrap    = document.getElementById('cartItems');
    const empty   = document.getElementById('cartEmpty');
    const summary = document.getElementById('cartSummary');
    if (!wrap || !empty || !summary) return;

    if (!cart.length) {
      wrap.innerHTML = '';
      empty.style.display = 'block';
      summary.classList.add('hidden');
      return;
    }
    empty.style.display = 'none';
    summary.classList.remove('hidden');

    wrap.innerHTML = cart.map(i => {
      const thumb = i.image
        ? `<img src="${escapeHtml(i.image)}" alt="">`
        : escapeHtml(i.icon);
      return `
        <div class="cart-item">
          <div class="cart-item-icon">${thumb}</div>
          <div class="cart-item-info">
            <div class="cart-item-name">${escapeHtml(i.name)}</div>
            <div class="cart-item-price">${formatMoney(i.price)} × ${i.qty} = ${formatMoney(i.price * i.qty)}</div>
          </div>
          <div class="cart-item-qty">
            <button class="qty-btn" data-id="${i.id}" data-delta="-1">−</button>
            <span class="qty-value">${i.qty}</span>
            <button class="qty-btn" data-id="${i.id}" data-delta="1">+</button>
          </div>
          <button class="remove-btn" data-id="${i.id}" title="Удалить">✕</button>
        </div>
      `;
    }).join('');

    wrap.querySelectorAll('.qty-btn').forEach(b => {
      b.addEventListener('click', () => changeQty(parseInt(b.dataset.id, 10), parseInt(b.dataset.delta, 10)));
    });
    wrap.querySelectorAll('.remove-btn').forEach(b => {
      b.addEventListener('click', () => removeFromCart(parseInt(b.dataset.id, 10)));
    });

    updateTotals();
  }

  function updateTotals() {
    const subtotal     = getSubtotal();
    const deliverySel  = document.getElementById('deliverySelect');
    const deliveryType = deliverySel ? deliverySel.value : 'pickup';
    const deliveryPrice = DELIVERY[deliveryType] ? DELIVERY[deliveryType].price : 0;
    const total = subtotal + deliveryPrice;

    const subEl = document.getElementById('cartSubtotal');
    const totEl = document.getElementById('cartTotal');
    if (subEl) subEl.textContent = formatMoney(subtotal);
    if (totEl) totEl.textContent = formatMoney(total);

    // Показ/скрытие поля адреса
    const addr = document.getElementById('checkoutAddress');
    if (addr) addr.style.display = deliveryType === 'pickup' ? 'none' : 'block';
  }

  const deliverySelect = document.getElementById('deliverySelect');
  if (deliverySelect) deliverySelect.addEventListener('change', updateTotals);

  // ---- Оформление заказа ----
  const checkoutForm = document.getElementById('checkoutForm');
  if (checkoutForm) {
    checkoutForm.addEventListener('submit', e => {
      const recipient = document.getElementById('checkoutName');
      const phone     = document.getElementById('checkoutPhone');
      const address   = document.getElementById('checkoutAddress');
      const delivery  = document.getElementById('deliverySelect');

      if (!recipient || !recipient.value.trim()) {
        e.preventDefault();
        alert('Укажите ФИО получателя');
        recipient && recipient.focus();
        return;
      }
      if (!phone || !phone.value.trim()) {
        e.preventDefault();
        alert('Укажите телефон для связи');
        phone && phone.focus();
        return;
      }

      const dType = delivery ? delivery.value : 'pickup';
      if (dType !== 'pickup' && (!address || !address.value.trim())) {
        e.preventDefault();
        alert('Укажите адрес доставки');
        address && address.focus();
        return;
      }

      const subtotal = getSubtotal();
      const total = subtotal + (DELIVERY[dType] ? DELIVERY[dType].price : 0);

      document.getElementById('orderItems').value     = JSON.stringify(cart);
      document.getElementById('orderDelivery').value  = dType;
      document.getElementById('orderTotal').value     = total;
      document.getElementById('orderRecipient').value = recipient.value.trim();
      document.getElementById('orderPhone').value     = phone.value.trim();
      document.getElementById('orderAddress').value   = address ? address.value.trim() : '';
    });
  }

  // ============================================================
  // ЭКСПОРТ КАТАЛОГА В CSV
  // ============================================================
  const exportBtn = document.getElementById('exportCsvBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      const products = BEE.products || [];
      if (!products.length) { alert('Каталог пуст'); return; }

      const header = ['id', 'icon', 'image', 'name', 'description', 'price', 'old_price', 'category', 'is_promo'];
      const lines = [header.join(',')];

      document.querySelectorAll('#productsGrid .card').forEach(card => {
        const id   = card.dataset.id || '';
        const img  = card.querySelector('.card-img img');
        const image = img ? (img.getAttribute('src') || '') : '';
        const icon = img ? '' : (card.querySelector('.card-img')?.textContent.trim() || '');
        const name = card.querySelector('.card-body h3')?.textContent.trim() || '';
        const desc = card.querySelector('.card-body p')?.textContent.trim() || '';

        // Берём данные из BEE.products — там точные значения
        const prod = products.find(p => String(p.id) === String(id));
        const price    = prod ? prod.price : 0;
        const oldPrice = prod ? (prod.old_price || 0) : 0;
        const category = prod ? (prod.category || '') : '';
        const isPromo  = prod ? (prod.is_promo || 0) : 0;

        lines.push(
          [id, icon, image, name, desc, price, oldPrice, category, isPromo]
            .map(escapeCsv).join(',')
        );
      });

      const csv = '\uFEFF' + lines.join('\r\n'); // BOM для Excel
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href = url;
      a.download = 'products_export.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });
  }

  // ============================================================
  // УТИЛИТЫ
  // ============================================================
  function escapeCsv(v) {
    v = String(v ?? '');
    if (/[",\r\n]/.test(v)) {
      return '"' + v.replace(/"/g, '""') + '"';
    }
    return v;
  }
  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ============================================================
  // ИНИЦИАЛИЗАЦИЯ
  // ============================================================
  if (user) {
    cart = loadCart();
    updateCartCount();
  }

})();