<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyPreflight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-FK-EXAMS');

        ForeignKeyPreflight::assertRelations('MIG-FK-EXAMS', [
            [
                'name' => 'reservation_inventory',
                'source_table' => 'internal_exam_reservations',
                'source_columns' => ['organization_id', 'internal_exam_inventory_entry_id'],
                'target_table' => 'internal_exam_inventory_entries',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'reservation_attempt',
                'source_table' => 'internal_exam_reservations',
                'source_columns' => ['organization_id', 'internal_exam_attempt_id'],
                'target_table' => 'internal_exam_attempts',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'access_attempt',
                'source_table' => 'internal_exam_accesses',
                'source_columns' => ['organization_id', 'internal_exam_attempt_id'],
                'target_table' => 'internal_exam_attempts',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'access_station',
                'source_table' => 'internal_exam_accesses',
                'source_columns' => ['organization_id', 'station_id'],
                'target_table' => 'exam_stations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'station_session_attempt',
                'source_table' => 'internal_exam_station_sessions',
                'source_columns' => ['organization_id', 'internal_exam_attempt_id'],
                'target_table' => 'internal_exam_attempts',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'station_session_exact_access_attempt',
                'source_table' => 'internal_exam_station_sessions',
                'source_columns' => ['organization_id', 'internal_exam_access_id', 'internal_exam_attempt_id'],
                'target_table' => 'internal_exam_accesses',
                'target_columns' => ['organization_id', 'id', 'internal_exam_attempt_id'],
            ],
            [
                'name' => 'station_session_station',
                'source_table' => 'internal_exam_station_sessions',
                'source_columns' => ['organization_id', 'exam_station_id'],
                'target_table' => 'exam_stations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'station_session_transfer_same_attempt',
                'source_table' => 'internal_exam_station_sessions',
                'source_columns' => ['organization_id', 'transferred_from_session_id', 'internal_exam_attempt_id'],
                'target_table' => 'internal_exam_station_sessions',
                'target_columns' => ['organization_id', 'id', 'internal_exam_attempt_id'],
            ],
            [
                'name' => 'attempt_question_attempt',
                'source_table' => 'internal_exam_attempt_questions',
                'source_columns' => ['organization_id', 'internal_exam_attempt_id'],
                'target_table' => 'internal_exam_attempts',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'exam_document_attempt',
                'source_table' => 'internal_exam_documents',
                'source_columns' => ['organization_id', 'internal_exam_attempt_id'],
                'target_table' => 'internal_exam_attempts',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'exam_document_asset',
                'source_table' => 'internal_exam_documents',
                'source_columns' => ['organization_id', 'asset_id'],
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
