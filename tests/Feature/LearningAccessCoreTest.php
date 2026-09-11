<?php

namespace Tests\Feature;

use App\Modules\LearningAccess\LearningAccountService;
use App\Modules\LearningAccess\LicenseService;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class LearningAccessCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
        Carbon::setTestNow(Carbon::parse('2026-09-11T12:00:00+02:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_student_create_initial_license_is_atomic_and_server_binds_new_student_id(): void
    {
        $actor = $this->accessActor();
        $fixture = $this->inventory($actor['organization_id'], 30);

        $student = app(StudentService::class)->create($actor['session_id'], [
            'first_name' => 'Anna',
            'last_name' => 'Atomowa',
            'no_pesel' => true,
            'birth_date' => '1991-02-03',
            'initial_license' => [
                'license_inventory_entry_id' => $fixture['inventory_id'],
                'language_code' => 'pl',
                'target' => [
                    'new_learning_account' => [
                        'login_identifier' => 'anna.atomowa@example.test',
                        'language_code' => 'pl',
                    ],
                ],
            ],
        ], (string) Str::uuid7());

        $account = DB::table('student_learning_accounts')
            ->where('organization_id', $actor['organization_id'])
            ->where('student_id', $student['id'])
            ->first();
        $this->assertNotNull($account);
        $assignment = DB::table('license_assignments')
            ->where('organization_id', $actor['organization_id'])
            ->where('student_id', $student['id'])
            ->first();
        $this->assertNotNull($assignment);
        $this->assertSame((string) $account->id, (string) $assignment->student_learning_account_id);
        $this->assertSame('assigned', DB::table('license_inventory_entries')->where('id', $fixture['inventory_id'])->value('status'));

        $badFixture = $this->inventory($actor['organization_id'], 30);
        try {
            app(StudentService::class)->create($actor['session_id'], [
                'first_name' => 'Rollback',
                'last_name' => 'Test',
                'no_pesel' => true,
                'birth_date' => '1992-03-04',
                'initial_license' => [
                    'license_inventory_entry_id' => $badFixture['inventory_id'],
                    'language_code' => 'en',
                    'target' => [
                        'new_learning_account' => [
                            'login_identifier' => 'rollback@example.test',
                            'language_code' => 'en',
                        ],
                    ],
                ],
            ], (string) Str::uuid7());
            $this->fail('Unsupported product language must roll back the entire Student create.');
        } catch (ResourceDomainException $exception) {
            $this->assertSame('VALIDATION_FAILED', $exception->machineCode);
        }

        $this->assertFalse(DB::table('students')->where('first_name', 'Rollback')->where('last_name', 'Test')->exists());
        $this->assertFalse(DB::table('auth_login_identifiers')->where('identifier_normalized', 'rollback@example.test')->exists());
        $this->assertSame('available', DB::table('license_inventory_entries')->where('id', $badFixture['inventory_id'])->value('status'));
    }

    public function test_dbt_lic_001_archive_blocks_new_effects_without_rewriting_access_history(): void
    {
        $actor = $this->accessActor();
        $student = $this->student($actor);
        $account = app(LearningAccountService::class)->create($actor['session_id'], $student['id'], [
            'login_identifier' => 'archived.learner@example.test',
            'language_code' => 'pl',
        ], (string) Str::uuid7());
        $inventory = $this->inventory($actor['organization_id'], 30);
        $license = app(LicenseService::class);
        $assignment = $license->createAssignment($actor['session_id'], [
            'license_inventory_entry_id' => $inventory['inventory_id'],
            'target' => ['existing_learning_account_id' => $account['id']],
            'language_code' => 'pl',
        ], (string) Str::uuid7());

        $accountBefore = DB::table('student_learning_accounts')->where('id', $account['id'])->firstOrFail();
        app(StudentService::class)->archive(
            $actor['session_id'],
            $student['id'],
            (string) Str::uuid7(),
            'Archiwizacja testowa',
        );

        $blockedActivation = $this->captureDomainException(fn () => $license->activate(
            $actor['session_id'],
            $assignment['id'],
            (string) Str::uuid7(),
            '"v1"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $blockedActivation->machineCode);

        $blockedNewAssignment = $this->captureDomainException(fn () => $license->createAssignment(
            $actor['session_id'],
            [
                'license_inventory_entry_id' => $this->inventory($actor['organization_id'], 30)['inventory_id'],
                'target' => ['existing_learning_account_id' => $account['id']],
                'language_code' => 'pl',
            ],
            (string) Str::uuid7(),
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $blockedNewAssignment->machineCode);

        $accountAfter = DB::table('student_learning_accounts')->where('id', $account['id'])->firstOrFail();
        $this->assertSame($accountBefore->status, $accountAfter->status);
        $this->assertSame($accountBefore->version, $accountAfter->version);
        $this->assertSame('assigned', DB::table('license_assignments')->where('id', $assignment['id'])->value('status'));
        $this->assertSame('assigned', DB::table('license_inventory_entries')->where('id', $inventory['inventory_id'])->value('status'));
    }

    public function test_dbt_lic_002_003_revoke_restores_same_unit_and_rejects_second_current_assignment(): void
    {
        $actor = $this->accessActor();
        $student = $this->student($actor);
        $account = app(LearningAccountService::class)->create($actor['session_id'], $student['id'], [
            'login_identifier' => 'reassign@example.test',
            'language_code' => 'pl',
        ], (string) Str::uuid7());
        $fixture = $this->inventory($actor['organization_id'], 30);
        $service = app(LicenseService::class);

        $first = $service->createAssignment($actor['session_id'], [
            'license_inventory_entry_id' => $fixture['inventory_id'],
            'target' => ['existing_learning_account_id' => $account['id']],
            'language_code' => 'pl',
        ], (string) Str::uuid7());

        $duplicate = $this->captureDomainException(fn () => $service->createAssignment(
            $actor['session_id'],
            [
                'license_inventory_entry_id' => $fixture['inventory_id'],
                'target' => ['existing_learning_account_id' => $account['id']],
                'language_code' => 'pl',
            ],
            (string) Str::uuid7(),
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $duplicate->machineCode);

        $revoked = $service->revokeUnactivated(
            $actor['session_id'],
            $first['id'],
            'Nieaktywowany dostęp nie jest już potrzebny',
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame('revoked_before_activation', $revoked['status']);
        $this->assertSame('available', DB::table('license_inventory_entries')->where('id', $fixture['inventory_id'])->value('status'));

        $second = $service->createAssignment($actor['session_id'], [
            'license_inventory_entry_id' => $fixture['inventory_id'],
            'target' => ['existing_learning_account_id' => $account['id']],
            'language_code' => 'pl',
        ], (string) Str::uuid7());

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(1, $first['assignment_sequence']);
        $this->assertSame(2, $second['assignment_sequence']);
        $this->assertSame(2, DB::table('license_assignments')->where('license_inventory_entry_id', $fixture['inventory_id'])->count());
        $this->assertSame(1, DB::table('license_assignments')->where('license_inventory_entry_id', $fixture['inventory_id'])->where('status', 'assigned')->count());
    }

    public function test_dbt_lic_004_second_activation_is_rejected_and_entitlement_history_stacks(): void
    {
        $actor = $this->accessActor();
        $student = $this->student($actor);
        $account = app(LearningAccountService::class)->create($actor['session_id'], $student['id'], [
            'login_identifier' => 'stack@example.test',
            'language_code' => 'pl',
        ], (string) Str::uuid7());
        $service = app(LicenseService::class);

        $firstInventory = $this->inventory($actor['organization_id'], 30);
        $firstAssignment = $service->createAssignment($actor['session_id'], [
            'license_inventory_entry_id' => $firstInventory['inventory_id'],
            'target' => ['existing_learning_account_id' => $account['id']],
            'language_code' => 'pl',
        ], (string) Str::uuid7());
        $first = $service->activate(
            $actor['session_id'],
            $firstAssignment['id'],
            (string) Str::uuid7(),
            '"v1"',
        );

        $secondInventory = $this->inventory($actor['organization_id'], 30);
        $secondAssignment = $service->createAssignment($actor['session_id'], [
            'license_inventory_entry_id' => $secondInventory['inventory_id'],
            'target' => ['existing_learning_account_id' => $account['id']],
            'language_code' => 'pl',
        ], (string) Str::uuid7());
        $second = $service->activate(
            $actor['session_id'],
            $secondAssignment['id'],
            (string) Str::uuid7(),
            '"v1"',
        );

        $this->assertSame(1, $first['entitlement_sequence']);
        $this->assertSame(2, $second['entitlement_sequence']);
        $this->assertSame($first['effective_to'], $second['effective_from']);
        $this->assertSame(
            30 * 86400,
            Carbon::parse($first['effective_to'])->getTimestamp() - Carbon::parse($first['effective_from'])->getTimestamp(),
        );
        $this->assertSame(
            60 * 86400,
            Carbon::parse($second['effective_to'])->getTimestamp() - Carbon::parse($first['effective_from'])->getTimestamp(),
        );
        $this->assertSame('consumed', DB::table('license_inventory_entries')->where('id', $firstInventory['inventory_id'])->value('status'));

        $again = $this->captureDomainException(fn () => $service->activate(
            $actor['session_id'],
            $firstAssignment['id'],
            (string) Str::uuid7(),
            '"v2"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $again->machineCode);
        $this->assertSame(2, DB::table('license_activations')->where('student_learning_account_id', $account['id'])->count());
    }

    public function test_login_patch_is_versioned_and_old_global_identifier_is_not_hiddenly_revoked(): void
    {
        $actor = $this->accessActor();
        $student = $this->student($actor);
        $accounts = app(LearningAccountService::class);
        $account = $accounts->create($actor['session_id'], $student['id'], [
            'login_identifier' => 'first.alias@example.test',
            'language_code' => 'pl',
        ], (string) Str::uuid7());

        $updated = $accounts->update(
            $actor['session_id'],
            $student['id'],
            $account['id'],
            ['login_identifier' => 'second.alias@example.test'],
            (string) Str::uuid7(),
            '"v1"',
        );

        $this->assertSame(2, $updated['version']);
        $this->assertSame('second.alias@example.test', $updated['login_identifier']);
        $this->assertSame(
            2,
            DB::table('auth_login_identifiers')->where('user_id', DB::table('student_learning_accounts')->where('id', $account['id'])->value('user_id'))->whereNull('revoked_at')->count(),
        );

        $stale = $this->captureDomainException(fn () => $accounts->update(
            $actor['session_id'],
            $student['id'],
            $account['id'],
            ['language_code' => 'pl'],
            (string) Str::uuid7(),
            '"v1"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $stale->machineCode);
    }

    public function test_dbt_lic_051_password_reset_persists_only_hash_and_sanitized_idempotent_replay(): void
    {
        $actor = $this->accessActor();
        $student = $this->student($actor);
        $account = app(LearningAccountService::class)->create($actor['session_id'], $student['id'], [
            'login_identifier' => 'password.reset@example.test',
            'language_code' => 'pl',
            'initial_password' => 'InitialPassphrase2026',
        ], (string) Str::uuid7());

        $key = (string) Str::uuid7();
        $url = "/api/v1/students/{$student['id']}/learning-accounts/{$account['id']}/password-reset";
        $first = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '"v1"'])
            ->postJson($url, [])
            ->assertOk();
        $plaintext = (string) $first->json('one_time_plaintext_password');
        $this->assertNotSame('', $plaintext);
        $this->assertSame(2, $first->json('credential_version'));

        $replay = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '"v1"'])
            ->postJson($url, [])
            ->assertOk();
        $this->assertNull($replay->json('one_time_plaintext_password'));

        $userId = (string) DB::table('student_learning_accounts')->where('id', $account['id'])->value('user_id');
        $hash = (string) DB::table('users')->where('id', $userId)->value('password_hash');
        $this->assertTrue(Hash::check($plaintext, $hash));
        $this->assertSame(2, DB::table('user_password_management')->where('user_id', $userId)->value('credential_version'));
        $this->assertSame(1, DB::table('student_access_handoffs')->where('student_learning_account_id', $account['id'])->where('handoff_type', 'password_reset')->count());

        $snapshot = (string) DB::table('idempotency_records')
            ->where('organization_id', $actor['organization_id'])
            ->where('operation_key', 'learning_access.password.reset')
            ->where('idempotency_key', $key)
            ->value('safe_response_snapshot');
        $this->assertStringNotContainsString($plaintext, $snapshot);
        $safeReplay = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($safeReplay);
        $this->assertArrayHasKey('one_time_plaintext_password', $safeReplay);
        $this->assertNull($safeReplay['one_time_plaintext_password']);

        foreach (['audit_logs', 'domain_events', 'outbox_messages'] as $table) {
            $payloads = DB::table($table)->get()->map(static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->implode("\n");
            $this->assertStringNotContainsString($plaintext, $payloads);
            $this->assertStringNotContainsString($hash, $payloads);
        }
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function accessActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view', 'students.create', 'students.archive', 'students.restore',
            'student_access.view', 'student_access.create', 'student_access.manage_credentials', 'student_access.reset_password',
            'licenses.view', 'licenses.assign', 'licenses.activate', 'licenses.revoke_unactivated', 'licenses.progress.view',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array<string,mixed>
     */
    private function student(array $actor): array
    {
        return app(StudentService::class)->create($actor['session_id'], [
            'first_name' => 'Jan',
            'last_name' => 'Licencja',
            'no_pesel' => true,
            'birth_date' => '1990-01-01',
        ], (string) Str::uuid7());
    }

    /** @return array{product_id:string,capability_id:string,inventory_id:string} */
    private function inventory(string $organizationId, int $durationDays): array
    {
        $product = (string) Str::uuid7();
        $capability = (string) Str::uuid7();
        $inventory = (string) Str::uuid7();
        DB::table('license_products')->insert([
            'id' => $product,
            'code' => 'TEST-'.$product,
            'duration_days' => $durationDays,
            'active' => true,
            'activation_mode' => 'manual',
            'metadata' => null,
        ]);
        DB::table('license_product_language_capabilities')->insert([
            'id' => $capability,
            'license_product_id' => $product,
            'language_code' => 'pl',
            'enabled_at' => now()->subDay(),
            'disabled_at' => null,
            'created_at' => now()->subDay(),
        ]);
        DB::table('license_inventory_entries')->insert([
            'id' => $inventory,
            'organization_id' => $organizationId,
            'license_product_id' => $product,
            'source_order_item_id' => null,
            'source_order_item_grant_ordinal' => null,
            'status' => 'available',
            'granted_at' => now(),
            'created_at' => now(),
        ]);

        return ['product_id' => $product, 'capability_id' => $capability, 'inventory_id' => $inventory];
    }

    /** @param callable():mixed $callback */
    private function captureDomainException(callable $callback): ResourceDomainException
    {
        try {
            $callback();
            $this->fail('Expected ResourceDomainException.');
        } catch (ResourceDomainException $exception) {
            return $exception;
        }
    }
}
