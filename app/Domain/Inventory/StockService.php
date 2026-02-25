<?php

namespace App\Domain\Inventory;

use App\Infrastructure\Persistence\StockRepository;

final class StockService
{
    public function __construct(
        private readonly StockRepository $stockRepository,
        private readonly bool $allowNegativeStock = false
    ) {
    }

    public function increase(int $productId, float $qty): void
    {
        $this->stockRepository->increase($productId, $qty);
    }

    public function decrease(int $productId, float $qty): void
    {
        $this->stockRepository->decrease($productId, $qty, $this->allowNegativeStock);
    }
}

