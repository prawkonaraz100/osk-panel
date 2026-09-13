<?php

namespace Tests\Feature;

use App\Support\Migrations\Stage5FormalDocumentsMigrationPlan;
use Database\Seeders\ResourceReferenceCatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class FormalTrainingDocumentRuntimeTest extends TestCase
{
    /** @var list<string> */
    private const STAGE5_PHASE_MIGRATIONS = [
        '2026_09_13_000050_preflight_formal_document_mode_legacy_rows',
        '2026_09_13_000060_backfill_formal_document_mode_legacy_rows',
        '2026_09_13_000070_validate_formal_document_mode',
        '2026_09_13_000080_validate_formal_training_document_templates',
        '2026_09_13_000090_validate_formal_training_documents',
        '2026_09_13_000095_validate_formal_training_document_events',
        '2026_09_13_000110_contract_formal_document_mode_not_null',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['formal_documents.document_storage_disk' => 'local']);

        FoundationSchema::ensureMigrated();
        $this->dropFormalDocumentFinalArtifacts();
        $this->resetPhaseRegistrations();
        FoundationSchema::reset();
        $this->applyFinalFormalDocumentSchema();
    }

    protected function tearDown(): void
    {
        $this->dropFormalDocumentFinalArtifacts();
        $this->resetPhaseRegistrations();
        FoundationSchema::reset();

        parent::tearDown();
    }

    public function test_preview_approval_download_and_replay_use_exact_immutable_evidence(): void
    {
        $actor = $this->formalActor();
        $course = $this->courseFixture($actor);

        $preview = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/course-enrollments/{$course['id']}/formal-documents/preview?document_type=training_record_card")
            ->assertOk();

        $this->assertSame($course['id'], $preview->json('course_enrollment_id'));
        $this->assertSame(1, $preview->json('course_version'));
        $this->assertSame(1, $preview->json('requirements_revision'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $preview->json('evidence_bundle_hash'));
        $this->assertSame('regeneration_required', $preview->json('freshness'));
        $this->assertSame(45, $preview->json('totals.osk_theory_minutes'));
        $this->assertSame(120, $preview->json('totals.external_practical_minutes'));

        $approval = $this->approvalPayload($preview->json());
        $key = (string) Str::uuid7();
        $approved = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->withHeader('If-Match', '"v1"')
            ->postJson("/api/v1/course-enrollments/{$course['id']}/formal-documents", $approval)
            ->assertCreated();

        $documentId = (string) $approved->json('id');
        $this->assertSame(1, $approved->json('revision'));
        $this->assertSame($preview->json('evidence_bundle_hash'), $approved->json('evidence_bundle_hash'));
        $this->assertDatabaseCount('formal_training_documents', 1);
        $this->assertSame(
            ['approved', 'generated'],
            DB::table('formal_training_document_events')
                ->where('formal_training_document_id', $documentId)
                ->orderBy('event_type')
                ->pluck('event_type')
                ->all(),
        );
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'formal_document.approved')->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('event_type', 'formal_document.approved')->count());

        $replayed = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->withHeader('If-Match', '"v1"')
            ->postJson("/api/v1/course-enrollments/{$course['id']}/formal-documents", $approval)
            ->assertCreated();
        $this->assertSame($documentId, $replayed->json('id'));
        $this->assertDatabaseCount('formal_training_documents', 1);

        $sameEvidenceNewKey = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withHeader('If-Match', '"v1"')
            ->postJson("/api/v1/course-enrollments/{$course['id']}/formal-documents", $approval)
            ->assertCreated();
        $this->assertSame($documentId, $sameEvidenceNewKey->json('id'));
        $this->assertDatabaseCount('formal_training_documents', 1);

        $download = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->get("/api/v1/formal-training-documents/{$documentId}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $contentHash = (string) DB::table('formal_training_documents')->where('id', $documentId)->value('content_hash');
        $this->assertSame($contentHash, hash('sha256', $download->getContent()));
        $this->assertStringStartsWith('%PDF-1.4', $download->getContent());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'formal_document.downloaded')->count());

        $fresh = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/course-enrollments/{$course['id']}/formal-documents/preview?document_type=training_record_card")
            ->assertOk();
        $this->assertSame('fresh', $fresh->json('freshness'));
        $this->assertSame($documentId, $fresh->json('latest_document.id'));
    }

    public function test_approval_rejects_stale_preview_when_evidence_changes_without_course_version_change(): void
    {
        $actor = $this->formalActor();
        $course = $this->courseFixture($actor);

        $preview = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/course-enrollments/{$course['id']}/formal-documents/preview?document_type=training_record_card")
            ->assertOk();

        DB::table('training_hour_ledger_entries')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'course_enrollment_id' => $course['id'],
            'training_session_id' => null,
            'entry_type' => 'correction',
            'training_part' => 'theory',
            'minutes' => 15,
            'source_entry_id' => null,
            'reason' => 'direct SQL drift proof',
            'actor_user_id' => $actor['user_id'],
            'created_at' => now(),
        ]);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withHeader('If-Match', '"v1"')
            ->postJson(
                "/api/v1/course-enrollments/{$course['id']}/formal-documents",
                $this->approvalPayload($preview->json()),
            )
            ->assertStatus(409);

        $this->assertDatabaseCount('formal_training_documents', 0);
        $this->assertDatabaseCount('file_assets', 0);
    }

    public function test_source_correction_creates_new_revision_and_preserves_old_document(): void
    {
        $actor = $this->formalActor();
        $course = $this->courseFixture($actor);

        $firstPreview = $this->preview($actor, $course['id'], 'training_record_card');
        $first = $this->approveFromPreview($actor, $course['id'], $firstPreview, '"v1"');

        DB::table('training_hour_ledger_entries')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'course_enrollment_id' => $course['id'],
            'training_session_id' => null,
            'entry_type' => 'correction',
            'training_part' => 'theory',
            'minutes' => 15,
            'source_entry_id' => null,
            'reason' => 'reviewed correction',
            'actor_user_id' => $actor['user_id'],
            'created_at' => now(),
        ]);
        DB::table('course_enrollments')->where('id', $course['id'])->update([
            'version' => 2,
            'updated_at' => now(),
        ]);

        $secondPreview = $this->preview($actor, $course['id'], 'training_record_card');
        $this->assertNotSame($firstPreview['evidence_bundle_hash'], $secondPreview['evidence_bundle_hash']);
        $this->assertSame('regeneration_required', $secondPreview['freshness']);

        $second = $this->approveFromPreview($actor, $course['id'], $secondPreview, '"v2"');

        $this->assertSame(1, $first['revision']);
        $this->assertSame(2, $second['revision']);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertDatabaseCount('formal_training_documents', 2);
        $this->assertDatabaseHas('formal_training_documents', [
            'id' => $first['id'],
            'revision' => 1,
            'evidence_bundle_hash' => $firstPreview['evidence_bundle_hash'],
        ]);
    }

    public function test_theory_journal_uses_same_canonical_evidence_without_inventing_module_source(): void
    {
        $actor = $this->formalActor();
        $course = $this->courseFixture($actor);

        $preview = $this->preview($actor, $course['id'], 'theory_delivery_journal');
        $approved = $this->approveFromPreview($actor, $course['id'], $preview, '"v1"');

        $this->assertSame('theory_delivery_journal', $approved['document_type']);
        $this->assertSame(45, $preview['totals']['osk_theory_minutes']);
        $this->assertSame(1, DB::table('formal_training_documents')
            ->where('document_type', 'theory_delivery_journal')->count());
    }

    public function test_cross_tenant_document_routes_fail_closed(): void
    {
        $owner = $this->formalActor();
        $other = $this->formalActor();
        $course = $this->courseFixture($owner);

        $preview = $this->preview($owner, $course['id'], 'training_record_card');
        $document = $this->approveFromPreview($owner, $course['id'], $preview, '"v1"');

        $this->withSession(['auth_session_id' => $other['session_id']])
            ->getJson("/api/v1/course-enrollments/{$course['id']}/formal-documents/preview?document_type=training_record_card")
            ->assertNotFound();

        $this->withSession(['auth_session_id' => $other['session_id']])
            ->get("/api/v1/formal-training-documents/{$document['id']}/file")
            ->assertNotFound();
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function formalActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach (['formal_documents.view', 'formal_documents.approve', 'formal_documents.download'] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array{id:string}
     */
    private function courseFixture(array $actor): array
    {
        $studentId = (string) Str::uuid7();
        DB::table('students')->insert([
            'id' => $studentId,
            'organization_id' => $actor['organization_id'],
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'birth_date' => '1990-05-17',
            'no_pesel_declared' => true,
            'version' => 1,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $instructorId = (string) Str::uuid7();
        DB::table('staff_profiles')->insert([
            'id' => $instructorId,
            'organization_id' => $actor['organization_id'],
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email_normalized' => strtolower($instructorId).'@example.test',
            'authorization_number' => 'INSTR-001',
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $categoryId = (string) DB::table('driving_categories')->where('code', 'B')->value('id');
        $courseId = (string) Str::uuid7();
        DB::table('course_enrollments')->insert([
            'id' => $courseId,
            'organization_id' => $actor['organization_id'],
            'student_id' => $studentId,
            'training_type' => 'basic',
            'driving_category_id' => $categoryId,
            'started_at' => now()->subDays(10),
            'lead_instructor_id' => $instructorId,
            'training_stage' => 'theory',
            'version' => 1,
            'requirements_revision' => 1,
            'document_mode' => 'paper',
            'document_mode_selected_at' => now()->subDays(11),
            'document_mode_selected_by_user_id' => $actor['user_id'],
            'created_at' => now()->subDays(12),
            'updated_at' => now()->subDays(10),
        ]);

        DB::table('training_requirement_profiles')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'course_enrollment_id' => $courseId,
            'requirements_revision' => 1,
            'course_version_after' => 1,
            'rule_set_version' => 'test-v1',
            'trigger_code' => 'course_create',
            'calculation_reason' => null,
            'calculated_by_user_id' => $actor['user_id'],
            'input_snapshot' => '{}',
            'base_output_snapshot' => '{}',
            'effective_output_snapshot' => '{}',
            'manual_override_decision_id' => null,
            'theory_training_required' => true,
            'minimum_theory_minutes' => 1350,
            'internal_theory_exam_required' => true,
            'practical_training_required' => true,
            'minimum_practical_minutes' => 1800,
            'internal_practical_exam_required' => true,
            'exemption_basis_code' => null,
            'calculated_at' => now()->subDays(10),
            'superseded_at' => null,
        ]);

        DB::table('training_hour_ledger_entries')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'course_enrollment_id' => $courseId,
            'training_session_id' => null,
            'entry_type' => 'credit',
            'training_part' => 'theory',
            'minutes' => 45,
            'source_entry_id' => null,
            'reason' => 'synthetic formal theory evidence',
            'actor_user_id' => $actor['user_id'],
            'created_at' => now()->subDays(5),
        ]);

        DB::table('recognized_external_training')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'course_enrollment_id' => $courseId,
            'training_part' => 'practical',
            'recognized_minutes' => 120,
            'record_role' => 'documented_transfer',
            'source_kind' => 'documented_transfer',
            'source_school_reference' => 'OSK-OLD',
            'evidence_reference' => 'DOC-001',
            'reason' => 'synthetic transfer',
            'approved_by_user_id' => $actor['user_id'],
            'recognized_for_driving_category_id' => $categoryId,
            'recognized_for_training_type' => 'basic',
            'supersedes_record_id' => null,
            'created_at' => now()->subDays(9),
            'superseded_at' => null,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revocation_reason' => null,
        ]);

        return ['id' => $courseId];
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array<string,mixed>
     */
    private function preview(array $actor, string $courseId, string $documentType): array
    {
        return $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/course-enrollments/{$courseId}/formal-documents/preview?document_type={$documentType}")
            ->assertOk()
            ->json();
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $preview
     * @return array<string,mixed>
     */
    private function approveFromPreview(array $actor, string $courseId, array $preview, string $etag): array
    {
        return $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withHeader('If-Match', $etag)
            ->postJson("/api/v1/course-enrollments/{$courseId}/formal-documents", $this->approvalPayload($preview))
            ->assertCreated()
            ->json();
    }

    /**
     * @param  array<string,mixed>  $preview
     * @return array<string,mixed>
     */
    private function approvalPayload(array $preview): array
    {
        /** @var array<string,mixed> $template */
        $template = $preview['template'];

        return [
            'document_type' => $preview['document_type'],
            'requirements_revision' => $preview['requirements_revision'],
            'evidence_bundle_hash' => $preview['evidence_bundle_hash'],
            'template_id' => $template['id'],
            'template_version' => $template['template_version'],
            'renderer_version' => $template['renderer_version'],
            'template_content_hash' => $template['template_content_hash'],
        ];
    }

    private function applyFinalFormalDocumentSchema(): void
    {
        foreach (['preflight', 'backfill', 'validate', 'contract'] as $phase) {
            $extension = app(Stage5FormalDocumentsMigrationPlan::class);
            $extension->validate();
            $exit = Artisan::call('migration:stage5:formal-docs:controlled', [
                '--plan' => $extension->identity(),
                '--execution' => $extension->executionIdentity(),
                '--phase' => $phase,
                '--force' => true,
            ]);
            $this->assertSame(Command::SUCCESS, $exit, Artisan::output());
        }

        app(ResourceReferenceCatalogSeeder::class)->run();
    }

    private function resetPhaseRegistrations(): void
    {
        DB::table('migrations')->whereIn('migration', self::STAGE5_PHASE_MIGRATIONS)->delete();
    }

    private function dropFormalDocumentFinalArtifacts(): void
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

        DB::statement(<<<'SQL'
ALTER TABLE course_enrollments
    ALTER COLUMN document_mode DROP NOT NULL,
    ALTER COLUMN document_mode_selected_at DROP NOT NULL
SQL);
    }
}
