<?php

namespace App\Domain\Sales;

use App\Domain\Inventory\StockService;
use App\Domain\Sales\DTO\CreateSalesInvoiceData;
use App\Infrastructure\Database\TransactionManager;
use App\Infrastructure\Persistence\AuditLogRepository;
use App\Infrastructure\Persistence\DocSequenceRepository;
use App\Infrastructure\Persistence\StockRepository;
use InvalidArgumentException;

final class SalesService
{
    private \PDO $pdo;
    private TransactionManager $tx;
    private DocSequenceRepository $docSequences;
    private StockService $stockService;
    private AuditLogRepository $auditLogs;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \DB::pdo();
        $this->tx = new TransactionManager($this->pdo);
        $this->docSequences = new DocSequenceRepository($this->pdo);

        $config = require __DIR__ . '/../../../config/config.php';
        $allowNegativeStock = (bool)($config['app']['allow_negative_stock'] ?? false);
        $this->stockService = new StockService(new StockRepository($this->pdo), $allowNegativeStock);
        $this->auditLogs = new AuditLogRepository($this->pdo);
    }

    public function createInvoice(CreateSalesInvoiceData $data, int $actorId): int
    {
        return $this->tx->run(function () use ($data, $actorId): int {
            $total = 0.0;
            foreach ($data->items as $item) {
                $total += ((float)$item['qty']) * ((float)$item['unit_price']);
            }
            if ($total <= 0) {
                throw new InvalidArgumentException('Invoice total must be greater than zero.');
            }

            $paidNow = min(max(0.0, $data->paidNow), $total);
            $remaining = $total - $paidNow;
            if ($remaining > 0 && empty($data->dueDate)) {
                throw new InvalidArgumentException('Due date is required for outstanding amounts.');
            }

            $ymd = str_replace('-', '', $data->invoiceDate);
            $invoiceNo = $this->docSequences->nextInvoiceNumber('sales', $ymd, 'SI');

            $stmt = $this->pdo->prepare(
                "INSERT INTO sales_invoices
                  (invoice_no, customer_id, invoice_date, total_amount, total, net_total,
                   paid_amount, remaining_amount, due_date, notes, created_by)
                 VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $invoiceNo,
                $data->customerId,
                $data->invoiceDate,
                $total,
                $total,
                $total,
                $paidNow,
                $remaining,
                $remaining > 0 ? $data->dueDate : null,
                $data->notes !== '' ? $data->notes : null,
                $actorId,
            ]);
            $invoiceId = (int)$this->pdo->lastInsertId();

            $insItem = $this->pdo->prepare(
                "INSERT INTO sales_invoice_items
                  (sales_invoice_id, product_id, qty, unit_price, line_total)
                 VALUES
                  (?, ?, ?, ?, ?)"
            );

            foreach ($data->items as $item) {
                $lineTotal = ((float)$item['qty']) * ((float)$item['unit_price']);
                $insItem->execute([
                    $invoiceId,
                    (int)$item['product_id'],
                    (float)$item['qty'],
                    (float)$item['unit_price'],
                    $lineTotal,
                ]);
                $this->stockService->decrease((int)$item['product_id'], (float)$item['qty']);
            }

            $ledgerSale = $this->pdo->prepare(
                "INSERT INTO ledger_entries
                  (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
                 VALUES
                  (?, ?, 'sale_invoice', 'sales_invoices', ?, ?, 0, ?, ?, ?)"
            );
            $ledgerSale->execute([
                $data->customerId,
                $data->invoiceDate,
                $invoiceId,
                $total,
                $remaining > 0 ? $data->dueDate : null,
                $data->notes !== '' ? $data->notes : null,
                $actorId,
            ]);

            if ($paidNow > 0) {
                $payment = $this->pdo->prepare(
                    "INSERT INTO payments
                      (party_id, direction, payment_date, amount, method, cheque_id,
                       ref_table, ref_id, notes, created_by)
                     VALUES
                      (?, 'in', ?, ?, ?, ?, 'sales_invoices', ?, ?, ?)"
                );
                $payment->execute([
                    $data->customerId,
                    $data->invoiceDate,
                    $paidNow,
                    $data->paymentMethod,
                    $data->paymentMethod === 'cheque' ? $data->chequeNo : null,
                    $invoiceId,
                    $data->notes !== '' ? $data->notes : null,
                    $actorId,
                ]);
                $paymentId = (int)$this->pdo->lastInsertId();

                $ledgerPayment = $this->pdo->prepare(
                    "INSERT INTO ledger_entries
                      (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
                     VALUES
                      (?, ?, 'payment_in', 'payments', ?, 0, ?, NULL, ?, ?)"
                );
                $ledgerPayment->execute([
                    $data->customerId,
                    $data->invoiceDate,
                    $paymentId,
                    $paidNow,
                    $data->notes !== '' ? $data->notes : null,
                    $actorId,
                ]);
            }

            $this->auditLogs->insert($actorId, 'sales_invoice_created', 'sales_invoices', $invoiceId, [
                'invoice_no' => $invoiceNo,
                'customer_id' => $data->customerId,
                'total' => $total,
                'paid_now' => $paidNow,
                'remaining' => $remaining,
            ]);

            return $invoiceId;
        });
    }
}

