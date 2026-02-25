<?php
$base = $base ?? ($config['app']['base_url'] ?? '/tagom/public');
$assetBase = $base !== '' ? $base : '';
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($title ?? 'CRM') ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Cairo', 'ui-sans-serif', 'system-ui'] }
        }
      }
    }
  </script>

  <link rel="stylesheet" href="<?= $assetBase ?>/assets/app.css" />
</head>

<body class="tagom-shell text-slate-900 font-sans">
  <div class="min-h-screen flex">

    <aside id="sidebar"
      class="sidebar tagom-sidebar transition-all duration-300 w-[270px] text-slate-100 p-4 hidden md:block">

      <div class="sidebar-brand rounded-2xl p-3 flex items-center justify-between mb-4">
        <div class="font-extrabold sidebar-text">CRM</div>
        <div class="text-xs sidebar-badge sidebar-text">Internal</div>
      </div>

      <div class="sidebar-user-card rounded-2xl p-3 mb-4">
        <div class="font-semibold sidebar-text"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
        <div class="text-xs text-slate-300 mt-1 sidebar-text"><?= htmlspecialchars($user['role'] ?? '') ?></div>
      </div>

      <nav class="space-y-2">
        <a class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-xl" href="<?= $base ?>/index.php">
          <span class="sidebar-icon"><img class="sidebar-icon-img" src="<?= $assetBase ?>/assets/img/dashborad.png" alt="Dashboard"></span><span class="sidebar-text">Dashboard</span>
        </a>

        <a class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-xl" href="<?= $base ?>/customers.php">
          <span class="sidebar-icon"><img class="sidebar-icon-img" src="<?= $assetBase ?>/assets/img/customers.png" alt="Customers"></span><span class="sidebar-text">Customers</span>
        </a>

        <a class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-xl" href="<?= $base ?>/suppliers.php">
          <span class="sidebar-icon"><img class="sidebar-icon-img" src="<?= $assetBase ?>/assets/img/supliers.png" alt="Suppliers"></span><span class="sidebar-text">Suppliers</span>
        </a>

        <a class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-xl" href="<?= $base ?>/products.php">
          <span class="sidebar-icon"><img class="sidebar-icon-img" src="<?= $assetBase ?>/assets/img/prodututs.png" alt="Products"></span><span class="sidebar-text">Products</span>
        </a>

        <?php if (has_role([ROLE_OWNER])): ?>
          <a class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-xl" href="<?= $base ?>/users.php">
            <span class="sidebar-icon"><img class="sidebar-icon-img" src="<?= $assetBase ?>/assets/img/users.png" alt="Users"></span><span class="sidebar-text">Users</span>
          </a>
        <?php endif; ?>

        <a class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-xl" href="<?= $base ?>/logout.php">
          <span class="sidebar-icon"><img class="sidebar-icon-img" src="<?= $assetBase ?>/assets/img/logout.png" alt="Logout"></span><span class="sidebar-text">Logout</span>
        </a>
      </nav>
    </aside>

    <main class="flex-1 p-4 md:p-6">

      <div class="flex items-center justify-between mb-4">
        <button id="toggleSidebar"
          class="topbar-toggle inline-flex items-center justify-center rounded-xl px-3 py-2 shadow-sm">
          Menu
        </button>

        <div class="text-right">
          <div class="text-xl md:text-2xl font-extrabold">
            <?= htmlspecialchars($title ?? '') ?>
          </div>
          <?php if (!empty($subtitle)): ?>
            <div class="text-sm text-slate-500 mt-1">
              <?= htmlspecialchars($subtitle) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
