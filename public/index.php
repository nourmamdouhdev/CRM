<?php
require __DIR__ . '/bootstrap.php';

$today = date('Y-m-d');
$lowStockThreshold = 5;
$canManageTransactions = has_role($config['authz']['sales_create'] ?? [ROLE_OWNER, ROLE_EMPLOYEE]);

$scalar = static function (PDO $pdo, string $sql, array $params = []): float {
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    log_message('warning', 'Dashboard scalar query failed', [
      'sql' => $sql,
      'error' => $e->getMessage(),
    ]);
    return 0.0;
  }
};

$count = static function (PDO $pdo, string $sql, array $params = []): int {
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    log_message('warning', 'Dashboard count query failed', [
      'sql' => $sql,
      'error' => $e->getMessage(),
    ]);
    return 0;
  }
};

$fetchRow = static function (PDO $pdo, string $sql, array $params = [], array $fallback = []): array {
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: $fallback;
  } catch (Throwable $e) {
    log_message('warning', 'Dashboard row query failed', [
      'sql' => $sql,
      'error' => $e->getMessage(),
    ]);
    return $fallback;
  }
};

$fetchAll = static function (PDO $pdo, string $sql, array $params = []): array {
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    log_message('warning', 'Dashboard list query failed', [
      'sql' => $sql,
      'error' => $e->getMessage(),
    ]);
    return [];
  }
};

$tableColumns = [];
$getColumns = static function (PDO $pdo, string $table) use (&$tableColumns): array {
  if (isset($tableColumns[$table])) {
    return $tableColumns[$table];
  }

  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $cols[(string)$row['Field']] = true;
    }
    $tableColumns[$table] = $cols;
    return $cols;
  } catch (Throwable $e) {
    log_message('warning', 'Dashboard table introspection failed', [
      'table' => $table,
      'error' => $e->getMessage(),
    ]);
    $tableColumns[$table] = [];
    return [];
  }
};

$pickColumn = static function (array $cols, array $candidates): ?string {
  foreach ($candidates as $candidate) {
    if (isset($cols[$candidate])) {
      return $candidate;
    }
  }
  return null;
};

$salesCols = $getColumns($pdo, 'sales_invoices');
$purchaseCols = $getColumns($pdo, 'purchase_invoices');

$salesTotalCol = $pickColumn($salesCols, ['net_total', 'total_amount', 'total']);
$salesPaidCol = $pickColumn($salesCols, ['paid_amount']);
$salesRemainingCol = $pickColumn($salesCols, ['remaining_amount']);
$salesDueCol = $pickColumn($salesCols, ['due_date']);
$salesDateCol = $pickColumn($salesCols, ['invoice_date', 'created_at']);
$salesInvoiceNoCol = $pickColumn($salesCols, ['invoice_no']);

$purchaseTotalCol = $pickColumn($purchaseCols, ['net_total', 'total_amount', 'total']);
$purchasePaidCol = $pickColumn($purchaseCols, ['paid_amount']);
$purchaseRemainingCol = $pickColumn($purchaseCols, ['remaining_amount']);
$purchaseDueCol = $pickColumn($purchaseCols, ['due_date']);
$purchaseDateCol = $pickColumn($purchaseCols, ['invoice_date', 'created_at']);
$purchaseInvoiceNoCol = $pickColumn($purchaseCols, ['invoice_no']);

$salesTotalExpr = $salesTotalCol ? "COALESCE(`{$salesTotalCol}`, 0)" : "0";
$salesPaidExpr = $salesPaidCol ? "COALESCE(`{$salesPaidCol}`, 0)" : "0";
$salesRemainingExpr = $salesRemainingCol
  ? "COALESCE(`{$salesRemainingCol}`, 0)"
  : "GREATEST(($salesTotalExpr) - ($salesPaidExpr), 0)";
$salesInvoiceNoExpr = $salesInvoiceNoCol ? "`{$salesInvoiceNoCol}`" : "CONCAT('#', `id`)";
$salesInvoiceDateExpr = $salesDateCol ? "DATE(`{$salesDateCol}`)" : "NULL";

$purchaseTotalExpr = $purchaseTotalCol ? "COALESCE(`{$purchaseTotalCol}`, 0)" : "0";
$purchasePaidExpr = $purchasePaidCol ? "COALESCE(`{$purchasePaidCol}`, 0)" : "0";
$purchaseRemainingExpr = $purchaseRemainingCol
  ? "COALESCE(`{$purchaseRemainingCol}`, 0)"
  : "GREATEST(($purchaseTotalExpr) - ($purchasePaidExpr), 0)";
