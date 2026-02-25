<?php

namespace App\Infrastructure\Persistence;

final class DocSequenceRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function nextInvoiceNumber(string $docType, string $ymd, string $prefix): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT last_no FROM doc_sequences WHERE doc_type = ? AND ymd = ? FOR UPDATE"
        );
        $stmt->execute([$docType, $ymd]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $ins = $this->pdo->prepare(
                "INSERT INTO doc_sequences (doc_type, ymd, last_no) VALUES (?, ?, 0)"
            );
            $ins->execute([$docType, $ymd]);

            $stmt->execute([$docType, $ymd]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        $nextNo = ((int)($row['last_no'] ?? 0)) + 1;
        $upd = $this->pdo->prepare(
            "UPDATE doc_sequences SET last_no = ? WHERE doc_type = ? AND ymd = ?"
        );
        $upd->execute([$nextNo, $docType, $ymd]);

        return sprintf('%s-%s-%04d', $prefix, $ymd, $nextNo);
    }
}

