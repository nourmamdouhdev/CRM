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

// ---- helpers
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n) { return number_format((float)$n, 2); }
function typeLabel(string $type): string {
  return match ($type) {
    'sale_invoice' => 'فاتورة بيع',
    'purchase_invoice' => 'فاتورة شراء',
    'payment_in' => 'تحصيل',
    'payment_out' => 'سداد',
    'adjustment' => 'تسوية',
    default => $type,
  };
}

// ---- get id
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Invalid id');
}

// ---- get customer
$stmt = $pdo->prepare("
  SELECT id, name, phone, address, notes, opening_balance, opening_balance_type, is_active
  FROM parties
  WHERE id = ? AND type='customer' AND is_active=1
  LIMIT 1
");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
  http_response_code(404);
  exit('Customer not found');
}

// ---- ledger entries (statement) with smart reference display + link to invoice
$stmt = $pdo->prepare("
  SELECT
    le.id, le.entry_date, le.type, le.ref_table, le.ref_id,
    le.debit, le.credit, le.due_date, le.notes,

    -- payment -> invoice link (from payments.ref_table/ref_id)
    p.ref_table AS pay_ref_table,
    p.ref_id    AS pay_ref_id,

    -- invoice numbers (optional)
    si.invoice_no  AS invoice_no_direct,
    si2.invoice_no AS invoice_no_from_payment,

    -- for linking to sale_view
    si.id  AS invoice_id_direct,
    si2.id AS invoice_id_from_payment

  FROM ledger_entries le

  LEFT JOIN payments p
    ON le.ref_table='payments' AND p.id = le.ref_id

  LEFT JOIN sales_invoices si
    ON le.ref_table='sales_invoices' AND si.id = le.ref_id

  LEFT JOIN sales_invoices si2
    ON p.ref_table='sales_invoices' AND si2.id = p.ref_id

  WHERE le.party_id = ?
  ORDER BY le.entry_date ASC, le.id ASC
");
$stmt->execute([$id]);
$entries = $stmt->fetchAll();

// ---- opening balance
$opening = (float)($customer['opening_balance'] ?? 0);
$openingType = $customer['opening_balance_type'] ?? 'debit';

$openingDebit  = ($opening > 0 && $openingType === 'debit')  ? $opening : 0.0;
$openingCredit = ($opening > 0 && $openingType === 'credit') ? $opening : 0.0;

// ---- compute running balance (Debit - Credit)
$totalDebit = $openingDebit;
$totalCredit = $openingCredit;

$running = $openingDebit - $openingCredit;

$rows = [];
foreach ($entries as $e) {
  $debit  = (float)$e['debit'];
  $credit = (float)$e['credit'];

  $totalDebit  += $debit;
  $totalCredit += $credit;

  $running += ($debit - $credit);

  // Build smart reference display + link to invoice if possible
  $refDisplay = '—';
  $invoiceLinkId = null;

  if (!empty($e['ref_table']) && !empty($e['ref_id'])) {

    // Direct invoice row
    if ($e['ref_table'] === 'sales_invoices') {
      $invoiceLinkId = (int)($e['invoice_id_direct'] ?? 0);
      $invNo = $e['invoice_no_direct'] ?? null;
      $refDisplay = $invNo ? ("فاتورة بيع " . $invNo) : ("فاتورة بيع #".$e['ref_id']);
    }

    // Payment row -> may link to invoice through payments.ref_table/ref_id
    elseif ($e['ref_table'] === 'payments') {
      if (($e['pay_ref_table'] ?? '') === 'sales_invoices' && !empty($e['pay_ref_id'])) {
        $invoiceLinkId = (int)($e['invoice_id_from_payment'] ?? $e['pay_ref_id']);
        $invNo = $e['invoice_no_from_payment'] ?? null;

        $refDisplay = $invNo
          ? ("تحصيل → فاتورة بيع " . $invNo)
          : ("تحصيل → فاتورة بيع #".$e['pay_ref_id']);
      } else {
        $refDisplay = "تحصيل #".$e['ref_id'];
      }
    }

    // Anything else
    else {
      $refDisplay = $e['ref_table']." #".$e['ref_id'];
    }
  }

  $rows[] = [
    'entry_date' => $e['entry_date'],
    'type' => $e['type'],
    'ref_display' => $refDisplay,
    'invoice_link_id' => $invoiceLinkId,
    'debit' => $debit,
    'credit' => $credit,
    'due_date' => $e['due_date'],
    'notes' => $e['notes'],
    'balance' => $running,
  ];
}

$currentBalance = $running; // +ve => customer owes you

if ($currentBalance > 0) {
  $badgeClass = "bg-rose-50 text-rose-700 border-rose-200";
  $balanceText = "عليه";
  $balanceValue = $currentBalance;
} elseif ($currentBalance < 0) {
  $badgeClass = "bg-emerald-50 text-emerald-700 border-emerald-200";
  $balanceText = "له";
  $balanceValue = abs($currentBalance);
} else {
  $badgeClass = "bg-slate-50 text-slate-700 border-slate-200";
  $balanceText = "متزن";
  $balanceValue = 0;
}

$title = "Customer Profile";
$subtitle = "كشف حساب + رصيد العميل";
require __DIR__ . '/../app/views/partials/header.php';

$today = date('Y-m-d');
?>

<!-- Header Card -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-4">
  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div>
      <div class="text-xl font-extrabold"><?= h($customer['name']) ?></div>
      <div class="text-sm text-slate-500 mt-1">
        ID: <?= (int)$customer['id'] ?>
        <?php if (!empty($customer['phone'])): ?> • Phone: <?= h($customer['phone']) ?><?php endif; ?>
      </div>

      <?php if (!empty($customer['address'])): ?>
        <div class="text-sm text-slate-600 mt-2">📍 <?= h($customer['address']) ?></div>
      <?php endif; ?>

      <?php if (!empty($customer['notes'])): ?>
        <div class="text-sm text-slate-600 mt-2">📝 <?= h($customer['notes']) ?></div>
      <?php endif; ?>
    </div>

    <div class="w-full md:w-auto">
      <div class="inline-flex items-center gap-2 rounded-2xl border px-3 py-2 <?= $badgeClass ?>">
        <span class="text-sm font-semibold">الرصيد:</span>
        <span class="text-lg font-extrabold"><?= money($balanceValue) ?></span>
        <span class="text-sm font-semibold">(<?= h($balanceText) ?>)</span>
      </div>

      <div class="mt-3 flex gap-2">
        <a href="<?= $base ?>/customers.php"
           class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
          ← رجوع للعملاء
        </a>

        <a href="<?= $base ?>/sale_create.php?customer_id=<?= (int)$customer['id'] ?>"
           class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-4 py-2 hover:bg-slate-800 font-semibold">
          + فاتورة بيع
        </a>
        <a href="<?= $base ?>/payment_in_create.php?customer_id=<?= (int)$customer['id'] ?>"
           class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
          + تحصيل
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Summary -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">إجمالي Debit</div>
    <div class="text-2xl font-extrabold mt-2"><?= money($totalDebit) ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">إجمالي Credit</div>
    <div class="text-2xl font-extrabold mt-2"><?= money($totalCredit) ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="text-slate-500 text-sm">الرصيد (Debit - Credit)</div>
    <div class="text-2xl font-extrabold mt-2"><?= money($currentBalance) ?></div>
  </div>
</div>

<!-- Statement -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
  <div class="flex items-center justify-between mb-3">
    <div>
      <div class="font-extrabold text-lg">كشف الحساب</div>
      <div class="text-sm text-slate-500">مرتب حسب التاريخ (الأقدم → الأحدث) + Running Balance</div>
    </div>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-right text-slate-500 border-b">
          <th class="py-2 px-2 whitespace-nowrap">التاريخ</th>
          <th class="py-2 px-2 whitespace-nowrap">النوع</th>
          <th class="py-2 px-2 whitespace-nowrap">مرجع</th>
          <th class="py-2 px-2 whitespace-nowrap">Debit</th>
          <th class="py-2 px-2 whitespace-nowrap">Credit</th>
          <th class="py-2 px-2 whitespace-nowrap">Due Date</th>
          <th class="py-2 px-2 whitespace-nowrap">الرصيد</th>
          <th class="py-2 px-2">ملاحظات</th>
        </tr>
      </thead>

      <tbody>
        <?php if ($opening > 0): ?>
          <?php $openBal = $openingDebit - $openingCredit; ?>
          <tr class="border-b bg-slate-50">
            <td class="py-2 px-2">—</td>
            <td class="py-2 px-2 font-semibold">رصيد افتتاحي</td>
            <td class="py-2 px-2">—</td>
            <td class="py-2 px-2"><?= $openingDebit ? money($openingDebit) : '' ?></td>
            <td class="py-2 px-2"><?= $openingCredit ? money($openingCredit) : '' ?></td>
            <td class="py-2 px-2">—</td>
            <td class="py-2 px-2 font-extrabold"><?= money($openBal) ?></td>
            <td class="py-2 px-2 text-slate-500">—</td>
          </tr>
        <?php endif; ?>

        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="8" class="py-8 text-center text-slate-500">لا يوجد حركات حتى الآن.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
          <?php
            $due = $r['due_date'];
            $dueBadge = "bg-slate-50 text-slate-700 border-slate-200";
            if (!empty($due)) {
              if ($due < $today) $dueBadge = "bg-rose-50 text-rose-700 border-rose-200";
              else $dueBadge = "bg-amber-50 text-amber-700 border-amber-200";
            }
          ?>
          <tr class="border-b hover:bg-slate-50">
            <td class="py-2 px-2 whitespace-nowrap"><?= h($r['entry_date']) ?></td>
            <td class="py-2 px-2 whitespace-nowrap font-semibold"><?= h(typeLabel($r['type'])) ?></td>
            <td class="py-2 px-2 whitespace-nowrap text-slate-600">
              <?php if (!empty($r['invoice_link_id'])): ?>
                <a class="text-blue-700 font-semibold hover:underline"
                   href="<?= $base ?>/sale_view.php?id=<?= (int)$r['invoice_link_id'] ?>">
                  <?= h($r['ref_display']) ?>
                </a>
              <?php else: ?>
                <?= h($r['ref_display'] ?? '—') ?>
              <?php endif; ?>
            </td>
            <td class="py-2 px-2 whitespace-nowrap"><?= $r['debit'] ? money($r['debit']) : '' ?></td>
            <td class="py-2 px-2 whitespace-nowrap"><?= $r['credit'] ? money($r['credit']) : '' ?></td>
            <td class="py-2 px-2 whitespace-nowrap">
              <?php if (!empty($due)): ?>
                <span class="inline-flex items-center rounded-xl border px-2 py-1 text-xs font-semibold <?= $dueBadge ?>">
                  <?= h($due) ?>
                </span>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="py-2 px-2 whitespace-nowrap font-extrabold"><?= money($r['balance']) ?></td>
            <td class="py-2 px-2 text-slate-600"><?= h($r['notes'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
