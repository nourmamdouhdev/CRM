<?php
require __DIR__ . '/bootstrap.php';

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
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$totalSuppliers = 0;
$totalPages = 1;

/**
 * LIST Suppliers
 */
if ($q !== '') {
  $countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM parties
    WHERE type='supplier' AND is_active=1
      AND (name LIKE ? OR phone LIKE ?)
  ");
  $like = "%{$q}%";
  $countStmt->execute([$like, $like]);
  $totalSuppliers = (int)$countStmt->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT id, name, phone, created_at
    FROM parties
    WHERE type='supplier' AND is_active=1
      AND (name LIKE ? OR phone LIKE ?)
    ORDER BY id DESC
    LIMIT ? OFFSET ?
  ");
  $stmt->bindValue(1, $like, PDO::PARAM_STR);
  $stmt->bindValue(2, $like, PDO::PARAM_STR);
  $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(4, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $suppliers = $stmt->fetchAll();
} else {
  $countStmt = $pdo->query("
    SELECT COUNT(*)
    FROM parties
    WHERE type='supplier' AND is_active=1
  ");
  $totalSuppliers = (int)$countStmt->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT id, name, phone, created_at
    FROM parties
    WHERE type='supplier' AND is_active=1
    ORDER BY id DESC
    LIMIT ? OFFSET ?
  ");
  $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(2, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $suppliers = $stmt->fetchAll();
}
$totalPages = max(1, (int)ceil($totalSuppliers / $perPage));
$page = min($page, $totalPages);

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
            Showing <b><?= count($suppliers) ?></b> result(s) out of <b><?= (int)$totalSuppliers ?></b>
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
      <?php if ($totalPages > 1): ?>
        <div class="mt-4 flex items-center justify-between text-sm">
          <div class="text-slate-500">Page <?= (int)$page ?> / <?= (int)$totalPages ?></div>
          <div class="flex gap-2">
            <?php
              $prevPage = max(1, $page - 1);
              $nextPage = min($totalPages, $page + 1);
              $qParam = $q !== '' ? '&q=' . urlencode($q) : '';
            ?>
            <a class="rounded-xl border border-slate-200 px-3 py-1.5 <?= $page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>"
               href="<?= $base ?>/suppliers.php?page=<?= $prevPage . $qParam ?>">Prev</a>
            <a class="rounded-xl border border-slate-200 px-3 py-1.5 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>"
               href="<?= $base ?>/suppliers.php?page=<?= $nextPage . $qParam ?>">Next</a>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
