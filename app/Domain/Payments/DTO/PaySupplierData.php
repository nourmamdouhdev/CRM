<?php

namespace App\Domain\Payments\DTO;

use InvalidArgumentException;

final class PaySupplierData
{
    public function __construct(
        public readonly int $supplierId,
        public readonly string $paymentDate,
        public readonly float $amount,
        public readonly string $method,
        public readonly ?string $chequeNo,
        public readonly string $notes,
        public readonly int $invoiceId
    ) {
    }

    public static function fromRequest(array $post, int $supplierId): self
    {
        $paymentDate = trim((string)($post['payment_date'] ?? date('Y-m-d')));
        $amount = (float)($post['amount'] ?? 0);
        $method = trim((string)($post['method'] ?? 'cash'));
        $chequeNo = trim((string)($post['cheque_no'] ?? ''));
        $notes = trim((string)($post['notes'] ?? ''));
        $invoiceId = (int)($post['invoice_id'] ?? 0);

        if ($supplierId <= 0) {
            throw new InvalidArgumentException('Supplier is required.');
        }
        if ($paymentDate === '') {
            throw new InvalidArgumentException('Payment date is required.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return new self(
            $supplierId,
            $paymentDate,
            $amount,
            $method !== '' ? $method : 'cash',
            $chequeNo !== '' ? $chequeNo : null,
            $notes,
            max(0, $invoiceId)
        );
    }
}