$purchaseInvoiceNoExpr = $purchaseInvoiceNoCol ? "`{$purchaseInvoiceNoCol}`" : "CONCAT('#', `id`)";
$purchaseInvoiceDateExpr = $purchaseDateCol ? "DATE(`{$purchaseDateCol}`)" : "NULL";

$paymentsCols = $getColumns($pdo, 'payments');
$paymentAmountCol = $pickColumn($paymentsCols, ['amount']);
$paymentDirectionCol = $pickColumn($paymentsCols, ['direction']);
$paymentDateCol = $pickColumn($paymentsCols, ['payment_date', 'created_at']);
$paymentMethodCol = $pickColumn($paymentsCols, ['method']);
$paymentRefTableCol = $pickColumn($paymentsCols, ['ref_table']);
$paymentRefIdCol = $pickColumn($paymentsCols, ['ref_id']);
$paymentPartyIdCol = $pickColumn($paymentsCols, ['party_id']);

$paymentAmountExpr = $paymentAmountCol ? "COALESCE(p.`{$paymentAmountCol}`, 0)" : "0";
$paymentDirectionExpr = $paymentDirectionCol ? "p.`{$paymentDirectionCol}`" : "NULL";
$paymentDateExpr = $paymentDateCol ? "DATE(p.`{$paymentDateCol}`)" : "NULL";
$paymentMethodExpr = $paymentMethodCol ? "p.`{$paymentMethodCol}`" : "NULL";
$paymentRefTableExpr = $paymentRefTableCol ? "p.`{$paymentRefTableCol}`" : "NULL";
$paymentRefIdExpr = $paymentRefIdCol ? "p.`{$paymentRefIdCol}`" : "NULL";
$paymentPartyIdExpr = $paymentPartyIdCol ? "p.`{$paymentPartyIdCol}`" : "NULL";

$openReceivables = $scalar(
  $pdo,
  "SELECT COALESCE(SUM($salesRemainingExpr), 0) FROM sales_invoices WHERE ($salesRemainingExpr) > 0"
);
$openPayables = $scalar(
  $pdo,
  "SELECT COALESCE(SUM($purchaseRemainingExpr), 0) FROM purchase_invoices WHERE ($purchaseRemainingExpr) > 0"
);

$todaySales = 0.0;
if ($salesDateCol) {
  $todaySales = $scalar(
    $pdo,
    "SELECT COALESCE(SUM($salesTotalExpr), 0) FROM sales_invoices WHERE DATE(`{$salesDateCol}`) = ?",
    [$today]
  );
}

$todayPurchases = 0.0;
if ($purchaseDateCol) {
  $todayPurchases = $scalar(
    $pdo,
    "SELECT COALESCE(SUM($purchaseTotalExpr), 0) FROM purchase_invoices WHERE DATE(`{$purchaseDateCol}`) = ?",
    [$today]
  );
}

$todayCollections = 0.0;
$todayDisbursements = 0.0;
if ($paymentDateCol && $paymentDirectionCol && $paymentAmountCol) {
  $todayCollections = $scalar(
    $pdo,
    "SELECT COALESCE(SUM(`{$paymentAmountCol}`), 0) FROM payments WHERE `{$paymentDirectionCol}`='in' AND DATE(`{$paymentDateCol}`) = ?",
    [$today]
  );
  $todayDisbursements = $scalar(
    $pdo,
    "SELECT COALESCE(SUM(`{$paymentAmountCol}`), 0) FROM payments WHERE `{$paymentDirectionCol}`='out' AND DATE(`{$paymentDateCol}`) = ?",
    [$today]
  );
}

$overdueReceivables = ['overdue_count' => 0, 'overdue_amount' => 0];
if ($salesDueCol) {
  $overdueReceivables = $fetchRow(
    $pdo,
    "
      SELECT
        COUNT(*) AS overdue_count,
        COALESCE(SUM($salesRemainingExpr), 0) AS overdue_amount
      FROM sales_invoices
      WHERE ($salesRemainingExpr) > 0
        AND `{$salesDueCol}` IS NOT NULL
        AND DATE(`{$salesDueCol}`) < ?
    ",
    [$today],
    $overdueReceivables
  );
}

