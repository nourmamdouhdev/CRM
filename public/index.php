<?php
require __DIR__ . '/../app/core/DB.php';
require __DIR__ . '/../app/core/Auth.php';
require __DIR__ . '/../app/Middlewares/AuthMiddleware.php';

$config = require __DIR__ . '/../config/config.php';
session_name($config['app']['session_name']);
session_start();

AuthMiddleware::handle();
$user = Auth::user();

$pdo = DB::pdo();

// مؤشرات بسيطة (MVP)
$customersCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM parties WHERE type='customer' AND is_active=1")->fetch()['c'];
$suppliersCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM parties WHERE type='supplier' AND is_active=1")->fetch()['c'];
$productsCount  = (int)$pdo->query("SELECT COUNT(*) AS c FROM products WHERE is_active=1")->fetch()['c'];

$title = "Dashboard";
$base = $config['app']['base_url'] ?? '/tagom/public';
$title = "Dashboard";
$subtitle = "نظرة سريعة على السيستم";

require __DIR__ . '/../app/views/partials/header.php';
?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">Customers</div>
    <div class="text-3xl font-extrabold mt-2"><?= $customersCount ?></div>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">Suppliers</div>
    <div class="text-3xl font-extrabold mt-2"><?= $suppliersCount ?></div>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">Products</div>
    <div class="text-3xl font-extrabold mt-2"><?= $productsCount ?></div>
  </div>
</div>

<?php
require __DIR__ . './../app/views/partials/footer.php';
