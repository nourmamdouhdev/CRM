<?php

use App\Domain\Leads\LeadSource;
use PHPUnit\Framework\TestCase;

final class LeadSourceTest extends TestCase
{
    public function test_options_match_required_labels(): void
    {
        $this->assertSame([
            'marketing_campaign' => 'Marketing Campaign',
            'cold_lead' => 'Cold Lead',
            'business_cards' => 'Business Cards',
            'marketing_agent' => 'Marketing Agent',
            'people_referral' => 'people Referral',
        ], LeadSource::options());
    }

    public function test_normalize_accepts_each_option_and_blank(): void
    {
        foreach (LeadSource::values() as $value) {
            $this->assertSame($value, LeadSource::normalize($value));
        }

        $this->assertNull(LeadSource::normalize(''));
        $this->assertNull(LeadSource::normalize(null));
    }

    public function test_normalize_rejects_unknown_source(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LeadSource::normalize('walk_in');
    }
}
