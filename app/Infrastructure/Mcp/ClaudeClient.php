<?php

namespace App\Infrastructure\Mcp;

use App\Infrastructure\Http\HttpClientInterface;
use RuntimeException;

final class ClaudeClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiBase,
        private readonly string $apiKey,
        private readonly string $apiVersion,
        private readonly string $model,
        private readonly string $mcpBeta,
    ) {
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param list<array<string, mixed>> $mcpServers
     * @return array<string, mixed>
     */
    public function createMessage(
        array $messages,
        ?string $system = null,
        int $maxTokens = 256,
        array $mcpServers = [],
    ): array {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];
        if ($system !== null && $system !== '') {
            $payload['system'] = $system;
        }
        if ($mcpServers !== []) {
            $payload['mcp_servers'] = $mcpServers;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
        ];
        if ($mcpServers !== []) {
            $headers['anthropic-beta'] = $this->mcpBeta;
        }

        $response = $this->http->request(
            'POST',
            rtrim($this->apiBase, '/') . '/v1/messages',
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            [],
            45,
        );

        $json = $response->json();
        if (!$response->ok() || isset($json['error'])) {
            $message = (string)($json['error']['message'] ?? ('Claude API error HTTP ' . $response->status));
            throw new RuntimeException($message);
        }

        return $json;
    }

    public static function textFromMessage(array $message): string
    {
        $chunks = [];
        foreach ($message['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'text') {
                $chunks[] = (string)($part['text'] ?? '');
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @return array<string, mixed>
     */
    public static function mcpServerDescriptor(string $name, string $url, string $token): array
    {
        return [
            'type' => 'url',
            'name' => $name,
            'url' => $url,
            'authorization_token' => $token,
        ];
    }
}
