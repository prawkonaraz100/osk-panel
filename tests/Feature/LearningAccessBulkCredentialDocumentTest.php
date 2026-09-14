<?php

namespace Tests\Feature;

use App\Modules\LearningAccess\LearningAccountService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class LearningAccessBulkCredentialDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();
    }

    public function test_nonsecret_bulk_pdf_is_replayable_without_duplicate_batch_or_handoffs(): void
    {
        $actor = $this->actor();
        [$studentA, $accountA] = $this->managedAccount($actor, 'bulk-a@example.test');
        [$studentB, $accountB] = $this->managedAccount($actor, 'bulk-b@example.test');

        $versionsBefore = $this->versions([$accountA['id'], $accountB['id']]);
        $key = (string) Str::uuid7();
        $payload = [
            'targets' => [
                ['learning_account_id' => $accountA['id']],
                ['learning_account_id' => $accountB['id']],
            ],
        ];

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/learning-accesses/bulk-access-document', $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');

        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $bytes);
        $this->assertStringContainsString('% PNR-STUDENT-ACCESS-BULK', $bytes);
        $this->assertStringContainsString('/Count 2', $bytes);
        $this->assertSame(1, DB::table('student_access_export_batches')->count());
        $this->assertSame(2, DB::table('student_access_handoffs')->count());
        $this->assertSame($versionsBefore, $this->versions([$accountA['id'], $accountB['id']]));

        $batch = DB::table('student_access_export_batches')->first();
        $this->assertNotNull($batch);
        $this->assertSame('nonsecret_combined_pdf', $batch->export_mode);
        $this->assertSame(2, (int) $batch->selected_account_count);
        $this->assertSame(
            0,
            DB::table('student_access_handoffs')->where('batch_id', $batch->id)->where('contains_fresh_secret', true)->count(),
        );

        $replay = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/learning-accesses/bulk-access-document', $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('% PNR-STUDENT-ACCESS-BULK', (string) $replay->getContent());
        $this->assertSame(1, DB::table('student_access_export_batches')->count());
        $this->assertSame(2, DB::table('student_access_handoffs')->count());
        $this->assertSame($versionsBefore, $this->versions([$accountA['id'], $accountB['id']]));

        $this->assertSame($studentA['id'], $accountA['student_id']);
        $this->assertSame($studentB['id'], $accountB['student_id']);
    }

    public function test_reset_bulk_pdf_mutates_each_unique_user_once_and_secret_is_not_replayed(): void
    {
        $actor = $this->actor();
        [, $accountA] = $this->managedAccount($actor, 'reset-a@example.test');
        [, $accountB] = $this->managedAccount($actor, 'reset-b@example.test');

        $key = (string) Str::uuid7();
        $payload = [
            'targets' => [
                ['learning_account_id' => $accountA['id'], 'expected_credential_version' => 1],
                ['learning_account_id' => $accountB['id'], 'expected_credential_version' => 1],
            ],
            'regenerate_credentials_when_required' => true,
        ];

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/learning-accesses/bulk-access-document', $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $bytes = (string) $response->getContent();
        $this->assertStringContainsString('% PNR-STUDENT-ACCESS-BULK', $bytes);
        $this->assertStringContainsString('/Count 2', $bytes);
        $this->assertSame([2, 2], $this->versions([$accountA['id'], $accountB['id']]));

        $batch = DB::table('student_access_export_batches')->first();
        $this->assertNotNull($batch);
        $this->assertSame('reset_and_secret_combined_pdf', $batch->export_mode);
        $this->assertSame(
            2,
            DB::table('student_access_handoffs')
                ->where('batch_id', $batch->id)
                ->where('contains_fresh_secret', true)
                ->where('credential_version_snapshot', 2)
                ->count(),
        );

        $snapshot = (string) DB::table('idempotency_records')
            ->where('operation_key', 'license_credentials.bulk_pdf')
            ->where('idempotency_key', $key)
            ->value('safe_response_snapshot');
        $this->assertStringNotContainsString('%PDF', $snapshot);
        $this->assertStringNotContainsString('plaintext', strtolower($snapshot));
        $this->assertStringContainsString('reset_and_secret_combined_pdf', $snapshot);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/learning-accesses/bulk-access-document', $payload)
            ->assertStatus(409);

        $this->assertSame([2, 2], $this->versions([$accountA['id'], $accountB['id']]));
        $this->assertSame(1, DB::table('student_access_export_batches')->count());
        $this->assertSame(2, DB::table('student_access_handoffs')->count());

        foreach (['audit_logs', 'domain_events', 'outbox_messages', 'idempotency_records'] as $table) {
            $persisted = DB::table($table)
                ->get()
                ->map(static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))
                ->implode("\n");
            $this->assertStringNotContainsString('% PNR-STUDENT-ACCESS-BULK', $persisted);
        }
    }

    public function test_stale_expected_version_rolls_back_entire_reset_batch(): void
    {
        $actor = $this->actor();
        [, $accountA] = $this->managedAccount($actor, 'stale-a@example.test');
        [, $accountB] = $this->managedAccount($actor, 'stale-b@example.test');

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/learning-accesses/bulk-access-document', [
                'targets' => [
                    ['learning_account_id' => $accountA['id'], 'expected_credential_version' => 1],
                    ['learning_account_id' => $accountB['id'], 'expected_credential_version' => 0],
                ],
                'regenerate_credentials_when_required' => true,
            ])
            ->assertStatus(409);

        $this->assertSame([1, 1], $this->versions([$accountA['id'], $accountB['id']]));
        $this->assertSame(0, DB::table('student_access_export_batches')->count());
        $this->assertSame(0, DB::table('student_access_handoffs')->count());
    }

    public function test_renderer_failure_happens_before_any_password_write_or_batch_metadata(): void
    {
        $actor = $this->actor();
        [, $account] = $this->managedAccount($actor, 'unsupported-locale@example.test');

        DB::table('languages')->insert([
            'code' => 'zz',
            'label_key' => 'language.zz',
            'active' => true,
            'valid_from' => null,
            'valid_to' => null,
        ]);
        DB::table('student_learning_accounts')->where('id', $account['id'])->update([
            'language_code' => 'zz',
            'updated_at' => now(),
        ]);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/learning-accesses/bulk-access-document', [
                'targets' => [
                    ['learning_account_id' => $account['id'], 'expected_credential_version' => 1],
                ],
                'regenerate_credentials_when_required' => true,
            ])
            ->assertStatus(500);

        $this->assertSame([1], $this->versions([$account['id']]));
        $this->assertSame(0, DB::table('student_access_export_batches')->count());
        $this->assertSame(0, DB::table('student_access_handoffs')->count());
    }

    public function test_duplicate_global_user_expected_versions_must_agree_and_increment_once(): void
    {
        $actor = $this->actor();
        [$studentA, $accountA] = $this->managedAccount($actor, 'shared-user@example.test');
        $studentB = $this->student($actor);

        $row = DB::table('student_learning_accounts')->where('id', $accountA['id'])->first();
        $this->assertNotNull($row);
        $accountBId = (string) Str::uuid7();
        DB::table('student_learning_accounts')->insert([
            'id' => $accountBId,
            'organization_id' => $actor['organization_id'],
            'student_id' => $studentB['id'],
            'user_id' => $row->user_id,
            'auth_login_identifier_id' => $row->auth_login_identifier_id,
            'language_code' => 'pl',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/learning-accesses/bulk-access-document', [
                'targets' => [
                    ['learning_account_id' => $accountA['id'], 'expected_credential_version' => 1],
                    ['learning_account_id' => $accountBId, 'expected_credential_version' => 2],
                ],
                'regenerate_credentials_when_required' => true,
            ])
            ->assertStatus(409);

        $userId = (string) $row->user_id;
        $this->assertSame(
            1,
            (int) DB::table('user_password_management')->where('user_id', $userId)->value('credential_version'),
        );
        $this->assertSame(0, DB::table('student_access_export_batches')->count());

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/learning-accesses/bulk-access-document', [
                'targets' => [
                    ['learning_account_id' => $accountA['id'], 'expected_credential_version' => 1],
                    ['learning_account_id' => $accountBId, 'expected_credential_version' => 1],
                ],
                'regenerate_credentials_when_required' => true,
            ])
            ->assertOk();

        $this->assertSame(
            2,
            (int) DB::table('user_password_management')->where('user_id', $userId)->value('credential_version'),
        );
        $this->assertSame(1, DB::table('student_access_export_batches')->count());
        $this->assertSame(
            2,
            DB::table('student_access_handoffs')
                ->where('credential_version_snapshot', 2)
                ->where('contains_fresh_secret', true)
                ->count(),
        );
        $this->assertSame($studentA['id'], $accountA['student_id']);
    }

    public function test_duplicate_learning_account_target_is_rejected_without_effects(): void
    {
        $actor = $this->actor();
        [, $account] = $this->managedAccount($actor, 'duplicate-target@example.test');

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/learning-accesses/bulk-access-document', [
                'targets' => [
                    ['learning_account_id' => $account['id']],
                    ['learning_account_id' => $account['id']],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame([1], $this->versions([$account['id']]));
        $this->assertSame(0, DB::table('student_access_export_batches')->count());
        $this->assertSame(0, DB::table('student_access_handoffs')->count());
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function actor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.create',
            'student_access.create',
            'student_access.manage_credentials',
            'student_access.reset_password',
            'licenses.access_documents.download',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function managedAccount(array $actor, string $login): array
    {
        $student = $this->student($actor);
        $account = app(LearningAccountService::class)->create(
            $actor['session_id'],
            $student['id'],
            [
                'login_identifier' => $login,
                'language_code' => 'pl',
                'initial_password' => str_repeat('x', 16),
            ],
            (string) Str::uuid7(),
        );

        return [$student, $account];
    }

    /**
     * @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor
     * @return array<string,mixed>
     */
    private function student(array $actor): array
    {
        return app(StudentService::class)->create(
            $actor['session_id'],
            [
                'first_name' => 'Jan',
                'last_name' => 'Bulk',
                'no_pesel' => true,
                'birth_date' => '1990-01-01',
            ],
            (string) Str::uuid7(),
        );
    }

    /** @param list<string> $accountIds
     * @return list<int>
     */
    private function versions(array $accountIds): array
    {
        return array_map(function (string $accountId): int {
            $userId = DB::table('student_learning_accounts')->where('id', $accountId)->value('user_id');

            return (int) DB::table('user_password_management')->where('user_id', $userId)->value('credential_version');
        }, $accountIds);
    }
}
