<?php
require __DIR__ . '/../app/core/DB.php';
require __DIR__ . '/../app/core/Auth.php';
require __DIR__ . '/../app/core/CSRF.php';
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

if (empty($user['id'])) {
  die("User not authenticated (missing user id).");
}

// ---------- Load suppliers
$suppliers = $pdo->query("
  SELECT id, name
  FROM parties
  WHERE type='supplier' AND is_active=1
  ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$error = null;

// ---------- GET selected supplier (for loading products dropdown)
$selectedSupplierId = (int)($_GET['supplier_id'] ?? 0);
$availableProducts = [];

if ($selectedSupplierId > 0) {
  $stmt = $pdo->prepare("
    SELECT id, name, sku, cost_price_default
    FROM products
    WHERE is_active=1 AND supplier_id = ?
    ORDER BY name ASC
  ");
  $stmt->execute([$selectedSupplierId]);
  $availableProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrf = CSRF::token();

// ---------- Handle POST (Confirm invoice)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'confirm')) {
  try {
    CSRF::verify($_POST['csrf_token'] ?? null);

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
    $costs       = $_POST['cost'] ?? [];

    if ($supplier_id <= 0) {
      $error = "اختر المورد.";
    } elseif (empty($product_ids)) {
      $error = "أضف على الأقل منتج واحد.";
    } else {

      // build items
      $items = [];
      $total = 0.0;

      for ($i=0; $i<count($product_ids); $i++) {
        $pid  = (int)$product_ids[$i];
        $qty  = (float)($qtys[$i] ?? 0);
        $cost = (float)($costs[$i] ?? 0);

        if ($pid <= 0) continue;
        if ($qty <= 0) continue;
        if ($cost < 0) $cost = 0;

        $line = $qty * $cost;
        $total += $line;

        $items[] = [
          'product_id' => $pid,
          'qty' => $qty,
          'cost' => $cost,
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

            // 1) Insert purchase_invoices
            $invoice_no = 'PI-' . date('Ymd') . '-' . random_int(1000, 9999);

            $stmt = $pdo->prepare("
              INSERT INTO purchase_invoices
                (invoice_no, supplier_id, invoice_date,
                 total_amount, total, net_total,
                 paid_amount, remaining_amount, due_date, notes, created_by)
              VALUES
                (?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
              $invoice_no,
              $supplier_id,
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

            // 2) Insert purchase_invoice_items + update stock
            $insItem = $pdo->prepare("
              INSERT INTO purchase_invoice_items
                (purchase_invoice_id, product_id, qty, unit_cost, line_total)
              VALUES
                (?, ?, ?, ?, ?)
            ");

            // stock upsert
            $stockSel = $pdo->prepare("SELECT product_id FROM stock WHERE product_id = ? LIMIT 1");
            $stockIns = $pdo->prepare("INSERT INTO stock (product_id, qty_on_hand) VALUES (?, ?)");
            $stockUpd = $pdo->prepare("UPDATE stock SET qty_on_hand = qty_on_hand + ? WHERE product_id = ?");

            foreach ($items as $it) {
              $insItem->execute([$invoice_id, $it['product_id'], $it['qty'], $it['cost'], $it['line_total']]);

              $stockSel->execute([$it['product_id']]);
              $exists = $stockSel->fetch(PDO::FETCH_ASSOC);
              if (!$exists) {
                $stockIns->execute([$it['product_id'], 0]);
              }
              $stockUpd->execute([$it['qty'], $it['product_id']]);
            }

            // 3) Ledger entry for supplier (Purchase invoice) => CREDIT
            $stmt = $pdo->prepare("
              INSERT INTO ledger_entries
                (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
              VALUES
                (?, ?, 'purchase_invoice', 'purchase_invoices', ?, 0, ?, ?, ?, ?)
            ");
            $stmt->execute([
              $supplier_id,
              $invoice_date,
              $invoice_id,
              $total,
              ($remaining > 0 ? $due_date : null),
              ($notes !== '' ? $notes : null),
              (int)$user['id'],
            ]);

            // 4) If paid now: record payment + ledger (DEBIT)
            if ($paid_now > 0) {
              // payments table (your real columns)
              $stmt = $pdo->prepare("
                INSERT INTO payments
                  (party_id, direction, payment_date, amount, method, cheque_id,
                   ref_table, ref_id, notes, created_by)
                VALUES
                  (?, 'out', ?, ?, ?, ?,
                   'purchase_invoices', ?, ?, ?)
              ");
              $stmt->execute([
                $supplier_id,
                $invoice_date, // payment_date
                $paid_now,
                $payment_method,
                ($payment_method === 'cheque' && $cheque_no !== '' ? $cheque_no : null),
                $invoice_id,
                ($notes !== '' ? $notes : null),
                (int)$user['id'],
              ]);
              $payment_id = (int)$pdo->lastInsertId();

              // ledger: payment_out => DEBIT
              $stmt = $pdo->prepare("
                INSERT INTO ledger_entries
                  (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
                VALUES
                  (?, ?, 'payment_out', 'payments', ?, ?, 0, NULL, ?, ?)
              ");
              $stmt->execute([
                $supplier_id,
                $invoice_date,
                $payment_id,
                $paid_now,
                ($notes !== '' ? $notes : null),
                (int)$user['id'],
              ]);
            }

            $pdo->commit();

header("Location: {$base}/purchase_view.php?id={$invoice_id}");
exit;


          } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Database error: " . $e->getMessage();
          }
        }
      }
    }

    // reload products list if error and supplier chosen
    $selectedSupplierId = $supplier_id;
    if ($selectedSupplierId > 0) {
      $stmt = $pdo->prepare("
        SELECT id, name, sku, cost_price_default
        FROM products
        WHERE is_active=1 AND supplier_id = ?
        ORDER BY name ASC
      ");
      $stmt->execute([$selectedSupplierId]);
      $availableProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
  } catch (Throwable $e) {
    $error = "Error: " . $e->getMessage();
  }
}

$title = "Purchase Invoice";
$subtitle = "فاتورة شراء (تدخل مخزون + تضيف على حساب المورد)";
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
      <div class="font-extrabold text-lg">Create Purchase Invoice</div>
      <div class="text-sm text-slate-500 mt-1">اختار مورد → ضيف أصناف → Confirm</div>
    </div>
    <a href="<?= $base ?>/suppliers.php"
       class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">
      ← Suppliers
    </a>
  </div>

  <!-- Supplier picker (GET reload) -->
  <form method="get" action="<?= $base ?>/purchase_create.php" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
    <div>
      <label class="block text-sm text-slate-600 mb-1">Supplier</label>
      <select name="supplier_id"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
        <option value="">— اختر مورد —</option>
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
      <div class="text-xs text-slate-500">بيجيب منتجات المورد فقط (حسب products.supplier_id)</div>
    </div>
  </form>

  <hr class="my-4">

  <!-- Confirm form -->
  <form method="post" action="<?= $base ?>/purchase_create.php?supplier_id=<?= (int)$selectedSupplierId ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="confirm">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="block text-sm text-slate-600 mb-1">Supplier</label>
        <select name="supplier_id" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
          <option value="">— اختر مورد —</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ($selectedSupplierId==(int)$s['id']?'selected':'') ?>>
              <?= h($s['name']) ?>
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

      <?php if ($selectedSupplierId <= 0): ?>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800 text-sm">
          اختر مورد واضغط "Load Products" علشان تظهر قائمة المنتجات.
        </div>
      <?php else: ?>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-right text-slate-500 border-b">
                <th class="py-2 px-2">Product</th>
                <th class="py-2 px-2">Qty</th>
                <th class="py-2 px-2">Cost</th>
                <th class="py-2 px-2">Add</th>
              </tr>
            </thead>
            <tbody>
              <tr class="border-b">
                <td class="py-2 px-2">
                  <select id="productSelect"
                          class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200 bg-white">
                    <option value="">— اختر منتج —</option>
                    <?php foreach ($availableProducts as $p): ?>
                      <option value="<?= (int)$p['id'] ?>" data-cost="<?= h($p['cost_price_default']) ?>">
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
                  <input id="costInput" type="number" step="0" value="1"
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
                <th class="py-2 px-2">Cost</th>
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
      الفاتورة هتدخل مخزون (qty_on_hand) + هتسجل في كشف حساب المورد فورًا.
    </div>
  </form>
</div>

<script>
  const productSelect = document.getElementById('productSelect');
  const qtyInput = document.getElementById('qtyInput');
  const costInput = document.getElementById('costInput');
  const addRowBtn = document.getElementById('addRowBtn');
  const itemsTableBody = document.querySelector('#itemsTable tbody');
  const totalText = document.getElementById('totalText');

  function recalcTotal() {
    let total = 0;
    itemsTableBody.querySelectorAll('tr').forEach(tr => {
      const qty = parseFloat(tr.querySelector('input[name="qty[]"]').value || '0');
      const cost = parseFloat(tr.querySelector('input[name="cost[]"]').value || '0');
      total += qty * cost;
      tr.querySelector('.lineTotal').textContent = (qty * cost).toFixed(2);
    });
    totalText.textContent = total.toFixed(2);
  }

  productSelect?.addEventListener('change', () => {
    const opt = productSelect.options[productSelect.selectedIndex];
    const defCost = opt?.getAttribute('data-cost');
    if (defCost !== null && defCost !== undefined && defCost !== '') {
      costInput.value = defCost;
    }
  });

  addRowBtn?.addEventListener('click', () => {
    const pid = productSelect.value;
    if (!pid) return;

    const pname = productSelect.options[productSelect.selectedIndex].textContent;
    const qty = parseFloat(qtyInput.value || '0');
    const cost = parseFloat(costInput.value || '0');
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
        <input name="cost[]" type="number" step="0.01" value="${cost}"
          class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </td>
      <td class="py-2 px-2 font-extrabold lineTotal">${(qty*cost).toFixed(2)}</td>
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
