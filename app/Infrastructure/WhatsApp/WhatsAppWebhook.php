<?php

namespace App\Infrastructure\WhatsApp;

final class WhatsAppWebhook
{
    public function verifySubscription(array $query, string $expectedToken): ?string
    {
        $mode = (string)($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string)($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string)($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        if ($mode !== 'subscribe' || $challenge === '') {
            return null;
        }

        if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
            return null;
        }

        return $challenge;
    }

    public function verifySignature(string $rawBody, ?string $header, string $appSecret): bool
    {
        if ($appSecret === '') {
            return false;
        }
        if ($header === null || $header === '') {
            return false;
        }

        $provided = $header;
        if (str_starts_with(strtolower($header), 'sha256=')) {
            $provided = substr($header, 7);
        }

        $expected = hash_hmac('sha256', $rawBody, $appSecret);
        return hash_equals($expected, $provided);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{from:string,id:?string,body:string,timestamp:?string}>
     */
    public function parseInboundMessages(array $payload): array
    {
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return [];
        }

        $messages = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['messages'] ?? [] as $message) {
                    $type = (string)($message['type'] ?? '');
                    $body = '';
                    if ($type === 'text') {
                        $body = (string)($message['text']['body'] ?? '');
                    } elseif ($type !== '') {
                        $body = '[' . $type . ']';
                    }

                    $messages[] = [
                        'from' => (string)($message['from'] ?? ''),
                        'id' => isset($message['id']) ? (string)$message['id'] : null,
                        'body' => $body,
                        'timestamp' => isset($message['timestamp']) ? (string)$message['timestamp'] : null,
                    ];
                }
            }
        }

        return $messages;
    }
}
