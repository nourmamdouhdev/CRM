<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sales\DTO\CreateSalesInvoiceData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CreateSalesInvoiceDataTest extends TestCase
{
    public function testBuildsDtoFromValidPayload(): void
    {
        $dto = CreateSalesInvoiceData::fromRequest([
            'customer_id' => 5,
            'supplier_id' => 8,
            'invoice_date' => '2026-02-25',
            'product_id' => [10],
            'qty' => [2],
            'price' => [50],
        ]);

        self::assertSame(5, $dto->customerId);
        self::assertCount(1, $dto->items);
    }

    public function testThrowsWhenItemsAreMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CreateSalesInvoiceData::fromRequest([
            'customer_id' => 5,
            'supplier_id' => 8,
            'invoice_date' => '2026-02-25',
            'product_id' => [],
        ]);
    }
}

