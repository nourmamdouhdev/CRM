<?php

namespace App\Domain\Payments;

use App\Domain\Payments\DTO\CollectPaymentData;
use App\Domain\Payments\DTO\PaySupplierData;
use App\Infrastructure\Database\TransactionManager;
use App\Infrastructure\Persistence\AuditLogRepository;
use InvalidArgumentException;

final class PaymentService
{
    private \PDO $pdo;
    private TransactionManager $tx;
    private AuditLogRepository $auditLogs;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \DB::pdo();
        $this->tx = new TransactionManager($this->pdo);
        $this->auditLogs = new AuditLogRepository($this->pdo);
    }

    public function collectFromCustomer(CollectPaymentData $data, int $actorId): int
    {
        return $this->tx->run(function () use ($data, $actorId): int {
            $amount = $data->amount;
            $refTable = null;
            $refId = null;

            if ($data->invoiceId > 0) {
                $invoice = $this->lockSalesInvoice($data->invoiceId, $data->customerId);
                if (!$invoice) {
                    throw new InvalidArgumentException('Invalid selected invoice.');
                }
                $remaining = (float)$invoice['remaining_amount'];
                if ($remaining <= 0) {
                    throw new InvalidArgumentException('Invoice is already fully paid.');
                }
                if ($amount > $remaining) {
                    $amount = $remaining;
                }
                $refTable = 'sales_invoices';
                $refId = $data->invoiceId;
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO payments
                  (party_id, direction, payment_date, amount, method, cheque_id, ref_table, ref_id, notes, created_by)
                 VALUES
                  (?, 'in', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $data->customerId,
                $data->paymentDate,
                $amount,
                $data->method,
                $data->method === 'cheque' ? $data->chequeNo : null,
                $refTable,
                $refId,
                $data->notes !== '' ? $data->notes : null,
                $actorId,
            ]);
            $paymentId = (int)$this->pdo->lastInsertId();

            $ledger = $this->pdo->prepare(
                "INSERT INTO ledger_entries
                  (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
                 VALUES
                  (?, ?, 'payment_in', 'payments', ?, 0, ?, NULL, ?, ?)"
            );
            $ledger->execute([
                $data->customerId,
                $data->paymentDate,
                $paymentId,
                $amount,
                $data->notes !== '' ? $data->notes : null,
                $actorId,
            ]);

            if ($data->invoiceId > 0) {
                $update = $this->pdo->prepare(
                    "UPDATE sales_invoices
                     SET
                       paid_amount = paid_amount + ?,
                       remaining_amount = GREATEST(remaining_amount - ?, 0),
                       due_date = CASE WHEN GREATEST(remaining_amount - ?, 0) = 0 THEN NULL ELSE due_date END
                     WHERE id = ? AND customer_id = ?"
                );
                $update->execute([$amount, $amount, $amount, $data->invoiceId, $data->customerId]);
            }

            $this->auditLogs->insert($actorId, 'payment_collected', 'payments', $paymentId, [
                'customer_id' => $data->customerId,
                'invoice_id' => $data->invoiceId ?: null,
                'amount' => $amount,
                'method' => $data->method,
            ]);

            return $paymentId;
        });
    }

    public function paySupplier(PaySupplierData $data, int $actorId): int
    {
        return $this->tx->run(function () use ($data, $actorId): int {
            $amount = $data->amount;
            $refTable = null;
            $refId = null;

            if ($data->invoiceId > 0) {
                $invoice = $this->lockPurchaseInvoice($data->invoiceId, $data->supplierId);
                if (!$invoice) {
                    throw new InvalidArgumentException('Invalid selected invoice.');
                }
                $remaining = (float)$invoice['remaining_amount'];
                if ($remaining <= 0) {
                    throw new InvalidArgumentException('Invoice is already fully paid.');
                }
                if ($amount > $remaining) {
                    $amount = $remaining;
                }
                $refTable = 'purchase_invoices';
                $refId = $data->invoiceId;
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO payments
                  (party_id, direction, payment_date, amount, method, cheque_id, ref_table, ref_id, notes, created_by)
                 VALUES
                  (?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $data->supplierId,
                $data->paymentDate,
                $amount,
                $data->method,
                $data->method === 'cheque' ? $data->chequeNo : null,
                $refTable,
                $refId,
                $data->notes !== '' ? $data->notes : null,
                $actorId,
            ]);
            $paymentId = (int)$this->pdo->lastInsertId();

            $ledger = $this->pdo->prepare(
                "INSERT INTO ledger_entries
                  (party_id, entry_date, type, ref_table, ref_id, debit, credit, due_date, notes, created_by)
                 VALUES
                  (?, ?, 'payment_out', 'payments', ?, ?, 0, NULL, ?, ?)"
            );
            $ledger->execute([
                $data->supplierId,
                $data->paymentDate,
                $paymentId,
                $amount,
                $data->notes !== '' ? $data->notes : null,
                $actorId,
            ]);

            if ($data->invoiceId > 0) {
                $update = $this->pdo->prepare(
                    "UPDATE purchase_invoices
                     SET
                       paid_amount = paid_amount + ?,
                       remaining_amount = GREATEST(remaining_amount - ?, 0),
                       due_date = CASE WHEN GREATEST(remaining_amount - ?, 0) = 0 THEN NULL ELSE due_date END
                     WHERE id = ? AND supplier_id = ?"
                );
                $update->execute([$amount, $amount, $amount, $data->invoiceId, $data->supplierId]);
            }

            $this->auditLogs->insert($actorId, 'supplier_payment', 'payments', $paymentId, [
                'supplier_id' => $data->supplierId,
                'invoice_id' => $data->invoiceId ?: null,
                'amount' => $amount,
                'method' => $data->method,
            ]);

            return $paymentId;
        });
    }

    private function lockSalesInvoice(int $invoiceId, int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, remaining_amount
             FROM sales_invoices
             WHERE id = ? AND customer_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$invoiceId, $customerId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function lockPurchaseInvoice(int $invoiceId, int $supplierId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, remaining_amount
             FROM purchase_invoices
             WHERE id = ? AND supplier_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$invoiceId, $supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}

