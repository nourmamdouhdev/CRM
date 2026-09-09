<?php

namespace App\Domain\Integrations;

use App\Domain\Leads\LeadSource;
use App\Infrastructure\WhatsApp\PhoneNormalizer;
use InvalidArgumentException;
use PDO;

final class CrmMcpTools
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WhatsAppService $whatsApp,
        private readonly string $defaultCountryCode = '20',
    ) {
    }

    /**
     * @return array<string, array{description:string,title?:string,inputSchema:array<string,mixed>,handler:callable}>
     */
    public function definitions(): array
    {
        return [
            'list_lead_sources' => [
                'title' => 'List lead sources',
                'description' => 'Return the CRM lead source options.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'additionalProperties' => false,
                ],
                'handler' => fn () => LeadSource::options(),
            ],
            'search_customers' => [
                'title' => 'Search customers',
                'description' => 'Search customers by name, phone, or lead source.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Name or phone search text'],
                        'lead_source' => ['type' => 'string', 'enum' => LeadSource::values()],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    ],
                    'additionalProperties' => false,
                ],
                'handler' => fn (array $args) => $this->searchCustomers($args),
            ],
            'get_customer' => [
                'title' => 'Get customer',
                'description' => 'Fetch one customer profile by id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                    ],
                    'required' => ['id'],
                    'additionalProperties' => false,
                ],
                'handler' => fn (array $args) => $this->getCustomer((int)($args['id'] ?? 0)),
            ],
            'create_customer' => [
                'title' => 'Create customer',
                'description' => 'Create a customer lead with an optional lead source.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'address' => ['type' => 'string'],
                        'notes' => ['type' => 'string'],
                        'lead_source' => ['type' => 'string', 'enum' => LeadSource::values()],
                    ],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ],
                'handler' => fn (array $args) => $this->createCustomer($args),
            ],
            'send_whatsapp' => [
                'title' => 'Send WhatsApp message',
                'description' => 'Send a WhatsApp Cloud API text message to a customer or phone number.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer'],
                        'phone' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                    ],
                    'required' => ['message'],
                    'additionalProperties' => false,
                ],
                'handler' => fn (array $args) => $this->sendWhatsApp($args),
            ],
            'dashboard_summary' => [
                'title' => 'Dashboard summary',
                'description' => 'Return high-level CRM counts for customers and lead sources.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'additionalProperties' => false,
                ],
                'handler' => fn () => $this->dashboardSummary(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return list<array<string, mixed>>
     */
    private function searchCustomers(array $args): array
    {
        $query = trim((string)($args['query'] ?? ''));
        $leadSource = LeadSource::normalize(isset($args['lead_source']) ? (string)$args['lead_source'] : null);
        $limit = max(1, min(50, (int)($args['limit'] ?? 20)));

        $sql = "SELECT id, name, phone, lead_source, created_at
                FROM parties
                WHERE type = 'customer' AND is_active = 1";
        $params = [];

        if ($query !== '') {
            $sql .= ' AND (name LIKE ? OR phone LIKE ?)';
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($leadSource !== null) {
            $sql .= ' AND lead_source = ?';
            $params[] = $leadSource;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $row['lead_source_label'] = LeadSource::label($row['lead_source'] ?? null);
            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCustomer(int $id): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Customer id is required.');
        }

        $stmt = $this->pdo->prepare("
            SELECT id, name, phone, address, notes, lead_source, created_at
            FROM parties
            WHERE id = ? AND type = 'customer' AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Customer not found.');
        }

        $row['lead_source_label'] = LeadSource::label($row['lead_source'] ?? null);
        return $row;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function createCustomer(array $args): array
    {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Customer name is required.');
        }

        $leadSource = LeadSource::normalize(isset($args['lead_source']) ? (string)$args['lead_source'] : null);
        $phone = trim((string)($args['phone'] ?? ''));
        $address = trim((string)($args['address'] ?? ''));
        $notes = trim((string)($args['notes'] ?? ''));

        $stmt = $this->pdo->prepare("
            INSERT INTO parties
              (type, name, phone, address, notes, lead_source, opening_balance, opening_balance_type, is_active)
            VALUES
              ('customer', ?, ?, ?, ?, ?, 0, 'debit', 1)
        ");
        $stmt->execute([
            $name,
            $phone !== '' ? $phone : null,
            $address !== '' ? $address : null,
            $notes !== '' ? $notes : null,
            $leadSource,
        ]);

        return [
            'id' => (int)$this->pdo->lastInsertId(),
            'name' => $name,
            'phone' => $phone !== '' ? $phone : null,
            'lead_source' => $leadSource,
            'lead_source_label' => LeadSource::label($leadSource),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function sendWhatsApp(array $args): array
    {
        $message = trim((string)($args['message'] ?? ''));
        $customerId = (int)($args['customer_id'] ?? 0);
        $phone = trim((string)($args['phone'] ?? ''));

        if ($customerId > 0) {
            $customer = $this->getCustomer($customerId);
            $phone = $phone !== '' ? $phone : (string)($customer['phone'] ?? '');
            return $this->whatsApp->sendText($customerId, $phone, $message);
        }

        if ($phone === '') {
            throw new InvalidArgumentException('customer_id or phone is required.');
        }

        return $this->whatsApp->sendText(null, PhoneNormalizer::toWhatsApp($phone, $this->defaultCountryCode), $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardSummary(): array
    {
        $total = (int)$this->pdo->query("SELECT COUNT(*) FROM parties WHERE type='customer' AND is_active=1")->fetchColumn();
        $stmt = $this->pdo->query("
            SELECT lead_source, COUNT(*) AS total
            FROM parties
            WHERE type='customer' AND is_active=1
            GROUP BY lead_source
        ");
        $bySource = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['lead_source'] ?: 'unspecified';
            $bySource[$key] = (int)$row['total'];
        }

        return [
            'customers' => $total,
            'by_lead_source' => $bySource,
            'whatsapp_ready' => $this->whatsApp->isReady(),
        ];
    }
}
