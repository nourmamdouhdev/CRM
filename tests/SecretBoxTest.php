<?php

use App\Infrastructure\Security\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function test_round_trip_and_tamper_detection(): void
    {
        $box = new SecretBox('unit-test-key');
        $stored = $box->encrypt('wa-access-token');

        $this->assertStringStartsWith('enc:', $stored);
        $this->assertSame('wa-access-token', $box->decrypt($stored));
        $this->assertStringContainsString('oken', $box->mask('wa-access-token'));

        $this->expectException(RuntimeException::class);
        $box->decrypt($stored . 'x');
    }
}
