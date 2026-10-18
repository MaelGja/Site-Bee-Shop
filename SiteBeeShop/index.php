<?php
declare(strict_types=1);
session_start();

// ============================================================
// КОНФИГУРАЦИЯ
// ============================================================
const DATA_DIR       = __DIR__ . '/data';
const USERS_FILE     = DATA_DIR . '/users.json';
const ORDERS_FILE    = DATA_DIR . '/orders.json';
const CSV_FILE       = __DIR__ . '/products.csv';
const UPLOADS_DIR    = __DIR__ . '/uploads';
const ADMIN_EMAIL    = 'admin@bee-shop.ru';
const ADMIN_PASSWORD = 'admin123';

if (!is_dir(DATA_DIR))    @mkdir(DATA_DIR, 0755, true);
if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);

// ============================================================
// ХЕЛПЕРЫ
// ============================================================
function loadUsers(): array {
    if (!file_exists(USERS_FILE)) return [];
    $d = json_decode((string)file_get_contents(USERS_FILE), true);
    return is_array($d) ? $d : [];
}
function saveUsers(array $u): void {
    file_put_contents(USERS_FILE, json_encode($u, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function loadProducts(): array {
    if (!file_exists(CSV_FILE)) return [];
    $products = [];
    $fp = fopen(CSV_FILE, 'r');
    if (!$fp) return [];
    $headers = fgetcsv($fp, 0, ',', '"', '\\');
    if (!$headers) { fclose($fp); return []; }
    if (isset($headers[0]) && strncmp($headers[0], "\xEF\xBB\xBF", 3) === 0) {
        $headers[0] = substr($headers[0], 3);
    }
    while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
        if (count($row) === count($headers)) {
            $products[] = array_combine($headers, $row);
        }
    }
    fclose($fp);
    return $products;
}
function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}
function redirect(string $url = 'index.php'): void {
    header("Location: $url");
    exit;
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verifyCsrf(?string $t): bool {
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// ============================================================
// ГАРАНТИРУЕМ АДМИНА
// ============================================================
$users = loadUsers();
if (!isset($users[ADMIN_EMAIL])) {
    $users[ADMIN_EMAIL] = [
        'name'     => 'Администратор',
        'email'    => ADMIN_EMAIL,
        'password' => password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT),
        'is_admin' => true,
        'created'  => date('c'),
    ];
    saveUsers($users);
}

// ============================================================
// POST
// ============================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------- РЕГИСТРАЦИЯ ----------
    if ($action === 'register') {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Ошибка безопасности. Обновите страницу.','tab'=>'register'];
            redirect();
        }
        $name  = trim($_POST['name'] ?? '');
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';

        $errors = [];
        if ($name === '') $errors[] = 'Введите имя';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Некорректный email';
        if (mb_strlen($pass) < 6) $errors[] = 'Пароль должен быть не короче 6 символов';
        if ($pass !== $pass2) $errors[] = 'Пароли не совпадают';

        $users = loadUsers();
        if (!$errors && isset($users[$email])) $errors[] = 'Пользователь с таким email уже существует';

        if ($errors) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>implode('. ', $errors),'tab'=>'register'];
            $_SESSION['old'] = ['name'=>$name,'email'=>$email];
            redirect();
        }

        $users[$email] = [
            'name'     => $name,
            'email'    => $email,
            'password' => password_hash($pass, PASSWORD_DEFAULT),
            'created'  => date('c'),
        ];
        saveUsers($users);

        session_regenerate_id(true);
        $_SESSION['user'] = ['name'=>$name, 'email'=>$email, 'is_admin'=>false];
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Регистрация успешна. Добро пожаловать!'];
        redirect();
    }

    // ---------- ВХОД ----------
    if ($action === 'login') {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Ошибка безопасности.','tab'=>'login'];
            redirect();
        }
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $users = loadUsers();

        if (!isset($users[$email]) || !password_verify($pass, $users[$email]['password'])) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Неверный email или пароль','tab'=>'login'];
            $_SESSION['old'] = ['email'=>$email];
            redirect();
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'name'     => $users[$email]['name'],
            'email'    => $email,
            'is_admin' => !empty($users[$email]['is_admin']),
        ];
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Вы вошли в аккаунт'];
        redirect();
    }

    // ---------- ОФОРМЛЕНИЕ ЗАКАЗА ----------
    if ($action === 'order') {
        if (!currentUser()) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Войдите, чтобы оформить заказ','tab'=>'login'];
            redirect();
        }
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Ошибка безопасности.'];
            redirect();
        }

        $recipient = trim($_POST['recipient'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        $delivery  = $_POST['delivery'] ?? 'pickup';

        $errors = [];
        if ($recipient === '')               $errors[] = 'Укажите ФИО получателя';
        if ($phone === '')                   $errors[] = 'Укажите телефон';
        if ($delivery !== 'pickup' && $address === '') $errors[] = 'Укажите адрес доставки';

        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items) || !count($items)) $errors[] = 'Корзина пуста';

        if ($errors) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>implode('. ', $errors)];
            redirect();
        }

        $orders = file_exists(ORDERS_FILE)
            ? (json_decode((string)file_get_contents(ORDERS_FILE), true) ?: [])
            : [];
        $orders[] = [
            'id'        => uniqid('ord_', true),
            'user'      => currentUser()['email'],
            'recipient' => $recipient,
            'items'     => $items,
            'delivery'  => $delivery,
            'address'   => $address,
            'phone'     => $phone,
            'total'     => (int)($_POST['total'] ?? 0),
            'created'   => date('c'),
            'status'    => 'new',
        ];
        file_put_contents(ORDERS_FILE, json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Заказ оформлен! Мы свяжемся с вами.'];
        redirect();
    }
}

