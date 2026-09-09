<?php

use App\Infrastructure\Mcp\McpAuth;
use App\Infrastructure\Mcp\McpServer;
use PHPUnit\Framework\TestCase;

final class McpServerTest extends TestCase
{
    private function server(): McpServer
    {
        return new McpServer([
            'list_lead_sources' => [
                'title' => 'List lead sources',
                'description' => 'Lead sources',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'handler' => static fn () => ['cold_lead' => 'Cold Lead'],
            ],
            'ping_crm' => [
                'description' => 'Ping',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'handler' => static fn () => 'ok',
            ],
        ], 'tagom-crm');
    }

    public function test_initialize_negotiates_supported_protocol(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'claude', 'version' => '1'],
            ],
        ]);

        $this->assertSame('2025-11-25', $response['result']['protocolVersion']);
        $this->assertSame('tagom-crm', $response['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    public function test_ping_tools_list_and_call_succeed(): void
    {
        $server = $this->server();

        $ping = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);
        $this->assertEquals(new stdClass(), $ping['result']);

        $listed = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
        $names = array_column($listed['result']['tools'], 'name');
        $this->assertContains('list_lead_sources', $names);

        $called = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'list_lead_sources', 'arguments' => []],
        ]);
        $this->assertFalse($called['result']['isError']);
        $this->assertStringContainsString('Cold Lead', $called['result']['content'][0]['text']);
    }

    public function test_unknown_method_is_json_rpc_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'resources/list',
        ]);

        $this->assertSame(-32601, $response['error']['code']);
    }

    public function test_bearer_auth_is_constant_time_and_rejects_empty(): void
    {
        $auth = new McpAuth();
        $this->assertSame('abc', $auth->extractBearer('Bearer abc'));
        $this->assertTrue($auth->verify('secret-token', 'secret-token'));
        $this->assertFalse($auth->verify('nope', 'secret-token'));
        $this->assertFalse($auth->verify('', 'secret-token'));
        $this->assertFalse($auth->verify('secret-token', ''));
    }
}
