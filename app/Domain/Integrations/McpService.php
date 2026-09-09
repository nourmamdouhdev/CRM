<?php

namespace App\Domain\Integrations;

use App\Infrastructure\Http\CurlHttpClient;
use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Mcp\ClaudeClient;
use App\Infrastructure\Mcp\McpAuth;
use App\Infrastructure\Mcp\McpServer;
use App\Infrastructure\Persistence\SettingsRepository;
use RuntimeException;

final class McpService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CrmMcpTools $tools,
        private readonly McpAuth $auth = new McpAuth(),
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBool('mcp.enabled');
    }

    public function isConfigured(): bool
    {
        return $this->settings->getString('mcp.bearer_token') !== '';
    }

    public function claudeEnabled(): bool
    {
        return $this->settings->getBool('claude.enabled');
    }

    public function claudeConfigured(): bool
    {
        return $this->settings->getString('claude.api_key') !== '';
    }

    public function isReady(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    public function authorize(?string $authorizationHeader, ?string $queryToken = null): bool
    {
        if (!$this->isReady()) {
            return false;
        }

        $provided = $this->auth->extractBearer($authorizationHeader, $queryToken);
        return $this->auth->verify($provided, $this->settings->getString('mcp.bearer_token'));
    }

    public function server(): McpServer
    {
        return new McpServer(
            $this->tools->definitions(),
            $this->settings->getString('mcp.server_name', 'tagom-crm'),
        );
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        return $this->server()->handle($message);
    }

    /**
     * @return array{ok:bool,message:string,details:array<string,mixed>}
     */
    public function testProtocol(): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'MCP connectivity is not activated.', 'details' => []];
        }

        $server = $this->server();
        $init = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => McpServer::PROTOCOL_LATEST,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'tagom-self-test', 'version' => '1.0.0'],
            ],
        ]);
        $ping = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);
        $tools = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
        $call = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'list_lead_sources', 'arguments' => new \stdClass()],
        ]);

        $toolCount = count($tools['result']['tools'] ?? []);
        $ok = isset($init['result']['protocolVersion'])
            && isset($ping['result'])
            && $toolCount > 0
            && ($call['result']['isError'] ?? true) === false;

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'MCP protocol handshake, ping, tools/list, and tools/call succeeded.'
                : 'MCP protocol self-test failed.',
            'details' => [
                'protocolVersion' => $init['result']['protocolVersion'] ?? null,
                'tool_count' => $toolCount,
                'lead_sources_ok' => ($call['result']['isError'] ?? true) === false,
            ],
        ];
    }

    /**
     * @return array{ok:bool,message:string,details:array<string,mixed>}
     */
    public function testClaude(?string $mcpUrl = null): array
    {
        if (!$this->claudeEnabled()) {
            return ['ok' => false, 'message' => 'Claude connectivity is not activated.', 'details' => []];
        }
        if (!$this->claudeConfigured()) {
            return ['ok' => false, 'message' => 'Claude API key is missing.', 'details' => []];
        }

        try {
            $mcpServers = [];
            if ($mcpUrl && $this->isReady()) {
                $mcpServers[] = ClaudeClient::mcpServerDescriptor(
                    $this->settings->getString('mcp.server_name', 'tagom-crm'),
                    $mcpUrl,
                    $this->settings->getString('mcp.bearer_token'),
                );
            }

            $client = $this->claudeClient();
            $response = $client->createMessage(
                [['role' => 'user', 'content' => 'Reply with the single word PONG.']],
                'You are a connectivity probe for Tagom CRM. Reply with PONG only.',
                32,
                $mcpServers,
            );
            $text = ClaudeClient::textFromMessage($response);

            return [
                'ok' => true,
                'message' => 'Claude Messages API accepted the request.',
                'details' => [
                    'model' => $response['model'] ?? $this->settings->getString('claude.model'),
                    'reply' => $text,
                    'mcp_attached' => $mcpServers !== [],
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'details' => []];
        }
    }

    public function draftMessage(string $prompt): string
    {
        if (!$this->claudeEnabled() || !$this->claudeConfigured()) {
            throw new RuntimeException('Claude is not configured.');
        }

        $response = $this->claudeClient()->createMessage(
            [['role' => 'user', 'content' => $prompt]],
            'You write short professional WhatsApp messages for a CRM. Return only the message text.',
            200,
        );

        $text = ClaudeClient::textFromMessage($response);
        if ($text === '') {
            throw new RuntimeException('Claude returned an empty draft.');
        }

        return $text;
    }

    private function claudeClient(): ClaudeClient
    {
        return new ClaudeClient(
            $this->http ?? new CurlHttpClient(),
            $this->settings->getString('claude.api_base', 'https://api.anthropic.com'),
            $this->settings->getString('claude.api_key'),
            $this->settings->getString('claude.api_version', '2023-06-01'),
            $this->settings->getString('claude.model', 'claude-sonnet-4-5'),
            $this->settings->getString('claude.mcp_beta', 'mcp-client-2025-04-04'),
        );
    }
}
