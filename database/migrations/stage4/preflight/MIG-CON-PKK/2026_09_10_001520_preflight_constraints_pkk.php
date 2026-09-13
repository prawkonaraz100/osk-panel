<?php

use App\Support\Migrations\ConstraintPreflight;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CON-PKK');

        ConstraintPreflight::assertChecks('MIG-CON-PKK', [
            [
                'name' => 'pkk_profile_revision_positive',
                'table' => 'pkk_profiles',
                'columns' => ['identity_revision'],
                'predicate' => 'src.identity_revision >= 1',
            ],
            [
                'name' => 'pkk_operation_closed_state',
                'table' => 'pkk_operations',
                'columns' => ['operation_type', 'business_status', 'version', 'course_operation_sequence'],
                'predicate' => 'src.operation_type IN (\'fetch_profile\',\'update_and_return\',\'return_to_school\',\'return_to_authority\',\'return_expired\') AND src.business_status IN (\'draft\',\'pending\',\'requires_signature\',\'submitted\',\'success\',\'failed\',\'cancelled\') AND src.version >= 1 AND src.course_operation_sequence >= 1',
            ],
            [
                'name' => 'pkk_attempt_sequences_positive',
                'table' => 'pkk_operation_attempts',
                'columns' => ['attempt_no', 'integration_configuration_revision'],
                'predicate' => 'src.attempt_no >= 1 AND src.integration_configuration_revision >= 1',
            ],
            [
                'name' => 'pkk_signature_handoff_shape',
                'table' => 'pkk_signature_handoffs',
                'columns' => ['handoff_no', 'requires_signature_operation_version', 'signed_file_asset_id', 'signed_sha256', 'signed_attached_at', 'consumed_at', 'cancelled_at'],
                'predicate' => 'src.handoff_no >= 1 AND src.requires_signature_operation_version >= 1 AND ((src.signed_file_asset_id IS NULL) = (src.signed_sha256 IS NULL)) AND (src.signed_file_asset_id IS NULL OR src.signed_attached_at IS NOT NULL) AND NOT (src.consumed_at IS NOT NULL AND src.cancelled_at IS NOT NULL)',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
