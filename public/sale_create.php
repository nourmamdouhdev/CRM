<?php
require __DIR__ . '/bootstrap.php';
if (empty($user['id'])) {
  render_error('Authentication required.', 401);
}
authorize($config['authz']['sales_create'] ?? [ROLE_ADMIN, ROLE_MANAGER]);

// ---------- Load customers
$customers = $pdo->query("
  SELECT id, name
  FROM parties
  WHERE type='customer' AND is_active=1
  ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ---------- Load suppliers
$suppliers = $pdo->query("
  SELECT id, name
  FROM parties
  WHERE type='supplier' AND is_active=1
  ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$error = null;

// ---------- Selected filters (customer + supplier)
$selectedCustomerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$selectedSupplierId = (int)($_GET['supplier_id'] ?? $_POST['supplier_id'] ?? 0);

// ---------- Load products (filtered by supplier)
$products = [];
if ($selectedSupplierId > 0) {
  $stmt = $pdo->prepare("
    SELECT id, name, sku, sale_price_default
    FROM products
    WHERE is_active=1 AND supplier_id = ?
    ORDER BY name ASC
  ");
  $stmt->execute([$selectedSupplierId]);
  $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrf = CSRF::token();
$useSalesService = (bool)($config['features']['use_sales_service'] ?? false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'confirm') && $useSalesService) {
  try {
    CSRF::verify($_POST['csrf_token'] ?? null);

    $validator = new \App\Domain\Sales\Validators\CreateSalesInvoiceValidator();
    $dto = $validator->validate($_POST);
    $service = new \App\Domain\Sales\SalesService($pdo);

    $invoiceId = $service->createInvoice($dto, (int)$user['id']);
    header("Location: {$base}/sale_view.php?id={$invoiceId}");
    exit;
  } catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
  } catch (Throwable $e) {
    log_message('error', 'Failed to create sales invoice via service', [
      'exception' => $e->getMessage(),
      'trace' => $e->getTraceAsString(),
    ]);
    $error = "Unexpected error while creating invoice. Please try again.";
  }

  $selectedCustomerId = (int)($_POST['customer_id'] ?? $selectedCustomerId);
  $selectedSupplierId = (int)($_POST['supplier_id'] ?? $selectedSupplierId);
}

// ---------- Handle POST (Confirm invoice)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'confirm') && !$useSalesService) {
  try {
    CSRF::verify($_POST['csrf_token'] ?? null);

    $customer_id  = (int)($_POST['customer_id'] ?? 0);
    $supplier_id  = (int)($_POST['supplier_id'] ?? 0);
    $invoice_date = trim($_POST['invoice_date'] ?? date('Y-m-d'));
    $due_date     = trim($_POST['due_date'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    $paid_now = (float)($_POST['paid_now'] ?? 0);
    if ($paid_now < 0) $paid_now = 0;

    $payment_method = trim($_POST['payment_method'] ?? 'cash'); // cash/bank/cheque
    $cheque_no      = trim($_POST['cheque_no'] ?? '');          // UI field, we store it into payments.cheque_id

    $product_ids = $_POST['product_id'] ?? [];
    $qtys        = $_POST['qty'] ?? [];
    $prices      = $_POST['price'] ?? [];

    if ($customer_id <= 0) {
      $error = "اختار العميل.";
    } elseif ($supplier_id <= 0) {
      $error = "اختار المورد.";
    } elseif (empty($product_ids)) {
      $error = "أضف على الأقل منتج واحد.";
    } else {

      // build items
      $items = [];
      $total = 0.0;

      for ($i=0; $i<count($product_ids); $i++) {
        $pid   = (int)$product_ids[$i];
        $qty   = (float)($qtys[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);

        if ($pid <= 0) continue;
        if ($qty <= 0) continue;
        if ($price < 0) $price = 0;

        $line = $qty * $price;
        $total += $line;

        $items[] = [
          'product_id' => $pid,
          'qty' => $qty,
          'price' => $price,
          'line_total' => $line,
        ];
      }

      if (count($items) === 0) {
        $error = "السطور غير صحيحة (تأكد من الكمية والسعر).";
      } else {
        if ($paid_now > $total) $paid_now = $total;
        $remaining = $total - $paid_now;

        if ($remaining > 0 && $due_date === '') {
          $error = "لازم تحدد تاريخ الاستحقاق (Due Date) لأن فيه مبلغ آجل.";
        } else {

          try {
            $pdo->beginTransaction();

            // 1) Insert sales_invoices
            $invoice_no = 'SI-' . date('Ymd') . '-' . random_int(1000, 9999);

            $stmt = $pdo->prepare("
              INSERT INTO sales_invoices
                (invoice_no, customer_id, invoice_date,
                 total_amount, total, net_total,
                 paid_amount, remaining_amount, due_date, notes, created_by)
              VALUES
                (?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
              $invoice_no,
              $customer_id,
              $invoice_date,
              $total,  // total_amount
              $total,  // total
              $total,  // net_total
              $paid_now,
              $remaining,
              ($remaining > 0 ? $due_date : null),
              ($notes !== '' ? $notes : null),
              (int)$user['id'],
            ]);
            $invoice_id = (int)$pdo->lastInsertId();

            // 2) Insert sales_invoice_items + update stock
            $insItem = $pdo->prepare("
              INSERT INTO sales_invoice_items
                (sales_invoice_id, product_id, qty, unit_price, line_total)
              VALUES
                (?, ?, ?, ?, ?)
            ");

            // stock upsert
            $stockSel = $pdo->prepare("SELECT product_id FROM stock WHERE product_id = ? LIMIT 1");
            $stockIns = $pdo->prepare("INSERT INTO stock (product_id, qty_on_hand) VALUES (?, ?)");
            $stockUpd = $pdo->prepare("UPDATE stock SET qty_on_hand = qty_on_hand - ? WHERE product_id = ?");

            foreach ($items as $it) {
              $insItem->execute([$invoice_id, $it['product_id'], $it['qty'], $it['price'], $it['line_total']]);

              $stockSel->execute([$it['product_id']]);
              $exists = $stockSel->fetch(PDO::FETCH_ASSOC);
              if (!$exists) {
                $stockIns->execute([$it['product_id'], 0]);
              }
              $stockUpd->execute([$it['qty'], $it['product_id']]);
            }

            // 3) Ledger entry for customer (Sales invoice) => DEBIT
            $stmt = $pdo->prepare("
              INSERT INTO ledger_entries
                (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
              VALUES
                (?, ?, 'sale_invoice', 'sales_invoices', ?, ?, 0, ?, ?, ?)
            ");
            $stmt->execute([
              $customer_id,
              $invoice_date,
              $invoice_id,
              $total,
              ($remaining > 0 ? $due_date : null),
              ($notes !== '' ? $notes : null),
              (int)$user['id'],
            ]);

            // 4) If paid now: record payment + ledger (CREDIT)
            if ($paid_now > 0) {
              // payments table (your real columns)
              $stmt = $pdo->prepare("
                INSERT INTO payments
                  (party_id, direction, payment_date, amount, method, cheque_id,
                   ref_table, ref_id, notes, created_by)
                VALUES
                  (?, 'in', ?, ?, ?, ?,
                   'sales_invoices', ?, ?, ?)
              ");
              $stmt->execute([
                $customer_id,
                $invoice_date, // payment_date
                $paid_now,
                $payment_method,
                ($payment_method === 'cheque' && $cheque_no !== '' ? $cheque_no : null),
                $invoice_id,
                ($notes !== '' ? $notes : null),
                (int)$user['id'],
              ]);
              $payment_id = (int)$pdo->lastInsertId();

              // ledger: payment_in => CREDIT
              $stmt = $pdo->prepare("
                INSERT INTO ledger_entries
                  (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
                VALUES
                  (?, ?, 'payment_in', 'payments', ?, 0, ?, NULL, ?, ?)
              ");
              $stmt->execute([
                $customer_id,
                $invoice_date,
                $payment_id,
                $paid_now,
                ($notes !== '' ? $notes : null),
                (int)$user['id'],
              ]);
            }

            $pdo->commit();

            header("Location: {$base}/sale_view.php?id={$invoice_id}");
            exit;

          } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_message('error', 'Failed to create sales invoice', [
              'exception' => $e->getMessage(),
              'trace' => $e->getTraceAsString(),
            ]);
            $error = "حدث خطأ في قاعدة البيانات. حاول مرة أخرى.";
          }
        }
      }
    }

    // keep selection if error
    $selectedCustomerId = $customer_id;
    $selectedSupplierId = $supplier_id;

  } catch (Throwable $e) {
    log_message('error', 'Unhandled error in sale_create', [
      'exception' => $e->getMessage(),
      'trace' => $e->getTraceAsString(),
    ]);
    $error = "حدث خطأ غير متوقع. حاول مرة أخرى.";
  }
}

$title = "Sales Invoice";
$subtitle = "فاتورة بيع (تخرج مخزون + تضيف على حساب العميل)";
require __DIR__ . '/../app/views/partials/header.php';
?>

<?php if ($error): ?>
  <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
    <?= h($error) ?>
  </div>
<?php endif; ?>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
    <div>
      <div class="font-extrabold text-lg">Create Sales Invoice</div>
      <div class="text-sm text-slate-500 mt-1">اختار عميل → ضيف أصناف → Confirm</div>
    </div>
    <a href="<?= $base ?>/customers.php"
       class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
      ← العملاء
    </a>
  </div>

  <!-- Customer picker (GET reload) -->
  <form method="get" action="<?= $base ?>/sale_create.php" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3">
    <div>
      <label class="block text-sm text-slate-600 mb-1">Customer</label>
      <select name="customer_id"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
        <option value="">— اختار عميل —</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ($selectedCustomerId==(int)$c['id']?'selected':'') ?>>
            <?= h($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-sm text-slate-600 mb-1">Supplier</label>
      <select name="supplier_id"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
        <option value="">— اختار مورد —</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ($selectedSupplierId==(int)$s['id']?'selected':'') ?>>
            <?= h($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="md:col-span-2 flex items-end gap-2">
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 hover:bg-slate-800 font-semibold">
        Load Products
      </button>
      <div class="text-xs text-slate-500">اختار عميل + مورد علشان تظهر منتجات المورد.</div>
    </div>
  </form>

  <hr class="my-4">

  <!-- Confirm form -->
  <form method="post" action="<?= $base ?>/sale_create.php?customer_id=<?= (int)$selectedCustomerId ?>&supplier_id=<?= (int)$selectedSupplierId ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="confirm">
    <input type="hidden" name="supplier_id" value="<?= (int)$selectedSupplierId ?>">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="block text-sm text-slate-600 mb-1">Customer</label>
        <select name="customer_id" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
          <option value="">— اختار عميل —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ($selectedCustomerId==(int)$c['id']?'selected':'') ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm text-slate-600 mb-1">Invoice Date</label>
        <input type="date" name="invoice_date" value="<?= h($_POST['invoice_date'] ?? date('Y-m-d')) ?>"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>

      <div>
        <label class="block text-sm text-slate-600 mb-1">Notes (optional)</label>
        <input name="notes" value="<?= h($_POST['notes'] ?? '') ?>"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>
    </div>

    <div class="mt-4">
      <div class="font-extrabold mb-2">Items</div>

      <?php if ($selectedCustomerId <= 0 || $selectedSupplierId <= 0): ?>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800 text-sm">
          اختار عميل + مورد فوق الأول علشان تظهر قائمة المنتجات.
        </div>
      <?php else: ?>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-right text-slate-500 border-b">
                <th class="py-2 px-2">Product</th>
                <th class="py-2 px-2">Qty</th>
                <th class="py-2 px-2">Price</th>
                <th class="py-2 px-2">Add</th>
              </tr>
            </thead>
            <tbody>
              <tr class="border-b">
                <td class="py-2 px-2">
                  <select id="productSelect"
                          class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
                    <option value="">— اختار منتج —</option>
                    <?php foreach ($products as $p): ?>
                      <option value="<?= (int)$p['id'] ?>" data-price="<?= h($p['sale_price_default']) ?>">
                        <?= h($p['name']) ?><?= !empty($p['sku']) ? ' ('.h($p['sku']).')' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="py-2 px-2">
                  <input id="qtyInput" type="number" step="0" value="1"
                         class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
                </td>
                <td class="py-2 px-2">
                  <input id="priceInput" type="number" step="0.01" value="1"
                         class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
                </td>
                <td class="py-2 px-2">
                  <button type="button" id="addRowBtn"
                          class="rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2">
                    Add
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-3 overflow-x-auto">
          <table class="w-full text-sm" id="itemsTable">
            <thead>
              <tr class="text-right text-slate-500 border-b">
                <th class="py-2 px-2">Product</th>
                <th class="py-2 px-2">Qty</th>
                <th class="py-2 px-2">Price</th>
                <th class="py-2 px-2">Line Total</th>
                <th class="py-2 px-2">Remove</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

      <?php endif; ?>
    </div>

    <div class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3">
      <div class="md:col-span-1">
        <label class="block text-sm text-slate-600 mb-1">Paid Now</label>
        <input id="paidNow" name="paid_now" type="number" step="0.01" value="<?= h($_POST['paid_now'] ?? 0) ?>"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>

      <div>
        <label class="block text-sm text-slate-600 mb-1">Payment Method</label>
        <select name="payment_method"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
          <option value="cash">Cash</option>
          <option value="bank">Bank</option>
          <option value="cheque">Cheque</option>
        </select>
      </div>

      <div>
        <label class="block text-sm text-slate-600 mb-1">Cheque No (optional)</label>
        <input name="cheque_no" value="<?= h($_POST['cheque_no'] ?? '') ?>"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>

      <div>
        <label class="block text-sm text-slate-600 mb-1">Due Date (if remaining)</label>
        <input name="due_date" type="date" value="<?= h($_POST['due_date'] ?? '') ?>"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>
    </div>

    <div class="mt-4 flex items-center justify-between">
      <div class="text-lg font-extrabold">
        Total: <span id="totalText">0.00</span>
      </div>

      <button type="submit"
              class="rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-semibold px-5 py-2.5">
        Confirm Invoice
      </button>
    </div>

    <div class="mt-2 text-xs text-slate-500">
      الفاتورة هتخصم من المخزون (qty_on_hand) + هتسجل في كشف حساب العميل فورًا.
    </div>
  </form>
</div>

<script>
  const productSelect = document.getElementById('productSelect');
  const qtyInput = document.getElementById('qtyInput');
  const priceInput = document.getElementById('priceInput');
  const addRowBtn = document.getElementById('addRowBtn');
  const itemsTableBody = document.querySelector('#itemsTable tbody');
  const totalText = document.getElementById('totalText');

  function recalcTotal() {
    let total = 0;
    itemsTableBody.querySelectorAll('tr').forEach(tr => {
      const qty = parseFloat(tr.querySelector('input[name=\"qty[]\"]').value || '0');
      const price = parseFloat(tr.querySelector('input[name=\"price[]\"]').value || '0');
      total += qty * price;
      tr.querySelector('.lineTotal').textContent = (qty * price).toFixed(2);
    });
    totalText.textContent = total.toFixed(2);
  }

  productSelect?.addEventListener('change', () => {
    const opt = productSelect.options[productSelect.selectedIndex];
    const defPrice = opt?.getAttribute('data-price');
    if (defPrice !== null && defPrice !== undefined && defPrice !== '') {
      priceInput.value = defPrice;
    }
  });

  addRowBtn?.addEventListener('click', () => {
    const pid = productSelect.value;
    if (!pid) return;

    const pname = productSelect.options[productSelect.selectedIndex].textContent;
    const qty = parseFloat(qtyInput.value || '0');
    const price = parseFloat(priceInput.value || '0');
    if (qty <= 0) return;

    const tr = document.createElement('tr');
    tr.className = 'border-b hover:bg-slate-50';

    tr.innerHTML = `
      <td class="py-2 px-2">
        <div class="font-semibold">${pname}</div>
        <input type="hidden" name="product_id[]" value="${pid}">
      </td>
      <td class="py-2 px-2">
        <input name="qty[]" type="number" step="0.01" value="${qty}"
          class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </td>
      <td class="py-2 px-2">
        <input name="price[]" type="number" step="0.01" value="${price}"
          class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </td>
      <td class="py-2 px-2 font-extrabold lineTotal">${(qty*price).toFixed(2)}</td>
      <td class="py-2 px-2">
        <button type="button" class="rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50 font-semibold removeBtn">
          Remove
        </button>
      </td>
    `;

    itemsTableBody.appendChild(tr);

    tr.querySelectorAll('input').forEach(inp => inp.addEventListener('input', recalcTotal));
    tr.querySelector('.removeBtn').addEventListener('click', () => {
      tr.remove();
      recalcTotal();
    });

    recalcTotal();
  });

  recalcTotal();
</script>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
