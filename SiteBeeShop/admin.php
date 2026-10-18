<?php
declare(strict_types=1);
session_start();

const DATA_DIR    = __DIR__ . '/data';
const ORDERS_FILE = DATA_DIR . '/orders.json';

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);

function loadOrders(): array {
    if (!file_exists(ORDERS_FILE)) return [];
    $d = json_decode((string)file_get_contents(ORDERS_FILE), true);
    return is_array($d) ? $d : [];
}
function saveOrders(array $o): void {
    file_put_contents(ORDERS_FILE, json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function currentUser(): ?array { return $_SESSION['user'] ?? null; }
function isAdmin(): bool {
    $u = currentUser();
    return $u && !empty($u['is_admin']);
}

const DELIVERY_MAP = [
    'pickup'  => 'Самовывоз',
    'courier' => 'Курьер по городу',
    'cdek'    => 'СДЭК',
    'post'    => 'Почта России',
];
const STATUS_MAP = [
    'new'        => 'Новый',
    'processing' => 'В обработке',
    'shipped'    => 'Отправлен',
    'done'       => 'Выполнен',
    'cancelled'  => 'Отменён',
];

// Изменение статуса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $orderId   = $_POST['order_id'] ?? '';
    $newStatus = $_POST['status']   ?? '';
    if (isset(STATUS_MAP[$newStatus]) && $orderId) {
        $orders = loadOrders();
        foreach ($orders as &$o) {
            if ($o['id'] === $orderId) { $o['status'] = $newStatus; break; }
        }
        unset($o);
        saveOrders($orders);
    }
    $back = $_POST['back'] ?? 'admin.php';
    header("Location: $back");
    exit;
}

$admin  = isAdmin();
$orders = loadOrders();
usort($orders, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));

$filter   = $_GET['status'] ?? 'all';
$filtered = $filter === 'all'
    ? $orders
    : array_values(array_filter($orders, fn($o) => ($o['status'] ?? 'new') === $filter));

$totalOrders  = count($orders);
$newCount     = count(array_filter($orders, fn($o) => ($o['status'] ?? 'new') === 'new'));
$totalRevenue = array_sum(array_map(
    fn($o) => ($o['status'] ?? '') === 'done' ? (int)($o['total'] ?? 0) : 0,
    $orders
));

