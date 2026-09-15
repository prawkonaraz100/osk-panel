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

final class FormalTrainingDocumentMigrationValidateTest extends TestCase
{
    private const PREFLIGHT_MIGRATION = '2026_09_13_000050_preflight_formal_document_mode_legacy_rows';

    private const BACKFILL_MIGRATION = '2026_09_13_000060_backfill_formal_document_mode_legacy_rows';

    private const VALIDATE_MIGRATIONS = [
        '2026_09_13_000070_validate_formal_document_mode',
        '2026_09_13_000080_validate_formal_training_document_templates',
        '2026_09_13_000090_validate_formal_training_documents',
        '2026_09_13_000095_validate_formal_training_document_events',
    ];

    private string $evidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evidencePath = storage_path('framework/testing/formal-doc-006-migration-evidence.jsonl');
        @unlink($this->evidencePath);
        config(['migration.evidence_path' => $this->evidencePath]);

        FoundationSchema::ensureMigrated();
        $this->dropValidateArtifacts();
        FoundationSchema::reset();
        $this->resetPhaseRegistrations();

        $this->assertSame(Command::SUCCESS, $this->runPhase('preflight'));
        $this->assertSame(Command::SUCCESS, $this->runPhase('backfill'));
        $this->assertSame(Command::SUCCESS, $this->runPhase('validate'));
    }

    protected function tearDown(): void
    {
        $this->dropValidateArtifacts();
        $this->resetPhaseRegistrations();
        @unlink($this->evidencePath);

        parent::tearDown();
    }

    public function test_validate_phase_is_fully_registered_idempotent_and_contract_is_registered(): void
    {
        $extension = app(Stage5FormalDocumentsMigrationPlan::class);
        $extension->validate();

        $this->assertSame(11, $extension->implementedStepCount());
        $this->assertSame([
            'S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE',
            'S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES',
            'S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS',
            'S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS',
        ], array_column($extension->phaseSteps('validate'), 'node_id'));
        $this->assertSame(4, DB::table('migrations')->whereIn('migration', self::VALIDATE_MIGRATIONS)->count());

        $this->assertSame(Command::SUCCESS, $this->runPhase('validate'));
        $this->assertSame(4, DB::table('migrations')->whereIn('migration', self::VALIDATE_MIGRATIONS)->count());

        $this->assertSame(
            ['S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE'],
            array_column($extension->phaseSteps('contract'), 'node_id'),
        );
    }

    public function test_validate_proves_completeness_without_setting_column_not_null_contract(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'course_enrollments')
            ->whereIn('column_name', ['document_mode', 'document_mode_selected_at'])
            ->pluck('is_nullable', 'column_name')
            ->all();

        $this->assertSame('YES', $columns['document_mode']);
        $this->assertSame('YES', $columns['document_mode_selected_at']);

        $this->assertQueryFails(function (): void {
            $this->insertCourse((string) Str::uuid7(), [
                'document_mode' => null,
                'document_mode_selected_at' => null,
            ]);
        });
    }

    public function test_document_mode_change_is_allowed_before_start_and_rejected_at_or_after_start(): void
    {
        $organizationId = (string) Str::uuid7();
        $future = $this->insertCourse($organizationId, [
            'started_at' => now()->addDay(),
            'document_mode' => 'paper',
            'document_mode_selected_at' => now(),
        ]);

        DB::table('course_enrollments')->where('id', $future['id'])->update([
            'document_mode' => 'electronic',
            'document_mode_selected_at' => now(),
            'document_mode_selected_by_user_id' => (string) Str::uuid7(),
        ]);

        $this->assertSame('electronic', DB::table('course_enrollments')->where('id', $future['id'])->value('document_mode'));

        $started = $this->insertCourse($organizationId, [
            'started_at' => now()->subMinute(),
            'document_mode' => 'paper',
            'document_mode_selected_at' => now()->subHour(),
        ]);

        $this->assertQueryFails(function () use ($started): void {
            DB::table('course_enrollments')->where('id', $started['id'])->update([
                'document_mode' => 'electronic',
                'document_mode_selected_at' => now(),
                'document_mode_selected_by_user_id' => (string) Str::uuid7(),
            ]);
        });

        $this->assertSame('paper', DB::table('course_enrollments')->where('id', $started['id'])->value('document_mode'));
    }

    public function test_template_effective_intervals_do_not_overlap_per_document_type(): void
    {
        $from = now()->startOfSecond();
        $this->insertTemplate([
            'document_type' => 'training_record_card',
            'template_version' => 'v1',
            'effective_from' => $from,
            'effective_to' => $from->copy()->addDay(),
        ]);

        $this->assertQueryFails(function () use ($from): void {
            $this->insertTemplate([
                'document_type' => 'training_record_card',
                'template_version' => 'v2',
                'effective_from' => $from->copy()->addHour(),
                'effective_to' => $from->copy()->addDays(2),
            ]);
        });

        $this->insertTemplate([
            'document_type' => 'training_record_card',
            'template_version' => 'v3',
            'effective_from' => $from->copy()->addDay(),
            'effective_to' => $from->copy()->addDays(2),
        ]);

        $this->insertTemplate([
            'document_type' => 'theory_delivery_journal',
            'template_version' => 'v1',
            'effective_from' => $from,
            'effective_to' => $from->copy()->addDays(2),
        ]);

        $this->assertDatabaseCount('formal_training_document_templates', 3);
    }

    public function test_document_requires_same_tenant_course_asset_and_exact_template_snapshot(): void
    {
        $organizationA = (string) Str::uuid7();
        $organizationB = (string) Str::uuid7();

        $courseA = $this->insertCourse($organizationA);
        $courseB = $this->insertCourse($organizationB);
        $assetA = $this->insertAsset($organizationA, 'ready');
        $assetB = $this->insertAsset($organizationB, 'ready');
        $template = $this->insertTemplate();

        $valid = $this->insertDocument($organizationA, $courseA['id'], $assetA['id'], $template);
        $this->assertDatabaseHas('formal_training_documents', ['id' => $valid['id']]);

        $this->assertQueryFails(function () use ($organizationA, $courseB, $assetA, $template): void {
            $this->insertDocument($organizationA, $courseB['id'], $assetA['id'], $template, ['revision' => 2]);
        });

        $this->assertQueryFails(function () use ($organizationA, $courseA, $assetB, $template): void {
            $this->insertDocument($organizationA, $courseA['id'], $assetB['id'], $template, ['revision' => 3]);
        });

        $this->assertQueryFails(function () use ($organizationA, $courseA, $assetA, $template): void {
            $this->insertDocument($organizationA, $courseA['id'], $assetA['id'], $template, [
                'revision' => 4,
                'renderer_version_snapshot' => 'renderer-mismatch',
            ]);
        });
    }

    public function test_document_revision_and_used_template_are_immutable(): void
    {
        $organizationId = (string) Str::uuid7();
        $course = $this->insertCourse($organizationId);
        $asset = $this->insertAsset($organizationId, 'ready');
        $template = $this->insertTemplate();
        $document = $this->insertDocument($organizationId, $course['id'], $asset['id'], $template);

        $this->assertQueryFails(function () use ($document): void {
            DB::table('formal_training_documents')->where('id', $document['id'])->update([
                'content_hash' => str_repeat('f', 64),
            ]);
        });

        $this->assertQueryFails(function () use ($document): void {
            DB::table('formal_training_documents')->where('id', $document['id'])->delete();
        });

        $this->assertQueryFails(function () use ($template): void {
            DB::table('formal_training_document_templates')->where('id', $template['id'])->update([
                'effective_to' => now()->addMonth(),
            ]);
        });

        $this->assertDatabaseHas('formal_training_documents', ['id' => $document['id']]);
        $this->assertDatabaseHas('formal_training_document_templates', ['id' => $template['id']]);
    }

    public function test_event_requires_same_tenant_document_and_ready_optional_asset(): void
    {
        $organizationA = (string) Str::uuid7();
        $organizationB = (string) Str::uuid7();

        $courseA = $this->insertCourse($organizationA);
        $courseB = $this->insertCourse($organizationB);
        $canonicalAssetA = $this->insertAsset($organizationA, 'ready');
        $canonicalAssetB = $this->insertAsset($organizationB, 'ready');
        $readyAssetA = $this->insertAsset($organizationA, 'ready');
        $readyAssetB = $this->insertAsset($organizationB, 'ready');
        $pendingAssetA = $this->insertAsset($organizationA, 'pending');
        $templateA = $this->insertTemplate(['template_version' => 'v1']);
        $templateB = $this->insertTemplate([
            'document_type' => 'theory_delivery_journal',
            'template_version' => 'v1',
        ]);
        $documentA = $this->insertDocument($organizationA, $courseA['id'], $canonicalAssetA['id'], $templateA);
        $documentB = $this->insertDocument($organizationB, $courseB['id'], $canonicalAssetB['id'], $templateB);

        $event = $this->insertEvent($organizationA, $documentA['id'], $readyAssetA['id']);
        $this->assertDatabaseHas('formal_training_document_events', ['id' => $event['id']]);

        $this->assertQueryFails(function () use ($organizationA, $documentB, $readyAssetA): void {
            $this->insertEvent($organizationA, $documentB['id'], $readyAssetA['id']);
        });

        $this->assertQueryFails(function () use ($organizationA, $documentA, $readyAssetB): void {
            $this->insertEvent($organizationA, $documentA['id'], $readyAssetB['id']);
        });

        $this->assertQueryFails(function () use ($organizationA, $documentA, $pendingAssetA): void {
            $this->insertEvent($organizationA, $documentA['id'], $pendingAssetA['id']);
        });
    }

    public function test_document_events_are_append_only(): void
    {
        $organizationId = (string) Str::uuid7();
        $course = $this->insertCourse($organizationId);
        $asset = $this->insertAsset($organizationId, 'ready');
        $template = $this->insertTemplate();
        $document = $this->insertDocument($organizationId, $course['id'], $asset['id'], $template);
        $event = $this->insertEvent($organizationId, $document['id'], null);

        $this->assertQueryFails(function () use ($event): void {
            DB::table('formal_training_document_events')->where('id', $event['id'])->update([
                'reason' => 'rewritten history',
            ]);
        });

        $this->assertQueryFails(function () use ($event): void {
            DB::table('formal_training_document_events')->where('id', $event['id'])->delete();
        });

        $this->assertDatabaseHas('formal_training_document_events', ['id' => $event['id']]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{id:string, organization_id:string}
     */
    private function insertCourse(string $organizationId, array $overrides = []): array
    {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('course_enrollments')->insert(array_merge([
            'id' => $id,
            'organization_id' => $organizationId,
            'student_id' => (string) Str::uuid7(),
            'training_type' => 'basic',
            'driving_category_id' => (string) Str::uuid7(),
            'started_at' => $now->copy()->addDay(),
            'lead_instructor_id' => (string) Str::uuid7(),
            'document_mode' => 'paper',
            'document_mode_selected_at' => $now,
            'document_mode_selected_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return ['id' => $id, 'organization_id' => $organizationId];
    }

    /**
     * @return array{id:string, organization_id:string}
     */
    private function insertAsset(string $organizationId, string $status): array
    {
        $id = (string) Str::uuid7();

        DB::table('file_assets')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'storage_disk' => 'local',
            'storage_key' => "formal-documents/{$id}.pdf",
            'size_bytes' => 123,
            'purpose' => 'formal_training_document',
            'status' => $status,
            'created_at' => now(),
            'ready_at' => $status === 'ready' ? now() : null,
        ]);

        return ['id' => $id, 'organization_id' => $organizationId];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{id:string, document_type:string, template_version:string, renderer_version:string, template_content_hash:string}
     */
    private function insertTemplate(array $overrides = []): array
    {
        $id = (string) Str::uuid7();
        $values = array_merge([
            'id' => $id,
            'document_type' => 'training_record_card',
            'template_version' => 'v-'.substr(str_replace('-', '', $id), 0, 12),
            'renderer_version' => 'renderer-v1',
            'template_content_hash' => hash('sha256', $id),
            'effective_from' => now()->subDay(),
            'effective_to' => null,
            'created_at' => now(),
        ], $overrides);

        DB::table('formal_training_document_templates')->insert($values);

        return [
            'id' => $id,
            'document_type' => $values['document_type'],
            'template_version' => $values['template_version'],
            'renderer_version' => $values['renderer_version'],
            'template_content_hash' => $values['template_content_hash'],
        ];
    }

    /**
     * @param  array{id:string, document_type:string, template_version:string, renderer_version:string, template_content_hash:string}  $template
     * @param  array<string, mixed>  $overrides
     * @return array{id:string}
     */
    private function insertDocument(
        string $organizationId,
        string $courseId,
        string $assetId,
        array $template,
        array $overrides = [],
    ): array {
        $id = (string) Str::uuid7();

        DB::table('formal_training_documents')->insert(array_merge([
            'id' => $id,
            'organization_id' => $organizationId,
            'course_enrollment_id' => $courseId,
            'document_type' => $template['document_type'],
            'revision' => 1,
            'document_mode_snapshot' => 'paper',
            'formal_training_document_template_id' => $template['id'],
            'template_version_snapshot' => $template['template_version'],
            'renderer_version_snapshot' => $template['renderer_version'],
            'template_hash_snapshot' => $template['template_content_hash'],
            'course_version_snapshot' => 1,
            'requirements_revision_snapshot' => 1,
            'evidence_bundle_hash' => hash('sha256', 'evidence-'.$id),
            'asset_id' => $assetId,
            'content_hash' => hash('sha256', 'content-'.$id),
            'approved_by_user_id' => null,
            'approved_at' => null,
            'generated_by_user_id' => null,
            'generated_at' => now(),
            'created_at' => now(),
        ], $overrides));

        return ['id' => $id];
    }

    /**
     * @return array{id:string}
     */
    private function insertEvent(string $organizationId, string $documentId, ?string $optionalAssetId): array
    {
        $id = (string) Str::uuid7();

        DB::table('formal_training_document_events')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'formal_training_document_id' => $documentId,
            'event_type' => $optionalAssetId === null ? 'printed' : 'signed_scan_attached',
            'actor_user_id' => null,
            'reason' => null,
            'optional_asset_id' => $optionalAssetId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return ['id' => $id];
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
        ))->delete();
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
