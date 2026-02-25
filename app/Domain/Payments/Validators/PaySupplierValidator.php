<?php

namespace App\Domain\Payments\Validators;

use App\Domain\Payments\DTO\PaySupplierData;

final class PaySupplierValidator
{
    public function validate(array $payload, int $supplierId): PaySupplierData
    {
        return PaySupplierData::fromRequest($payload, $supplierId);
    }
}

