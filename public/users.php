<?php
require __DIR__ . '/bootstrap.php';
authorize($config['authz']['users_manage'] ?? [ROLE_OWNER]);

$title = 'Users';
$subtitle = 'Manage system users and roles';

$error = null;
$success = null;

$csrf = CSRF::token();
$currentUserId = (int)($user['id'] ?? 0);

$rolesStmt = $pdo->query("SELECT id, name FROM roles ORDER BY id ASC");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
$roleMap = [];
foreach ($roles as $role) {
  $roleMap[(int)$role['id']] = (string)$role['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    CSRF::verify($_POST['csrf_token'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
      $fullName = trim((string)($_POST['full_name'] ?? ''));
      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      $password = (string)($_POST['password'] ?? '');
      $roleId = (int)($_POST['role_id'] ?? 0);
      $isActive = isset($_POST['is_active']) ? 1 : 0;

      if ($fullName === '' || $username === '' || $password === '' || $roleId <= 0) {
        throw new InvalidArgumentException('Please fill all required fields.');
      }
      if (!isset($roleMap[$roleId])) {
        throw new InvalidArgumentException('Selected role is invalid.');
      }
      if (!preg_match('/^[a-z0-9_.-]{3,60}$/', $username)) {
        throw new InvalidArgumentException('Username must be 3-60 chars and contain only a-z, 0-9, _, -, .');
      }
      if (strlen($password) < 6) {
        throw new InvalidArgumentException('Password must be at least 6 characters.');
      }

      $existsStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
      $existsStmt->execute([$username]);
      if ($existsStmt->fetch()) {
        throw new InvalidArgumentException('Username already exists.');
      }

      $hash = password_hash($password, PASSWORD_BCRYPT);
      $createdAt = date('Y-m-d H:i:s');

      $ins = $pdo->prepare("\n        INSERT INTO users (role_id, full_name, username, password_hash, is_active, created_at)\n        VALUES (?, ?, ?, ?, ?, ?)\n      ");
      $ins->execute([$roleId, $fullName, $username, $hash, $isActive, $createdAt]);

      $newId = (int)$pdo->lastInsertId();
      audit_log('user_created', 'users', $newId, [
        'username' => $username,
        'role' => $roleMap[$roleId] ?? null,
        'is_active' => $isActive,
      ]);

      header("Location: {$base}/users.php?ok=created");
      exit;
    }

    if ($action === 'toggle_status') {
      $targetUserId = (int)($_POST['user_id'] ?? 0);
      $nextActive = (int)($_POST['next_active'] ?? 0) === 1 ? 1 : 0;

      if ($targetUserId <= 0) {
        throw new InvalidArgumentException('Invalid user id.');
      }
      if ($targetUserId === $currentUserId && $nextActive === 0) {
        throw new InvalidArgumentException('You cannot deactivate your own account.');
      }

      $upd = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? LIMIT 1");
      $upd->execute([$nextActive, $targetUserId]);

      audit_log('user_status_updated', 'users', $targetUserId, [
        'is_active' => $nextActive,
      ]);

      header("Location: {$base}/users.php?ok=status");
      exit;
    }
  } catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
  } catch (Throwable $e) {
    log_message('error', 'Users page action failed', [
      'exception' => $e->getMessage(),
      'trace' => $e->getTraceAsString(),
    ]);
    $error = 'Unexpected error. Please try again.';
  }
}

$ok = (string)($_GET['ok'] ?? '');
if ($ok === 'created') {
  $success = 'User created successfully.';
} elseif ($ok === 'status') {
  $success = 'User status updated successfully.';
}

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

