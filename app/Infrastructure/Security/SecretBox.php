<?php

namespace App\Infrastructure\Security;

use RuntimeException;

final class SecretBox
{
    public function __construct(private readonly string $appKey)
    {
    }

    public static function fromConfig(array $config): self
    {
        $key = trim((string)($config['app']['key'] ?? ''));
        if ($key === '') {
            $key = self::persistentLocalKey();
        }

        return new self($key);
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $this->keyBytes(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Unable to encrypt secret.');
        }

        $mac = hash_hmac('sha256', $iv . $cipher, $this->keyBytes(), true);
        return 'enc:' . base64_encode($iv . $mac . $cipher);
    }

    public function decrypt(?string $stored): string
    {
        $stored = (string)$stored;
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, 'enc:')) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) < 49) {
            throw new RuntimeException('Stored secret is corrupted.');
        }

        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);
        $expected = hash_hmac('sha256', $iv . $cipher, $this->keyBytes(), true);
        if (!hash_equals($expected, $mac)) {
            throw new RuntimeException('Stored secret MAC mismatch.');
        }

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $this->keyBytes(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt secret.');
        }

        return $plain;
    }

    public function mask(string $value, int $visible = 4): string
    {
        $len = strlen($value);
        if ($len === 0) {
            return '';
        }
        if ($len <= $visible) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', max(4, $len - $visible)) . substr($value, -$visible);
    }

    private function keyBytes(): string
    {
        return hash('sha256', $this->appKey, true);
    }

    private static function persistentLocalKey(): string
    {
        $dir = dirname(__DIR__, 3) . '/storage';
        $file = $dir . '/app.key';
        if (is_file($file)) {
            $existing = trim((string)file_get_contents($file));
            if ($existing !== '') {
                return $existing;
            }
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $generated = bin2hex(random_bytes(32));
        @file_put_contents($file, $generated);
        return $generated;
    }
}
