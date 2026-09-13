<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('backfill', 'S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE backfill requires PostgreSQL.');
        }

        if (! Schema::hasTable('course_enrollments') || ! Schema::hasColumns('course_enrollments', [
            'document_mode',
            'document_mode_selected_at',
            'document_mode_selected_by_user_id',
        ])) {
            throw new LogicException('S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE backfill requires the completed expand phase.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE course_enrollments IN SHARE ROW EXCLUSIVE MODE');

            $partialLegacy = (int) DB::table('course_enrollments')
                ->whereNull('document_mode')
                ->where(function ($query): void {
                    $query->whereNotNull('document_mode_selected_at')
                        ->orWhereNotNull('document_mode_selected_by_user_id');
                })
                ->count();

            if ($partialLegacy !== 0) {
                throw new LogicException(
                    "FORMAL-DOC-005 backfill failed: {$partialLegacy} legacy course rows have partial document-mode selection state."
                );
            }

            $selectedWithoutTimestamp = (int) DB::table('course_enrollments')
                ->whereNotNull('document_mode')
                ->whereNull('document_mode_selected_at')
                ->count();

            if ($selectedWithoutTimestamp !== 0) {
                throw new LogicException(
                    "FORMAL-DOC-005 backfill failed: {$selectedWithoutTimestamp} course rows have document mode without selection timestamp."
                );
            }

            $invalidMode = (int) DB::table('course_enrollments')
                ->whereNotNull('document_mode')
                ->whereNotIn('document_mode', ['paper', 'electronic'])
                ->count();

            if ($invalidMode !== 0) {
                throw new LogicException(
                    "FORMAL-DOC-005 backfill failed: {$invalidMode} course rows contain an unsupported document mode."
                );
            }

            $documentedLegacy = (int) DB::table('course_enrollments as courses')
                ->join('formal_training_documents as documents', function ($join): void {
                    $join->on('documents.organization_id', '=', 'courses.organization_id')
                        ->on('documents.course_enrollment_id', '=', 'courses.id');
                })
                ->whereNull('courses.document_mode')
                ->count();

            if ($documentedLegacy !== 0) {
                throw new LogicException(
                    "FORMAL-DOC-005 backfill failed: {$documentedLegacy} formal document revisions already reference courses without a selected document mode."
                );
            }

            $legacyRows = (int) DB::table('course_enrollments')
                ->whereNull('document_mode')
                ->whereNull('document_mode_selected_at')
                ->whereNull('document_mode_selected_by_user_id')
                ->count();

            $eligibleLegacyRows = (int) DB::table('course_enrollments')
                ->whereNull('document_mode')
                ->whereNull('document_mode_selected_at')
                ->whereNull('document_mode_selected_by_user_id')
                ->whereNotNull('created_at')
                ->count();

            if ($legacyRows !== $eligibleLegacyRows) {
                throw new LogicException(
                    'FORMAL-DOC-005 backfill failed: not every legacy row has the durable created_at timestamp required for deterministic paper-mode backfill.'
                );
            }

            $updated = DB::table('course_enrollments')
                ->whereNull('document_mode')
                ->whereNull('document_mode_selected_at')
                ->whereNull('document_mode_selected_by_user_id')
                ->update([
                    'document_mode' => 'paper',
                    'document_mode_selected_at' => DB::raw('created_at'),
                    'document_mode_selected_by_user_id' => null,
                ]);

            if ($updated !== $legacyRows) {
                throw new LogicException(
                    "FORMAL-DOC-005 backfill postcondition failed: expected {$legacyRows} legacy rows, updated {$updated}."
                );
            }

            $remainingLegacy = (int) DB::table('course_enrollments')
                ->whereNull('document_mode')
                ->count();

            if ($remainingLegacy !== 0) {
                throw new LogicException(
                    "FORMAL-DOC-005 backfill postcondition failed: {$remainingLegacy} course rows still have null document mode."
                );
            }

            $selectedWithoutTimestampAfter = (int) DB::table('course_enrollments')
                ->whereNotNull('document_mode')
                ->whereNull('document_mode_selected_at')
                ->count();

            if ($selectedWithoutTimestampAfter !== 0) {
                throw new LogicException(
                    "FORMAL-DOC-005 backfill postcondition failed: {$selectedWithoutTimestampAfter} selected course rows have no selection timestamp."
                );
            }
        }, 1);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Deterministic historical backfill must not be reversed automatically.');
    }
};
