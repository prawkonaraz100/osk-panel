<?php

namespace Tests\Unit;

use App\Support\Migrations\MigrationPlan;
use LogicException;
use Tests\TestCase;

class MigrationPlanContractTest extends TestCase
{
    public function test_stage_four_authority_resolves_to_exact_reviewable_plan(): void
    {
        $plan = new MigrationPlan;
        $plan->validate();

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(17, $plan->batchCount());
        $this->assertSame(45, $plan->implementedNodeCount());
        $this->assertSame(45, $plan->implementedStepCount());
        $this->assertSame('ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441', $plan->summary()['authority_blob']);
        $this->assertSame('59ed166d900d2701df2b8ca6e534b6b59d0de78ecf8fa0f04204f255020e5bcc', $plan->executionIdentity());
        $this->assertSame([
            'MIG-EXT-BTREE-GIST',
            'MIG-TBL-ORGANIZATIONS',
            'MIG-TBL-USERS',
            'MIG-TBL-PERMISSIONS',
            'MIG-TBL-DATA_SCOPES',
            'MIG-TBL-DRIVING_CATEGORIES',
            'MIG-TBL-LOCATION_TYPES',
            'MIG-TBL-STAFF_TYPES',
            'MIG-TBL-ORGANIZATION_SETTINGS',
            'MIG-TBL-ORGANIZATION_CONTACT_ADDRESSES',
            'MIG-TBL-AUTH_LOGIN_IDENTIFIERS',
            'MIG-TBL-ORGANIZATION_MEMBERSHIPS',
            'MIG-TBL-MEMBERSHIP_PERMISSIONS',
            'MIG-TBL-PERMISSION_SCOPE_OPTIONS',
            'MIG-TBL-MEMBERSHIP_PERMISSION_SCOPES',
            'MIG-TBL-AUTH_SESSIONS',
            'MIG-TBL-FILE_ASSETS',
            'MIG-TBL-IDEMPOTENCY_RECORDS',
            'MIG-TBL-LOCATIONS',
            'MIG-TBL-VEHICLES',
            'MIG-TBL-STAFF_PROFILES',
            'MIG-TBL-STAFF_TYPE_ASSIGNMENTS',
            'MIG-TBL-STAFF_CATEGORY_ASSIGNMENTS',
            'MIG-TBL-STAFF_LOCATION_ASSIGNMENTS',
            'MIG-TBL-STAFF_MEMBERSHIP_LINKS',
            'MIG-TBL-STAFF_DOCUMENTS',
            'MIG-TBL-VEHICLE_DOCUMENTS',
            'MIG-TBL-VEHICLE_CATEGORY_ASSIGNMENTS',
            'MIG-TBL-VEHICLE_LOCATION_ASSIGNMENTS',
            'MIG-TBL-STUDENTS',
            'MIG-TBL-COURSE_ENROLLMENTS',
            'MIG-TBL-COURSE_ENROLLMENT_LIFECYCLE_EVENTS',
            'MIG-TBL-TRAINING_REQUIREMENT_RULE_SETS',
            'MIG-TBL-COURSE_REQUIREMENT_CONTEXTS',
            'MIG-TBL-COURSE_REQUIREMENT_CONTEXT_HELD_CATEGORIES',
            'MIG-TBL-COURSE_REQUIREMENT_OVERRIDE_DECISIONS',
            'MIG-TBL-TRAINING_REQUIREMENT_PROFILES',
            'MIG-TBL-COURSE_EXEMPTION_DECISIONS',
            'MIG-TBL-RECOGNIZED_EXTERNAL_TRAINING',
            'MIG-TBL-PKK_PROFILES',
            'MIG-TBL-AUDIT_ACTION_POLICY_REVISIONS',
            'MIG-TBL-AUDIT_ACTION_POLICY_CURRENTS',
            'MIG-TBL-AUDIT_LOGS',
            'MIG-TBL-DOMAIN_EVENTS',
            'MIG-TBL-OUTBOX_MESSAGES',
        ], array_column($plan->phaseSteps('expand'), 'node_id'));
    }

    public function test_later_phase_is_closed_until_every_authoritative_earlier_phase_step_is_materialized_and_applied(): void
    {
        $plan = new MigrationPlan;
        $plan->validate();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No materialized migration steps for phase preflight');
        $plan->assertPhaseEntry('preflight', []);
    }
}
