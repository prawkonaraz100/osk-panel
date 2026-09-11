<?php

use Tests\Feature\AuditOutboxFoundationTest;
use Tests\Feature\IdentityTenantFoundationTest;
use Tests\Feature\OrganizationSettingsFoundationTest;
use Tests\Feature\ResourcesCoreTest;
use Tests\Feature\Stage4MigrationPostcheckTest;
use Tests\Feature\StudentsCoursesCoreTest;

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
    'DBT-RES-001' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_001_assigned_location_scope_without_active_staff_link_is_empty',
        'scope' => 'Assigned-location scope resolves to empty without an active staff membership link.',
    ],
    'DBT-RES-002' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_002_staff_can_exist_without_membership_link',
        'scope' => 'Staff profile may exist independently from panel identity and membership.',
    ],
    'DBT-RES-003' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_003_staff_membership_link_command_never_builds_cross_tenant_pair',
        'scope' => 'Panel-account linking never creates a cross-tenant staff-to-membership pair.',
    ],
    'DBT-RES-004' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_004_archived_staff_cannot_receive_active_staff_membership_link',
        'scope' => 'Archived staff cannot receive a current panel membership link.',
    ],
    'DBT-RES-005' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_005_staff_can_have_multiple_categories_and_locations',
        'scope' => 'Staff supports multiple category and tenant-location assignments.',
    ],
    'DBT-RES-006' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_006_staff_location_assignment_cross_tenant_pair_is_rejected',
        'scope' => 'Staff location assignment rejects foreign-tenant location IDs.',
    ],
    'DBT-RES-007' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_007_vehicle_can_have_multiple_categories_and_locations',
        'scope' => 'Vehicle supports multiple category and tenant-location assignments.',
    ],
    'DBT-RES-008' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_008_vehicle_location_assignment_cross_tenant_pair_is_rejected',
        'scope' => 'Vehicle location assignment rejects foreign-tenant location IDs.',
    ],
    'DBT-RES-009' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_009_private_assets_require_same_tenant_correct_purpose_and_ready_state',
        'scope' => 'Private staff/vehicle asset references require tenant ownership, purpose, and ready state.',
    ],
    'DBT-RES-010' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_010_staff_document_has_at_most_one_current_row_per_type',
        'scope' => 'Staff document replacement leaves one current version and preserves superseded history.',
    ],
    'DBT-RES-011' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_011_vehicle_document_has_at_most_one_current_row_per_type',
        'scope' => 'Vehicle document replacement leaves one current version and preserves superseded history.',
    ],
    'DBT-RES-012' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_012_archived_staff_still_blocks_duplicate_pesel_in_same_organization',
        'scope' => 'Archived staff history continues to reserve the tenant PESEL identity.',
    ],
    'DBT-RES-013' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_013_archived_vehicle_still_blocks_duplicate_vin_in_same_organization',
        'scope' => 'Archived fleet history continues to reserve the tenant VIN identity.',
    ],
    'DBT-RES-014' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_014_vehicle_archive_releases_registration_for_current_fleet_but_preserves_history',
        'scope' => 'Vehicle archive releases current-fleet registration while preserving the archived record.',
    ],
    'DBT-RES-015' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_015_vehicle_restore_conflicts_if_registration_is_held_by_another_current_vehicle',
        'scope' => 'Vehicle restore fails without implicit swap when registration is held by another current vehicle.',
    ],
    'DBT-RES-016' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_016_staff_archive_nonowner_unlinks_suspends_and_clears_bound_sessions_atomically',
        'scope' => 'Non-owner staff archive unlinks panel access, suspends membership, and clears bound session tenant context.',
    ],
    'DBT-RES-017' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_017_staff_archive_owner_unlinks_staff_without_changing_owner_governance',
        'scope' => 'Owner staff archive ends staff link without silently changing owner governance.',
    ],
    'DBT-RES-018' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_018_staff_restore_does_not_auto_restore_panel_access_or_old_sessions',
        'scope' => 'Staff restore restores only the profile and never old access or sessions.',
    ],
    'DBT-RES-046' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_016_staff_archive_nonowner_unlinks_suspends_and_clears_bound_sessions_atomically',
        'scope' => 'Transactional archive_staff_profile invariant is executable.',
    ],
    'DBT-RES-047' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_009_private_assets_require_same_tenant_correct_purpose_and_ready_state',
        'scope' => 'Transactional private attachment invariant is executable at current materialized boundary.',
    ],
    'DBT-RES-048' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_048_create_staff_membership_link_is_same_tenant_and_idempotent_at_domain_level',
        'scope' => 'Staff membership link creation is serialized, same-tenant, and does not duplicate current links.',
    ],
    'DBT-RES-049' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_049_replace_staff_or_vehicle_document_preserves_superseded_history',
        'scope' => 'Document replacement preserves historical versions for staff and vehicles.',
    ],
    'DBT-RES-050' => [
        'class' => ResourcesCoreTest::class,
        'method' => 'test_dbt_res_050_restore_staff_profile_restores_record_only',
        'scope' => 'Transactional restore_staff_profile invariant restores record only.',
    ],
    'DBT-TRN-003' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_pre_course_incomplete_student_is_allowed_but_formal_course_is_rejected',
        'scope' => 'Pre-course Student may be incomplete, while formal CourseEnrollment creation fails until formal identity is complete.',
    ],
    'DBT-IAM-032' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_duplicate_student_pesel_is_blocked_even_after_archive',
        'scope' => 'Student PESEL identity remains unique per organization including archived Student history at the current runtime boundary.',
    ],
    'DBT-CORE-021' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_student_two_mutations_with_same_expected_version_cannot_both_commit',
        'scope' => 'Two Student mutations carrying the same expected version cannot both commit.',
    ],
    'DBT-TRN-006' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_each_material_course_version_has_exactly_one_lifecycle_event',
        'scope' => 'Each material CourseEnrollment version emitted by implemented lifecycle commands has exactly one lifecycle event.',
    ],
    'DBT-TRN-007' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_course_restore_rejects_archived_student_and_succeeds_after_student_restore',
        'scope' => 'Normal restore reopens only a cancelled CourseEnrollment and cannot reopen it under an archived Student.',
    ],
    'DBT-TRN-008' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_held_b1_recalculates_basic_b_theory_to_zero_and_practice_down_by_600',
        'scope' => 'Current requirement profile revision equals CourseEnrollment requirements_revision after source-fact recalculation.',
    ],
    'DBT-CORE-023' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_held_b1_recalculates_basic_b_theory_to_zero_and_practice_down_by_600',
        'scope' => 'A requirement source-fact change commits together with a new single current requirement profile.',
    ],
    'DBT-TRN-013' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_category_change_supersedes_external_projection_and_revalidates_context',
        'scope' => 'A current external-training projection cannot survive a category change without supersession and context revalidation.',
    ],
    'DBT-TRN-035' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_http_student_create_and_course_create_require_idempotency_and_emit_version_etag',
        'scope' => 'CreateCourseEnrollment commits its projection atomically and same-key replay returns the same CourseEnrollment without a second effect.',
    ],
    'DBT-TRN-037' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_external_training_is_append_history_and_revoke_does_not_delete_record',
        'scope' => 'Recognized external training mutation preserves append/revoke history rather than destructively deleting the source record.',
    ],
    'DBT-TRN-038' => [
        'class' => StudentsCoursesCoreTest::class,
        'method' => 'test_student_archive_restore_mutation_preserves_single_profile_history',
        'scope' => 'Student profile archive/restore mutates one persistent profile with versioned audited state rather than replacing history.',
    ],
];
