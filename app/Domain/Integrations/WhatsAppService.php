<?php

namespace App\Domain\Integrations;

use App\Infrastructure\Http\CurlHttpClient;
use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Persistence\SettingsRepository;
use App\Infrastructure\Persistence\WhatsAppMessageRepository;
use App\Infrastructure\WhatsApp\PhoneNormalizer;
use App\Infrastructure\WhatsApp\WhatsAppClient;
use App\Infrastructure\WhatsApp\WhatsAppWebhook;
use InvalidArgumentException;
use RuntimeException;

final class WhatsAppService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly WhatsAppMessageRepository $messages,
        private readonly WhatsAppWebhook $webhook = new WhatsAppWebhook(),
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBool('whatsapp.enabled');
    }

    public function isConfigured(): bool
    {
        return $this->settings->getString('whatsapp.access_token') !== ''
            && $this->settings->getString('whatsapp.phone_number_id') !== ''
            && $this->settings->getString('whatsapp.verify_token') !== '';
    }

    public function isReady(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    /**
     * @return array{ok:bool,message:string,details:array<string,mixed>}
     */
    public function testConnection(): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'WhatsApp integration is not activated.', 'details' => []];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'WhatsApp credentials are incomplete.', 'details' => []];
        }

        try {
            $info = $this->client()->getPhoneNumber();
            return [
                'ok' => true,
                'message' => 'WhatsApp Cloud API is reachable and the phone number is valid.',
                'details' => [
                    'display_phone_number' => $info['display_phone_number'] ?? null,
                    'verified_name' => $info['verified_name'] ?? null,
                    'quality_rating' => $info['quality_rating'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'details' => []];
        }
    }

    /**
     * @return array{id:int,wa_message_id:?string,phone:string}
     */
    public function sendText(?int $partyId, string $phone, string $body, ?int $actorId = null): array
    {
        if (!$this->isReady()) {
            throw new RuntimeException('WhatsApp integration is not activated.');
        }

        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('Message body is required.');
        }

        $normalized = PhoneNormalizer::toWhatsApp(
            $phone,
            $this->settings->getString('whatsapp.default_country_code', '20')
        );
        if ($normalized === '') {
            throw new InvalidArgumentException('A valid WhatsApp phone number is required.');
        }

        try {
            $result = $this->client()->sendText($normalized, $body);
            $waId = $result['messages'][0]['id'] ?? null;
            $id = $this->messages->insert(
                $partyId,
                'out',
                $normalized,
                $body,
                'sent',
                is_string($waId) ? $waId : null,
                null,
                $result,
                $actorId
            );

            return ['id' => $id, 'wa_message_id' => is_string($waId) ? $waId : null, 'phone' => $normalized];
        } catch (\Throwable $e) {
            $this->messages->insert($partyId, 'out', $normalized, $body, 'failed', null, $e->getMessage(), [], $actorId);
            throw $e;
        }
    }

    public function verifyWebhook(array $query): ?string
    {
        return $this->webhook->verifySubscription(
            $query,
            $this->settings->getString('whatsapp.verify_token')
        );
    }

    public function acceptWebhook(string $rawBody, ?string $signatureHeader, bool $isLocal): bool
    {
        $appSecret = $this->settings->getString('whatsapp.app_secret');
        if ($appSecret !== '') {
            return $this->webhook->verifySignature($rawBody, $signatureHeader, $appSecret);
        }

        return $isLocal;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function ingestWebhook(array $payload): int
    {
        $count = 0;
        foreach ($this->webhook->parseInboundMessages($payload) as $message) {
            if ($message['from'] === '' || $message['body'] === '') {
                continue;
            }
            $partyId = $this->messages->findPartyIdByPhone($message['from']);
            $this->messages->insert(
                $partyId,
                'in',
                $message['from'],
                $message['body'],
                'received',
                $message['id'],
                null,
                $message
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentForParty(int $partyId): array
    {
        return $this->messages->recentForParty($partyId);
    }

    private function client(): WhatsAppClient
    {
        $token = $this->settings->getString('whatsapp.access_token');
        $phoneNumberId = $this->settings->getString('whatsapp.phone_number_id');
        if ($token === '' || $phoneNumberId === '') {
            throw new RuntimeException('WhatsApp credentials are incomplete.');
        }

        return new WhatsAppClient(
            $this->http ?? new CurlHttpClient(),
            $this->settings->getString('whatsapp.graph_base', 'https://graph.facebook.com'),
            $this->settings->getString('whatsapp.api_version', 'v21.0'),
            $phoneNumberId,
            $token,
        );
    }
}
