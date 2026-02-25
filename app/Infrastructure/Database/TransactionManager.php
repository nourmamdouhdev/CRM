<?php

namespace App\Infrastructure\Database;

use Throwable;

final class TransactionManager
{
    private \PDO $pdo;
    private int $depth = 0;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \DB::pdo();
    }

    public function run(callable $fn): mixed
    {
        $level = ++$this->depth;
        $savepoint = 'sp_' . $level;

        try {
            if ($level === 1) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec("SAVEPOINT {$savepoint}");
            }

            $result = $fn($this->pdo);

            if ($level === 1) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($level === 1) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                }
            }
            throw $e;
        } finally {
            $this->depth--;
        }
    }
}

