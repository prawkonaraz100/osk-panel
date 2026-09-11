<?php

use Tests\Feature\AuditOutboxFoundationTest;
use Tests\Feature\AvailabilityFormalizationCoreTest;
use Tests\Feature\AvailabilitySlotCoreTest;
use Tests\Feature\CalendarDrivingLessonCoreTest;
use Tests\Feature\CalendarEventCoreTest;
use Tests\Feature\CalendarImportantDateProjectionCoreTest;
use Tests\Feature\IdentityTenantFoundationTest;
use Tests\Feature\OrganizationSettingsFoundationTest;
use Tests\Feature\ResourcesCoreTest;
use Tests\Feature\Stage4MigrationPostcheckTest;
use Tests\Feature\StudentFinanceCoreTest;
use Tests\Feature\StudentsCoursesCoreTest;
use Tests\Feature\TrainingSessionCoreTest;

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

    'DBT-CAL-002' => [
        'class' => CalendarEventCoreTest::class,
        'method' => 'test_material_patch_is_versioned_noop_is_not_and_meeting_place_sources_are_exclusive',
        'scope' => 'Manual CalendarEvent cannot persist managed location and custom meeting place at the same time.',
    ],
    'DBT-CAL-003' => [
        'class' => CalendarImportantDateProjectionCoreTest::class,
        'method' => 'test_staff_and_vehicle_expiry_dates_project_without_calendar_rows_or_claims',
        'scope' => 'Important dates are source projections and never manual CalendarEvent or reservation rows.',
    ],
    'DBT-CAL-004' => [
        'class' => TrainingSessionCoreTest::class,
        'method' => 'test_planned_session_creates_exact_shared_claims_and_rejects_overlap_but_allows_adjacent_interval',
        'scope' => 'Shared calendar occupancy uses half-open intervals so adjacent reservations are legal.',
    ],
    'DBT-CAL-011' => [
        'class' => CalendarEventCoreTest::class,
        'method' => 'test_material_patch_is_versioned_noop_is_not_and_meeting_place_sources_are_exclusive',
        'scope' => 'Material CalendarEvent versions emit matching lifecycle history while semantic no-ops do not.',
    ],
    'DBT-CAL-012' => [
        'class' => CalendarEventCoreTest::class,
        'method' => 'test_material_patch_is_versioned_noop_is_not_and_meeting_place_sources_are_exclusive',
        'scope' => 'A stale expected CalendarEvent version cannot commit a second mutation.',
    ],
    'DBT-CAL-013' => [
        'class' => CalendarEventCoreTest::class,
        'method' => 'test_calendar_manage_own_is_canonical_instructor_ownership_and_cannot_transfer',
        'scope' => 'Calendar own scope resolves through active StaffProfile instructor ownership and cannot transfer ownership.',
    ],
    'DBT-CAL-014' => [
        'class' => AvailabilitySlotCoreTest::class,
        'method' => 'test_booking_is_idempotent_versioned_and_creates_exact_claims_without_training_effect',
        'scope' => 'Availability booking state, booking fields, version, and lifecycle history remain consistent.',
    ],
    'DBT-CAL-016' => [
        'class' => AvailabilitySlotCoreTest::class,
        'method' => 'test_booking_conflict_rolls_back_slot_to_available_without_partial_claims',
        'scope' => 'Availability booking conflict rolls back status, version, booking fields, claims, and history atomically.',
    ],
    'DBT-CAL-017' => [
        'class' => AvailabilitySlotCoreTest::class,
        'method' => 'test_cancel_booked_slot_releases_claims_preserves_student_snapshot_and_cannot_rebook',
        'scope' => 'Cancelled AvailabilitySlot releases booking claims, preserves evidence, and remains terminal.',
    ],
    'DBT-TRN-015' => [
        'class' => TrainingSessionCoreTest::class,
        'method' => 'test_planned_session_creates_exact_shared_claims_and_rejects_overlap_but_allows_adjacent_interval',
        'scope' => 'Planned TrainingSession creates the exact current course Student and Instructor resource claim set.',
    ],
    'DBT-TRN-017' => [
        'class' => TrainingSessionCoreTest::class,
        'method' => 'test_planned_session_creates_exact_shared_claims_and_rejects_overlap_but_allows_adjacent_interval',
        'scope' => 'Planning and claiming a TrainingSession does not create attendance or formal training credit.',
    ],
    'DBT-TRN-018' => [
        'class' => TrainingSessionCoreTest::class,
        'method' => 'test_absent_completion_and_cancellation_never_credit_formal_time',
        'scope' => 'Completed or cancelled TrainingSession releases all shared schedule claims.',
    ],
    'DBT-CAL-018' => [
        'class' => CalendarDrivingLessonCoreTest::class,
        'method' => 'test_calendar_create_and_list_project_one_practical_training_session_without_calendar_event_copy',
        'scope' => 'Practical TrainingSession projects exactly once as driving_lesson without a CalendarEvent copy.',
    ],
    'DBT-CAL-019' => [
        'class' => CalendarDrivingLessonCoreTest::class,
        'method' => 'test_calendar_metadata_patch_uses_training_session_version_and_preserves_meeting_place_xor',
        'scope' => 'TrainingSession calendar companion metadata preserves optional display name and custom meeting place without schedule duplication.',
    ],
    'DBT-CAL-020' => [
        'class' => CalendarDrivingLessonCoreTest::class,
        'method' => 'test_calendar_metadata_patch_uses_training_session_version_and_preserves_meeting_place_xor',
        'scope' => 'Formal TrainingSession managed location and custom meeting place are mutually exclusive.',
    ],
    'DBT-CAL-021' => [
        'class' => CalendarDrivingLessonCoreTest::class,
        'method' => 'test_calendar_manage_permission_alone_cannot_create_formal_training',
        'scope' => 'Calendar management permission cannot escalate into formal TrainingSession mutation.',
    ],
    'DBT-CAL-022' => [
        'class' => CalendarEventCoreTest::class,
        'method' => 'test_terminal_commands_require_if_match_are_idempotent_and_never_credit_training_hours',
        'scope' => 'Completing a manual CalendarEvent never bypasses the formal TrainingSession credit pipeline.',
    ],
    'DBT-CAL-024' => [
        'class' => AvailabilityFormalizationCoreTest::class,
        'method' => 'test_formalization_transfers_booking_to_one_practical_training_session_atomically',
        'scope' => 'Formalization atomically transfers booking ownership from AvailabilitySlot claims to one practical TrainingSession.',
    ],
    'DBT-CAL-025' => [
        'class' => AvailabilityFormalizationCoreTest::class,
        'method' => 'test_formalization_transfers_booking_to_one_practical_training_session_atomically',
        'scope' => 'Formalized slot keeps one TrainingSession link, zero booking claims, and one calendar item.',
    ],
    'DBT-CAL-051' => [
        'class' => AvailabilitySlotCoreTest::class,
        'method' => 'test_booking_is_idempotent_versioned_and_creates_exact_claims_without_training_effect',
        'scope' => 'book_availability_slot preserves atomic booking state, history, and exact shared claims.',
    ],
    'DBT-CAL-052' => [
        'class' => CalendarEventCoreTest::class,
        'method' => 'test_general_event_uses_shared_claim_boundary_and_formal_driving_lesson_row_is_rejected',
        'scope' => 'calendar_conflict rejects overlap at the shared runtime boundary without partial CalendarEvent state.',
    ],
    'DBT-CAL-053' => [
        'class' => AvailabilitySlotCoreTest::class,
        'method' => 'test_cancel_booked_slot_releases_claims_preserves_student_snapshot_and_cannot_rebook',
        'scope' => 'cancel_availability_slot preserves terminal history while releasing current booking claims atomically.',
    ],
    'DBT-CAL-054' => [
        'class' => CalendarEventCoreTest::class,
        'method' => 'test_terminal_commands_require_if_match_are_idempotent_and_never_credit_training_hours',
        'scope' => 'complete_or_cancel_manual_calendar_event preserves versioned terminal history and releases claims without training credit.',
    ],
    'DBT-CAL-055' => [
        'class' => CalendarEventCoreTest::class,
        'method' => 'test_material_patch_is_versioned_noop_is_not_and_meeting_place_sources_are_exclusive',
        'scope' => 'create_or_update_manual_calendar_event preserves optimistic concurrency and lifecycle atomicity.',
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
    'DBT-FIN-001' => [
        'class' => StudentFinanceCoreTest::class,
        'method' => 'test_dbt_fin_001_exact_student_course_charge_payment_currency_relations_fail_closed',
        'scope' => 'Student Finance runtime rejects wrong-student or foreign-tenant course links and mismatched payment currency without persisting an invalid finance relation.',
    ],
    'DBT-FIN-005' => [
        'class' => StudentFinanceCoreTest::class,
        'method' => 'test_same_idempotency_key_replays_charge_payment_reversal_and_cancel_without_second_effect',
        'scope' => 'Same idempotency key and request replays charge, payment, reversal, and cancellation without a second financial effect.',
    ],
];
