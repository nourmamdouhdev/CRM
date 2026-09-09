<?php

use App\Infrastructure\Mcp\ClaudeClient;
use PHPUnit\Framework\TestCase;
use Tests\FakeHttpClient;

final class ClaudeClientTest extends TestCase
{
    public function test_messages_request_includes_mcp_server_descriptor(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, json_encode([
            'id' => 'msg_1',
            'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'PONG']],
        ], JSON_THROW_ON_ERROR));

        $client = new ClaudeClient(
            $http,
            'https://api.anthropic.com',
            'sk-test',
            '2023-06-01',
            'claude-sonnet-4-5',
            'mcp-client-2025-04-04',
        );

        $mcp = ClaudeClient::mcpServerDescriptor(
            'tagom-crm',
            'https://crm.example/mcp/index.php',
            'mcp-secret',
        );

        $message = $client->createMessage(
            [['role' => 'user', 'content' => 'Reply with PONG']],
            'Probe',
            32,
            [$mcp],
        );

        $this->assertSame('PONG', ClaudeClient::textFromMessage($message));
        $request = $http->requests[0];
        $this->assertSame('https://api.anthropic.com/v1/messages', $request['url']);
        $this->assertSame('sk-test', $request['headers']['x-api-key']);
        $this->assertSame('2023-06-01', $request['headers']['anthropic-version']);
        $this->assertSame('mcp-client-2025-04-04', $request['headers']['anthropic-beta']);

        $payload = json_decode((string)$request['body'], true);
        $this->assertSame('url', $payload['mcp_servers'][0]['type']);
        $this->assertSame('https://crm.example/mcp/index.php', $payload['mcp_servers'][0]['url']);
        $this->assertSame('mcp-secret', $payload['mcp_servers'][0]['authorization_token']);
    }
}
