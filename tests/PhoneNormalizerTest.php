<?php

use App\Infrastructure\WhatsApp\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

final class PhoneNormalizerTest extends TestCase
{
    public function test_egyptian_local_number_gets_country_code(): void
    {
        $this->assertSame('201012345678', PhoneNormalizer::toWhatsApp('01012345678', '20'));
        $this->assertSame('201012345678', PhoneNormalizer::toWhatsApp('+20 10 1234 5678', '20'));
        $this->assertSame('201012345678', PhoneNormalizer::toWhatsApp('00201012345678', '20'));
    }

    public function test_already_international_stays_stable(): void
    {
        $this->assertSame('201012345678', PhoneNormalizer::toWhatsApp('201012345678', '20'));
    }
}
