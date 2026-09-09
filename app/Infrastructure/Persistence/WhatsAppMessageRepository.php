<?php

namespace App\Infrastructure\Persistence;

use PDO;

final class WhatsAppMessageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(
        ?int $partyId,
        string $direction,
        string $phone,
        string $body,
        string $status,
        ?string $waMessageId = null,
        ?string $error = null,
        array $payload = [],
        ?int $createdBy = null,
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO whatsapp_messages
              (party_id, direction, phone, wa_message_id, body, status, error_message, payload_json, created_by)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $partyId,
            $direction,
            $phone,
            $waMessageId,
            $body,
            $status,
            $error,
            $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $createdBy,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentForParty(int $partyId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, direction, phone, body, status, error_message, created_at
            FROM whatsapp_messages
            WHERE party_id = ?
            ORDER BY id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $partyId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findPartyIdByPhone(string $phone): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM parties
            WHERE type = 'customer' AND is_active = 1 AND phone IS NOT NULL
              AND REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') LIKE ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['%' . $phone]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }
}
