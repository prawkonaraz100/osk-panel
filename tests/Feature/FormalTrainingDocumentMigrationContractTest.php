<?php

namespace Tests\Feature;

use App\Support\Migrations\Stage5FormalDocumentsMigrationPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FoundationSchema;
use Tests\TestCase;
use Throwable;

final class FormalTrainingDocumentMigrationContractTest extends TestCase
{
    private const PREFLIGHT_MIGRATION = '2026_09_13_000050_preflight_formal_document_mode_legacy_rows';

    private const BACKFILL_MIGRATION = '2026_09_13_000060_backfill_formal_document_mode_legacy_rows';

    private const VALIDATE_MIGRATIONS = [
        '2026_09_13_000070_validate_formal_document_mode',
        '2026_09_13_000080_validate_formal_training_document_templates',
        '2026_09_13_000090_validate_formal_training_documents',
        '2026_09_13_000095_validate_formal_training_document_events',
    ];

    private const CONTRACT_MIGRATION = '2026_09_13_000110_contract_formal_document_mode_not_null';

    private string $evidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evidencePath = storage_path('framework/testing/formal-doc-007-migration-evidence.jsonl');
        @unlink($this->evidencePath);
        config(['migration.evidence_path' => $this->evidencePath]);

        FoundationSchema::ensureMigrated();
        $this->dropValidateArtifacts();
        $this->dropContractShape();
        FoundationSchema::reset();
        $this->resetPhaseRegistrations();
    }

    protected function tearDown(): void
    {
        $this->dropValidateArtifacts();
        $this->dropContractShape();
        $this->resetPhaseRegistrations();
        @unlink($this->evidencePath);

        parent::tearDown();
    }

    public function test_contract_requires_validate_phase_to_be_applied_first(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runPhase('preflight'));
        $this->assertSame(Command::SUCCESS, $this->runPhase('backfill'));

        $this->assertPhaseFailsWith(
            'contract',
            'Earlier Stage-5 phase validate is not fully applied',
        );

        $this->assertSame(0, DB::table('migrations')->where('migration', self::CONTRACT_MIGRATION)->count());
        $this->assertColumnNullability('YES', 'YES', 'YES');
    }

    public function test_contract_sets_exact_required_columns_not_null_and_keeps_legacy_actor_nullable(): void
    {
        $this->applyThroughValidate();

        $this->assertSame(Command::SUCCESS, $this->runPhase('contract'));

        $this->assertColumnNullability('NO', 'NO', 'YES');
        $this->assertSame(1, DB::table('migrations')->where('migration', self::CONTRACT_MIGRATION)->count());
    }

    public function test_contract_preserves_paper_and_server_timestamp_defaults_for_new_courses(): void
    {
        $this->applyThroughValidate();
        $this->assertSame(Command::SUCCESS, $this->runPhase('contract'));

        $id = (string) Str::uuid7();
        DB::table('course_enrollments')->insert([
            'id' => $id,
            'organization_id' => (string) Str::uuid7(),
            'student_id' => (string) Str::uuid7(),
            'training_type' => 'basic',
            'driving_category_id' => (string) Str::uuid7(),
            'started_at' => now()->addDay(),
            'lead_instructor_id' => (string) Str::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $course = DB::table('course_enrollments')->where('id', $id)->firstOrFail();

        $this->assertSame('paper', $course->document_mode);
        $this->assertNotNull($course->document_mode_selected_at);
        $this->assertNull($course->document_mode_selected_by_user_id);
    }

    public function test_contract_rejects_explicit_null_mode_or_selection_timestamp_at_column_boundary(): void
    {
        $this->applyThroughValidate();
        $this->assertSame(Command::SUCCESS, $this->runPhase('contract'));

        $this->assertQueryFails(function (): void {
            DB::table('course_enrollments')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => (string) Str::uuid7(),
                'student_id' => (string) Str::uuid7(),
                'training_type' => 'basic',
                'driving_category_id' => (string) Str::uuid7(),
                'started_at' => now()->addDay(),
                'lead_instructor_id' => (string) Str::uuid7(),
                'document_mode' => null,
                'document_mode_selected_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_contract_controlled_executor_is_idempotent(): void
    {
        $this->applyThroughValidate();

        $this->assertSame(Command::SUCCESS, $this->runPhase('contract'));
        $this->assertSame(Command::SUCCESS, $this->runPhase('contract'));

        $this->assertSame(1, DB::table('migrations')->where('migration', self::CONTRACT_MIGRATION)->count());
        $this->assertColumnNullability('NO', 'NO', 'YES');
    }

    public function test_contract_refuses_to_run_when_validate_constraint_evidence_is_missing(): void
    {
        $this->applyThroughValidate();

        DB::statement(
            'ALTER TABLE course_enrollments DROP CONSTRAINT course_enrollments_document_mode_complete_check'
        );

        $this->assertPhaseFailsWith(
            'contract',
            'requires both document-mode validation constraints to exist and be validated',
        );

        $this->assertSame(0, DB::table('migrations')->where('migration', self::CONTRACT_MIGRATION)->count());
        $this->assertColumnNullability('YES', 'YES', 'YES');
    }

    private function applyThroughValidate(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runPhase('preflight'));
        $this->assertSame(Command::SUCCESS, $this->runPhase('backfill'));
        $this->assertSame(Command::SUCCESS, $this->runPhase('validate'));
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

    private function assertColumnNullability(string $mode, string $selectedAt, string $selectedBy): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'course_enrollments')
            ->whereIn('column_name', [
                'document_mode',
                'document_mode_selected_at',
                'document_mode_selected_by_user_id',
            ])
            ->pluck('is_nullable', 'column_name')
            ->all();

        $this->assertSame($mode, $columns['document_mode']);
        $this->assertSame($selectedAt, $columns['document_mode_selected_at']);
        $this->assertSame($selectedBy, $columns['document_mode_selected_by_user_id']);
    }

    private function assertQueryFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected PostgreSQL to reject the operation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function resetPhaseRegistrations(): void
    {
        DB::table('migrations')->whereIn('migration', array_merge(
            [self::PREFLIGHT_MIGRATION, self::BACKFILL_MIGRATION],
            self::VALIDATE_MIGRATIONS,
            [self::CONTRACT_MIGRATION],
        ))->delete();
    }

    private function dropContractShape(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE course_enrollments
    ALTER COLUMN document_mode DROP NOT NULL,
    ALTER COLUMN document_mode_selected_at DROP NOT NULL
SQL);
    }

    private function dropValidateArtifacts(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS formal_training_document_events_append_only_guard ON formal_training_document_events');
        DB::statement('DROP TRIGGER IF EXISTS formal_training_document_events_asset_ready_guard ON formal_training_document_events');
        DB::statement('DROP TRIGGER IF EXISTS formal_training_documents_immutable_guard ON formal_training_documents');
        DB::statement('DROP TRIGGER IF EXISTS formal_training_document_templates_used_immutable_guard ON formal_training_document_templates');
        DB::statement('DROP TRIGGER IF EXISTS course_enrollments_document_mode_after_start_guard ON course_enrollments');

        DB::statement('ALTER TABLE formal_training_document_events DROP CONSTRAINT IF EXISTS formal_training_document_events_optional_asset_same_tenant_fk');
        DB::statement('ALTER TABLE formal_training_document_events DROP CONSTRAINT IF EXISTS formal_training_document_events_document_same_tenant_fk');
        DB::statement('ALTER TABLE formal_training_documents DROP CONSTRAINT IF EXISTS formal_training_documents_template_snapshot_fk');
        DB::statement('ALTER TABLE formal_training_documents DROP CONSTRAINT IF EXISTS formal_training_documents_asset_same_tenant_fk');
        DB::statement('ALTER TABLE formal_training_documents DROP CONSTRAINT IF EXISTS formal_training_documents_course_same_tenant_fk');
        DB::statement('ALTER TABLE formal_training_document_templates DROP CONSTRAINT IF EXISTS formal_training_document_templates_effective_nonoverlap');
        DB::statement('ALTER TABLE course_enrollments DROP CONSTRAINT IF EXISTS course_enrollments_document_mode_complete_check');

        DB::statement('DROP INDEX IF EXISTS s5doc_formal_training_document_templates_snapshot_uidx');
        DB::statement('DROP INDEX IF EXISTS s5doc_formal_training_documents_org_id_uidx');
        DB::statement('DROP INDEX IF EXISTS s5doc_file_assets_org_id_uidx');
        DB::statement('DROP INDEX IF EXISTS s5doc_course_enrollments_org_id_uidx');

        DB::statement('DROP FUNCTION IF EXISTS s5doc_guard_formal_training_document_event_append_only()');
        DB::statement('DROP FUNCTION IF EXISTS s5doc_guard_formal_training_document_event_asset_ready()');
        DB::statement('DROP FUNCTION IF EXISTS s5doc_guard_formal_training_document_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS s5doc_guard_used_template_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS s5doc_guard_course_document_mode_after_start()');
    }
}
