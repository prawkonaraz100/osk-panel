<?php

namespace Tests\Unit;

use App\Support\Operations\RecoveryPolicy;
use Tests\TestCase;

final class RecoveryPolicyTest extends TestCase
{
    public function test_core_recovery_targets_are_explicit_and_postgresql_is_tier_zero_authority(): void
    {
        $policy = app(RecoveryPolicy::class);

        $this->assertSame('2026-09-13-v1', $policy->version());
        $this->assertSame(5, $policy->rpoMinutes('tier0_postgresql_business_authority'));
        $this->assertSame(60, $policy->rtoMinutes('tier0_postgresql_business_authority'));
        $this->assertTrue($policy->isSourceOfTruth('tier0_postgresql_business_authority'));

        $this->assertSame(60, $policy->rpoMinutes('tier1_formal_object_assets'));
        $this->assertSame(240, $policy->rtoMinutes('tier1_formal_object_assets'));
        $this->assertTrue($policy->isSourceOfTruth('tier1_formal_object_assets'));
    }

    public function test_rebuildable_projections_and_redis_are_not_durable_authority(): void
    {
        $policy = app(RecoveryPolicy::class);

        $this->assertNull($policy->rpoMinutes('tier2_rebuildable_projections'));
        $this->assertSame(240, $policy->rtoMinutes('tier2_rebuildable_projections'));
        $this->assertFalse($policy->isSourceOfTruth('tier2_rebuildable_projections'));

        $this->assertNull($policy->rpoMinutes('ephemeral_redis'));
        $this->assertSame(30, $policy->rtoMinutes('ephemeral_redis'));
        $this->assertFalse($policy->isSourceOfTruth('ephemeral_redis'));
    }

    public function test_go_live_requires_a_measured_restore_drill_and_quarterly_repetition(): void
    {
        $policy = app(RecoveryPolicy::class);

        $this->assertTrue($policy->goLiveRequiresRestoreDrill());
        $this->assertSame(90, $policy->drillIntervalDays());
        $this->assertTrue((bool) config('recovery.drill.requires_database_restore'));
        $this->assertTrue((bool) config('recovery.drill.requires_formal_object_restore'));
        $this->assertTrue((bool) config('recovery.drill.requires_measured_rpo_rto'));
        $this->assertFalse((bool) config('recovery.drill.redis_restore_required'));
    }
}