// ---------- ВЫХОД ----------
if (($_GET['action'] ?? '') === 'logout') {
    unset($_SESSION['user']);
    session_regenerate_id(true);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Вы вышли из аккаунта'];
    redirect();
}

$user       = currentUser();
$products   = loadProducts();
$csrf       = csrf();
$old        = $_SESSION['old'] ?? [];
unset($_SESSION['old']);
$activeTab  = $flash['tab'] ?? 'login';
$openModal  = ($flash && $flash['type'] === 'error' && !empty($flash['tab'])) ? true : false;

// Хелпер: посчитать скидку и акционность
function productMeta(array $p): array {
    $price    = (int)($p['price'] ?? 0);
    $oldPrice = (int)($p['old_price'] ?? 0);
    $hasDiscount = $oldPrice > $price;
    $discountPct = $hasDiscount ? (int)round((1 - $price / $oldPrice) * 100) : 0;
    $isPromo = isset($p['is_promo']) && (string)$p['is_promo'] === '1';
    return ['price'=>$price, 'old_price'=>$oldPrice, 'has_discount'=>$hasDiscount,
            'discount_pct'=>$discountPct, 'is_promo'=>$isPromo];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Пчеловод — магазин товаров для пчеловодства</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php if ($flash): ?>
<div class="flash flash-<?= htmlspecialchars($flash['type']) ?>" id="flash">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- ================= HEADER ================= -->
<header>
  <div class="container nav">
    <a href="index.php" class="logo" style="text-decoration:none;"><span>🐝</span> Пчеловод</a>
    <nav>
      <ul>
        <li><a href="#products">Каталог</a></li>
        <li><a href="#delivery">Доставка</a></li>
        <li><a href="#advantages">Преимущества</a></li>
        <li><a href="#about">О нас</a></li>
        <li><a href="#contacts">Контакты</a></li>
      </ul>
    </nav>

    <div class="header-actions">
      <?php if ($user): ?>
        <?php if (!empty($user['is_admin'])): ?>
          <a href="admin-products.php" class="header-btn outline" style="text-decoration:none;">📦 Каталог</a>
          <a href="admin.php" class="header-btn outline" style="text-decoration:none;">📋 Заказы</a>
        <?php endif; ?>
        <button id="cartBtn" class="header-btn" type="button">
          🛒 Корзина (<span id="cartCount">0</span>)
        </button>
        <div class="account-wrapper">
          <button id="accountBtn" class="header-btn outline" type="button">
            👤 <?= htmlspecialchars($user['name']) ?>
          </button>
          <div class="account-panel" id="accountPanel">
            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
            <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
            <hr>
            <a href="?action=logout" class="logout-btn">Выйти</a>
          </div>
        </div>
      <?php else: ?>
        <button id="loginBtn" class="header-btn" type="button">Войти</button>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- ================= HERO ================= -->
<section class="hero">
  <h1>Всё для пчеловодства</h1>
  <p>Натуральный мёд, ульи, инвентарь и пчелопакеты — от проверенных пасечников с многолетним опытом.</p>
  <a href="#products" class="btn-primary">Перейти в каталог</a>
</section>

<!-- ================= PRODUCTS ================= -->
<section id="products">
  <div class="container">
    <h2 class="section-title">Наш каталог</h2>
    <p class="section-sub">
      Каталог формируется из таблицы <code>products.csv</code>
      &nbsp;·&nbsp;
      <button type="button" id="exportCsvBtn" class="link-btn">📥 Скачать каталог (CSV)</button>
    </p>

    <?php if (!count($products)): ?>
      <p style="text-align:center;color:#a55;">Файл products.csv не найден или пуст.</p>
    <?php else: ?>
    <div class="products" id="productsGrid">
      <?php foreach ($products as $p):
          $m = productMeta($p);
      ?>
        <div class="card" data-id="<?= htmlspecialchars($p['id']) ?>">
          <div class="card-img">
            <?php if (!empty($p['image'])): ?>
              <img src="<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
            <?php else: ?>
              <?= htmlspecialchars($p['icon']) ?>
            <?php endif; ?>
            <div class="card-badges">
              <?php if ($m['is_promo']): ?>
                <span class="promo-badge">🔥 Акция</span>
              <?php endif; ?>
              <?php if ($m['has_discount']): ?>
                <span class="discount-badge">-<?= $m['discount_pct'] ?>%</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="card-body">
            <h3><?= htmlspecialchars($p['name']) ?></h3>
            <p><?= htmlspecialchars($p['description']) ?></p>
            <div class="price-row">
              <div class="price-block">
                <span class="price"><?= number_format($m['price'], 0, '.', ' ') ?> ₽</span>
                <?php if ($m['has_discount']): ?>
                  <span class="price-old"><?= number_format($m['old_price'], 0, '.', ' ') ?> ₽</span>
                <?php endif; ?>
              </div>
              <button type="button" class="buy-btn"
                      data-id="<?= htmlspecialchars($p['id']) ?>"
                      data-name="<?= htmlspecialchars($p['name']) ?>"
                      data-price="<?= $m['price'] ?>"
                      data-icon="<?= htmlspecialchars($p['icon']) ?>"
                      data-image="<?= htmlspecialchars($p['image'] ?? '') ?>">
                В корзину
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ================= DELIVERY ================= -->
<section id="delivery" class="delivery-section">
  <div class="container">
    <h2 class="section-title">Сервисы доставки</h2>
    <p class="section-sub">Выберите удобный способ получения — способ можно уточнить при оформлении заказа</p>

    <div class="delivery-grid">
      <div class="delivery-card">
        <div class="delivery-icon">🏠</div>
        <h3>Самовывоз</h3>
        <div class="delivery-price free">Бесплатно</div>
        <div class="delivery-term">в день заказа</div>
        <p>Заберите заказ лично на нашей пасеке.<br>Московская обл., дер. Медовая, 12</p>
      </div>
      <div class="delivery-card">
        <div class="delivery-icon">🚴</div>
        <h3>Курьер по городу</h3>
        <div class="delivery-price">250 ₽</div>
        <div class="delivery-term">в день заказа</div>
        <p>Доставка по городу в течение дня.<br>Бесплатно при заказе от 3 000 ₽</p>
      </div>
      <div class="delivery-card">
        <div class="delivery-icon">📦</div>
        <h3>СДЭК</h3>
        <div class="delivery-price">450 ₽</div>
        <div class="delivery-term">2–4 дня</div>
        <p>Доставка в пункты выдачи по всей России.<br>Отслеживание по номеру заказа.</p>
      </div>
      <div class="delivery-card">
        <div class="delivery-icon">📮</div>
        <h3>Почта России</h3>
        <div class="delivery-price">350 ₽</div>
        <div class="delivery-term">5–10 дней</div>
        <p>Доставка в любое почтовое отделение страны.<br>Удобно для отдалённых регионов.</p>
      </div>
    </div>
  </div>
</section>

<!-- ================= ADVANTAGES ================= -->
<section id="advantages" class="advantages">
  <div class="container">
    <h2 class="section-title">Почему выбирают нас</h2>
    <p class="section-sub">Мы заботимся о качестве и удобстве каждого пасечника</p>
    <div class="adv-grid">
      <div class="adv-item"><span class="adv-icon">🚚</span><h3>Быстрая доставка</h3><p>Отправляем заказы по всей России в течение 1–3 дней.</p></div>
      <div class="adv-item"><span class="adv-icon">✅</span><h3>Гарантия качества</h3><p>Вся продукция проверена нашими пчеловодами.</p></div>
      <div class="adv-item"><span class="adv-icon">💬</span><h3>Консультация</h3><p>Поможем выбрать улей и ответим на любые вопросы.</p></div>
      <div class="adv-item"><span class="adv-icon">💰</span><h3>Честные цены</h3><p>Прямые поставки без посредников и наценок.</p></div>
    </div>
  </div>
</section>

<!-- ================= ABOUT ================= -->
<section id="about">
  <div class="container about-wrap">
    <div class="about-text">
      <h2>О нашей пасеке</h2>
      <p>Мы занимаемся пчеловодством более 20 лет. Наша пасека расположена в экологически чистом районе, вдали от трасс и промышленных объектов.</p>
      <p>Помимо продажи товаров для пчеловодства, мы делимся опытом, проводим консультации для начинающих и всегда рады помочь советом.</p>
      <p>Каждый продукт — от мёда до инвентаря — проверен лично нами.</p>
    </div>
    <div class="about-visual">🍯</div>
  </div>
</section>

<!-- ================= CONTACTS ================= -->
<section id="contacts" class="contacts-section">
  <div class="container">
    <h2 class="section-title">Контакты</h2>
    <p class="section-sub">Свяжитесь с нами любым удобным способом — ответим в течение 15 минут</p>
    <div class="contacts-grid">
      <div class="contact-info">
        <div class="contact-item"><span class="contact-icon">📞</span><div><div class="contact-label">Телефон</div><a href="tel:+70000000000" class="contact-value">+7 (000) 000-00-00</a></div></div>
        <div class="contact-item"><span class="contact-icon">✉️</span><div><div class="contact-label">Email</div><a href="mailto:info@bee-shop.ru" class="contact-value">info@bee-shop.ru</a></div></div>
        <div class="contact-item"><span class="contact-icon">📍</span><div><div class="contact-label">Адрес пасеки</div><div class="contact-value">Московская обл., дер. Медовая, 12</div></div></div>
        <div class="contact-item"><span class="contact-icon">🕐</span><div><div class="contact-label">Часы работы</div><div class="contact-value">Пн–Сб: 9:00 – 19:00<br>Вс: 10:00 – 16:00</div></div></div>
      </div>
      <form class="contact-form" id="contactForm" onsubmit="event.preventDefault(); this.reset(); alert('Спасибо! Мы свяжемся с вами.');">
        <input type="text" placeholder="Ваше имя" required>
        <input type="tel" placeholder="Телефон" required>
        <textarea placeholder="Комментарий или вопрос"></textarea>
        <button type="submit">Отправить заявку</button>
      </form>
    </div>
  </div>
</section>

<!-- ================= FOOTER ================= -->
<footer>
  <div class="logo" style="justify-content:center;"><span>🐝</span> Пчеловод</div>
  <p>Магазин товаров для пчеловодства · © 2025</p>
  <p>Тел: <a href="tel:+70000000000">+7 (000) 000-00-00</a> · Email: <a href="mailto:info@bee-shop.ru">info@bee-shop.ru</a></p>
</footer>

<!-- ================= AUTH MODAL ================= -->
<div class="modal-overlay" id="authModal">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h2>Добро пожаловать</h2>
      <p>Войдите или создайте аккаунт</p>
      <button class="modal-close" id="authModalClose" type="button" aria-label="Закрыть">×</button>
    </div>
    <div class="tabs">
      <button class="tab <?= $activeTab === 'login' ? 'active' : '' ?>" data-tab="login" type="button">Вход</button>
      <button class="tab <?= $activeTab === 'register' ? 'active' : '' ?>" data-tab="register" type="button">Регистрация</button>
    </div>
    <form class="auth-form <?= $activeTab === 'login' ? '' : 'hidden' ?>" id="loginForm" method="POST" action="index.php" novalidate>
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div class="field"><label for="loginEmail">Email</label>
        <input type="email" id="loginEmail" name="email" placeholder="example@mail.ru" value="<?= htmlspecialchars($old['email'] ?? '') ?>" autocomplete="username" required></div>
      <div class="field"><label for="loginPassword">Пароль</label>
        <input type="password" id="loginPassword" name="password" placeholder="••••••••" autocomplete="current-password" required></div>
      <button type="submit" class="submit-btn">Войти</button>
    </form>
    <form class="auth-form <?= $activeTab === 'register' ? '' : 'hidden' ?>" id="registerForm" method="POST" action="index.php" novalidate>
      <input type="hidden" name="action" value="register">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div class="field"><label for="regName">Ваше имя</label>
        <input type="text" id="regName" name="name" placeholder="Иван Иванов" value="<?= htmlspecialchars($old['name'] ?? '') ?>" autocomplete="name" required></div>
      <div class="field"><label for="regEmail">Email</label>
        <input type="email" id="regEmail" name="email" placeholder="example@mail.ru" value="<?= htmlspecialchars($old['email'] ?? '') ?>" autocomplete="email" required></div>
      <div class="field"><label for="regPassword">Пароль</label>
        <input type="password" id="regPassword" name="password" placeholder="минимум 6 символов" autocomplete="new-password" required></div>
      <div class="field"><label for="regPassword2">Повторите пароль</label>
        <input type="password" id="regPassword2" name="password2" placeholder="••••••••" autocomplete="new-password" required></div>
      <button type="submit" class="submit-btn">Зарегистрироваться</button>
    </form>
  </div>
</div>

<!-- ================= CART MODAL ================= -->
<div class="modal-overlay" id="cartModal">
  <div class="modal modal-wide" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h2>Корзина</h2>
      <p>Проверьте заказ и оформите доставку</p>
      <button class="modal-close" id="cartModalClose" type="button" aria-label="Закрыть">×</button>
    </div>
    <div class="cart-body">
      <div id="cartItems"></div>
      <div id="cartEmpty" class="cart-empty">Корзина пуста 🐝</div>

      <div class="cart-summary <?= $user ? '' : 'hidden' ?>" id="cartSummary">
        <div class="summary-row"><span>Товары</span><span id="cartSubtotal">0 ₽</span></div>
        <div class="summary-row">
          <span>Доставка</span>
          <select id="deliverySelect">
            <option value="pickup">Самовывоз — бесплатно</option>
            <option value="courier">Курьер по городу — 250 ₽</option>
            <option value="cdek">СДЭК — 450 ₽</option>
            <option value="post">Почта России — 350 ₽</option>
          </select>
        </div>
        <div class="summary-row total"><span>Итого</span><span id="cartTotal">0 ₽</span></div>

        <div class="checkout-fields">
          <input type="text" id="checkoutName" placeholder="ФИО получателя *" required>
          <input type="tel"  id="checkoutPhone" placeholder="Телефон для связи *" required>
          <input type="text" id="checkoutAddress" placeholder="Адрес доставки (если не самовывоз)">
        </div>

        <form method="POST" action="index.php" id="checkoutForm">
          <input type="hidden" name="action" value="order">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="items" id="orderItems">
          <input type="hidden" name="delivery" id="orderDelivery">
          <input type="hidden" name="total" id="orderTotal">
          <input type="hidden" name="recipient" id="orderRecipient">
          <input type="hidden" name="phone" id="orderPhone">
          <input type="hidden" name="address" id="orderAddress">
          <button type="submit" class="submit-btn">Оформить заказ</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ================= ДАННЫЕ ДЛЯ JS ================= -->
<script>
window.BEE = {
  products:  <?= json_encode(array_map(function($p){
      $m = productMeta($p);
      return [
          'id'        => (int)$p['id'],
          'icon'      => $p['icon'] ?? '',
          'image'     => $p['image'] ?? '',
          'name'      => $p['name'] ?? '',
          'price'     => $m['price'],
          'old_price' => $m['old_price'],
          'category'  => $p['category'] ?? '',
          'is_promo'  => $m['is_promo'] ? 1 : 0,
      ];
  }, $products), JSON_UNESCAPED_UNICODE) ?>,
  user:      <?= json_encode($user ?: null) ?>,
  csrf:      <?= json_encode($csrf) ?>,
  activeTab: <?= json_encode($activeTab) ?>,
  openModal: <?= $openModal ? 'true' : 'false' ?>
};
</script>
<script src="assets/main.js"></script>
</body>
</html>