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
        $this->assertSame(126, $plan->implementedNodeCount());
        $this->assertSame(134, $plan->implementedStepCount());
        $this->assertSame('ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441', $plan->summary()['authority_blob']);
        $this->assertSame('6f62bc3f68cf89943cddefe3186baf337f3e90d3afe76d9c6bb6475f8f48e654', $plan->executionIdentity());
        $this->assertSame([
            'MIG-EXT-BTREE-GIST',
            'MIG-TBL-ORGANIZATIONS',
            'MIG-TBL-USERS',
            'MIG-TBL-PERMISSIONS',
            'MIG-TBL-DATA_SCOPES',
            'MIG-TBL-LANGUAGES',
            'MIG-TBL-DRIVING_CATEGORIES',
            'MIG-TBL-LOCATION_TYPES',
            'MIG-TBL-STAFF_TYPES',
            'MIG-TBL-INTERNAL_EXAM_CAPABILITIES',
            'MIG-TBL-LEGAL_DOCUMENTS',
            'MIG-TBL-ORGANIZATION_SETTINGS',
            'MIG-TBL-ORGANIZATION_CONTACT_ADDRESSES',
            'MIG-TBL-USER_PASSWORD_MANAGEMENT',
            'MIG-TBL-AUTH_LOGIN_IDENTIFIERS',
            'MIG-TBL-AUTH_SOCIAL_ACCOUNTS',
            'MIG-TBL-ORGANIZATION_MEMBERSHIPS',
            'MIG-TBL-MEMBERSHIP_PERMISSIONS',
            'MIG-TBL-PERMISSION_SCOPE_OPTIONS',
            'MIG-TBL-MEMBERSHIP_PERMISSION_SCOPES',
            'MIG-TBL-AUTH_SESSIONS',
            'MIG-TBL-ACCOUNT_CLOSURE_REQUESTS',
            'MIG-TBL-TERMS_ACCEPTANCES',
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
            'MIG-TBL-STUDENT_LEARNING_ACCOUNTS',
            'MIG-TBL-STUDENT_ACCESS_HANDOFFS',
            'MIG-TBL-STUDENT_ACCESS_EXPORT_BATCHES',
            'MIG-TBL-COURSE_ENROLLMENTS',
            'MIG-TBL-COURSE_ENROLLMENT_LIFECYCLE_EVENTS',
            'MIG-TBL-TRAINING_REQUIREMENT_RULE_SETS',
            'MIG-TBL-COURSE_REQUIREMENT_CONTEXTS',
            'MIG-TBL-COURSE_REQUIREMENT_CONTEXT_HELD_CATEGORIES',
            'MIG-TBL-COURSE_REQUIREMENT_OVERRIDE_DECISIONS',
            'MIG-TBL-TRAINING_REQUIREMENT_PROFILES',
            'MIG-TBL-COURSE_EXEMPTION_DECISIONS',
            'MIG-TBL-RECOGNIZED_EXTERNAL_TRAINING',
            'MIG-TBL-TRAINING_SESSIONS',
            'MIG-TBL-TRAINING_SESSION_ATTENDANCE',
            'MIG-TBL-TRAINING_HOUR_LEDGER_ENTRIES',
            'MIG-TBL-CALENDAR_EVENTS',
            'MIG-TBL-CALENDAR_EVENT_LIFECYCLE_EVENTS',
            'MIG-TBL-AVAILABILITY_SLOTS',
            'MIG-TBL-AVAILABILITY_SLOT_LIFECYCLE_EVENTS',
            'MIG-TBL-CALENDAR_RESOURCE_CLAIMS',
            'MIG-TBL-TRAINING_SESSION_CALENDAR_DETAILS',
            'MIG-TBL-PKK_INTEGRATION_SETTINGS',
            'MIG-TBL-PKK_INTEGRATION_CONFIGURATION_REVISIONS',
            'MIG-TBL-PKK_PROFILES',
            'MIG-TBL-PKK_PROVIDER_PROFILE_SNAPSHOTS',
            'MIG-TBL-PKK_OPERATIONS',
            'MIG-TBL-PKK_OPERATION_LIFECYCLE_EVENTS',
            'MIG-TBL-PKK_OPERATION_ATTEMPTS',
            'MIG-TBL-PKK_OPERATION_ATTEMPT_RECONCILIATIONS',
            'MIG-TBL-PKK_SIGNATURE_HANDOFFS',
            'MIG-TBL-PKK_SIGNATURE_HANDOFF_UPLOAD_RESERVATIONS',
            'MIG-TBL-PKK_PROTECTED_PAYLOADS',
            'MIG-TBL-PKK_PROTECTED_PAYLOAD_KEY_WRAPPINGS',
            'MIG-TBL-PKK_PAYLOAD_REDACTED_PROJECTIONS',
            'MIG-TBL-PKK_SIGNATURE_FILE_ASSET_PROTECTIONS',
            'MIG-TBL-PKK_SIGNATURE_FILE_ASSET_KEY_WRAPPINGS',
            'MIG-TBL-STUDENT_CHARGES',
            'MIG-TBL-STUDENT_PAYMENTS',
            'MIG-TBL-COURSE_COST_CHARGE_ORIGINS',
            'MIG-TBL-LICENSE_PRODUCTS',
            'MIG-TBL-LICENSE_PRODUCT_LANGUAGE_CAPABILITIES',
            'MIG-TBL-LICENSE_INVENTORY_ENTRIES',
            'MIG-TBL-LICENSE_ASSIGNMENTS',
            'MIG-TBL-LICENSE_ACTIVATIONS',
            'MIG-TBL-EXAM_STATIONS',
            'MIG-TBL-EXAM_STATION_CREDENTIALS',
            'MIG-TBL-INTERNAL_EXAM_DEFINITIONS',
            'MIG-TBL-INTERNAL_EXAM_DOCUMENT_TEMPLATES',
            'MIG-TBL-INTERNAL_EXAM_INVENTORY_ENTRIES',
            'MIG-TBL-INTERNAL_EXAM_INVENTORY_ADJUSTMENTS',
            'MIG-TBL-INTERNAL_EXAM_INVENTORY_LEDGER_ENTRIES',
            'MIG-TBL-INTERNAL_EXAM_ATTEMPTS',
            'MIG-TBL-INTERNAL_EXAM_ATTEMPT_LIFECYCLE_EVENTS',
            'MIG-TBL-INTERNAL_EXAM_RESERVATIONS',
            'MIG-TBL-INTERNAL_EXAM_ACCESSES',
            'MIG-TBL-INTERNAL_EXAM_ACCESS_LIFECYCLE_EVENTS',
            'MIG-TBL-INTERNAL_EXAM_ACCESS_TOKENS',
            'MIG-TBL-INTERNAL_EXAM_STATION_SESSIONS',
            'MIG-TBL-INTERNAL_EXAM_ATTEMPT_QUESTIONS',
            'MIG-TBL-INTERNAL_EXAM_RESULTS',
            'MIG-TBL-INTERNAL_EXAM_DOCUMENTS',
            'MIG-TBL-COMMERCE_CATALOG_ITEMS',
            'MIG-TBL-ORDERS',
            'MIG-TBL-ORDER_ITEMS',
            'MIG-TBL-PAYMENTS',
            'MIG-TBL-PAYMENT_EVENTS',
            'MIG-TBL-ORDER_PAYMENT_SETTLEMENTS',
            'MIG-TBL-ORDER_FULFILLMENTS',
            'MIG-TBL-SERVICE_ENTITLEMENTS',
            'MIG-TBL-SERVICE_ACTIVATIONS',
            'MIG-TBL-AUDIT_ACTION_POLICY_REVISIONS',
            'MIG-TBL-AUDIT_ACTION_POLICY_CURRENTS',
            'MIG-TBL-AUDIT_LOGS',
            'MIG-TBL-DOMAIN_EVENTS',
            'MIG-TBL-OUTBOX_MESSAGES',
            'MIG-TBL-ACTIVITY_PROJECTION_POLICY_REVISIONS',
            'MIG-TBL-ACTIVITY_PROJECTION_POLICY_CURRENTS',
            'MIG-TBL-ORGANIZATION_ACTIVITY_EVENTS',
            'MIG-TBL-NOTIFICATIONS',
            'MIG-TBL-EVENT_PROJECTION_MIGRATION_CASES',
            'MIG-TBL-DATA_RETENTION_EXECUTION_RUNS',
        ], array_column($plan->phaseSteps('expand'), 'node_id'));

        $candidateKeyNodes = [
            'MIG-CK-IDENTITY',
            'MIG-CK-ASSETS_RESOURCES',
            'MIG-CK-TRAINING',
            'MIG-CK-FINANCE',
            'MIG-CK-LICENSES',
            'MIG-CK-EXAMS',
            'MIG-CK-COMMERCE',
            'MIG-CK-EVENTS',
        ];
        $this->assertSame($candidateKeyNodes, array_column($plan->phaseSteps('preflight'), 'node_id'));
        $this->assertSame($candidateKeyNodes, array_column($plan->phaseSteps('write_fence'), 'node_id'));
        $this->assertSame([], $plan->phaseSteps('backfill'));
        $this->assertSame([], $plan->phaseSteps('reconcile'));
        $this->assertSame([], $plan->phaseSteps('validate'));
        $this->assertSame([], $plan->phaseSteps('contract'));
    }

    public function test_global_write_fence_entry_stays_closed_until_all_authoritative_preflight_nodes_are_materialized(): void
    {
        $plan = new MigrationPlan;
        $plan->validate();

        $appliedExpand = array_column($plan->phaseSteps('expand'), 'migration_name');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Earlier phase preflight is not fully materialized.');
        $plan->assertPhaseEntry('write_fence', $appliedExpand);
    }
}
