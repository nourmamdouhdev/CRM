<?php

namespace App\Domain\Purchase\Validators;

use App\Domain\Purchase\DTO\CreatePurchaseInvoiceData;

final class CreatePurchaseInvoiceValidator
{
    public function validate(array $payload): CreatePurchaseInvoiceData
    {
        return CreatePurchaseInvoiceData::fromRequest($payload);
    }
}

