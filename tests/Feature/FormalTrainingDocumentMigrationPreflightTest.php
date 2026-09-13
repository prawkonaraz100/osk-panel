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

final class FormalTrainingDocumentMigrationPreflightTest extends TestCase
{
    private const PREFLIGHT_MIGRATION = '2026_09_13_000050_preflight_formal_document_mode_legacy_rows';

    private string $evidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evidencePath = storage_path('framework/testing/formal-doc-004-migration-evidence.jsonl');
        @unlink($this->evidencePath);
        config(['migration.evidence_path' => $this->evidencePath]);

        FoundationSchema::reset();
        $this->resetPreflightRegistration();
    }

    protected function tearDown(): void
    {
        @unlink($this->evidencePath);
        parent::tearDown();
    }

    public function test_preflight_accepts_deterministic_legacy_null_state_without_backfill(): void
    {
        $course = $this->insertCourse([
            'document_mode' => null,
            'document_mode_selected_at' => null,
            'document_mode_selected_by_user_id' => null,
        ]);

        $before = DB::table('course_enrollments')->where('id', $course['id'])->firstOrFail();

        $exit = $this->runPreflight();

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(1, DB::table('migrations')->where('migration', self::PREFLIGHT_MIGRATION)->count());

        $after = DB::table('course_enrollments')->where('id', $course['id'])->firstOrFail();
        $this->assertSame($before->document_mode, $after->document_mode);
        $this->assertSame($before->document_mode_selected_at, $after->document_mode_selected_at);
        $this->assertSame($before->document_mode_selected_by_user_id, $after->document_mode_selected_by_user_id);
        $this->assertNull($after->document_mode);
        $this->assertNull($after->document_mode_selected_at);
        $this->assertNull($after->document_mode_selected_by_user_id);
    }

    public function test_preflight_rejects_partial_legacy_selection_state(): void
    {
        $this->insertCourse([
            'document_mode' => null,
            'document_mode_selected_at' => now(),
            'document_mode_selected_by_user_id' => null,
        ]);

        $this->assertPreflightFailsWith('legacy course rows have partial document-mode selection state');
        $this->assertSame(0, DB::table('migrations')->where('migration', self::PREFLIGHT_MIGRATION)->count());
    }

    public function test_preflight_rejects_selected_mode_without_timestamp(): void
    {
        $this->insertCourse([
            'document_mode' => 'paper',
            'document_mode_selected_at' => null,
            'document_mode_selected_by_user_id' => null,
        ]);

        $this->assertPreflightFailsWith('course rows have document mode without selection timestamp');
        $this->assertSame(0, DB::table('migrations')->where('migration', self::PREFLIGHT_MIGRATION)->count());
    }

    public function test_preflight_rejects_document_revision_bound_to_course_without_mode(): void
    {
        $course = $this->insertCourse([
            'document_mode' => null,
            'document_mode_selected_at' => null,
            'document_mode_selected_by_user_id' => null,
        ]);

        DB::table('formal_training_documents')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $course['organization_id'],
            'course_enrollment_id' => $course['id'],
            'document_type' => 'training_record_card',
            'revision' => 1,
            'document_mode_snapshot' => 'paper',
            'formal_training_document_template_id' => (string) Str::uuid7(),
            'template_version_snapshot' => 'v1',
            'renderer_version_snapshot' => 'renderer-v1',
            'template_hash_snapshot' => str_repeat('a', 64),
            'course_version_snapshot' => 1,
            'requirements_revision_snapshot' => 1,
            'evidence_bundle_hash' => str_repeat('b', 64),
            'asset_id' => (string) Str::uuid7(),
            'content_hash' => str_repeat('c', 64),
            'generated_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertPreflightFailsWith('formal document revisions already reference courses without a selected document mode');
        $this->assertSame(0, DB::table('migrations')->where('migration', self::PREFLIGHT_MIGRATION)->count());
        $this->assertSame(1, DB::table('formal_training_documents')->count());
    }

    public function test_validate_remains_fail_closed_after_backfill_materialization(): void
    {
        $extension = app(Stage5FormalDocumentsMigrationPlan::class);
        $extension->validate();

        $exit = Artisan::call('migration:stage5:formal-docs:controlled', [
            '--plan' => $extension->identity(),
            '--execution' => $extension->executionIdentity(),
            '--phase' => 'validate',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString(
            'No materialized Stage-5 formal-documents migration steps for phase validate',
            Artisan::output(),
        );
        $this->assertDatabaseCount('course_enrollments', 0);
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

    private function runPreflight(): int
    {
        $extension = app(Stage5FormalDocumentsMigrationPlan::class);
        $extension->validate();

        return Artisan::call('migration:stage5:formal-docs:controlled', [
            '--plan' => $extension->identity(),
            '--execution' => $extension->executionIdentity(),
            '--phase' => 'preflight',
            '--force' => true,
        ]);
    }

    private function assertPreflightFailsWith(string $expectedMessage): void
    {
        try {
            $exit = $this->runPreflight();
            $this->assertNotSame(Command::SUCCESS, $exit, 'Preflight unexpectedly succeeded.');
            $this->assertStringContainsString($expectedMessage, Artisan::output());
        } catch (Throwable $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    private function resetPreflightRegistration(): void
    {
        DB::table('migrations')->where('migration', self::PREFLIGHT_MIGRATION)->delete();
    }
}
