<?php

use App\Infrastructure\WhatsApp\WhatsAppClient;
use PHPUnit\Framework\TestCase;
use Tests\FakeHttpClient;

final class WhatsAppClientTest extends TestCase
{
    public function test_send_text_posts_cloud_api_payload(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, json_encode([
            'messages' => [['id' => 'wamid.123']],
        ], JSON_THROW_ON_ERROR));

        $client = new WhatsAppClient(
            $http,
            'https://graph.facebook.com',
            'v21.0',
            '123456789',
            'test-token',
        );

        $result = $client->sendText('201011112222', 'Hello from CRM');

        $this->assertSame('wamid.123', $result['messages'][0]['id']);
        $this->assertCount(1, $http->requests);
        $this->assertSame('POST', $http->requests[0]['method']);
        $this->assertSame('https://graph.facebook.com/v21.0/123456789/messages', $http->requests[0]['url']);
        $this->assertSame('Bearer test-token', $http->requests[0]['headers']['Authorization']);

        $payload = json_decode((string)$http->requests[0]['body'], true);
        $this->assertSame('whatsapp', $payload['messaging_product']);
        $this->assertSame('201011112222', $payload['to']);
        $this->assertSame('text', $payload['type']);
        $this->assertSame('Hello from CRM', $payload['text']['body']);
    }

    public function test_phone_lookup_is_used_for_connection_test(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, json_encode([
            'verified_name' => 'Tagom',
            'display_phone_number' => '+20 101 111 2222',
        ], JSON_THROW_ON_ERROR));

        $client = new WhatsAppClient($http, 'https://graph.facebook.com', 'v21.0', '999', 'tok');
        $info = $client->getPhoneNumber();

        $this->assertSame('Tagom', $info['verified_name']);
        $this->assertSame('GET', $http->requests[0]['method']);
        $this->assertSame('https://graph.facebook.com/v21.0/999', $http->requests[0]['url']);
        $this->assertSame('verified_name,display_phone_number,quality_rating,code_verification_status', $http->requests[0]['query']['fields']);
    }

    public function test_graph_error_becomes_exception(): void
    {
        $http = new FakeHttpClient();
        $http->queue(401, json_encode([
            'error' => ['message' => 'Invalid OAuth access token'],
        ], JSON_THROW_ON_ERROR));

        $client = new WhatsAppClient($http, 'https://graph.facebook.com', 'v21.0', '999', 'bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid OAuth access token');
        $client->getPhoneNumber();
    }
}
