<?php

namespace App\Domain\Leads;

use InvalidArgumentException;

final class LeadSource
{
    public const MARKETING_CAMPAIGN = 'marketing_campaign';
    public const COLD_LEAD = 'cold_lead';
    public const BUSINESS_CARDS = 'business_cards';
    public const MARKETING_AGENT = 'marketing_agent';
    public const PEOPLE_REFERRAL = 'people_referral';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::MARKETING_CAMPAIGN => 'Marketing Campaign',
            self::COLD_LEAD => 'Cold Lead',
            self::BUSINESS_CARDS => 'Business Cards',
            self::MARKETING_AGENT => 'Marketing Agent',
            self::PEOPLE_REFERRAL => 'people Referral',
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::options());
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return isset(self::options()[$value]);
    }

    public static function normalize(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (!self::isValid($value)) {
            throw new InvalidArgumentException('Invalid lead source.');
        }

        return $value;
    }

    public static function label(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return self::options()[$value] ?? $value;
    }
}
