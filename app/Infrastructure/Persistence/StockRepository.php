<?php

namespace App\Infrastructure\Persistence;

use RuntimeException;

final class StockRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function ensureAndLockQty(int $productId): float
    {
        $upsert = $this->pdo->prepare(
            "INSERT INTO stock (product_id, qty_on_hand) VALUES (?, 0)
             ON DUPLICATE KEY UPDATE product_id = product_id"
        );
        $upsert->execute([$productId]);

        $stmt = $this->pdo->prepare(
            "SELECT qty_on_hand FROM stock WHERE product_id = ? FOR UPDATE"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (float)($row['qty_on_hand'] ?? 0.0);
    }

    public function increase(int $productId, float $qty): void
    {
        $this->ensureAndLockQty($productId);
        $upd = $this->pdo->prepare(
            "UPDATE stock SET qty_on_hand = qty_on_hand + ? WHERE product_id = ?"
        );
        $upd->execute([$qty, $productId]);
    }

    public function decrease(int $productId, float $qty, bool $allowNegative = false): void
    {
        $currentQty = $this->ensureAndLockQty($productId);
        if (!$allowNegative && $currentQty < $qty) {
            throw new RuntimeException('Insufficient stock for product #' . $productId);
        }

        $upd = $this->pdo->prepare(
            "UPDATE stock SET qty_on_hand = qty_on_hand - ? WHERE product_id = ?"
        );
        $upd->execute([$qty, $productId]);
    }
}

