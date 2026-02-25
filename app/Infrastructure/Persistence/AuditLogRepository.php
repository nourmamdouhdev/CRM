<?php

namespace App\Infrastructure\Persistence;

final class AuditLogRepository
{
    private ?bool $isAvailable = null;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insert(?int $actorId, string $action, string $entityType, int $entityId, array $payload = []): void
    {
        if (!$this->tableAvailable()) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_logs
              (actor_id, action, entity_type, entity_id, payload_json)
             VALUES
              (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $actorId,
            $action,
            $entityType,
            $entityId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function tableAvailable(): bool
    {
        if ($this->isAvailable !== null) {
            return $this->isAvailable;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'audit_logs'");
            $this->isAvailable = (bool)$stmt->fetchColumn();
        } catch (\Throwable) {
            $this->isAvailable = false;
        }

        return $this->isAvailable;
    }
}

