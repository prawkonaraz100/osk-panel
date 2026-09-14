<?php

namespace Tests\Feature;

use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\PkkTriggerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4PkkTriggerGuardsTest extends TestCase
{
    public function test_pkk_guards_are_database_local_append_only_and_fail_closed_without_provider_io(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, Artisan::output());

            foreach ($plan->phaseSteps('write_fence') as $step) {
                ControlledMigrationContext::enter('write_fence', $step['node_id'], $plan->executionIdentity());

                try {
                    /** @var Migration $migration */
                    $migration = require base_path($step['migration_file']);
                    $migration->up();
                } finally {
                    ControlledMigrationContext::leave();
                }
            }

            PkkTriggerGuards::install();

            $fixture = $this->fixture();
            $profileId = (string) DB::table('pkk_profiles')
                ->where('course_enrollment_id', $fixture['course']['id'])
                ->whereNull('superseded_at')
                ->value('id');

            $this->insertConfiguration($fixture['actor']['organization_id']);
            $this->forceDeferredChecks();

            $operationId = $this->insertOperation(
                $fixture,
                $profileId,
                'fetch_profile',
                'pending',
                1,
                null,
                null,
            );
            $this->insertLifecycle(
                $fixture,
                $operationId,
                1,
                null,
                'pending',
            );
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_operations')
                    ->where('id', $operationId)
                    ->update(['operation_type' => 'return_expired']),
            );

            $this->expectDeferredGuardViolation(function () use ($operationId): void {
                DB::table('pkk_operations')->where('id', $operationId)->update([
                    'business_status' => 'success',
                    'version' => 2,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $completedAt = now();
            DB::table('pkk_operations')->where('id', $operationId)->update([
                'business_status' => 'success',
                'version' => 2,
                'completed_at' => $completedAt,
                'updated_at' => now(),
            ]);
            $this->insertLifecycle(
                $fixture,
                $operationId,
                2,
                'pending',
                'success',
            );
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_operations')
                    ->where('id', $operationId)
                    ->update(['business_status' => 'failed']),
            );

            $lifecycleId = (string) DB::table('pkk_operation_lifecycle_events')
                ->where('pkk_operation_id', $operationId)
                ->where('operation_version', 2)
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_operation_lifecycle_events')
                    ->where('id', $lifecycleId)
                    ->delete(),
            );

            $this->expectDeferredGuardViolation(function () use ($fixture, $profileId): void {
                $gapOperation = $this->insertOperation(
                    $fixture,
                    $profileId,
                    'fetch_profile',
                    'pending',
                    3,
                    null,
                    null,
                );
                $this->insertLifecycle($fixture, $gapOperation, 1, null, 'pending');
            });

            $snapshotId = (string) Str::uuid7();
            DB::table('pkk_provider_profile_snapshots')->insert([
                'id' => $snapshotId,
                'organization_id' => $fixture['actor']['organization_id'],
                'course_enrollment_id' => $fixture['course']['id'],
                'pkk_profile_id' => $profileId,
                'snapshot_revision' => 1,
                'snapshot_schema_version' => 1,
                'capture_origin' => 'runtime',
                'provider_status_snapshot' => 'available',
                'provider_profile_payload_ciphertext' => null,
                'redacted_profile_projection_jsonb' => json_encode(['status' => 'available'], JSON_THROW_ON_ERROR),
                'snapshot_content_hash' => str_repeat('a', 64),
                'fetched_at' => now(),
                'created_at' => now(),
            ]);
            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_provider_profile_snapshots')
                    ->where('id', $snapshotId)
                    ->update(['provider_status_snapshot' => 'rewritten']),
            );

            $attemptId = (string) Str::uuid7();
            DB::table('pkk_operation_attempts')->insert([
                'id' => $attemptId,
                'organization_id' => $fixture['actor']['organization_id'],
                'pkk_operation_id' => $operationId,
                'course_enrollment_id' => $fixture['course']['id'],
                'pkk_profile_id' => $profileId,
                'attempt_no' => 1,
                'command_idempotency_record_id' => null,
                'integration_configuration_revision' => 1,
                'signature_handoff_id' => null,
                'attempt_dispatch_status' => 'prepared',
                'retry_disposition' => 'safe_to_retry',
                'provider_request_id' => null,
                'provider_neutral_error_class' => null,
                'adapter_error_code' => null,
                'adapter_error_message' => null,
                'dispatch_started_at' => null,
                'transport_finished_at' => null,
                'resolved_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_operation_attempts')
                    ->where('id', $attemptId)
                    ->update(['attempt_no' => 2]),
            );

            $reconciliationId = (string) Str::uuid7();
            DB::table('pkk_operation_attempt_reconciliations')->insert([
                'id' => $reconciliationId,
                'organization_id' => $fixture['actor']['organization_id'],
                'pkk_operation_id' => $operationId,
                'pkk_operation_attempt_id' => $attemptId,
                'course_enrollment_id' => $fixture['course']['id'],
                'pkk_profile_id' => $profileId,
                'reconciliation_sequence' => 1,
                'outcome' => 'inconclusive',
                'provider_reference' => null,
                'evidence_hash' => str_repeat('b', 64),
                'safe_summary' => json_encode(['result' => 'inconclusive'], JSON_THROW_ON_ERROR),
                'actor_kind' => 'user',
                'actor_user_id' => $fixture['actor']['user_id'],
                'performed_at' => now(),
                'created_at' => now(),
            ]);
            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_operation_attempt_reconciliations')
                    ->where('id', $reconciliationId)
                    ->update(['outcome' => 'rewritten']),
            );
            $this->expectImmediateGuardViolation(function () use ($fixture, $profileId, $operationId, $attemptId): void {
                DB::table('pkk_operation_attempt_reconciliations')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $fixture['actor']['organization_id'],
                    'pkk_operation_id' => $operationId,
                    'pkk_operation_attempt_id' => $attemptId,
                    'course_enrollment_id' => $fixture['course']['id'],
                    'pkk_profile_id' => $profileId,
                    'reconciliation_sequence' => 3,
                    'outcome' => 'inconclusive',
                    'provider_reference' => null,
                    'evidence_hash' => str_repeat('c', 64),
                    'safe_summary' => null,
                    'actor_kind' => 'user',
                    'actor_user_id' => $fixture['actor']['user_id'],
                    'performed_at' => now(),
                    'created_at' => now(),
                ]);
            });

            $signatureOperationId = $this->insertOperation(
                $fixture,
                $profileId,
                'update_and_return',
                'pending',
                2,
                now(),
                $fixture['actor']['user_id'],
            );
            $this->insertLifecycle($fixture, $signatureOperationId, 1, null, 'pending');
            $this->forceDeferredChecks();

            $unsignedAssetId = $this->insertFileAsset(
                $fixture,
                'pkk_unsigned_xml_to_sign',
                str_repeat('d', 64),
            );

            DB::table('pkk_operations')->where('id', $signatureOperationId)->update([
                'business_status' => 'requires_signature',
                'version' => 2,
                'updated_at' => now(),
            ]);
            $this->insertLifecycle(
                $fixture,
                $signatureOperationId,
                2,
                'pending',
                'requires_signature',
            );

            $handoffId = (string) Str::uuid7();
            DB::table('pkk_signature_handoffs')->insert([
                'id' => $handoffId,
                'organization_id' => $fixture['actor']['organization_id'],
                'course_enrollment_id' => $fixture['course']['id'],
                'pkk_profile_id' => $profileId,
                'pkk_operation_id' => $signatureOperationId,
                'handoff_no' => 1,
                'requires_signature_operation_version' => 2,
                'source_pkk_operation_attempt_id' => null,
                'unsigned_file_asset_id' => $unsignedAssetId,
                'unsigned_sha256' => str_repeat('d', 64),
                'signed_file_asset_id' => null,
                'signed_sha256' => null,
                'signed_attached_by_user_id' => null,
                'signed_attached_at' => null,
                'consumed_at' => null,
                'cancelled_at' => null,
                'created_at' => now(),
            ]);
            $this->forceDeferredChecks();

            $signedAssetId = $this->insertFileAsset(
                $fixture,
                'pkk_signed_xml_return',
                str_repeat('e', 64),
            );
            DB::table('pkk_signature_handoffs')->where('id', $handoffId)->update([
                'signed_file_asset_id' => $signedAssetId,
                'signed_sha256' => str_repeat('e', 64),
                'signed_attached_by_user_id' => $fixture['actor']['user_id'],
                'signed_attached_at' => now(),
            ]);

            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_signature_handoffs')
                    ->where('id', $handoffId)
                    ->update(['signed_sha256' => str_repeat('f', 64)]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('file_assets')
                    ->where('id', $unsignedAssetId)
                    ->update(['storage_key' => 'replaced.xml']),
            );

            $reservationId = (string) Str::uuid7();
            DB::table('pkk_signature_handoff_upload_reservations')->insert([
                'id' => $reservationId,
                'organization_id' => $fixture['actor']['organization_id'],
                'pkk_signature_handoff_id' => $handoffId,
                'pkk_operation_id' => $signatureOperationId,
                'course_enrollment_id' => $fixture['course']['id'],
                'pkk_profile_id' => $profileId,
                'file_asset_id' => $signedAssetId,
                'created_by_user_id' => $fixture['actor']['user_id'],
                'created_at' => now(),
                'accepted_at' => null,
                'rejected_at' => null,
            ]);
            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_signature_handoff_upload_reservations')
                    ->where('id', $reservationId)
                    ->update(['file_asset_id' => $unsignedAssetId]),
            );

            $this->expectImmediateGuardViolation(
                fn () => DB::table('pkk_integration_configuration_revisions')
                    ->where('organization_id', $fixture['actor']['organization_id'])
                    ->where('execution_configuration_revision', 1)
                    ->update(['readiness_status_snapshot' => 'rewritten']),
            );
            $this->expectDeferredGuardViolation(
                fn () => DB::table('pkk_integration_settings')
                    ->where('organization_id', $fixture['actor']['organization_id'])
                    ->update(['execution_configuration_revision' => 2]),
            );

            $triggerTables = $this->signedPkkTriggerTables();
            foreach ([
                'file_assets',
                'pkk_integration_configuration_revisions',
                'pkk_integration_settings',
                'pkk_operation_attempt_reconciliations',
                'pkk_operation_attempts',
                'pkk_operation_lifecycle_events',
                'pkk_operations',
                'pkk_payload_redacted_projections',
                'pkk_protected_payload_key_wrappings',
                'pkk_protected_payloads',
                'pkk_provider_profile_snapshots',
                'pkk_signature_file_asset_key_wrappings',
                'pkk_signature_file_asset_protections',
                'pkk_signature_handoff_upload_reservations',
                'pkk_signature_handoffs',
            ] as $table) {
                $this->assertContains($table, $triggerTables);
            }
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array{
     *   actor:array{organization_id:string,user_id:string,membership_id:string,session_id:string},
     *   instructor:array<string,mixed>,
     *   student:array<string,mixed>,
     *   course:array<string,mixed>
     * }
     */
    private function fixture(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view',
            'students.create',
            'students.edit',
            'courses.view',
            'courses.create',
            'courses.edit',
            'courses.cancel',
            'courses.restore',
            'courses.stage.change',
            'course_requirements.correct',
            'external_training.recognize',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        $instructor = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'pkk.trigger.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Instruktor',
                'staff_type_codes' => ['Instructor'],
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );

        $student = app(StudentService::class)->create(
            $actor['session_id'],
            [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'pesel' => '02070803628',
                'no_pesel' => false,
            ],
            (string) Str::uuid7(),
        );

        $course = app(CourseEnrollmentService::class)->create(
            $actor['session_id'],
            $student['id'],
            [
                'training_type' => 'basic',
                'driving_category_code' => 'B',
                'pkk_number' => 'PKK-'.str_replace('-', '', (string) Str::uuid7()),
                'started_at' => '2026-09-11T08:00:00+02:00',
                'declared_theory_minutes' => 0,
                'declared_practical_minutes' => 0,
                'recognized_external_theory_minutes' => 0,
                'recognized_external_practical_minutes' => 0,
                'lead_instructor_id' => $instructor['id'],
                'location_id' => null,
            ],
            (string) Str::uuid7(),
        );

        return compact('actor', 'instructor', 'student', 'course');
    }

    private function insertConfiguration(string $organizationId): void
    {
        DB::table('pkk_integration_settings')->insert([
            'organization_id' => $organizationId,
            'school_name' => 'OSK Trigger Test',
            'osk_registry_number' => 'TEST-001',
            'external_osk_login_ciphertext' => 'encrypted-login',
            'external_osk_login_lookup_hash' => str_repeat('1', 64),
            'readiness_status' => 'configured_unverified',
            'execution_configuration_revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pkk_integration_configuration_revisions')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'execution_configuration_revision' => 1,
            'organization_settings_version_at_capture' => 1,
            'school_name_snapshot' => 'OSK Trigger Test',
            'osk_registry_number_snapshot' => 'TEST-001',
            'external_osk_login_binding_hmac' => str_repeat('2', 64),
            'readiness_status_snapshot' => 'configured_unverified',
            'configuration_binding_hmac' => str_repeat('3', 64),
            'revision_origin' => 'runtime',
            'created_at' => now(),
        ]);
    }

    /**
     * @param array{
     *   actor:array{organization_id:string,user_id:string,membership_id:string,session_id:string},
     *   course:array<string,mixed>
     * } $fixture
     */
    private function insertOperation(
        array $fixture,
        string $profileId,
        string $type,
        string $status,
        int $sequence,
        mixed $confirmedAt,
        ?string $confirmedBy,
    ): string {
        $id = (string) Str::uuid7();
        DB::table('pkk_operations')->insert([
            'id' => $id,
            'organization_id' => $fixture['actor']['organization_id'],
            'course_enrollment_id' => $fixture['course']['id'],
            'pkk_profile_id' => $profileId,
            'operation_type' => $type,
            'business_status' => $status,
            'operation_origin' => 'runtime',
            'version' => 1,
            'initial_idempotency_record_id' => null,
            'course_operation_sequence' => $sequence,
            'confirmed_at' => $confirmedAt,
            'confirmed_by_user_id' => $confirmedBy,
            'completed_at' => null,
            'actor_user_id' => $fixture['actor']['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param array{
     *   actor:array{organization_id:string,user_id:string,membership_id:string,session_id:string}
     * } $fixture
     */
    private function insertLifecycle(
        array $fixture,
        string $operationId,
        int $version,
        ?string $from,
        string $to,
    ): void {
        DB::table('pkk_operation_lifecycle_events')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $fixture['actor']['organization_id'],
            'pkk_operation_id' => $operationId,
            'operation_version' => $version,
            'from_business_status' => $from,
            'to_business_status' => $to,
            'event_origin' => 'runtime',
            'occurred_at' => now(),
            'actor_kind' => 'user',
            'actor_user_id' => $fixture['actor']['user_id'],
            'reason_code' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param array{actor:array{organization_id:string,user_id:string,membership_id:string,session_id:string}} $fixture
     */
    private function insertFileAsset(array $fixture, string $purpose, string $sha256): string
    {
        $id = (string) Str::uuid7();
        DB::table('file_assets')->insert([
            'id' => $id,
            'organization_id' => $fixture['actor']['organization_id'],
            'storage_disk' => 'local',
            'storage_key' => 'pkk/'.str_replace('-', '', $id).'.xml',
            'original_filename' => 'pkk.xml',
            'mime_type_declared' => 'application/xml',
            'mime_type_detected' => 'application/xml',
            'size_bytes' => 128,
            'sha256' => $sha256,
            'purpose' => $purpose,
            'status' => 'ready',
            'created_by_user_id' => $fixture['actor']['user_id'],
            'created_at' => now(),
            'ready_at' => now(),
            'deleted_at' => null,
        ]);

        return $id;
    }

    private function forceDeferredChecks(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_pkk_immediate');

        try {
            $operation();
            $this->fail('Expected immediate PKK trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_pkk_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_pkk_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred PKK trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_pkk_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    /** @return list<string> */
    private function signedPkkTriggerTables(): array
    {
        return DB::table('pg_trigger as trg')
            ->join('pg_class as cls', 'cls.oid', '=', 'trg.tgrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('trg.tgisinternal', false)
            ->whereRaw("COALESCE(obj_description(trg.oid, 'pg_trigger'), '') LIKE 'prawkonaraz:trigger-write-fence:v1:%'")
            ->where(function ($query): void {
                $query->where('cls.relname', 'like', 'pkk_%')
                    ->orWhere('cls.relname', 'file_assets');
            })
            ->distinct()
            ->orderBy('cls.relname')
            ->pluck('cls.relname')
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }
}
