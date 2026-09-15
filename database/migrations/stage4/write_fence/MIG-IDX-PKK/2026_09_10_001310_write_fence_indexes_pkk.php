<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-PKK');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-PKK', [
            [
                'name' => 'pkk_one_current_profile_per_course',
                'table' => 'pkk_profiles',
                'columns' => ['organization_id', 'course_enrollment_id'],
                'predicate' => 'superseded_at IS NULL',
            ],
            [
                'name' => 'pkk_one_active_operation_per_profile',
                'table' => 'pkk_operations',
                'columns' => ['organization_id', 'pkk_profile_id'],
                'predicate' => 'business_status IN (\'draft\', \'pending\', \'requires_signature\', \'submitted\')',
            ],
            [
                'name' => 'pkk_operation_course_sequence_unique',
                'table' => 'pkk_operations',
                'columns' => ['organization_id', 'course_enrollment_id', 'course_operation_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'pkk_provider_attempt_idempotency_unique',
                'table' => 'pkk_operation_attempts',
                'columns' => ['organization_id', 'command_idempotency_record_id'],
                'predicate' => 'command_idempotency_record_id IS NOT NULL',
            ],
            [
                'name' => 'pkk_operation_attempt_sequence_unique',
                'table' => 'pkk_operation_attempts',
                'columns' => ['organization_id', 'pkk_operation_id', 'attempt_no'],
                'predicate' => null,
            ],
            [
                'name' => 'pkk_one_replay_blocking_attempt_per_operation',
                'table' => 'pkk_operation_attempts',
                'columns' => ['organization_id', 'pkk_operation_id'],
                'predicate' => 'attempt_dispatch_status IN (\'prepared\', \'dispatching\', \'effect_unknown\')',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
