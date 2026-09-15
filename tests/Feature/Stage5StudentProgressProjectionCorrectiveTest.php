<?php

namespace Tests\Feature;

use App\Support\Migrations\Stage5StudentProgressMigrationPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage5StudentProgressProjectionCorrectiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        FoundationSchema::ensureStudentProgressMigrated();
        DB::table('student_learning_progress_projections')->delete();
        DB::table('learning_progress_source_bindings')->delete();
        FoundationSchema::reset();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_plan_preserves_frozen_authorities_and_materializes_exact_scope(): void
    {
        $summary = app(Stage5StudentProgressMigrationPlan::class)->summary();

        self::assertSame('d844b3e5c74844e6e9a357aa3271ff9b68bac93dc7fdcd6fa28d3ab44b9c978a', $summary['plan_identity']);
        self::assertSame('ae158ce5aad8ffb6ba84b12ce56ec3fe9ff6b16b87b77dea965ad2cc8d332389', $summary['execution_identity']);
        self::assertSame('d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10', $summary['stage4_plan_identity']);
        self::assertSame('82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712', $summary['stage4_execution_identity']);
        self::assertSame('34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99', $summary['preserved_formal_documents_plan_identity']);
        self::assertSame('85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d', $summary['preserved_social_identity_plan_identity']);
        self::assertSame('6d03c38e47ba30d07a3a090d514ac09384e1ea5e668947b95eeaaa6fce49c019', $summary['preserved_commerce_order_sequence_plan_identity']);
        self::assertSame(2, $summary['nodes']);
        self::assertSame(2, $summary['implemented_nodes']);
        self::assertSame(6, $summary['implemented_steps']);

        self::assertTrue(Schema::hasTable('learning_progress_source_bindings'));
        self::assertTrue(Schema::hasTable('student_learning_progress_projections'));
        self::assertSame(0, DB::table('learning_progress_source_bindings')->count());
        self::assertSame(0, DB::table('student_learning_progress_projections')->count());
    }

    public function test_source_binding_rejects_cross_tenant_or_wrong_account_identity(): void
    {
        $first = $this->learningAccountFixture();
        $second = $this->learningAccountFixture();

        $this->expectException(QueryException::class);
        DB::table('learning_progress_source_bindings')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $first['organization_id'],
            'student_id' => $second['student_id'],
            'student_learning_account_id' => $second['account_id'],
            'source_system' => 'learning-core',
            'source_subject_ref' => 'subject-cross',
            'source_access_ref' => 'access-cross',
            'status' => 'active',
            'version' => 1,
            'bound_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_projection_rejects_binding_from_another_tenant_or_account(): void
    {
        $first = $this->learningAccountFixture();
        $second = $this->learningAccountFixture();

        $bindingId = (string) Str::uuid7();
        DB::table('learning_progress_source_bindings')->insert([
            'id' => $bindingId,
            'organization_id' => $first['organization_id'],
            'student_id' => $first['student_id'],
            'student_learning_account_id' => $first['account_id'],
            'source_system' => 'learning-core',
            'source_subject_ref' => 'subject-1',
            'source_access_ref' => 'access-1',
            'status' => 'active',
            'version' => 1,
            'bound_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = (string) DB::table('driving_categories')->where('active', true)->value('id');
        $this->expectException(QueryException::class);
        DB::table('student_learning_progress_projections')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $second['organization_id'],
            'student_id' => $second['student_id'],
            'student_learning_account_id' => $second['account_id'],
            'learning_progress_source_binding_id' => $bindingId,
            'driving_category_id' => $categoryId,
            'learning_account_version' => 1,
            'source_snapshot_ref' => 'snapshot-cross',
            'source_observed_at' => now(),
            'projected_at' => now(),
            'projection_version' => 1,
            'tests_json' => '{}',
            'questions_json' => '{}',
            'handbook_json' => '{}',
            'lectures_json' => '{}',
            'topics_json' => '[]',
            'snapshot_hash' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{organization_id:string,student_id:string,account_id:string} */
    private function learningAccountFixture(): array
    {
        $actor = FoundationSchema::actor();
        $studentId = (string) Str::uuid7();
        $identifierId = (string) Str::uuid7();
        $accountId = (string) Str::uuid7();
        $now = now();
        $languageCode = (string) DB::table('languages')->where('active', true)->value('code');

        DB::table('students')->insert([
            'id' => $studentId,
            'organization_id' => $actor['organization_id'],
            'first_name' => 'Progress',
            'last_name' => 'Fixture',
            'birth_date' => '1990-01-01',
            'no_pesel_declared' => true,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('auth_login_identifiers')->insert([
            'id' => $identifierId,
            'user_id' => $actor['user_id'],
            'identifier_type' => 'email',
            'identifier_normalized' => strtolower($identifierId).'@example.invalid',
            'is_primary_for_type' => true,
            'verified_at' => $now,
            'created_at' => $now,
        ]);
        DB::table('student_learning_accounts')->insert([
            'id' => $accountId,
            'organization_id' => $actor['organization_id'],
            'student_id' => $studentId,
            'user_id' => $actor['user_id'],
            'auth_login_identifier_id' => $identifierId,
            'language_code' => $languageCode,
            'status' => 'active',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'organization_id' => $actor['organization_id'],
            'student_id' => $studentId,
            'account_id' => $accountId,
        ];
    }
}
