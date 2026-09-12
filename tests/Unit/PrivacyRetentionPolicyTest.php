<?php

namespace Tests\Unit;

use App\Support\Privacy\RetentionPolicy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class PrivacyRetentionPolicyTest extends TestCase
{
    public function test_statutory_and_technical_retention_clocks_are_explicit_and_no_global_delete_exists(): void
    {
        $policy = app(RetentionPolicy::class);

        $this->assertSame('2026-09-12-v1', $policy->version());
        $this->assertNull($policy->globalDeleteAfterDays());
        $this->assertFalse($policy->mayNormalApplicationRolePurge());

        $anchor = CarbonImmutable::parse('2026-09-12T12:00:00+02:00');
        $this->assertSame('2036-09-12T12:00:00+02:00', $policy->eligibleAt('formal_training_register', $anchor)?->toIso8601String());
        $this->assertSame('2028-09-12T12:00:00+02:00', $policy->eligibleAt('lesson_card_detail', $anchor)?->toIso8601String());
        $this->assertSame('2026-10-12T12:00:00+02:00', $policy->eligibleAt('student_contact_operational', $anchor)?->toIso8601String());
        $this->assertSame('2026-10-12T12:00:00+02:00', $policy->eligibleAt('outbox_published', $anchor)?->toIso8601String());
    }

    public function test_accounting_clock_starts_from_following_year_and_legal_hold_blocks_eligibility(): void
    {
        $policy = app(RetentionPolicy::class);
        $fiscalYearEnd = CarbonImmutable::parse('2026-12-31T23:59:59+01:00');

        $this->assertSame('2032-01-01T00:00:00+01:00', $policy->eligibleAt('finance_accounting_records', $fiscalYearEnd)?->toIso8601String());
        $this->assertNull($policy->eligibleAt('formal_training_register', CarbonImmutable::parse('2026-09-12T12:00:00+02:00'), true));
        $this->assertNull($policy->eligibleAt('audit_security_evidence', CarbonImmutable::parse('2026-09-12T12:00:00+02:00'), true));
    }

    public function test_inherited_and_deferred_classes_require_domain_or_external_authority_before_purge(): void
    {
        $policy = app(RetentionPolicy::class);
        $anchor = CarbonImmutable::parse('2026-09-12T12:00:00+02:00');

        $this->assertNull($policy->eligibleAt('student_formal_identity', $anchor));
        $this->assertNull($policy->eligibleAt('internal_exam_result_summary', $anchor));
        $this->assertNull($policy->eligibleAt('provider_pkk_raw_payload', $anchor));

        $provider = $policy->definition('provider_pkk_raw_payload');
        $this->assertSame('collection_forbidden_until_PWPW_provider_policy_is_verified', $provider['disposition']);

        $outbox = $policy->definition('outbox_published');
        $this->assertSame('published', $outbox['automatic_conditions']['publication_state']);
    }

    public function test_policy_matches_osk_formal_retention_and_minimization_boundaries(): void
    {
        $formal = config('retention.classes.formal_training_register');
        $cards = config('retention.classes.lesson_card_detail');
        $contact = config('retention.classes.student_contact_operational');

        $this->assertSame(120, $formal['clock']['value']);
        $this->assertSame('Dz.U. 2018 poz. 1885 par_18_ust_1_pkt_1', $formal['source']);
        $this->assertSame(24, $cards['clock']['value']);
        $this->assertStringContainsString('hours_summary', $cards['disposition']);
        $this->assertSame(30, $contact['clock']['value']);
        $this->assertTrue((bool) config('retention.legal_hold_overrides_retention'));
    }
}
