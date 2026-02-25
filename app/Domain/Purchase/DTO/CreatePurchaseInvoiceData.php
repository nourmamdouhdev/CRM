<?php

namespace App\Domain\Purchase\DTO;

use InvalidArgumentException;

final class CreatePurchaseInvoiceData
{
    /**
     * @param array<int, array{product_id:int, qty:float, unit_cost:float}> $items
     */
    public function __construct(
        public readonly int $supplierId,
        public readonly string $invoiceDate,
        public readonly ?string $dueDate,
        public readonly string $notes,
        public readonly float $paidNow,
        public readonly string $paymentMethod,
        public readonly ?string $chequeNo,
        public readonly array $items
    ) {
    }

    public static function fromRequest(array $post): self
    {
        $supplierId = (int)($post['supplier_id'] ?? 0);
        $invoiceDate = trim((string)($post['invoice_date'] ?? date('Y-m-d')));
        $dueDate = trim((string)($post['due_date'] ?? ''));
        $notes = trim((string)($post['notes'] ?? ''));
        $paidNow = (float)($post['paid_now'] ?? 0);
        $paymentMethod = trim((string)($post['payment_method'] ?? 'cash'));
        $chequeNo = trim((string)($post['cheque_no'] ?? ''));

        if ($supplierId <= 0) {
            throw new InvalidArgumentException('Supplier is required.');
        }
        if ($invoiceDate === '') {
            throw new InvalidArgumentException('Invoice date is required.');
        }

        $items = [];
        $ids = $post['product_id'] ?? [];
        $qtys = $post['qty'] ?? [];
        $costs = $post['cost'] ?? [];
        $count = max(count($ids), count($qtys), count($costs));

        for ($i = 0; $i < $count; $i++) {
            $productId = (int)($ids[$i] ?? 0);
            $qty = (float)($qtys[$i] ?? 0);
            $cost = (float)($costs[$i] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            if ($cost < 0) {
                $cost = 0.0;
            }
            $items[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'unit_cost' => $cost,
            ];
        }

        if (count($items) === 0) {
            throw new InvalidArgumentException('At least one valid item is required.');
        }

        return new self(
            $supplierId,
            $invoiceDate,
            $dueDate !== '' ? $dueDate : null,
            $notes,
            max(0, $paidNow),
            $paymentMethod !== '' ? $paymentMethod : 'cash',
            $chequeNo !== '' ? $chequeNo : null,
            $items
        );
    }
}

