<?php

namespace App\Domain\Integrations;

final class IntegrationFactory
{
    public static function settings(\PDO $pdo, array $config): \App\Infrastructure\Persistence\SettingsRepository
    {
        return new \App\Infrastructure\Persistence\SettingsRepository(
            $pdo,
            \App\Infrastructure\Security\SecretBox::fromConfig($config),
            $config,
        );
    }

    public static function whatsApp(\PDO $pdo, array $config, ?\App\Infrastructure\Http\HttpClientInterface $http = null): WhatsAppService
    {
        $settings = self::settings($pdo, $config);
        return new WhatsAppService(
            $settings,
            new \App\Infrastructure\Persistence\WhatsAppMessageRepository($pdo),
            new \App\Infrastructure\WhatsApp\WhatsAppWebhook(),
            $http,
        );
    }

    public static function mcp(\PDO $pdo, array $config, ?\App\Infrastructure\Http\HttpClientInterface $http = null): McpService
    {
        $settings = self::settings($pdo, $config);
        $whatsApp = self::whatsApp($pdo, $config, $http);
        $tools = new CrmMcpTools(
            $pdo,
            $whatsApp,
            $settings->getString('whatsapp.default_country_code', '20'),
        );

        return new McpService($settings, $tools, new \App\Infrastructure\Mcp\McpAuth(), $http);
    }
}
