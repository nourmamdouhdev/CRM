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

$title = "Suppliers";
$subtitle = "إضافة مورد + البحث + قائمة الموردين";

$error = null;

/**
 * ADD Supplier (POST + action=add)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'add')) {
  CSRF::verify($_POST['csrf_token'] ?? null);

  $name    = trim($_POST['name'] ?? '');
  $phone   = trim($_POST['phone'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $notes   = trim($_POST['notes'] ?? '');

  // الرصيد الافتراضي = 0
  $opening_balance = 0;
  $opening_balance_type = 'credit'; // مورد غالبًا "له"

  if ($name === '') {
    $error = 'اسم المورد مطلوب.';
  } else {
    $sql = "
      INSERT INTO parties
        (type, name, phone, address, notes, opening_balance, opening_balance_type, is_active)
      VALUES
        ('supplier', ?, ?, ?, ?, ?, ?, 1)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      $name,
      ($phone !== '' ? $phone : null),
      ($address !== '' ? $address : null),
      ($notes !== '' ? $notes : null),
      $opening_balance,
      $opening_balance_type,
    ]);

    header("Location: {$base}/suppliers.php");
    exit;
  }
}

/**
 * Search
 */
$q = trim($_GET['q'] ?? '');

/**
 * LIST Suppliers
 */
if ($q !== '') {
  $stmt = $pdo->prepare("
    SELECT id, name, phone, created_at
    FROM parties
    WHERE type='supplier' AND is_active=1
      AND (name LIKE ? OR phone LIKE ?)
    ORDER BY id DESC
  ");
  $like = "%{$q}%";
  $stmt->execute([$like, $like]);
  $suppliers = $stmt->fetchAll();
} else {
  $stmt = $pdo->query("
    SELECT id, name, phone, created_at
    FROM parties
    WHERE type='supplier' AND is_active=1
    ORDER BY id DESC
  ");
  $suppliers = $stmt->fetchAll();
}

$csrf = CSRF::token();

require __DIR__ . '/../app/views/partials/header.php';
?>

<?php if ($error): ?>
  <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
    <?= htmlspecialchars($error) ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

  <!-- Add Supplier -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-extrabold text-lg">Add Supplier</h3>
      <span class="text-xs text-slate-500">Required: name</span>
    </div>

    <form method="post" action="<?= $base ?>/suppliers.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

      <label class="block text-sm text-slate-600 mb-1">الاسم *</label>
      <input name="name" required
             class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
        <div>
          <label class="block text-sm text-slate-600 mb-1">الموبايل</label>
          <input name="phone"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
        <div>
          <label class="block text-sm text-slate-600 mb-1">العنوان</label>
          <input name="address"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
      </div>

      <div class="mt-3">
        <label class="block text-sm text-slate-600 mb-1">ملاحظات</label>
        <input name="notes"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>

      <button type="submit" name="action" value="add"
              class="mt-4 w-full rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2">
        Save
      </button>
    </form>

    <div class="mt-3 text-xs text-slate-500">
      رصيد المورد الافتراضي = 0
    </div>
  </div>

  <!-- Suppliers List -->
  <div class="lg:col-span-2">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
        <div>
          <h3 class="font-extrabold text-lg">Suppliers List</h3>
          <div class="text-sm text-slate-500">
            Showing <b><?= count($suppliers) ?></b> result(s)
            <?php if ($q !== ''): ?> for "<b><?= htmlspecialchars($q) ?></b>"<?php endif; ?>
          </div>
        </div>

        <form method="get" action="<?= $base ?>/suppliers.php" class="flex gap-2">
          <input name="q" value="<?= htmlspecialchars($q) ?>"
                 placeholder="Search name / phone..."
                 class="w-full md:w-72 rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
          <button class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
            Search
          </button>
          <a href="<?= $base ?>/suppliers.php"
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
              <th class="py-2 px-2">الاسم</th>
              <th class="py-2 px-2">الموبايل</th>
              <th class="py-2 px-2">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($suppliers)): ?>
            <tr>
              <td colspan="4" class="py-6 text-center text-slate-500">No suppliers found.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($suppliers as $s): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2"><?= (int)$s['id'] ?></td>
              <td class="py-2 px-2 font-semibold"><?= htmlspecialchars($s['name']) ?></td>
              <td class="py-2 px-2"><?= htmlspecialchars($s['phone'] ?? '') ?></td>
              <td class="py-2 px-2">
                <a class="inline-flex items-center rounded-xl bg-slate-900 text-white px-3 py-1.5 hover:bg-slate-800"
                   href="<?= $base ?>/supplier.php?id=<?= (int)$s['id'] ?>">
                  Profile
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
