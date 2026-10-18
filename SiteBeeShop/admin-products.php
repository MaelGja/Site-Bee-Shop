<?php
declare(strict_types=1);
session_start();

const CSV_FILE       = __DIR__ . '/products.csv';
const UPLOADS_DIR    = __DIR__ . '/uploads';
const UPLOADS_URL    = 'uploads';
const CSV_HEADERS    = ['id','icon','image','name','description','price','old_price','category','is_promo'];

if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);

// ---------- ХЕЛПЕРЫ ----------
function currentUser(): ?array { return $_SESSION['user'] ?? null; }
function isAdmin(): bool {
    $u = currentUser();
    return $u && !empty($u['is_admin']);
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
function saveProducts(array $products): void {
    $fp = fopen(CSV_FILE, 'w');
    if (!$fp) return;
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, CSV_HEADERS, ',', '"', '\\');
    foreach ($products as $p) {
        $row = [];
        foreach (CSV_HEADERS as $h) $row[] = $p[$h] ?? '';
        fputcsv($fp, $row, ',', '"', '\\');
    }
    fclose($fp);
}
function redirect(string $url = 'admin-products.php'): void {
    header("Location: $url");
    exit;
}
function saveUpload(array $file): ?string {
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['jpg','jpeg','png','gif','webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;
    $name = 'p_' . uniqid('', true) . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $name)) {
        return UPLOADS_URL . '/' . $name;
    }
    return null;
}

// ---------- ДЕЙСТВИЯ ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!isAdmin()) {
    // не админ — рендерим экран с запретом
    $isAdminPage = false;
} else {
    $isAdminPage = true;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $products = loadProducts();

        // ---------- СОХРАНИТЬ (создать или обновить) ----------
        if ($action === 'save') {
            $id          = trim($_POST['id'] ?? '');
            $icon        = trim($_POST['icon'] ?? '📦');
            $name        = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price       = (int)($_POST['price'] ?? 0);
            $oldPrice    = (int)($_POST['old_price'] ?? 0);
            $category    = trim($_POST['category'] ?? '');
            $isPromo     = !empty($_POST['is_promo']) ? '1' : '0';
            $removeImage = !empty($_POST['remove_image']);

            if ($name === '' || $price <= 0) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Название и цена (> 0) обязательны'];
                redirect();
            }
            if ($oldPrice > 0 && $oldPrice <= $price) {
                $oldPrice = 0; // ставим без скидки, чтобы избежать абсурда
            }

            // Изображение
            $newImage = null;
            if (!empty($_FILES['image_file']['name'])) {
                $newImage = saveUpload($_FILES['image_file']);
                if ($newImage === null) {
                    $_SESSION['flash'] = ['type'=>'error','msg'=>'Не удалось загрузить изображение (jpg/png/gif/webp до 5 МБ)'];
                    redirect();
                }
            }

            if ($id === '') {
                // CREATE
                $maxId = 0;
                foreach ($products as $p) {
                    $maxId = max($maxId, (int)$p['id']);
                }
                $products[] = [
                    'id'          => (string)($maxId + 1),
                    'icon'        => $icon,
                    'image'       => $newImage ?? '',
                    'name'        => $name,
                    'description' => $description,
                    'price'       => (string)$price,
                    'old_price'   => (string)$oldPrice,
                    'category'    => $category,
                    'is_promo'    => $isPromo,
                ];
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Товар добавлен'];
            } else {
                // UPDATE
                foreach ($products as &$p) {
                    if ((string)$p['id'] === $id) {
                        $p['icon']        = $icon;
                        $p['name']        = $name;
                        $p['description'] = $description;
                        $p['price']       = (string)$price;
                        $p['old_price']   = (string)$oldPrice;
                        $p['category']    = $category;
                        $p['is_promo']    = $isPromo;
                        if ($removeImage) $p['image'] = '';
                        if ($newImage)    $p['image'] = $newImage;
                        break;
                    }
                }
                unset($p);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Товар обновлён'];
            }
            saveProducts($products);
            redirect();
        }

        // ---------- УДАЛИТЬ ----------
        if ($action === 'delete') {
            $id = trim($_POST['id'] ?? '');
            if ($id !== '') {
                $products = array_values(array_filter($products, fn($p) => (string)$p['id'] !== $id));
                saveProducts($products);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Товар удалён'];
            }
            redirect();
        }
    }
}

$products = $isAdminPage ? loadProducts() : [];