function fmtMoney(int $n): string {
    return number_format($n, 0, '.', ' ') . ' ₽';
}
function fmtDate(string $iso): string {
    return date('d.m.Y H:i', strtotime($iso));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Админ-панель — Заказы</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  :root {
    --honey: #f6b93b;
    --honey-dark: #e58e26;
    --brown: #4a2c0f;
    --brown-light: #7a5230;
    --cream: #fff9ec;
    --dark: #2b1a0a;
  }
  body {
    font-family: 'Segoe UI', Tahoma, sans-serif;
    background: var(--cream);
    color: var(--dark);
    line-height: 1.5;
  }
  .topbar {
    background: var(--brown);
    color: #fff;
    padding: 15px 0;
    box-shadow: 0 2px 10px rgba(0,0,0,.15);
  }
  .topbar-inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
  }
  .topbar a.logo {
    color: var(--honey);
    text-decoration: none;
    font-size: 1.3rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .topbar nav a.active { 
    color: var(--honey); 
    border-bottom: 2px solid var(--honey); 
    padding-bottom: 3px; 
}
  .topbar nav a {
    color: #fff;
    text-decoration: none;
    margin-left: 20px;
    font-weight: 500;
    font-size: .95rem;
  }
  .topbar nav a:hover { color: var(--honey); }

  .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

  .stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
  }
  .stat {
    background: #fff;
    padding: 22px;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(74,44,15,.08);
    border-left: 5px solid var(--honey);
  }
  .stat-label { font-size: .85rem; color: var(--brown-light); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
  .stat-value { font-size: 2rem; font-weight: 700; color: var(--brown); margin-top: 5px; }
  .stat.highlight { border-left-color: #e74c3c; }
  .stat.revenue   { border-left-color: #27ae60; }

  .filters {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
  }
  .filters a {
    padding: 8px 18px;
    border-radius: 20px;
    background: #fff;
    color: var(--brown);
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    border: 2px solid transparent;
    transition: all .2s;
  }
  .filters a:hover { border-color: var(--honey); }
  .filters a.active { background: var(--honey); border-color: var(--honey); }

  .empty-state {
    background: #fff;
    padding: 60px 20px;
    text-align: center;
    border-radius: 16px;
    color: var(--brown-light);
    font-size: 1.1rem;
    box-shadow: 0 4px 15px rgba(74,44,15,.08);
  }
  .empty-state .emoji { font-size: 4rem; display: block; margin-bottom: 15px; }

  .order {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(74,44,15,.08);
    margin-bottom: 15px;
    overflow: hidden;
    border-left: 5px solid #ccc;
  }
  .order.new        { border-left-color: #e74c3c; }
  .order.processing { border-left-color: #f39c12; }
  .order.shipped    { border-left-color: #3498db; }
  .order.done       { border-left-color: #27ae60; }
  .order.cancelled  { border-left-color: #95a5a6; }

  .order-head {
    padding: 18px 22px;
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
    border-bottom: 1px solid #f0e6d2;
  }
  .order-id {
    font-family: monospace;
    font-size: .85rem;
    color: var(--brown-light);
    background: var(--cream);
    padding: 4px 8px;
    border-radius: 6px;
  }
  .order-date {
    color: var(--brown-light);
    font-size: .9rem;
  }
  .order-total {
    margin-left: auto;
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--honey-dark);
  }
  .status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: .8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
  }
  .status-badge.new        { background: #fde2e1; color: #c0392b; }
  .status-badge.processing { background: #fef3d7; color: #b7791f; }
  .status-badge.shipped    { background: #dbeafe; color: #1e40af; }
  .status-badge.done       { background: #d5f5e0; color: #196f3d; }
  .status-badge.cancelled  { background: #eaecee; color: #566573; }

  .order-body {
    padding: 18px 22px;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 20px;
    align-items: start;
  }
  .order-client { color: var(--brown-light); font-size: .95rem; line-height: 1.7; }
  .order-client strong { color: var(--brown); }
  .order-client a { color: var(--honey-dark); }

  .status-form {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
  }
  .status-form select {
    padding: 8px 12px;
    border: 2px solid #eadcc0;
    border-radius: 10px;
    font-family: inherit;
    font-size: .9rem;
    background: var(--cream);
    cursor: pointer;
    outline: none;
    font-weight: 600;
    color: var(--brown);
  }
  .status-form select:focus { border-color: var(--honey); }
  .status-form button {
    background: var(--brown);
    color: var(--honey);
    border: none;
    padding: 8px 16px;
    border-radius: 10px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: background .2s;
  }
  .status-form button:hover { background: var(--honey-dark); color: var(--brown); }

  .order-items {
    grid-column: 1 / -1;
    padding-top: 12px;
    border-top: 1px dashed #f0e6d2;
    margin-top: 8px;
  }
  .order-items-title {
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--brown-light);
    font-weight: 600;
    margin-bottom: 8px;
  }
  .item-line {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 5px 0;
    font-size: .95rem;
  }
  .item-line .icon { font-size: 1.3rem; width: 28px; text-align: center; }
  .item-line .name { flex: 1; color: var(--brown); }
  .item-line .qty { color: var(--brown-light); font-size: .9rem; }
  .item-line .sum { font-weight: 600; color: var(--honey-dark); min-width: 90px; text-align: right; }

  .order-address {
    grid-column: 1 / -1;
    margin-top: 12px;
    padding: 12px;
    background: var(--cream);
    border-radius: 10px;
    font-size: .9rem;
    color: var(--brown-light);
  }
  .order-address strong { color: var(--brown); }

  .not-admin {
    max-width: 500px;
    margin: 80px auto;
    background: #fff;
    padding: 40px;
    border-radius: 20px;
    text-align: center;
    box-shadow: 0 10px 40px rgba(74,44,15,.15);
  }
  .not-admin h1 { color: var(--brown); margin-bottom: 15px; }
  .not-admin p { color: var(--brown-light); margin-bottom: 25px; }
  .not-admin a {
    display: inline-block;
    background: var(--honey);
    color: var(--brown);
    text-decoration: none;
    padding: 12px 30px;
    border-radius: 30px;
    font-weight: 700;
    transition: transform .2s;
  }
  .not-admin a:hover { transform: scale(1.05); }

  @media (max-width: 600px) {
    .order-body { grid-template-columns: 1fr; }
    .order-total { margin-left: 0; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <a href="index.php" class="logo">🐝 Пчеловод · Админ</a>
    <nav>
      <a href="index.php">← На главную</a>
      <a href="admin.php" class="active">📋 Заказы</a>
        <a href="admin-products.php">📦 Каталог</a>
      <a href="?action=logout" onclick="event.preventDefault();fetch('index.php?action=logout',{redirect:'manual'}).then(()=>location.href='index.php')">Выйти</a>
    </nav>
  </div>
</div>

<?php if (!$admin): ?>
  <div class="not-admin">
    <h1>🔒 Доступ закрыт</h1>
    <p>Эта страница только для сотрудников магазина.<br>Войдите с аккаунтом администратора.</p>
    <a href="index.php">На главную → Войти</a>
  </div>
<?php else: ?>

<div class="container">
  <div class="stats">
    <div class="stat">
      <div class="stat-label">Всего заказов</div>
      <div class="stat-value"><?= $totalOrders ?></div>
    </div>
    <div class="stat highlight">
      <div class="stat-label">Новых</div>
      <div class="stat-value"><?= $newCount ?></div>
    </div>
    <div class="stat revenue">
      <div class="stat-label">Выручка (выполнено)</div>
      <div class="stat-value"><?= fmtMoney($totalRevenue) ?></div>
    </div>
  </div>

  <div class="filters">
    <a href="?status=all"        class="<?= $filter === 'all'        ? 'active' : '' ?>">Все (<?= $totalOrders ?>)</a>
    <a href="?status=new"        class="<?= $filter === 'new'        ? 'active' : '' ?>">Новые</a>
    <a href="?status=processing" class="<?= $filter === 'processing' ? 'active' : '' ?>">В обработке</a>
    <a href="?status=shipped"    class="<?= $filter === 'shipped'    ? 'active' : '' ?>">Отправлены</a>
    <a href="?status=done"       class="<?= $filter === 'done'       ? 'active' : '' ?>">Выполнены</a>
    <a href="?status=cancelled"  class="<?= $filter === 'cancelled'  ? 'active' : '' ?>">Отменены</a>
  </div>

  <?php if (!count($filtered)): ?>
    <div class="empty-state">
      <span class="emoji">📭</span>
      Заказов нет. Как только клиенты оформят заказ — они появятся здесь.
    </div>
  <?php else: ?>
    <?php foreach ($filtered as $o):
      $status = $o['status'] ?? 'new';
      $delivery = $o['delivery'] ?? 'pickup';
    ?>
    <div class="order <?= htmlspecialchars($status) ?>">
      <div class="order-head">
        <span class="order-id">#<?= htmlspecialchars($o['id']) ?></span>
        <span class="order-date">📅 <?= fmtDate($o['created']) ?></span>
        <span class="status-badge <?= htmlspecialchars($status) ?>"><?= STATUS_MAP[$status] ?? $status ?></span>
        <span class="order-total"><?= fmtMoney((int)($o['total'] ?? 0)) ?></span>
      </div>

      <div class="order-body">
        <div class="order-client">
            <?php if (!empty($o['recipient'])): ?>
            👤 Получатель: <strong><?= htmlspecialchars($o['recipient']) ?></strong><br>
            <?php endif; ?>
            📧 <span style="font-size:.9rem;"><?= htmlspecialchars($o['user']) ?></span><br>
            📞 <a href="tel:<?= htmlspecialchars($o['phone']) ?>"><?= htmlspecialchars($o['phone']) ?></a>
        </div>

        <form method="POST" action="admin.php" class="status-form">
          <input type="hidden" name="order_id" value="<?= htmlspecialchars($o['id']) ?>">
          <input type="hidden" name="back" value="?status=<?= htmlspecialchars($filter) ?>">
          <select name="status">
            <?php foreach (STATUS_MAP as $key => $label): ?>
              <option value="<?= $key ?>" <?= $key === $status ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit">Сохранить</button>
        </form>

        <div class="order-items">
          <div class="order-items-title">Состав заказа</div>
          <?php foreach (($o['items'] ?? []) as $item): ?>
            <div class="item-line">
              <span class="icon"><?= htmlspecialchars($item['icon'] ?? '📦') ?></span>
              <span class="name"><?= htmlspecialchars($item['name'] ?? '') ?></span>
              <span class="qty"><?= (int)($item['qty'] ?? 0) ?> × <?= fmtMoney((int)($item['price'] ?? 0)) ?></span>
              <span class="sum"><?= fmtMoney((int)($item['price'] ?? 0) * (int)($item['qty'] ?? 0)) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="order-address">
          🚚 <strong><?= DELIVERY_MAP[$delivery] ?? $delivery ?></strong>
          <?php if (!empty($o['address'])): ?>
            · <?= htmlspecialchars($o['address']) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php endif; ?>
</body>
</html>