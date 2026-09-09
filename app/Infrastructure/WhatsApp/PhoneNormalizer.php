<?php

namespace App\Infrastructure\WhatsApp;

final class PhoneNormalizer
{
    public static function digits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    public static function toWhatsApp(string $phone, string $defaultCountryCode = '20'): string
    {
        $digits = self::digits($phone);
        $country = preg_replace('/\D+/', '', $defaultCountryCode) ?: '20';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, $country)) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return $country . substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return $country . $digits;
        }

        return $digits;
    }
}
