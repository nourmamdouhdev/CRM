<?php

use App\Infrastructure\WhatsApp\WhatsAppWebhook;
use PHPUnit\Framework\TestCase;

final class WhatsAppWebhookTest extends TestCase
{
    public function test_subscription_challenge_is_returned_when_token_matches(): void
    {
        $webhook = new WhatsAppWebhook();
        $challenge = $webhook->verifySubscription([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'secret-token',
            'hub.challenge' => '12345',
        ], 'secret-token');

        $this->assertSame('12345', $challenge);
    }

    public function test_subscription_rejects_wrong_token_and_php_underscore_keys(): void
    {
        $webhook = new WhatsAppWebhook();
        $this->assertNull($webhook->verifySubscription([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong',
            'hub_challenge' => '12345',
        ], 'secret-token'));

        $this->assertSame('99', $webhook->verifySubscription([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'secret-token',
            'hub_challenge' => '99',
        ], 'secret-token'));
    }

    public function test_signature_uses_app_secret_hmac(): void
    {
        $webhook = new WhatsAppWebhook();
        $body = '{"object":"whatsapp_business_account"}';
        $secret = 'app-secret';
        $header = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue($webhook->verifySignature($body, $header, $secret));
        $this->assertFalse($webhook->verifySignature($body, $header, 'other'));
        $this->assertFalse($webhook->verifySignature($body, null, $secret));
    }

    public function test_inbound_text_messages_are_parsed(): void
    {
        $webhook = new WhatsAppWebhook();
        $messages = $webhook->parseInboundMessages([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '201011112222',
                            'id' => 'wamid.abc',
                            'timestamp' => '1710000000',
                            'type' => 'text',
                            'text' => ['body' => 'Hello CRM'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $this->assertCount(1, $messages);
        $this->assertSame('201011112222', $messages[0]['from']);
        $this->assertSame('Hello CRM', $messages[0]['body']);
        $this->assertSame('wamid.abc', $messages[0]['id']);
    }
}