$overduePayables = ['overdue_count' => 0, 'overdue_amount' => 0];
if ($purchaseDueCol) {
  $overduePayables = $fetchRow(
    $pdo,
    "
      SELECT
        COUNT(*) AS overdue_count,
        COALESCE(SUM($purchaseRemainingExpr), 0) AS overdue_amount
      FROM purchase_invoices
      WHERE ($purchaseRemainingExpr) > 0
        AND `{$purchaseDueCol}` IS NOT NULL
        AND DATE(`{$purchaseDueCol}`) < ?
    ",
    [$today],
    $overduePayables
  );
}

$recentSales = $fetchAll(
  $pdo,
  "
    SELECT
      si.id,
      $salesInvoiceNoExpr AS invoice_no,
      $salesInvoiceDateExpr AS invoice_date,
      $salesTotalExpr AS net_total,
      $salesRemainingExpr AS remaining_amount,
      c.name AS customer_name
    FROM sales_invoices si
    JOIN parties c ON c.id = si.customer_id
    ORDER BY si.id DESC
    LIMIT 8
  "
);

$recentPurchases = $fetchAll(
  $pdo,
  "
    SELECT
      pi.id,
      $purchaseInvoiceNoExpr AS invoice_no,
      $purchaseInvoiceDateExpr AS invoice_date,
      $purchaseTotalExpr AS net_total,
      $purchaseRemainingExpr AS remaining_amount,
      s.name AS supplier_name
    FROM purchase_invoices pi
    JOIN parties s ON s.id = pi.supplier_id
    ORDER BY pi.id DESC
    LIMIT 8
  "
);

$recentPayments = $fetchAll(
  $pdo,
  "
    SELECT
      p.id,
      $paymentDirectionExpr AS direction,
      $paymentDateExpr AS payment_date,
      $paymentAmountExpr AS amount,
      $paymentMethodExpr AS method,
      $paymentRefTableExpr AS ref_table,
      $paymentRefIdExpr AS ref_id,
      prt.name AS party_name
    FROM payments p
    LEFT JOIN parties prt ON prt.id = $paymentPartyIdExpr
    ORDER BY p.id DESC
    LIMIT 10
  "
);

$stockCols = $getColumns($pdo, 'stock');
$stockQtyCol = $pickColumn($stockCols, ['qty_on_hand']);
$stockProductIdCol = $pickColumn($stockCols, ['product_id']);

$lowStockCount = 0;
$lowStockItems = [];
if ($stockQtyCol && $stockProductIdCol) {
  $lowStockCount = $count(
    $pdo,
    "
      SELECT COUNT(*)
      FROM stock s
      JOIN products p ON p.id = s.`{$stockProductIdCol}`
      WHERE p.is_active = 1 AND s.`{$stockQtyCol}` <= ?
    ",
    [$lowStockThreshold]
  );

  $lowStockItems = $fetchAll(
    $pdo,
    "
      SELECT
        p.id, p.name, p.sku, s.`{$stockQtyCol}` AS qty_on_hand
      FROM stock s
      JOIN products p ON p.id = s.`{$stockProductIdCol}`
      WHERE p.is_active = 1 AND s.`{$stockQtyCol}` <= ?
      ORDER BY s.`{$stockQtyCol}` ASC, p.name ASC
      LIMIT 10
    ",
    [$lowStockThreshold]
  );
};

$customersCount = $count(
  $pdo,
  "SELECT COUNT(*) FROM parties WHERE type='customer' AND is_active=1"
);
$suppliersCount = $count(
  $pdo,
  "SELECT COUNT(*) FROM parties WHERE type='supplier' AND is_active=1"
);
$productsCount = $count(
  $pdo,
  "SELECT COUNT(*) FROM products WHERE is_active=1"
);

$title = "لوحة التحكم";
$subtitle = "ملخص سريع وواضح لحالة النظام";

