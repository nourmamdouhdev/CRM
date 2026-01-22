<?php
require __DIR__ . '/../app/core/DB.php';
require __DIR__ . '/../app/core/Auth.php';
require __DIR__ . '/../app/core/CSRF.php';
require __DIR__ . '/../app/Middlewares/AuthMiddleware.php';

$config = require __DIR__ . '/../config/config.php';
session_name($config['app']['session_name']);
session_start();

AuthMiddleware::handle();
$user = Auth::user();
$pdo  = DB::pdo();

$base = $config['app']['base_url'] ?? '/tagom/public';

$title = "Products";
$subtitle = "إضافة منتج + ربطه بمورد واحد + بحث + قائمة المنتجات";

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n) { return number_format((float)$n, 2); }

$error = null;

/**
 * Load suppliers (for searchable picker)
 */
$supStmt = $pdo->query("
  SELECT id, name
  FROM parties
  WHERE type='supplier' AND is_active=1
  ORDER BY name ASC
");
$suppliers = $supStmt->fetchAll();

$suppliersJson = json_encode(array_map(fn($s)=>[
  'id' => (int)$s['id'],
  'name' => (string)$s['name'],
], $suppliers), JSON_UNESCAPED_UNICODE);

/**
 * ADD Product
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'add')) {
  CSRF::verify($_POST['csrf_token'] ?? null);

  $name = trim($_POST['name'] ?? '');
  $sku  = trim($_POST['sku'] ?? '');
  $image_path = trim($_POST['image_path'] ?? '');

  $sale_price_default = (float)($_POST['sale_price_default'] ?? 0);
  $cost_price_default = (float)($_POST['cost_price_default'] ?? 0);

  $supplier_id = (int)($_POST['supplier_id'] ?? 0);
  if ($supplier_id <= 0) $supplier_id = null;

  if ($name === '') {
    $error = 'اسم المنتج مطلوب.';
  } else {
    // Positional placeholders to avoid HY093
    $sql = "
      INSERT INTO products
        (name, sku, image_path, sale_price_default, cost_price_default, supplier_id, is_active)
      VALUES
        (?, ?, ?, ?, ?, ?, 1)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      $name,
      ($sku !== '' ? $sku : null),
      ($image_path !== '' ? $image_path : null),
      $sale_price_default,
      $cost_price_default,
      $supplier_id
    ]);

    header("Location: {$base}/products.php");
    exit;
  }
}

/**
 * Search
 */
$q = trim($_GET['q'] ?? '');

/**
 * LIST products
 */
if ($q !== '') {
  $stmt = $pdo->prepare("
    SELECT p.id, p.name, p.sku, p.image_path,
           p.sale_price_default, p.cost_price_default,
           p.supplier_id, s.name AS supplier_name,
           p.created_at
    FROM products p
    LEFT JOIN parties s ON s.id = p.supplier_id
    WHERE p.is_active=1
      AND (p.name LIKE ? OR p.sku LIKE ?)
    ORDER BY p.id DESC
  ");
  $like = "%{$q}%";
  $stmt->execute([$like, $like]);
  $products = $stmt->fetchAll();
} else {
  $stmt = $pdo->query("
    SELECT p.id, p.name, p.sku, p.image_path,
           p.sale_price_default, p.cost_price_default,
           p.supplier_id, s.name AS supplier_name,
           p.created_at
    FROM products p
    LEFT JOIN parties s ON s.id = p.supplier_id
    WHERE p.is_active=1
    ORDER BY p.id DESC
  ");
  $products = $stmt->fetchAll();
}

$csrf = CSRF::token();

require __DIR__ . '/../app/views/partials/header.php';
?>

<?php if ($error): ?>
  <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
    <?= h($error) ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

  <!-- Add Product -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-extrabold text-lg">Add Product</h3>
      <span class="text-xs text-slate-500">Required: name</span>
    </div>

    <form method="post" action="<?= $base ?>/products.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

      <label class="block text-sm text-slate-600 mb-1">اسم المنتج *</label>
      <input name="name" required
             class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
        <div>
          <label class="block text-sm text-slate-600 mb-1">SKU</label>
          <input name="sku"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>

        <!-- Searchable Supplier Picker -->
        <div class="relative">
          <label class="block text-sm text-slate-600 mb-1">Supplier (واحد فقط)</label>

          <input id="supplierSearch" type="text" placeholder="اكتب اسم المورد..."
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">

          <input type="hidden" name="supplier_id" id="supplierId" value="">

          <div id="supplierResults"
               class="absolute z-20 mt-2 hidden w-full rounded-xl border border-slate-200 bg-white max-h-48 overflow-auto shadow-sm"></div>

          <div id="supplierSelected" class="mt-2 text-xs text-slate-500"></div>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
        <div>
          <label class="block text-sm text-slate-600 mb-1">Sale Price (Default)</label>
          <input name="sale_price_default" value="0" type="number" step="0.01"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
        <div>
          <label class="block text-sm text-slate-600 mb-1">Cost Price (Default)</label>
          <input name="cost_price_default" value="0" type="number" step="0.01"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
      </div>

      <div class="mt-3">
        <label class="block text-sm text-slate-600 mb-1">Image Path</label>
        <input name="image_path" placeholder="e.g. /uploads/p1.jpg"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>

      <button type="submit" name="action" value="add"
              class="mt-4 w-full rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2">
        Save
      </button>

      <div class="mt-3 text-xs text-slate-500">
        لو ما اخترتش مورد: المنتج هيتسجل من غير مورد.
      </div>
    </form>
  </div>

  <!-- Products List -->
  <div class="lg:col-span-2">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
        <div>
          <h3 class="font-extrabold text-lg">Products List</h3>
          <div class="text-sm text-slate-500">
            Showing <b><?= count($products) ?></b> result(s)
            <?php if ($q !== ''): ?> for "<b><?= h($q) ?></b>"<?php endif; ?>
          </div>
        </div>

        <form method="get" action="<?= $base ?>/products.php" class="flex gap-2">
          <input name="q" value="<?= h($q) ?>"
                 placeholder="Search name / sku..."
                 class="w-full md:w-72 rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
          <button class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
            Search
          </button>
          <a href="<?= $base ?>/products.php"
             class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold inline-flex items-center">
            Clear
          </a>
        </form>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-right text-slate-500 border-b">
              <th class="py-2 px-2">#</th>
              <th class="py-2 px-2">المنتج</th>
              <th class="py-2 px-2">SKU</th>
              <th class="py-2 px-2">Supplier</th>
              <th class="py-2 px-2">Sale</th>
              <th class="py-2 px-2">Cost</th>
              <th class="py-2 px-2">Image</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($products)): ?>
            <tr>
              <td colspan="7" class="py-6 text-center text-slate-500">No products found.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($products as $p): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2"><?= (int)$p['id'] ?></td>
              <td class="py-2 px-2 font-semibold"><?= h($p['name']) ?></td>
              <td class="py-2 px-2"><?= h($p['sku'] ?? '') ?></td>
              <td class="py-2 px-2"><?= h($p['supplier_name'] ?? '—') ?></td>
              <td class="py-2 px-2"><?= money($p['sale_price_default']) ?></td>
              <td class="py-2 px-2"><?= money($p['cost_price_default']) ?></td>
              <td class="py-2 px-2">
                <?php if (!empty($p['image_path'])): ?>
                  <span class="text-xs text-slate-600"><?= h($p['image_path']) ?></span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>

</div>

<script>
  const suppliers = <?= $suppliersJson ?>;

  const input = document.getElementById('supplierSearch');
  const results = document.getElementById('supplierResults');
  const hiddenId = document.getElementById('supplierId');
  const selected = document.getElementById('supplierSelected');

  function render(list){
    results.innerHTML = '';
    if (list.length === 0) {
      results.innerHTML = '<div class="p-3 text-sm text-slate-500">لا يوجد نتائج</div>';
      return;
    }
    list.slice(0, 30).forEach(s => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'w-full text-right px-3 py-2 hover:bg-slate-50';
      btn.textContent = s.name;
      btn.onclick = () => {
        hiddenId.value = s.id;
        selected.textContent = 'Selected: ' + s.name;
        results.classList.add('hidden');
      };
      results.appendChild(btn);
    });
  }

  input?.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    hiddenId.value = '';
    selected.textContent = '';
    if (!q) { results.classList.add('hidden'); return; }

    const filtered = suppliers.filter(s => (s.name || '').toLowerCase().includes(q));
    results.classList.remove('hidden');
    render(filtered);
  });

  document.addEventListener('click', (e) => {
    if (!results.contains(e.target) && e.target !== input) {
      results.classList.add('hidden');
    }
  });
</script>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
