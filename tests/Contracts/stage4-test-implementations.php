<?php

use Tests\Feature\AuditOutboxFoundationTest;
use Tests\Feature\IdentityTenantFoundationTest;
use Tests\Feature\OrganizationSettingsFoundationTest;
use Tests\Feature\Stage4MigrationPostcheckTest;

return [
    'DBT-CORE-009' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_core_009_owner_marker_alone_does_not_grant_permissions',
        'scope' => 'Owner governance marker never bypasses explicit runtime permission authority.',
    ],
    'DBT-CORE-015' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_core_015_authorization_mutation_increments_authorization_version_once',
        'scope' => 'Authorization-affecting permission mutation increments membership and authorization version once and emits audit/outbox.',
    ],
    'DBT-IAM-005' => [
        'class' => OrganizationSettingsFoundationTest::class,
        'method' => 'test_dbt_iam_005_settings_version_increments_once_per_successful_atomic_write',
        'scope' => 'Organization settings optimistic concurrency version increments exactly once on a successful atomic write.',
    ],
    'DBT-IAM-009' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_009_tenant_request_without_membership_context_is_denied',
        'scope' => 'Tenant operation without active membership context fails closed.',
    ],
    'DBT-IAM-013' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_013_granted_permission_without_scope_is_denied',
        'scope' => 'Granted permission without an applicable scope fails closed.',
    ],
    'DBT-IAM-015' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_015_organization_scope_never_bypasses_cross_tenant_validation',
        'scope' => 'Organization scope cannot cross the active membership tenant.',
    ],
    'DBT-IAM-016' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_016_owner_transfer_requires_materialized_successor_baseline',
        'scope' => 'Owner successor requires the protected permission baseline before transfer.',
    ],
    'DBT-IAM-017' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_017_actor_cannot_grant_permission_above_own_ceiling',
        'scope' => 'Actor cannot grant a permission/scope it does not hold.',
    ],
    'DBT-IAM-020' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_020_suspended_membership_cannot_authorize_with_retained_permission_rows',
        'scope' => 'Suspended membership cannot authorize despite retained permission rows.',
    ],
    'DBT-AUD-003' => [
        'class' => AuditOutboxFoundationTest::class,
        'method' => 'test_dbt_aud_003_missing_current_audit_policy_rolls_back_business_effect',
        'scope' => 'Runtime audit requires a registered current policy and failure rolls back the business effect.',
    ],
    'DBT-AUD-005' => [
        'class' => AuditOutboxFoundationTest::class,
        'method' => 'test_dbt_aud_005_business_audit_domain_event_and_outbox_commit_together',
        'scope' => 'Business effect, audit, domain event, and outbox intent commit in one local transaction.',
    ],
    'DBT-CORE-099' => [
        'class' => Stage4MigrationPostcheckTest::class,
        'method' => 'test_dbt_core_099_zero_gap_traceability_authority_is_executable',
        'scope' => 'Stage-4 zero-gap coverage summary and complete 491-ID catalog remain executable and unchanged.',
    ],
    'DBT-CORE-100' => [
        'class' => Stage4MigrationPostcheckTest::class,
        'method' => 'test_dbt_core_100_final_aggregate_sync_is_executable',
        'scope' => 'Final Stage-4 aggregate authority blobs and 491-test catalog remain synchronized.',
    ],
];
