<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-TRAINING');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-TRAINING', [
            [
                'name' => 'student_pesel_unique_per_organization_including_archived',
                'table' => 'students',
                'columns' => ['organization_id', 'pesel_lookup_hash'],
                'predicate' => 'pesel_lookup_hash IS NOT NULL',
            ],
            [
                'name' => 'training_requirement_profile_current_unique',
                'table' => 'training_requirement_profiles',
                'columns' => ['organization_id', 'course_enrollment_id'],
                'predicate' => 'superseded_at IS NULL',
            ],
            [
                'name' => 'recognized_external_training_one_successor_per_source',
                'table' => 'recognized_external_training',
                'columns' => ['organization_id', 'supersedes_record_id'],
                'predicate' => 'supersedes_record_id IS NOT NULL',
            ],
            [
                'name' => 'recognized_external_training_current_course_form_projection_unique',
                'table' => 'recognized_external_training',
                'columns' => ['organization_id', 'course_enrollment_id', 'training_part'],
                'predicate' => 'record_role = \'course_form_projection\' AND superseded_at IS NULL AND revoked_at IS NULL',
            ],
            [
                'name' => 'training_hour_ledger_base_credit_unique',
                'table' => 'training_hour_ledger_entries',
                'columns' => ['organization_id', 'training_session_id'],
                'predicate' => 'entry_type = \'credit\'',
            ],
            [
                'name' => 'training_hour_ledger_opening_balance_unique',
                'table' => 'training_hour_ledger_entries',
                'columns' => ['organization_id', 'course_enrollment_id', 'training_part'],
                'predicate' => 'entry_type = \'opening_balance\'',
            ],
            [
                'name' => 'training_hour_ledger_reversal_unique',
                'table' => 'training_hour_ledger_entries',
                'columns' => ['organization_id', 'source_entry_id'],
                'predicate' => 'entry_type = \'reversal\'',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
