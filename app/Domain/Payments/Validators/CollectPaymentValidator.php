<?php

namespace App\Domain\Payments\Validators;

use App\Domain\Payments\DTO\CollectPaymentData;

final class CollectPaymentValidator
{
    public function validate(array $payload, int $customerId): CollectPaymentData
    {
        return CollectPaymentData::fromRequest($payload, $customerId);
    }
}

