<?php

namespace App\Infrastructure\Mcp;

final class McpServer
{
    public const PROTOCOL_LATEST = '2025-11-25';

    /** @var list<string> */
    public const SUPPORTED_PROTOCOLS = [
        '2025-11-25',
        '2025-03-26',
        '2024-11-05',
    ];

    /**
     * @param array<string, array{description:string,title?:string,inputSchema:array<string,mixed>,handler:callable}> $tools
     */
    public function __construct(
        private readonly array $tools,
        private readonly string $serverName = 'tagom-crm',
        private readonly string $serverVersion = '1.0.0',
    ) {
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        if (($message['jsonrpc'] ?? '') !== '2.0') {
            return $this->error(null, -32600, 'Invalid Request: jsonrpc must be 2.0');
        }

        $id = $message['id'] ?? null;
        $method = (string)($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($method === '') {
            return $this->error($id, -32600, 'Invalid Request: method is required');
        }

        if ($id === null) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, $this->listTools()),
            'tools/call' => $this->result($id, $this->callTool($params)),
            default => $this->error($id, -32601, 'Method not found: ' . $method),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string)($params['protocolVersion'] ?? self::PROTOCOL_LATEST);
        $protocol = in_array($requested, self::SUPPORTED_PROTOCOLS, true)
            ? $requested
            : self::PROTOCOL_LATEST;

        return [
            'protocolVersion' => $protocol,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'title' => 'Tagom CRM',
                'version' => $this->serverVersion,
            ],
            'instructions' => 'Internal Tagom CRM MCP server. Use tools to search customers, inspect lead sources, and send WhatsApp messages.',
        ];
    }

    /**
     * @return array{tools: list<array<string, mixed>>}
     */
    private function listTools(): array
    {
        $tools = [];
        foreach ($this->tools as $name => $tool) {
            $tools[] = [
                'name' => $name,
                'title' => $tool['title'] ?? $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{content: list<array{type:string,text:string}>, isError: bool}
     */
    private function callTool(array $params): array
    {
        $name = (string)($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === '' || !isset($this->tools[$name])) {
            return [
                'content' => [['type' => 'text', 'text' => 'Unknown tool: ' . $name]],
                'isError' => true,
            ];
        }

        try {
            $output = ($this->tools[$name]['handler'])($arguments);
            if (!is_string($output)) {
                $output = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
            }

            return [
                'content' => [['type' => 'text', 'text' => $output]],
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    /**
     * @param mixed $id
     * @param mixed $result
     * @return array<string, mixed>
     */
    private function result(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param mixed $id
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
