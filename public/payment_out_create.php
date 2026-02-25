<?php
require __DIR__ . '/bootstrap.php';
if (empty($user['id'])) {
  render_error('Authentication required.', 401);
}
authorize($config['authz']['payment_out_create'] ?? [ROLE_ADMIN, ROLE_MANAGER]);

$supplier_id = (int)($_GET['supplier_id'] ?? $_POST['supplier_id'] ?? 0);
$invoice_id_q = (int)($_GET['invoice_id'] ?? 0);

if ($supplier_id <= 0) {
  render_error('طلب غير صالح (supplier).', 400);
}

// Load supplier
$stmt = $pdo->prepare("
  SELECT id, name
  FROM parties
  WHERE id=? AND type='supplier' AND is_active=1
  LIMIT 1
");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
  render_error('المورد غير موجود.', 404);
}

// Load unpaid invoices (remaining_amount > 0)
$stmt = $pdo->prepare("
  SELECT id, invoice_no, invoice_date, net_total, paid_amount, remaining_amount, due_date
  FROM purchase_invoices
  WHERE supplier_id = ? AND remaining_amount > 0
  ORDER BY invoice_date DESC, id DESC
");
$stmt->execute([$supplier_id]);
$openInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrf = CSRF::token();
$error = null;
$success = null;
$usePaymentService = (bool)($config['features']['use_payment_service'] ?? false);

$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save') && $usePaymentService) {
  try {
    CSRF::verify($_POST['csrf_token'] ?? null);

    $validator = new \App\Domain\Payments\Validators\PaySupplierValidator();
    $dto = $validator->validate($_POST, $supplier_id);
    $service = new \App\Domain\Payments\PaymentService($pdo);
    $service->paySupplier($dto, (int)$user['id']);

    if ($dto->invoiceId > 0) {
      header("Location: {$base}/purchase_view.php?id={$dto->invoiceId}");
      exit;
    }
    header("Location: {$base}/supplier.php?id={$supplier_id}");
    exit;
  } catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
  } catch (Throwable $e) {
    log_message('error', 'Failed to record supplier payment via service', [
      'exception' => $e->getMessage(),
      'trace' => $e->getTraceAsString(),
    ]);
    $error = "Unexpected error while saving payment. Please try again.";
  }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save') && !$usePaymentService) {
  try {
    CSRF::verify($_POST['csrf_token'] ?? null);

    $payment_date = trim($_POST['payment_date'] ?? $today);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? 'cash'); // cash/bank/cheque
    $cheque_no = trim($_POST['cheque_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $invoice_id = (int)($_POST['invoice_id'] ?? 0); // optional (0 = on account)

    if ($amount <= 0) {
      $error = "ادخل مبلغ صحيح أكبر من 0.";
    } elseif ($payment_date === '') {
      $error = "حدد تاريخ السداد.";
    } else {

      // If invoice selected: validate it belongs to same supplier and get remaining
      $invoice = null;
      if ($invoice_id > 0) {
        $stmt = $pdo->prepare("
          SELECT id, supplier_id, net_total, paid_amount, remaining_amount, invoice_no
          FROM purchase_invoices
          WHERE id = ? AND supplier_id = ?
          LIMIT 1
        ");
        $stmt->execute([$invoice_id, $supplier_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
          $error = "الفاتورة المختارة غير صحيحة.";
        } else {
          $remaining = (float)$invoice['remaining_amount'];
          if ($remaining <= 0) {
            $error = "الفاتورة دي متسددة بالفعل.";
          } elseif ($amount > $remaining) {
            // خليه يسدد أقصى حاجة المتبقي
            $amount = $remaining;
          }
        }
      }

      if (!$error) {
        try {
          $pdo->beginTransaction();

          // 1) Insert payments
          // payments table: id, party_id, direction, payment_date, amount, method, cheque_id,
          //                ref_table, ref_id, notes, created_by, created_at
          $stmt = $pdo->prepare("
            INSERT INTO payments
              (party_id, direction, payment_date, amount, method, cheque_id,
               ref_table, ref_id, notes, created_by)
            VALUES
              (?, 'out', ?, ?, ?, ?,
               ?, ?, ?, ?)
          ");

          $ref_table = ($invoice_id > 0) ? 'purchase_invoices' : null;
          $ref_id    = ($invoice_id > 0) ? $invoice_id : null;

          $stmt->execute([
            $supplier_id,
            $payment_date,
            $amount,
            $method,
            ($method === 'cheque' && $cheque_no !== '' ? $cheque_no : null), // stored in cheque_id
            $ref_table,
            $ref_id,
            ($notes !== '' ? $notes : null),
            (int)$user['id'],
          ]);

          $payment_id = (int)$pdo->lastInsertId();

          // 2) Insert ledger entry (payment_out => DEBIT)
          $stmt = $pdo->prepare("
            INSERT INTO ledger_entries
              (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
            VALUES
              (?, ?, 'payment_out', 'payments', ?, ?, 0, NULL, ?, ?)
          ");
          $stmt->execute([
            $supplier_id,
            $payment_date,
            $payment_id,
            $amount,
            ($notes !== '' ? $notes : null),
            (int)$user['id'],
          ]);

          // 3) If linked to invoice: update paid/remaining
// 3) If linked to invoice: update paid/remaining (SAFE)
if ($invoice_id > 0) {
  $stmt = $pdo->prepare("
    UPDATE purchase_invoices
    SET
      paid_amount = paid_amount + ?,
      remaining_amount = GREATEST(remaining_amount - ?, 0),
      due_date = CASE
        WHEN GREATEST(remaining_amount - ?, 0) = 0 THEN NULL
        ELSE due_date
      END
    WHERE id = ? AND supplier_id = ?
  ");
  $stmt->execute([$amount, $amount, $amount, $invoice_id, $supplier_id]);
}


          $pdo->commit();

          // Redirect
          if ($invoice_id > 0) {
            header("Location: {$base}/purchase_view.php?id={$invoice_id}");
            exit;
          } else {
            header("Location: {$base}/supplier.php?id={$supplier_id}");
            exit;
          }

        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          log_message('error', 'Failed to record supplier payment', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
          ]);
          $error = "حدث خطأ في قاعدة البيانات. حاول مرة أخرى.";
        }
      }
    }

  } catch (Throwable $e) {
    log_message('error', 'Unhandled error in payment_out_create', [
      'exception' => $e->getMessage(),
      'trace' => $e->getTraceAsString(),
    ]);
    $error = "حدث خطأ غير متوقع. حاول مرة أخرى.";
  }
}

$title = "Supplier Payment";
$subtitle = "سداد للمورد";
require __DIR__ . '/../app/views/partials/header.php';
?>

<?php if ($error): ?>
  <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
    <?= h($error) ?>
  </div>
<?php endif; ?>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="font-extrabold text-lg">+ سداد للمورد</div>
      <div class="text-sm text-slate-500 mt-1">
        المورد: <span class="font-semibold"><?= h($supplier['name']) ?></span>
      </div>
    </div>
    <a href="<?= $base ?>/supplier.php?id=<?= (int)$supplier_id ?>"
       class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
      ← رجوع للمورد
    </a>
  </div>

  <hr class="my-4">

  <form method="post" action="<?= $base ?>/payment_out_create.php?supplier_id=<?= (int)$supplier_id ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="supplier_id" value="<?= (int)$supplier_id ?>">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="block text-sm text-slate-600 mb-1">تاريخ السداد</label>
        <input type="date" name="payment_date" value="<?= h($_POST['payment_date'] ?? $today) ?>"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>

      <div>
        <label class="block text-sm text-slate-600 mb-1">المبلغ</label>
        <input type="number" step="0.01" name="amount" value="<?= h($_POST['amount'] ?? '') ?>"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200"
               placeholder="0.00">
      </div>

      <div>
        <label class="block text-sm text-slate-600 mb-1">طريقة الدفع</label>
        <select name="method"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
          <?php $m = $_POST['method'] ?? 'cash'; ?>
          <option value="cash"   <?= $m==='cash'?'selected':'' ?>>Cash</option>
          <option value="bank"   <?= $m==='bank'?'selected':'' ?>>Bank</option>
          <option value="cheque" <?= $m==='cheque'?'selected':'' ?>>Cheque</option>
        </select>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
      <div>
        <label class="block text-sm text-slate-600 mb-1">رقم/معرّف الشيك (اختياري)</label>
        <input name="cheque_no" value="<?= h($_POST['cheque_no'] ?? '') ?>"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200"
               placeholder="لو Cheque فقط">
        <div class="text-xs text-slate-500 mt-1">هيتخزن في payments.cheque_id</div>
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm text-slate-600 mb-1">ربط السداد بفاتورة (اختياري)</label>
        <?php
          $selectedInvoice = (int)($_POST['invoice_id'] ?? $invoice_id_q ?? 0);
        ?>
        <select name="invoice_id"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
          <option value="0">— سداد على الحساب (بدون فاتورة) —</option>
          <?php foreach ($openInvoices as $inv): ?>
            <option value="<?= (int)$inv['id'] ?>" <?= $selectedInvoice==(int)$inv['id']?'selected':'' ?>>
              <?= h($inv['invoice_no'] ?? ('#'.$inv['id'])) ?>
              • <?= h($inv['invoice_date']) ?>
              • المتبقي: <?= money($inv['remaining_amount']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="text-xs text-slate-500 mt-1">لو اخترت فاتورة: هيتم تحديث paid/remaining داخل الفاتورة.</div>
      </div>
    </div>

    <div class="mt-3">
      <label class="block text-sm text-slate-600 mb-1">ملاحظات (اختياري)</label>
      <input name="notes" value="<?= h($_POST['notes'] ?? '') ?>"
             class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
    </div>

    <div class="mt-4 flex items-center justify-end">
      <button type="submit"
              class="rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-semibold px-5 py-2.5">
        حفظ السداد
      </button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
