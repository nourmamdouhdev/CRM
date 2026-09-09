<?php

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Security\SecretBox;
use PDO;

final class SettingsRepository
{
    private const SECRET_KEYS = [
        'whatsapp.access_token',
        'whatsapp.app_secret',
        'claude.api_key',
        'mcp.bearer_token',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretBox $secrets,
        private readonly array $config,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->raw($key);
        if ($stored === null) {
            return $this->fromConfig($key, $default);
        }

        if ($this->isSecret($key)) {
            try {
                $decrypted = $this->secrets->decrypt($stored);
            } catch (\Throwable) {
                return $this->fromConfig($key, $default);
            }
            return $decrypted !== '' ? $decrypted : $this->fromConfig($key, $default);
        }

        if ($stored === '1' || $stored === '0') {
            return $stored === '1';
        }

        return $stored;
    }

    public function getString(string $key, string $default = ''): string
    {
        return trim((string)$this->get($key, $default));
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->isSecret($key)) {
            $plain = trim((string)$value);
            if ($plain === '') {
                return;
            }
            $value = $this->secrets->encrypt($plain);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } else {
            $value = (string)$value;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO integration_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$key, $value]);
    }

    public function forget(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM integration_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
    }

    public function mask(string $key): string
    {
        return $this->secrets->mask($this->getString($key));
    }

    private function raw(string $key): ?string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT setting_value FROM integration_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            return $row['setting_value'] === null ? null : (string)$row['setting_value'];
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    private function fromConfig(string $key, mixed $default): mixed
    {
        return match ($key) {
            'whatsapp.enabled' => (bool)($this->config['whatsapp']['enabled'] ?? $default),
            'whatsapp.access_token' => (string)($this->config['whatsapp']['access_token'] ?? $default ?? ''),
            'whatsapp.phone_number_id' => (string)($this->config['whatsapp']['phone_number_id'] ?? $default ?? ''),
            'whatsapp.business_account_id' => (string)($this->config['whatsapp']['business_account_id'] ?? $default ?? ''),
            'whatsapp.verify_token' => (string)($this->config['whatsapp']['verify_token'] ?? $default ?? ''),
            'whatsapp.app_secret' => (string)($this->config['whatsapp']['app_secret'] ?? $default ?? ''),
            'whatsapp.api_version' => (string)($this->config['whatsapp']['api_version'] ?? $default ?? 'v21.0'),
            'whatsapp.graph_base' => (string)($this->config['whatsapp']['graph_base'] ?? $default ?? 'https://graph.facebook.com'),
            'whatsapp.default_country_code' => (string)($this->config['whatsapp']['default_country_code'] ?? $default ?? '20'),
            'mcp.enabled' => (bool)($this->config['mcp']['enabled'] ?? $default),
            'mcp.bearer_token' => (string)($this->config['mcp']['bearer_token'] ?? $default ?? ''),
            'mcp.protocol_version' => (string)($this->config['mcp']['protocol_version'] ?? $default ?? '2025-11-25'),
            'mcp.server_name' => (string)($this->config['mcp']['server_name'] ?? $default ?? 'tagom-crm'),
            'claude.enabled' => (bool)($this->config['claude']['enabled'] ?? $default),
            'claude.api_key' => (string)($this->config['claude']['api_key'] ?? $default ?? ''),
            'claude.api_base' => (string)($this->config['claude']['api_base'] ?? $default ?? 'https://api.anthropic.com'),
            'claude.api_version' => (string)($this->config['claude']['api_version'] ?? $default ?? '2023-06-01'),
            'claude.model' => (string)($this->config['claude']['model'] ?? $default ?? 'claude-sonnet-4-5'),
            'claude.mcp_beta' => (string)($this->config['claude']['mcp_beta'] ?? $default ?? 'mcp-client-2025-04-04'),
            default => $default,
        };
    }
}
