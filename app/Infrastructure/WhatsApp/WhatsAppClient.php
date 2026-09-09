<?php

namespace App\Infrastructure\WhatsApp;

use App\Infrastructure\Http\HttpClientInterface;
use RuntimeException;

final class WhatsAppClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $graphBase,
        private readonly string $apiVersion,
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body): array
    {
        $payload = self::textMessagePayload($to, $body);
        return $this->postJson($this->phoneNumberId . '/messages', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPhoneNumber(): array
    {
        return $this->getJson($this->phoneNumberId, [
            'fields' => 'verified_name,display_phone_number,quality_rating,code_verification_status',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function textMessagePayload(string $to, string $body): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $body,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $payload): array
    {
        $response = $this->http->request(
            'POST',
            $this->endpoint($path),
            $this->headers(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        return $this->decodeOrFail($response->status, $response->json(), $response->body);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        $response = $this->http->request(
            'GET',
            $this->endpoint($path),
            $this->headers(),
            null,
            $query,
        );

        return $this->decodeOrFail($response->status, $response->json(), $response->body);
    }

    private function endpoint(string $path): string
    {
        $base = rtrim($this->graphBase, '/');
        $version = trim($this->apiVersion, '/');
        return $base . '/' . $version . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function decodeOrFail(int $status, array $json, string $raw): array
    {
        if ($status >= 200 && $status < 300 && !isset($json['error'])) {
            return $json;
        }

        $message = (string)($json['error']['message'] ?? ('WhatsApp API error HTTP ' . $status));
        throw new RuntimeException($message . ($raw !== '' && !isset($json['error']) ? ': ' . $raw : ''));
    }
}
