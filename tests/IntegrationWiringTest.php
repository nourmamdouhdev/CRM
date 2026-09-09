<?php

use App\Domain\Integrations\McpService;
use App\Domain\Integrations\CrmMcpTools;
use App\Domain\Integrations\WhatsAppService;
use App\Infrastructure\Persistence\SettingsRepository;
use App\Infrastructure\Persistence\WhatsAppMessageRepository;
use App\Infrastructure\Security\SecretBox;
use App\Infrastructure\WhatsApp\WhatsAppWebhook;
use PHPUnit\Framework\TestCase;
use Tests\FakeHttpClient;

final class IntegrationWiringTest extends TestCase
{
    public function test_mcp_self_test_and_whatsapp_graph_test_succeed_with_fakes(): void
    {
        $pdo = $this->sqlite();
        $config = [
            'app' => ['key' => 'test-key'],
            'whatsapp' => [
                'enabled' => true,
                'graph_base' => 'https://graph.facebook.com',
                'api_version' => 'v21.0',
                'access_token' => 'token',
                'phone_number_id' => '111',
                'verify_token' => 'verify',
                'app_secret' => 'secret',
                'default_country_code' => '20',
            ],
            'mcp' => [
                'enabled' => true,
                'bearer_token' => 'mcp-token',
                'server_name' => 'tagom-crm',
            ],
            'claude' => [
                'enabled' => true,
                'api_key' => 'sk-test',
                'api_base' => 'https://api.anthropic.com',
                'api_version' => '2023-06-01',
                'model' => 'claude-sonnet-4-5',
                'mcp_beta' => 'mcp-client-2025-04-04',
            ],
        ];

        $settings = new SettingsRepository($pdo, new SecretBox('test-key'), $config);
        $http = new FakeHttpClient();
        $http->queue(200, json_encode([
            'verified_name' => 'Tagom CRM',
            'display_phone_number' => '+20 100 000 0000',
            'quality_rating' => 'GREEN',
        ], JSON_THROW_ON_ERROR));

        $whatsApp = new WhatsAppService(
            $settings,
            new WhatsAppMessageRepository($pdo),
            new WhatsAppWebhook(),
            $http,
        );
        $mcp = new McpService($settings, new CrmMcpTools($pdo, $whatsApp), new \App\Infrastructure\Mcp\McpAuth(), $http);

        $waTest = $whatsApp->testConnection();
        $mcpTest = $mcp->testProtocol();

        $this->assertTrue($waTest['ok'], $waTest['message']);
        $this->assertTrue($mcpTest['ok'], $mcpTest['message']);
        $this->assertGreaterThanOrEqual(5, $mcpTest['details']['tool_count']);
        $this->assertTrue($mcp->authorize('Bearer mcp-token'));
        $this->assertFalse($mcp->authorize('Bearer wrong'));
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE integration_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE parties (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT,
            name TEXT,
            phone TEXT,
            address TEXT,
            notes TEXT,
            lead_source TEXT,
            opening_balance REAL DEFAULT 0,
            opening_balance_type TEXT DEFAULT "debit",
            is_active INTEGER DEFAULT 1,
            created_at TEXT
        )');
        $pdo->exec('CREATE TABLE whatsapp_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            party_id INTEGER,
            direction TEXT,
            phone TEXT,
            wa_message_id TEXT,
            body TEXT,
            status TEXT,
            error_message TEXT,
            payload_json TEXT,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec("INSERT INTO parties (type, name, phone, lead_source, is_active) VALUES ('customer', 'Ada', '01011112222', 'cold_lead', 1)");
        return $pdo;
    }
}
