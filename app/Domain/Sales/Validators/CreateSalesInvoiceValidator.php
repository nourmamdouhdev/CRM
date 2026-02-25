<?php

namespace App\Domain\Sales\Validators;

use App\Domain\Sales\DTO\CreateSalesInvoiceData;

final class CreateSalesInvoiceValidator
{
    public function validate(array $payload): CreateSalesInvoiceData
    {
        return CreateSalesInvoiceData::fromRequest($payload);
    }
}

