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

$title = "Customers";
$subtitle = "إضافة عميل + البحث + قائمة العملاء";

$error = null;

/**
 * ADD Customer (Only when action=add)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'add')) {
  CSRF::verify($_POST['csrf_token'] ?? null);

  $name    = trim($_POST['name'] ?? '');
  $phone   = trim($_POST['phone'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $notes   = trim($_POST['notes'] ?? '');

  // Default opening balance = 0 (زي ما اتفقنا)
  $opening_balance = 0;
  $opening_balance_type = 'debit';

  if ($name === '') {
    $error = 'اسم العميل مطلوب.';
  } else {
    // Use positional placeholders to avoid HY093 issues
    $sql = "
      INSERT INTO parties
        (type, name, phone, address, notes, opening_balance, opening_balance_type, is_active)
      VALUES
        ('customer', ?, ?, ?, ?, ?, ?, 1)
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

    header("Location: {$base}/customers.php");
    exit;
  }
}

/**
 * Search (GET)
 */
$q = trim($_GET['q'] ?? '');

/**
 * LIST Customers
 */
if ($q !== '') {
  $stmt = $pdo->prepare("
    SELECT id, name, phone, created_at
    FROM parties
    WHERE type='customer' AND is_active=1
      AND (name LIKE ? OR phone LIKE ?)
    ORDER BY id DESC
  ");
  $like = "%{$q}%";
  $stmt->execute([$like, $like]);
  $customers = $stmt->fetchAll();
} else {
  $stmt = $pdo->query("
    SELECT id, name, phone, created_at
    FROM parties
    WHERE type='customer' AND is_active=1
    ORDER BY id DESC
  ");
  $customers = $stmt->fetchAll();
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
  <!-- Add Customer -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-extrabold text-lg">Add Customer</h3>
      <span class="text-xs text-slate-500">Required: name</span>
    </div>

    <form method="post" action="<?= $base ?>/customers.php">
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

      <!-- Important: action=add to avoid accidental POST -->
      <button type="submit" name="action" value="add"
              class="mt-4 w-full rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2">
        Save
      </button>
    </form>

    <div class="mt-3 text-xs text-slate-500">
      الرصيد الافتراضي = 0 (الرصيد بيتحسب من الفواتير والمدفوعات)
    </div>
  </div>

  <!-- Customers List -->
  <div class="lg:col-span-2">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
        <div>
          <h3 class="font-extrabold text-lg">Customers List</h3>
          <div class="text-sm text-slate-500">
            Showing <b><?= count($customers) ?></b> result(s)
            <?php if ($q !== ''): ?> for "<b><?= htmlspecialchars($q) ?></b>"<?php endif; ?>
          </div>
        </div>

        <form method="get" action="<?= $base ?>/customers.php" class="flex gap-2">
          <input name="q" value="<?= htmlspecialchars($q) ?>"
                 placeholder="Search name / phone..."
                 class="w-full md:w-72 rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
          <button class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
            Search
          </button>
          <a href="<?= $base ?>/customers.php"
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
          <?php if (empty($customers)): ?>
            <tr>
              <td colspan="4" class="py-6 text-center text-slate-500">No customers found.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($customers as $c): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2"><?= (int)$c['id'] ?></td>
              <td class="py-2 px-2 font-semibold"><?= htmlspecialchars($c['name']) ?></td>
              <td class="py-2 px-2"><?= htmlspecialchars($c['phone'] ?? '') ?></td>
              <td class="py-2 px-2">
                <a class="inline-flex items-center rounded-xl bg-slate-900 text-white px-3 py-1.5 hover:bg-slate-800"
                   href="<?= $base ?>/customer.php?id=<?= (int)$c['id'] ?>">
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

<?php
require __DIR__ . '/../app/views/partials/footer.php';
