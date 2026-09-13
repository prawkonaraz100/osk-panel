<?php

namespace Tests\Feature;

use App\Support\Migrations\Stage5FormalDocumentsMigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FoundationSchema;
use Tests\TestCase;
use Throwable;

final class FormalTrainingDocumentMigrationBackfillTest extends TestCase
{
    private const PREFLIGHT_MIGRATION = '2026_09_13_000050_preflight_formal_document_mode_legacy_rows';

    private const BACKFILL_MIGRATION = '2026_09_13_000060_backfill_formal_document_mode_legacy_rows';

    private string $evidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evidencePath = storage_path('framework/testing/formal-doc-005-migration-evidence.jsonl');
        @unlink($this->evidencePath);
        config(['migration.evidence_path' => $this->evidencePath]);

        FoundationSchema::reset();
        DB::table('migrations')->whereIn('migration', [
            self::PREFLIGHT_MIGRATION,
            self::BACKFILL_MIGRATION,
        ])->delete();
    }

    protected function tearDown(): void
    {
        @unlink($this->evidencePath);
        parent::tearDown();
    }

    public function test_backfill_requires_preflight_to_be_applied_first(): void
    {
        $course = $this->insertCourse([
            'document_mode' => null,
            'document_mode_selected_at' => null,
            'document_mode_selected_by_user_id' => null,
        ]);

        $this->assertPhaseFailsWith('backfill', 'Earlier Stage-5 phase preflight is not fully applied');

        $stored = DB::table('course_enrollments')->where('id', $course['id'])->firstOrFail();
        $this->assertNull($stored->document_mode);
        $this->assertNull($stored->document_mode_selected_at);
        $this->assertSame(0, DB::table('migrations')->where('migration', self::BACKFILL_MIGRATION)->count());
    }

    public function test_backfill_deterministically_sets_legacy_rows_to_paper_from_created_at(): void
    {
        $legacy = $this->insertCourse([
            'document_mode' => null,
            'document_mode_selected_at' => null,
            'document_mode_selected_by_user_id' => null,
        ]);

        $electronicSelectedAt = now()->subHours(2);
        $electronic = $this->insertCourse([
            'document_mode' => 'electronic',
            'document_mode_selected_at' => $electronicSelectedAt,
            'document_mode_selected_by_user_id' => (string) Str::uuid7(),
        ]);

        $legacyBefore = DB::table('course_enrollments')->where('id', $legacy['id'])->firstOrFail();
        $electronicBefore = DB::table('course_enrollments')->where('id', $electronic['id'])->firstOrFail();

        $this->assertSame(Command::SUCCESS, $this->runPhase('preflight'));
        $this->assertSame(Command::SUCCESS, $this->runPhase('backfill'));

        $legacyAfter = DB::table('course_enrollments')->where('id', $legacy['id'])->firstOrFail();
        $electronicAfter = DB::table('course_enrollments')->where('id', $electronic['id'])->firstOrFail();

        $this->assertSame('paper', $legacyAfter->document_mode);
        $this->assertSame($legacyBefore->created_at, $legacyAfter->document_mode_selected_at);
        $this->assertNull($legacyAfter->document_mode_selected_by_user_id);
        $this->assertSame($legacyBefore->updated_at, $legacyAfter->updated_at);

        $this->assertSame($electronicBefore->document_mode, $electronicAfter->document_mode);
        $this->assertSame($electronicBefore->document_mode_selected_at, $electronicAfter->document_mode_selected_at);
        $this->assertSame($electronicBefore->document_mode_selected_by_user_id, $electronicAfter->document_mode_selected_by_user_id);

        $this->assertSame(1, DB::table('migrations')->where('migration', self::PREFLIGHT_MIGRATION)->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', self::BACKFILL_MIGRATION)->count());
        $this->assertSame(0, DB::table('course_enrollments')->whereNull('document_mode')->count());
        $this->assertSame(0, DB::table('course_enrollments')->whereNotNull('document_mode')->whereNull('document_mode_selected_at')->count());
    }

    public function test_backfill_rechecks_for_drift_after_successful_preflight_and_rolls_back(): void
    {
        $course = $this->insertCourse([
            'document_mode' => null,
            'document_mode_selected_at' => null,
            'document_mode_selected_by_user_id' => null,
        ]);

        $this->assertSame(Command::SUCCESS, $this->runPhase('preflight'));

        DB::table('course_enrollments')
            ->where('id', $course['id'])
            ->update(['document_mode_selected_at' => now()]);

        $this->assertPhaseFailsWith('backfill', 'legacy course rows have partial document-mode selection state');

        $stored = DB::table('course_enrollments')->where('id', $course['id'])->firstOrFail();
        $this->assertNull($stored->document_mode);
        $this->assertNotNull($stored->document_mode_selected_at);
        $this->assertSame(0, DB::table('migrations')->where('migration', self::BACKFILL_MIGRATION)->count());
    }

    public function test_successful_backfill_is_idempotent_at_controlled_executor_boundary(): void
    {
        $this->insertCourse([
            'document_mode' => null,
            'document_mode_selected_at' => null,
            'document_mode_selected_by_user_id' => null,
        ]);

        $this->assertSame(Command::SUCCESS, $this->runPhase('preflight'));
        $this->assertSame(Command::SUCCESS, $this->runPhase('backfill'));

        $snapshot = DB::table('course_enrollments')
            ->select(['id', 'document_mode', 'document_mode_selected_at', 'document_mode_selected_by_user_id', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        $this->assertSame(Command::SUCCESS, $this->runPhase('backfill'));

        $this->assertSame($snapshot, DB::table('course_enrollments')
            ->select(['id', 'document_mode', 'document_mode_selected_at', 'document_mode_selected_by_user_id', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all());
        $this->assertSame(1, DB::table('migrations')->where('migration', self::BACKFILL_MIGRATION)->count());
    }

    public function test_contract_phase_remains_fail_closed_after_validate_materialization(): void
    {
        $extension = app(Stage5FormalDocumentsMigrationPlan::class);
        $extension->validate();

        $exit = Artisan::call('migration:stage5:formal-docs:controlled', [
            '--plan' => $extension->identity(),
            '--execution' => $extension->executionIdentity(),
            '--phase' => 'contract',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString(
            'No materialized Stage-5 formal-documents migration steps for phase contract',
            Artisan::output(),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{id:string, organization_id:string}
     */
    private function insertCourse(array $overrides): array
    {
        $id = (string) Str::uuid7();
        $organizationId = (string) Str::uuid7();
        $now = now()->subDay();

        DB::table('course_enrollments')->insert(array_merge([
            'id' => $id,
            'organization_id' => $organizationId,
            'student_id' => (string) Str::uuid7(),
            'training_type' => 'basic',
            'driving_category_id' => (string) Str::uuid7(),
            'started_at' => $now,
            'lead_instructor_id' => (string) Str::uuid7(),
            'created_at' => $now->copy()->subDay(),
            'updated_at' => $now,
        ], $overrides));

        return [
            'id' => $id,
            'organization_id' => $organizationId,
        ];
    }

    private function runPhase(string $phase): int
    {
        $extension = app(Stage5FormalDocumentsMigrationPlan::class);
        $extension->validate();

        return Artisan::call('migration:stage5:formal-docs:controlled', [
            '--plan' => $extension->identity(),
            '--execution' => $extension->executionIdentity(),
            '--phase' => $phase,
            '--force' => true,
        ]);
    }

    private function assertPhaseFailsWith(string $phase, string $expectedMessage): void
    {
        try {
            $exit = $this->runPhase($phase);
            $this->assertNotSame(Command::SUCCESS, $exit, "{$phase} unexpectedly succeeded.");
            $this->assertStringContainsString($expectedMessage, Artisan::output());
        } catch (Throwable $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }
}
