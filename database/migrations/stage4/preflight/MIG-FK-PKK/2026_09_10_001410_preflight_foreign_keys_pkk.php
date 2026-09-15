<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyPreflight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-FK-PKK');

        ForeignKeyPreflight::assertRelations('MIG-FK-PKK', [
            [
                'name' => 'pkk_profile_course',
                'source_table' => 'pkk_profiles',
                'source_columns' => ['organization_id', 'course_enrollment_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'pkk_profile_supersedes_same_course',
                'source_table' => 'pkk_profiles',
                'source_columns' => ['organization_id', 'supersedes_pkk_profile_id', 'course_enrollment_id'],
                'target_table' => 'pkk_profiles',
                'target_columns' => ['organization_id', 'id', 'course_enrollment_id'],
            ],
            [
                'name' => 'pkk_operation_course',
                'source_table' => 'pkk_operations',
                'source_columns' => ['organization_id', 'course_enrollment_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'pkk_operation_exact_profile_course',
                'source_table' => 'pkk_operations',
                'source_columns' => ['organization_id', 'pkk_profile_id', 'course_enrollment_id'],
                'target_table' => 'pkk_profiles',
                'target_columns' => ['organization_id', 'id', 'course_enrollment_id'],
            ],
            [
                'name' => 'pkk_operation_initial_idempotency',
                'source_table' => 'pkk_operations',
                'source_columns' => ['organization_id', 'initial_idempotency_record_id'],
                'target_table' => 'idempotency_records',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'pkk_attempt_exact_operation_context',
                'source_table' => 'pkk_operation_attempts',
                'source_columns' => ['organization_id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
                'target_table' => 'pkk_operations',
                'target_columns' => ['organization_id', 'id', 'course_enrollment_id', 'pkk_profile_id'],
            ],
            [
                'name' => 'pkk_attempt_command_idempotency',
                'source_table' => 'pkk_operation_attempts',
                'source_columns' => ['organization_id', 'command_idempotency_record_id'],
                'target_table' => 'idempotency_records',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'pkk_attempt_configuration_revision',
                'source_table' => 'pkk_operation_attempts',
                'source_columns' => ['organization_id', 'integration_configuration_revision'],
                'target_table' => 'pkk_integration_configuration_revisions',
                'target_columns' => ['organization_id', 'execution_configuration_revision'],
            ],
            [
                'name' => 'pkk_attempt_signature_handoff',
                'source_table' => 'pkk_operation_attempts',
                'source_columns' => ['organization_id', 'signature_handoff_id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
                'target_table' => 'pkk_signature_handoffs',
                'target_columns' => ['organization_id', 'id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
            ],
            [
                'name' => 'pkk_handoff_exact_operation',
                'source_table' => 'pkk_signature_handoffs',
                'source_columns' => ['organization_id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
                'target_table' => 'pkk_operations',
                'target_columns' => ['organization_id', 'id', 'course_enrollment_id', 'pkk_profile_id'],
            ],
            [
                'name' => 'pkk_handoff_source_attempt',
                'source_table' => 'pkk_signature_handoffs',
                'source_columns' => ['organization_id', 'source_pkk_operation_attempt_id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
                'target_table' => 'pkk_operation_attempts',
                'target_columns' => ['organization_id', 'id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
            ],
            [
                'name' => 'pkk_handoff_unsigned_asset',
                'source_table' => 'pkk_signature_handoffs',
                'source_columns' => ['organization_id', 'unsigned_file_asset_id'],
                'target_table' => 'file_assets',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'pkk_handoff_signed_asset',
                'source_table' => 'pkk_signature_handoffs',
                'source_columns' => ['organization_id', 'signed_file_asset_id'],
                'target_table' => 'file_assets',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'pkk_upload_reservation_handoff',
                'source_table' => 'pkk_signature_handoff_upload_reservations',
                'source_columns' => ['organization_id', 'pkk_signature_handoff_id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
                'target_table' => 'pkk_signature_handoffs',
                'target_columns' => ['organization_id', 'id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
            ],
            [
                'name' => 'pkk_upload_reservation_asset',
                'source_table' => 'pkk_signature_handoff_upload_reservations',
                'source_columns' => ['organization_id', 'file_asset_id'],
                'target_table' => 'file_assets',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'pkk_signature_protection_handoff',
                'source_table' => 'pkk_signature_file_asset_protections',
                'source_columns' => ['organization_id', 'pkk_signature_handoff_id'],
                'target_table' => 'pkk_signature_handoffs',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'pkk_signature_protection_asset',
                'source_table' => 'pkk_signature_file_asset_protections',
                'source_columns' => ['organization_id', 'file_asset_id'],
                'target_table' => 'file_assets',
                'target_columns' => ['organization_id', 'id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
