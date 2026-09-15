<?php

namespace Tests\Feature;

use App\Support\Migrations\Stage5FormalDocumentsMigrationPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class FormalTrainingDocumentMigrationExpandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_expand_materializes_exact_formal_document_schema(): void
    {
        $this->assertTrue(Schema::hasColumns('course_enrollments', [
            'document_mode',
            'document_mode_selected_at',
            'document_mode_selected_by_user_id',
        ]));
        $this->assertTrue(Schema::hasTable('formal_training_document_templates'));
        $this->assertTrue(Schema::hasTable('formal_training_documents'));
        $this->assertTrue(Schema::hasTable('formal_training_document_events'));

        $extension = app(Stage5FormalDocumentsMigrationPlan::class);
        $extension->validate();

        $this->assertSame(4, $extension->implementedNodeCount());
        $this->assertSame(11, $extension->implementedStepCount());
        $this->assertSame('31704fcab61761aa9a952d824dc543a6f46e7a3717792d0a9349cefaaf57651f', $extension->executionIdentity());
    }

    public function test_new_course_defaults_to_paper_with_server_selection_timestamp(): void
    {
        $courseId = (string) Str::uuid7();
        $organizationId = (string) Str::uuid7();

        DB::table('course_enrollments')->insert([
            'id' => $courseId,
            'organization_id' => $organizationId,
            'student_id' => (string) Str::uuid7(),
            'training_type' => 'basic',
            'driving_category_id' => (string) Str::uuid7(),
            'started_at' => now()->addDay(),
            'lead_instructor_id' => (string) Str::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $course = DB::table('course_enrollments')->where('id', $courseId)->firstOrFail();

        $this->assertSame('paper', $course->document_mode);
        $this->assertNotNull($course->document_mode_selected_at);
        $this->assertNull($course->document_mode_selected_by_user_id);
    }

    public function test_expand_allows_legacy_null_but_rejects_invalid_new_document_mode(): void
    {
        DB::table('course_enrollments')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => (string) Str::uuid7(),
            'student_id' => (string) Str::uuid7(),
            'training_type' => 'basic',
            'driving_category_id' => (string) Str::uuid7(),
            'started_at' => now()->subDay(),
            'lead_instructor_id' => (string) Str::uuid7(),
            'document_mode' => null,
            'document_mode_selected_at' => null,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->assertDatabaseCount('course_enrollments', 1);

        try {
            DB::table('course_enrollments')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => (string) Str::uuid7(),
                'student_id' => (string) Str::uuid7(),
                'training_type' => 'basic',
                'driving_category_id' => (string) Str::uuid7(),
                'started_at' => now()->addDay(),
                'lead_instructor_id' => (string) Str::uuid7(),
                'document_mode' => 'invalid-mode',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Invalid formal document mode should be rejected by PostgreSQL.');
        } catch (QueryException) {
            $this->assertDatabaseCount('course_enrollments', 1);
        }
    }

    public function test_expand_table_constraints_reject_invalid_document_shapes(): void
    {
        $templateId = (string) Str::uuid7();
        DB::table('formal_training_document_templates')->insert([
            'id' => $templateId,
            'document_type' => 'training_record_card',
            'template_version' => 'v1',
            'renderer_version' => 'renderer-v1',
            'template_content_hash' => str_repeat('a', 64),
            'effective_from' => now(),
            'created_at' => now(),
        ]);

        $this->assertDatabaseCount('formal_training_document_templates', 1);

        try {
            DB::table('formal_training_document_templates')->insert([
                'id' => (string) Str::uuid7(),
                'document_type' => 'invented_document',
                'template_version' => 'v2',
                'renderer_version' => 'renderer-v1',
                'template_content_hash' => str_repeat('b', 64),
                'effective_from' => now(),
                'created_at' => now(),
            ]);
            $this->fail('Unknown formal document type should be rejected by PostgreSQL.');
        } catch (QueryException) {
            $this->assertDatabaseCount('formal_training_document_templates', 1);
        }

        DB::table('formal_training_documents')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => (string) Str::uuid7(),
            'course_enrollment_id' => (string) Str::uuid7(),
            'document_type' => 'training_record_card',
            'revision' => 1,
            'document_mode_snapshot' => 'paper',
            'formal_training_document_template_id' => $templateId,
            'template_version_snapshot' => 'v1',
            'renderer_version_snapshot' => 'renderer-v1',
            'template_hash_snapshot' => str_repeat('a', 64),
            'course_version_snapshot' => 1,
            'requirements_revision_snapshot' => 1,
            'evidence_bundle_hash' => str_repeat('c', 64),
            'asset_id' => (string) Str::uuid7(),
            'content_hash' => str_repeat('d', 64),
            'generated_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertDatabaseCount('formal_training_documents', 1);
    }

    public function test_expand_controlled_executor_is_idempotent_and_contract_is_registered(): void
    {
        $extension = app(Stage5FormalDocumentsMigrationPlan::class);
        $extension->validate();

        $migrationNames = array_column($extension->phaseSteps('expand'), 'migration_name');
        $before = DB::table('migrations')->whereIn('migration', $migrationNames)->count();

        $exit = Artisan::call('migration:stage5:formal-docs:controlled', [
            '--plan' => $extension->identity(),
            '--execution' => $extension->executionIdentity(),
            '--phase' => 'expand',
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(4, $before);
        $this->assertSame(4, DB::table('migrations')->whereIn('migration', $migrationNames)->count());

        $this->assertSame(
            ['S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE'],
            array_column($extension->phaseSteps('contract'), 'node_id'),
        );
    }
}