if ($q !== '') {
  $countStmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM users u\n    JOIN roles r ON r.id = u.role_id\n    WHERE u.full_name LIKE ? OR u.username LIKE ? OR r.name LIKE ?\n  ");
  $like = "%{$q}%";
  $countStmt->execute([$like, $like, $like]);
  $totalUsers = (int)$countStmt->fetchColumn();

  $listStmt = $pdo->prepare("\n    SELECT u.id, u.full_name, u.username, u.is_active, u.created_at, r.name AS role_name\n    FROM users u\n    JOIN roles r ON r.id = u.role_id\n    WHERE u.full_name LIKE ? OR u.username LIKE ? OR r.name LIKE ?\n    ORDER BY u.id DESC\n    LIMIT ? OFFSET ?\n  ");
  $listStmt->bindValue(1, $like, PDO::PARAM_STR);
  $listStmt->bindValue(2, $like, PDO::PARAM_STR);
  $listStmt->bindValue(3, $like, PDO::PARAM_STR);
  $listStmt->bindValue(4, $perPage, PDO::PARAM_INT);
  $listStmt->bindValue(5, $offset, PDO::PARAM_INT);
  $listStmt->execute();
  $users = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

  $listStmt = $pdo->prepare("\n    SELECT u.id, u.full_name, u.username, u.is_active, u.created_at, r.name AS role_name\n    FROM users u\n    JOIN roles r ON r.id = u.role_id\n    ORDER BY u.id DESC\n    LIMIT ? OFFSET ?\n  ");
  $listStmt->bindValue(1, $perPage, PDO::PARAM_INT);
  $listStmt->bindValue(2, $offset, PDO::PARAM_INT);
  $listStmt->execute();
  $users = $listStmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPages = max(1, (int)ceil($totalUsers / $perPage));
$page = min($page, $totalPages);

require __DIR__ . '/../app/views/partials/header.php';
?>

<?php if ($error): ?>
  <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
    <?= h($error) ?>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
    <?= h($success) ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-extrabold text-lg">Add User</h3>
      <span class="text-xs text-slate-500">Owner only</span>
    </div>

    <form method="post" action="<?= $base ?>/users.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="add">

      <label class="block text-sm text-slate-600 mb-1">Full Name *</label>
      <input name="full_name" required class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">

      <label class="block text-sm text-slate-600 mb-1 mt-3">Username *</label>
      <input name="username" required class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200" placeholder="e.g. user.name">

      <label class="block text-sm text-slate-600 mb-1 mt-3">Password *</label>
      <input type="password" name="password" required class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200" minlength="6">

      <label class="block text-sm text-slate-600 mb-1 mt-3">Role *</label>
      <select name="role_id" required class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
        <option value="">-- Select role --</option>
        <?php foreach ($roles as $role): ?>
          <option value="<?= (int)$role['id'] ?>"><?= h($role['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="mt-3 inline-flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="is_active" value="1" checked>
        Active account
      </label>

      <button type="submit" class="mt-4 w-full rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2">
        Create User
      </button>
    </form>
  </div>

  <div class="lg:col-span-2">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
        <div>
          <h3 class="font-extrabold text-lg">Users List</h3>
          <div class="text-sm text-slate-500">
            Showing <b><?= count($users) ?></b> result(s) out of <b><?= (int)$totalUsers ?></b>
          </div>
        </div>

        <form method="get" action="<?= $base ?>/users.php" class="flex gap-2">
          <input name="q" value="<?= h($q) ?>" placeholder="Search name / username / role..."
                 class="w-full md:w-72 rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
          <button class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">Search</button>
          <a href="<?= $base ?>/users.php" class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold inline-flex items-center">Clear</a>
        </form>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-right text-slate-500 border-b">
              <th class="py-2 px-2">#</th>
              <th class="py-2 px-2">Full Name</th>
              <th class="py-2 px-2">Username</th>
              <th class="py-2 px-2">Role</th>
              <th class="py-2 px-2">Status</th>
              <th class="py-2 px-2">Created</th>
              <th class="py-2 px-2">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($users)): ?>
            <tr><td colspan="7" class="py-6 text-center text-slate-500">No users found.</td></tr>
          <?php endif; ?>

          <?php foreach ($users as $row): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2"><?= (int)$row['id'] ?></td>
              <td class="py-2 px-2 font-semibold"><?= h($row['full_name']) ?></td>
              <td class="py-2 px-2"><?= h($row['username']) ?></td>
              <td class="py-2 px-2"><?= h($row['role_name']) ?></td>
              <td class="py-2 px-2">
                <?php if ((int)$row['is_active'] === 1): ?>
                  <span class="inline-flex items-center rounded-xl bg-emerald-50 text-emerald-700 px-2 py-1 text-xs font-semibold">Active</span>
                <?php else: ?>
                  <span class="inline-flex items-center rounded-xl bg-rose-50 text-rose-700 px-2 py-1 text-xs font-semibold">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="py-2 px-2"><?= h($row['created_at']) ?></td>
              <td class="py-2 px-2">
                <form method="post" action="<?= $base ?>/users.php?page=<?= (int)$page ?>&q=<?= urlencode($q) ?>" class="inline">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="next_active" value="<?= (int)$row['is_active'] === 1 ? 0 : 1 ?>">
                  <button class="rounded-xl border border-slate-200 px-3 py-1.5 hover:bg-slate-50 font-semibold" type="submit">
                    <?= (int)$row['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>
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
               href="<?= $base ?>/users.php?page=<?= $prevPage . $qParam ?>">Prev</a>
            <a class="rounded-xl border border-slate-200 px-3 py-1.5 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>"
               href="<?= $base ?>/users.php?page=<?= $nextPage . $qParam ?>">Next</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
