<?php
$base = $base ?? ($config['app']['base_url'] ?? '/tagom/public');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($title ?? 'Tagom CRM') ?></title>

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

  <style>
    /* hide text when sidebar minimized */
    .sidebar.min .sidebar-text {
      display: none;
    }
  </style>
</head>

<body class="bg-slate-50 text-slate-900 font-sans">
  <div class="min-h-screen flex">

    <!-- Sidebar -->
    <aside id="sidebar"
      class="sidebar transition-all duration-300 w-[270px] bg-slate-900 text-slate-100 p-4 hidden md:block">

      <div class="rounded-2xl bg-white/5 p-3 flex items-center justify-between mb-4">
        <div class="font-extrabold sidebar-text">Tagom CRM</div>
        <div class="text-xs opacity-80 sidebar-text">Internal</div>
      </div>

      <div class="rounded-2xl border border-white/10 bg-white/5 p-3 mb-4">
        <div class="font-semibold sidebar-text"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
        <div class="text-xs text-slate-300 mt-1 sidebar-text"><?= htmlspecialchars($user['role'] ?? '') ?></div>
      </div>

      <nav class="space-y-2">
        <a class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-white/10"
           href="<?= $base ?>/index.php">
          <span>🏠</span><span class="sidebar-text">Dashboard</span>
        </a>

        <a class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-white/10"
           href="<?= $base ?>/customers.php">
          <span>👥</span><span class="sidebar-text">Customers</span>
        </a>

        <a class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-white/10"
           href="<?= $base ?>/suppliers.php">
          <span>🏭</span><span class="sidebar-text">Suppliers</span>
        </a>

        <a class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-white/10"
           href="<?= $base ?>/products.php">
          <span>📦</span><span class="sidebar-text">Products</span>
        </a>

        <a class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-white/10"
           href="<?= $base ?>/logout.php">
          <span>🚪</span><span class="sidebar-text">Logout</span>
        </a>
      </nav>
    </aside>

    <!-- Main -->
    <main class="flex-1 p-4 md:p-6">

      <!-- Top bar -->
      <div class="flex items-center justify-between mb-4">
        <button id="toggleSidebar"
          class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm hover:bg-slate-50">
          ☰
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
