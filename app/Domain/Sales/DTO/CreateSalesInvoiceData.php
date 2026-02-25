<?php

namespace App\Domain\Sales\DTO;

use InvalidArgumentException;

final class CreateSalesInvoiceData
{
    /**
     * @param array<int, array{product_id:int, qty:float, unit_price:float}> $items
     */
    public function __construct(
        public readonly int $customerId,
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
        $customerId = (int)($post['customer_id'] ?? 0);
        $supplierId = (int)($post['supplier_id'] ?? 0);
        $invoiceDate = trim((string)($post['invoice_date'] ?? date('Y-m-d')));
        $dueDate = trim((string)($post['due_date'] ?? ''));
        $notes = trim((string)($post['notes'] ?? ''));
        $paidNow = (float)($post['paid_now'] ?? 0);
        $paymentMethod = trim((string)($post['payment_method'] ?? 'cash'));
        $chequeNo = trim((string)($post['cheque_no'] ?? ''));

        if ($customerId <= 0) {
            throw new InvalidArgumentException('Customer is required.');
        }
        if ($supplierId <= 0) {
            throw new InvalidArgumentException('Supplier is required.');
        }
        if ($invoiceDate === '') {
            throw new InvalidArgumentException('Invoice date is required.');
        }

        $items = [];
        $ids = $post['product_id'] ?? [];
        $qtys = $post['qty'] ?? [];
        $prices = $post['price'] ?? [];
        $count = max(count($ids), count($qtys), count($prices));

        for ($i = 0; $i < $count; $i++) {
            $productId = (int)($ids[$i] ?? 0);
            $qty = (float)($qtys[$i] ?? 0);
            $price = (float)($prices[$i] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            if ($price < 0) {
                $price = 0.0;
            }
            $items[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'unit_price' => $price,
            ];
        }

        if (count($items) === 0) {
            throw new InvalidArgumentException('At least one valid item is required.');
        }

        return new self(
            $customerId,
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