require __DIR__ . '/../app/views/partials/header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">عدد العملاء</div>
    <div class="text-3xl font-extrabold mt-2"><?= (int)$customersCount ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">عدد الموردين</div>
    <div class="text-3xl font-extrabold mt-2"><?= (int)$suppliersCount ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">عدد المنتجات</div>
    <div class="text-3xl font-extrabold mt-2"><?= (int)$productsCount ?></div>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">إجمالي مستحقات العملاء</div>
    <div class="text-2xl font-extrabold mt-2"><?= money($openReceivables) ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">إجمالي مستحقات الموردين</div>
    <div class="text-2xl font-extrabold mt-2"><?= money($openPayables) ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">مخزون منخفض (<?= (int)$lowStockThreshold ?> أو أقل)</div>
    <div class="text-3xl font-extrabold mt-2"><?= (int)$lowStockCount ?></div>
  </div>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mt-4">
  <div class="font-extrabold text-lg mb-3">حركة اليوم (<?= h($today) ?>)</div>
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
    <div class="rounded-xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">المبيعات اليوم</div>
      <div class="text-xl font-extrabold mt-1"><?= money($todaySales) ?></div>
    </div>
    <div class="rounded-xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">المشتريات اليوم</div>
      <div class="text-xl font-extrabold mt-1"><?= money($todayPurchases) ?></div>
    </div>
    <div class="rounded-xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">التحصيلات اليوم</div>
      <div class="text-xl font-extrabold mt-1 text-emerald-700"><?= money($todayCollections) ?></div>
    </div>
    <div class="rounded-xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">المدفوعات اليوم</div>
      <div class="text-xl font-extrabold mt-1 text-amber-700"><?= money($todayDisbursements) ?></div>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">فواتير عملاء متأخرة</div>
    <div class="mt-2 flex items-baseline gap-2">
      <span class="text-2xl font-extrabold"><?= (int)$overdueReceivables['overdue_count'] ?></span>
      <span class="text-sm text-slate-500">فاتورة</span>
    </div>
    <div class="text-sm text-rose-700 font-semibold mt-1"><?= money((float)$overdueReceivables['overdue_amount']) ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">فواتير موردين متأخرة</div>
    <div class="mt-2 flex items-baseline gap-2">
      <span class="text-2xl font-extrabold"><?= (int)$overduePayables['overdue_count'] ?></span>
      <span class="text-sm text-slate-500">فاتورة</span>
    </div>
    <div class="text-sm text-rose-700 font-semibold mt-1"><?= money((float)$overduePayables['overdue_amount']) ?></div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="font-extrabold text-lg mb-3">اختصارات سريعة</div>
    <div class="grid grid-cols-1 gap-2">
      <a href="<?= $base ?>/customers.php" class="rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50 font-semibold">العملاء</a>
      <a href="<?= $base ?>/suppliers.php" class="rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50 font-semibold">الموردين</a>
      <a href="<?= $base ?>/products.php" class="rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50 font-semibold">المنتجات</a>
      <?php if ($canManageTransactions): ?>
        <a href="<?= $base ?>/sale_create.php" class="rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50 font-semibold">فاتورة بيع جديدة</a>
        <a href="<?= $base ?>/purchase_create.php" class="rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50 font-semibold">فاتورة شراء جديدة</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 lg:col-span-2">
    <div class="flex items-center justify-between mb-3">
      <div class="font-extrabold text-lg">آخر التحصيلات والمدفوعات</div>
      <span class="text-xs text-slate-500">آخر 6 حركات</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-right text-slate-500 border-b">
            <th class="py-2 px-2">التاريخ</th>
            <th class="py-2 px-2">الطرف</th>
            <th class="py-2 px-2">النوع</th>
            <th class="py-2 px-2">المبلغ</th>
            <th class="py-2 px-2">الطريقة</th>
          </tr>
        </thead>
        <tbody>
          <?php $paymentsShort = array_slice($recentPayments, 0, 6); ?>
          <?php if (empty($paymentsShort)): ?>
            <tr><td colspan="5" class="py-6 text-center text-slate-500">لا توجد حركات دفع بعد.</td></tr>
          <?php endif; ?>
          <?php foreach ($paymentsShort as $payment): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2"><?= h($payment['payment_date']) ?></td>
              <td class="py-2 px-2 font-semibold"><?= h($payment['party_name'] ?? '-') ?></td>
              <td class="py-2 px-2">
                <?php if (($payment['direction'] ?? '') === 'in'): ?>
                  <span class="inline-flex items-center rounded-xl bg-emerald-50 text-emerald-700 px-2 py-1 text-xs font-semibold">تحصيل</span>
                <?php else: ?>
                  <span class="inline-flex items-center rounded-xl bg-amber-50 text-amber-700 px-2 py-1 text-xs font-semibold">سداد</span>
                <?php endif; ?>
              </td>
              <td class="py-2 px-2 font-extrabold"><?= money((float)$payment['amount']) ?></td>
              <td class="py-2 px-2"><?= h($payment['method'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
      <div class="font-extrabold text-lg">آخر فواتير البيع</div>
      <span class="text-xs text-slate-500">آخر 5</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-right text-slate-500 border-b">
            <th class="py-2 px-2">الفاتورة</th>
            <th class="py-2 px-2">العميل</th>
            <th class="py-2 px-2">الإجمالي</th>
            <th class="py-2 px-2">المتبقي</th>
          </tr>
        </thead>
        <tbody>
          <?php $salesShort = array_slice($recentSales, 0, 5); ?>
          <?php if (empty($salesShort)): ?>
            <tr><td colspan="4" class="py-6 text-center text-slate-500">لا توجد فواتير بيع بعد.</td></tr>
          <?php endif; ?>
          <?php foreach ($salesShort as $sale): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2">
                <a href="<?= $base ?>/sale_view.php?id=<?= (int)$sale['id'] ?>" class="text-blue-700 font-semibold hover:underline">
                  <?= h($sale['invoice_no'] ?? ('#' . (int)$sale['id'])) ?>
                </a>
              </td>
              <td class="py-2 px-2"><?= h($sale['customer_name']) ?></td>
              <td class="py-2 px-2"><?= money((float)$sale['net_total']) ?></td>
              <td class="py-2 px-2 font-semibold"><?= money((float)$sale['remaining_amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
      <div class="font-extrabold text-lg">آخر فواتير الشراء</div>
      <span class="text-xs text-slate-500">آخر 5</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-right text-slate-500 border-b">
            <th class="py-2 px-2">الفاتورة</th>
            <th class="py-2 px-2">المورد</th>
            <th class="py-2 px-2">الإجمالي</th>
            <th class="py-2 px-2">المتبقي</th>
          </tr>
        </thead>
        <tbody>
          <?php $purchaseShort = array_slice($recentPurchases, 0, 5); ?>
          <?php if (empty($purchaseShort)): ?>
            <tr><td colspan="4" class="py-6 text-center text-slate-500">لا توجد فواتير شراء بعد.</td></tr>
          <?php endif; ?>
          <?php foreach ($purchaseShort as $purchase): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2">
                <a href="<?= $base ?>/purchase_view.php?id=<?= (int)$purchase['id'] ?>" class="text-blue-700 font-semibold hover:underline">
                  <?= h($purchase['invoice_no'] ?? ('#' . (int)$purchase['id'])) ?>
                </a>
              </td>
              <td class="py-2 px-2"><?= h($purchase['supplier_name']) ?></td>
              <td class="py-2 px-2"><?= money((float)$purchase['net_total']) ?></td>
              <td class="py-2 px-2 font-semibold"><?= money((float)$purchase['remaining_amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mt-4">
  <div class="flex items-center justify-between mb-3">
    <div class="font-extrabold text-lg">منتجات منخفضة المخزون</div>
    <span class="text-xs text-slate-500">الحد: <?= (int)$lowStockThreshold ?> أو أقل</span>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-right text-slate-500 border-b">
          <th class="py-2 px-2">المنتج</th>
          <th class="py-2 px-2">SKU</th>
          <th class="py-2 px-2">المتاح</th>
        </tr>
      </thead>
      <tbody>
        <?php $lowStockShort = array_slice($lowStockItems, 0, 6); ?>
        <?php if (empty($lowStockShort)): ?>
          <tr><td colspan="3" class="py-6 text-center text-slate-500">لا يوجد منتجات منخفضة حالياً.</td></tr>
        <?php endif; ?>
        <?php foreach ($lowStockShort as $item): ?>
          <tr class="border-b hover:bg-slate-50">
            <td class="py-2 px-2 font-semibold"><?= h($item['name']) ?></td>
            <td class="py-2 px-2"><?= h($item['sku'] ?: '-') ?></td>
            <td class="py-2 px-2">
              <?php if ((float)$item['qty_on_hand'] <= 0): ?>
                <span class="inline-flex items-center rounded-xl bg-rose-50 text-rose-700 px-2 py-1 text-xs font-semibold"><?= h($item['qty_on_hand']) ?></span>
              <?php else: ?>
                <span class="inline-flex items-center rounded-xl bg-amber-50 text-amber-700 px-2 py-1 text-xs font-semibold"><?= h($item['qty_on_hand']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
require __DIR__ . '/../app/views/partials/footer.php';