// ---------- форма редактирования (по GET ?edit=ID) ----------
$editId = $_GET['edit'] ?? null;
$editing = null;
if ($editId !== null && $isAdminPage) {
    foreach ($products as $p) {
        if ((string)$p['id'] === (string)$editId) { $editing = $p; break; }
    }
}
$showForm = $editId !== null || isset($_GET['new']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Управление каталогом — Админ</title>
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
  body { font-family: 'Segoe UI', Tahoma, sans-serif; background: var(--cream); color: var(--dark); line-height: 1.5; }
  .topbar { background: var(--brown); color: #fff; padding: 15px 0; box-shadow: 0 2px 10px rgba(0,0,0,.15); }
  .topbar-inner { max-width: 1200px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
  .topbar a.logo { color: var(--honey); text-decoration: none; font-size: 1.3rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
  .topbar nav a { color: #fff; text-decoration: none; margin-left: 20px; font-weight: 500; font-size: .95rem; }
  .topbar nav a:hover { color: var(--honey); }
  .topbar nav a.active { color: var(--honey); border-bottom: 2px solid var(--honey); padding-bottom: 3px; }

  .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

  .flash { padding: 14px 22px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; }
  .flash-success { background: #d5f5e0; color: #196f3d; border-left: 4px solid #27ae60; }
  .flash-error   { background: #fde2e1; color: #c0392b; border-left: 4px solid #e74c3c; }

  .header-line { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
  .header-line h1 { color: var(--brown); font-size: 1.8rem; }
  .add-btn {
    background: var(--honey); color: var(--brown); padding: 12px 24px; border-radius: 30px;
    text-decoration: none; font-weight: 700; border: none; cursor: pointer;
    transition: background .2s, transform .2s; display: inline-block;
  }
  .add-btn:hover { background: var(--honey-dark); transform: scale(1.03); }

  .products-table { width: 100%; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 15px rgba(74,44,15,.08); }
  .products-table th { background: var(--brown); color: var(--honey); text-align: left; padding: 14px 16px; font-size: .85rem; text-transform: uppercase; letter-spacing: .5px; }
  .products-table td { padding: 12px 16px; border-top: 1px solid #f0e6d2; vertical-align: middle; }
  .products-table tr:hover td { background: #fdf8ee; }

  .thumb {
    width: 50px; height: 50px; border-radius: 10px; background: linear-gradient(135deg, #fff3d4, #ffe6a8);
    display: flex; align-items: center; justify-content: center; font-size: 1.8rem;
    overflow: hidden; flex-shrink: 0;
  }
  .thumb img { width: 100%; height: 100%; object-fit: cover; }
  .name-cell { display: flex; align-items: center; gap: 12px; }
  .name-cell .info { min-width: 0; }
  .name-cell .nm { font-weight: 600; color: var(--brown); }
  .name-cell .ds { font-size: .8rem; color: var(--brown-light); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 280px; }

  .price-col { font-weight: 700; color: var(--honey-dark); white-space: nowrap; }
  .price-col .old { display: block; font-size: .8rem; color: #999; text-decoration: line-through; font-weight: 400; }
  .price-col .off { display: inline-block; background: #e74c3c; color: #fff; border-radius: 6px; padding: 1px 6px; font-size: .7rem; margin-left: 4px; }

  .badges-cell { display: flex; gap: 6px; flex-wrap: wrap; }
  .tag { font-size: .7rem; padding: 2px 8px; border-radius: 8px; font-weight: 700; }
  .tag-promo { background: #fff3d4; color: var(--honey-dark); }
  .tag-cat   { background: #eaecee; color: #566573; }

  .actions { display: flex; gap: 6px; white-space: nowrap; }
  .btn-sm {
    padding: 6px 12px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; font-size: .85rem;
    text-decoration: none; display: inline-block; transition: background .2s;
  }
  .btn-edit   { background: var(--honey); color: var(--brown); }
  .btn-edit:hover { background: var(--honey-dark); }
  .btn-del    { background: #fde2e1; color: #c0392b; }
  .btn-del:hover { background: #f5b7b1; }

  .empty-state {
    background: #fff; padding: 60px 20px; text-align: center; border-radius: 16px;
    color: var(--brown-light); font-size: 1.1rem; box-shadow: 0 4px 15px rgba(74,44,15,.08);
  }

  .not-admin { max-width: 500px; margin: 80px auto; background: #fff; padding: 40px; border-radius: 20px; text-align: center; box-shadow: 0 10px 40px rgba(74,44,15,.15); }
  .not-admin h1 { color: var(--brown); margin-bottom: 15px; }
  .not-admin p { color: var(--brown-light); margin-bottom: 25px; }
  .not-admin a { display: inline-block; background: var(--honey); color: var(--brown); text-decoration: none; padding: 12px 30px; border-radius: 30px; font-weight: 700; }

  /* Модалка формы */
  .modal-overlay {
    position: fixed; inset: 0; background: rgba(43,26,10,.65); backdrop-filter: blur(4px);
    display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1000;
  }
  .modal-overlay.active { display: flex; }
  .modal {
    background: #fff; border-radius: 20px; width: 100%; max-width: 540px; max-height: 92vh;
    overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.35);
  }
  .modal-header {
    background: linear-gradient(135deg, var(--honey), var(--honey-dark));
    padding: 22px 24px; position: relative; text-align: center;
  }
  .modal-header h2 { color: var(--brown); font-size: 1.3rem; }
  .modal-close {
    position: absolute; top: 12px; right: 14px; background: rgba(74,44,15,.15); border: none;
    width: 32px; height: 32px; border-radius: 50%; font-size: 1.2rem; color: var(--brown); cursor: pointer;
  }
  .modal-close:hover { background: rgba(74,44,15,.3); }

  .product-form { padding: 24px; display: flex; flex-direction: column; gap: 14px; }
  .product-form .field { display: flex; flex-direction: column; gap: 5px; }
  .product-form label { font-size: .85rem; font-weight: 600; color: var(--brown-light); }
  .product-form input[type=text],
  .product-form input[type=number],
  .product-form textarea,
  .product-form select {
    padding: 10px 13px; border: 2px solid #eadcc0; border-radius: 10px; font-family: inherit;
    font-size: .95rem; background: var(--cream); outline: none; transition: border-color .2s;
  }
  .product-form input:focus, .product-form textarea:focus { border-color: var(--honey); background: #fff; }
  .product-form textarea { min-height: 70px; resize: vertical; }
  .product-form .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .product-form .checkbox-row { display: flex; align-items: center; gap: 8px; }
  .product-form .checkbox-row input { width: 18px; height: 18px; cursor: pointer; }
  .product-form .checkbox-row label { margin: 0; cursor: pointer; }
  .product-form .file-input { font-size: .85rem; padding: 8px; background: #fff; }
  .product-form .image-preview {
    display: flex; gap: 10px; align-items: center; padding: 8px; background: var(--cream); border-radius: 10px;
    margin-top: 6px;
  }
  .product-form .image-preview img { max-width: 60px; max-height: 60px; border-radius: 8px; }
  .product-form .image-preview span { font-size: .85rem; color: var(--brown-light); }

  .form-actions { display: flex; gap: 10px; margin-top: 8px; }
  .form-actions button {
    flex: 1; padding: 12px; border-radius: 10px; border: none; font-weight: 700; font-size: .95rem; cursor: pointer;
  }
  .btn-save { background: var(--brown); color: var(--honey); }
  .btn-save:hover { background: var(--honey-dark); color: var(--brown); }
  .btn-cancel { background: #f0e6d2; color: var(--brown); }

  @media (max-width: 700px) {
    .products-table { font-size: .85rem; }
    .products-table th:nth-child(4), .products-table td:nth-child(4),
    .products-table th:nth-child(5), .products-table td:nth-child(5) { display: none; }
    .name-cell .ds { display: none; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <a href="index.php" class="logo">🐝 Пчеловод · Админ</a>
    <nav>
      <a href="index.php">← На главную</a>
      <a href="admin.php">📋 Заказы</a>
      <a href="admin-products.php" class="active">📦 Каталог</a>
    </nav>
  </div>
</div>

<?php if (!$isAdminPage): ?>
  <div class="not-admin">
    <h1>🔒 Доступ закрыт</h1>
    <p>Эта страница только для сотрудников магазина.<br>Войдите с аккаунтом администратора.</p>
    <a href="index.php">На главную → Войти</a>
  </div>
<?php else: ?>

<div class="container">
  <?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="header-line">
    <h1>Управление каталогом</h1>
    <a href="admin-products.php?new=1" class="add-btn">➕ Добавить товар</a>
  </div>

  <?php if (!count($products)): ?>
    <div class="empty-state">В каталоге пока нет товаров. Нажмите «Добавить товар».</div>
  <?php else: ?>
    <table class="products-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Товар</th>
          <th>Цена</th>
          <th>Категория</th>
          <th>Бейджи</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p):
          $price    = (int)$p['price'];
          $oldPrice = (int)($p['old_price'] ?? 0);
          $hasDisc  = $oldPrice > $price;
          $discPct  = $hasDisc ? (int)round((1 - $price / $oldPrice) * 100) : 0;
          $isPromo  = isset($p['is_promo']) && (string)$p['is_promo'] === '1';
        ?>
        <tr>
          <td>#<?= htmlspecialchars($p['id']) ?></td>
          <td>
            <div class="name-cell">
              <div class="thumb">
                <?php if (!empty($p['image'])): ?>
                  <img src="<?= htmlspecialchars($p['image']) ?>" alt="">
                <?php else: ?>
                  <?= htmlspecialchars($p['icon'] ?? '📦') ?>
                <?php endif; ?>
              </div>
              <div class="info">
                <div class="nm"><?= htmlspecialchars($p['name']) ?></div>
                <div class="ds"><?= htmlspecialchars($p['description'] ?? '') ?></div>
              </div>
            </div>
          </td>
          <td class="price-col">
            <?= number_format($price, 0, '.', ' ') ?> ₽
            <?php if ($hasDisc): ?>
              <span class="old"><?= number_format($oldPrice, 0, '.', ' ') ?> ₽</span>
              <span class="off">-<?= $discPct ?>%</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($p['category'] ?? '—') ?></td>
          <td class="badges-cell">
            <?php if ($isPromo): ?><span class="tag tag-promo">🔥 Акция</span><?php endif; ?>
            <?php if (!empty($p['category'])): ?><span class="tag tag-cat"><?= htmlspecialchars($p['category']) ?></span><?php endif; ?>
          </td>
          <td>
            <div class="actions">
              <a href="admin-products.php?edit=<?= htmlspecialchars($p['id']) ?>" class="btn-sm btn-edit">✏️</a>
              <form method="POST" onsubmit="return confirm('Удалить товар «<?= htmlspecialchars(addslashes($p['name'])) ?>»?');" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                <button type="submit" class="btn-sm btn-del">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Модалка формы -->
<div class="modal-overlay <?= $showForm ? 'active' : '' ?>" id="productModal">
  <div class="modal">
    <div class="modal-header">
      <h2><?= $editing ? 'Редактировать товар' : 'Новый товар' ?></h2>
      <a href="admin-products.php" class="modal-close" style="text-decoration:none;line-height:32px;">×</a>
    </div>

    <form method="POST" action="admin-products.php" class="product-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= htmlspecialchars($editing['id'] ?? '') ?>">

      <div class="row">
        <div class="field">
          <label for="pIcon">Emoji (если нет картинки)</label>
          <input type="text" id="pIcon" name="icon" value="<?= htmlspecialchars($editing['icon'] ?? '📦') ?>" maxlength="8">
        </div>
        <div class="field">
          <label for="pCategory">Категория</label>
          <input type="text" id="pCategory" name="category" value="<?= htmlspecialchars($editing['category'] ?? '') ?>" placeholder="Мёд, Ульи, Инвентарь…">
        </div>
      </div>

      <div class="field">
        <label for="pName">Название *</label>
        <input type="text" id="pName" name="name" value="<?= htmlspecialchars($editing['name'] ?? '') ?>" required>
      </div>

      <div class="field">
        <label for="pDescription">Описание</label>
        <textarea id="pDescription" name="description"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
      </div>

      <div class="row">
        <div class="field">
          <label for="pPrice">Цена (₽) *</label>
          <input type="number" id="pPrice" name="price" value="<?= (int)($editing['price'] ?? 0) ?>" min="1" required>
        </div>
        <div class="field">
          <label for="pOldPrice">Старая цена (для скидки)</label>
          <input type="number" id="pOldPrice" name="old_price" value="<?= (int)($editing['old_price'] ?? 0) ?>" min="0" placeholder="0 = без скидки">
        </div>
      </div>

      <div class="field">
        <label for="pImage">Изображение товара</label>
        <input type="file" id="pImage" name="image_file" accept="image/*" class="file-input">
        <?php if (!empty($editing['image'])): ?>
          <div class="image-preview">
            <img src="<?= htmlspecialchars($editing['image']) ?>" alt="">
            <span>Текущее изображение</span>
            <label class="checkbox-row" style="margin-left:auto;">
              <input type="checkbox" name="remove_image" value="1">
              Удалить
            </label>
          </div>
        <?php endif; ?>
      </div>

      <div class="field checkbox-row">
        <input type="checkbox" id="pIsPromo" name="is_promo" value="1"
               <?= !empty($editing) && isset($editing['is_promo']) && (string)$editing['is_promo'] === '1' ? 'checked' : '' ?>>
        <label for="pIsPromo">🔥 Участвует в акции (бейдж «Акция»)</label>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn-save">💾 Сохранить</button>
        <a href="admin-products.php" class="btn-cancel" style="text-align:center;line-height:44px;text-decoration:none;">Отмена</a>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
</body>
</html>