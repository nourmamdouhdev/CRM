<?php
require __DIR__ . '/../app/core/DB.php';
require __DIR__ . '/../app/core/Auth.php';
require __DIR__ . '/../app/Middlewares/AuthMiddleware.php';

$config = require __DIR__ . '/../config/config.php';
session_name($config['app']['session_name']);
session_start();

AuthMiddleware::handle();
$user = Auth::user();
$pdo  = DB::pdo();

$base = $config['app']['base_url'] ?? '/tagom/public';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  die("Missing invoice id");
}

// 1) Load invoice + supplier
$stmt = $pdo->prepare("
  SELECT
    pi.*,
    p.name AS supplier_name
  FROM purchase_invoices pi
  JOIN parties p ON p.id = pi.supplier_id
  WHERE pi.id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
  http_response_code(404);
  die("Invoice not found");
}

// 2) Load items
$stmt = $pdo->prepare("
  SELECT
    pii.*,
    pr.name AS product_name,
    pr.sku  AS product_sku
  FROM purchase_invoice_items pii
  JOIN products pr ON pr.id = pii.product_id
  WHERE pii.purchase_invoice_id = ?
  ORDER BY pii.id ASC
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3) Load payments linked to this invoice (from payments table)
$stmt = $pdo->prepare("
  SELECT
    id, party_id, direction, payment_date, amount, method, cheque_id, ref_table, ref_id, notes, created_by, created_at
  FROM payments
  WHERE ref_table = 'purchase_invoices' AND ref_id = ?
  ORDER BY payment_date ASC, id ASC
");
$stmt->execute([$id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// totals
$items_total = 0.0;
foreach ($items as $it) $items_total += (float)$it['line_total'];

$title = "Purchase Invoice";
$subtitle = "عرض فاتورة شراء";
require __DIR__ . '/../app/views/partials/header.php';
?>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
    <div>
      <div class="font-extrabold text-lg">Purchase Invoice</div>
      <div class="text-sm text-slate-500 mt-1">
        فاتورة رقم: <span class="font-semibold"><?= h($invoice['invoice_no'] ?? ('#'.$invoice['id'])) ?></span>
      </div>
    </div>
    <div class="flex gap-2">
      <a href="<?= $base ?>/supplier.php?id=<?= (int)$invoice['supplier_id'] ?>"
         class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
        ← Supplier
      </a>
      <a href="<?= $base ?>/purchase_create.php?supplier_id=<?= (int)$invoice['supplier_id'] ?>"
         class="inline-flex items-center rounded-xl bg-slate-900 text-white px-4 py-2 hover:bg-slate-800 font-semibold">
        + New Purchase
      </a>
    </div>
  </div>

  <hr class="my-4">

  <!-- Header info -->
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <div class="rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Supplier</div>
      <div class="font-extrabold"><?= h($invoice['supplier_name'] ?? '') ?></div>
    </div>

    <div class="rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Invoice Date</div>
      <div class="font-extrabold"><?= h($invoice['invoice_date'] ?? '') ?></div>
    </div>

    <div class="rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Due Date</div>
      <div class="font-extrabold"><?= h($invoice['due_date'] ?? '—') ?></div>
    </div>

    <div class="rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Created At</div>
      <div class="font-extrabold"><?= h($invoice['created_at'] ?? '—') ?></div>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
    <div class="rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Items Total (SUM line_total)</div>
      <div class="font-extrabold"><?= money($items_total) ?></div>
    </div>

    <div class="rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Net Total</div>
      <div class="font-extrabold"><?= money($invoice['net_total'] ?? $invoice['total_amount'] ?? 0) ?></div>
    </div>

    <div class="rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Paid</div>
      <div class="font-extrabold"><?= money($invoice['paid_amount'] ?? 0) ?></div>
    </div>

    <div class="rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Remaining</div>
      <div class="font-extrabold"><?= money($invoice['remaining_amount'] ?? 0) ?></div>
    </div>
  </div>

  <?php if (!empty($invoice['notes'])): ?>
    <div class="mt-3 rounded-2xl border border-slate-200 p-3">
      <div class="text-xs text-slate-500">Notes</div>
      <div class="font-semibold"><?= h($invoice['notes']) ?></div>
    </div>
  <?php endif; ?>

  <hr class="my-4">

  <!-- Items -->
  <div class="font-extrabold mb-2">Items</div>
  <div class="overflow-x-auto rounded-2xl border border-slate-200">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-right text-slate-500 border-b bg-slate-50">
          <th class="py-2 px-2">#</th>
          <th class="py-2 px-2">Product</th>
          <th class="py-2 px-2">Qty</th>
          <th class="py-2 px-2">Unit Cost</th>
          <th class="py-2 px-2">Line Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="5" class="py-4 px-2 text-center text-slate-500">No items</td></tr>
        <?php else: ?>
          <?php foreach ($items as $idx => $it): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2"><?= (int)($idx+1) ?></td>
              <td class="py-2 px-2">
                <div class="font-semibold"><?= h($it['product_name'] ?? '') ?></div>
                <?php if (!empty($it['product_sku'])): ?>
                  <div class="text-xs text-slate-500"><?= h($it['product_sku']) ?></div>
                <?php endif; ?>
              </td>
              <td class="py-2 px-2 font-semibold"><?= h($it['qty']) ?></td>
              <td class="py-2 px-2"><?= money($it['unit_cost']) ?></td>
              <td class="py-2 px-2 font-extrabold"><?= money($it['line_total']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr class="bg-slate-50">
          <td colspan="4" class="py-2 px-2 text-right font-extrabold">Total</td>
          <td class="py-2 px-2 font-extrabold"><?= money($items_total) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <hr class="my-4">

  <!-- Payments -->
  <div class="flex items-center justify-between gap-2">
    <div class="font-extrabold">Payments linked to this invoice</div>

    <!-- هنخليها Placeholder دلوقتي لحد ما نعمل صفحة السداد -->
    <a href="<?= $base ?>/payment_out_create.php?supplier_id=<?= (int)$invoice['supplier_id'] ?>&invoice_id=<?= (int)$invoice['id'] ?>"
       class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
      + Add Payment
    </a>
  </div>

  <div class="mt-2 overflow-x-auto rounded-2xl border border-slate-200">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-right text-slate-500 border-b bg-slate-50">
          <th class="py-2 px-2">Date</th>
          <th class="py-2 px-2">Amount</th>
          <th class="py-2 px-2">Method</th>
          <th class="py-2 px-2">Cheque</th>
          <th class="py-2 px-2">Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($payments)): ?>
          <tr><td colspan="5" class="py-4 px-2 text-center text-slate-500">No payments for this invoice</td></tr>
        <?php else: ?>
          <?php foreach ($payments as $p): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-2 px-2 font-semibold"><?= h($p['payment_date'] ?? '') ?></td>
              <td class="py-2 px-2 font-extrabold"><?= money($p['amount'] ?? 0) ?></td>
              <td class="py-2 px-2"><?= h($p['method'] ?? '') ?></td>
              <td class="py-2 px-2"><?= h($p['cheque_id'] ?? '—') ?></td>
              <td class="py-2 px-2"><?= h($p['notes'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-3 text-xs text-slate-500">
    ملاحظة: الدفعات هنا بتظهر لو كانت مسجلة في payments مع (ref_table='purchase_invoices' و ref_id=invoice_id).
  </div>
</div>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
