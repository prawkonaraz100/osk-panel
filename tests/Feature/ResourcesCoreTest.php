<?php

namespace Tests\Feature;

use App\Modules\ResourcesCore\LocationService;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\ResourceIdempotency;
use App\Modules\ResourcesCore\ResourceScopeAuthorizer;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\ResourcesCore\VehicleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class ResourcesCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_dbt_res_001_assigned_location_scope_without_active_staff_link_is_empty(): void
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'locations.view', ['assigned_locations']);

        $visibility = app(ResourceScopeAuthorizer::class)->visibility($actor['session_id'], 'locations.view');

        $this->assertFalse($visibility['unrestricted']);
        $this->assertSame([], $visibility['location_ids']);
    }

    public function test_dbt_res_002_staff_can_exist_without_membership_link(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor);

        $this->assertFalse($staff['has_login_account']);
        $this->assertFalse(DB::table('staff_membership_links')
            ->where('organization_id', $actor['organization_id'])
            ->where('staff_profile_id', $staff['id'])
            ->whereNull('unlinked_at')
            ->exists());
    }

    public function test_dbt_res_003_staff_membership_link_command_never_builds_cross_tenant_pair(): void
    {
        $first = FoundationSchema::actor();
        $firstStaff = $this->createStaff($first, ['email' => 'shared.staff@example.test']);
        app(StaffService::class)->createPanelAccount(
            $first['session_id'],
            $firstStaff['id'],
            (string) Str::uuid7(),
        );

        $second = FoundationSchema::actor();
        $secondStaff = $this->createStaff($second, ['email' => 'shared.staff@example.test']);
        app(StaffService::class)->createPanelAccount(
            $second['session_id'],
            $secondStaff['id'],
            (string) Str::uuid7(),
        );

        $link = DB::table('staff_membership_links')
            ->where('organization_id', $second['organization_id'])
            ->where('staff_profile_id', $secondStaff['id'])
            ->whereNull('unlinked_at')
            ->firstOrFail();
        $membershipOrganization = DB::table('organization_memberships')
            ->where('id', $link->organization_membership_id)
            ->value('organization_id');

        $this->assertSame($second['organization_id'], $membershipOrganization);
        $this->assertNotSame($first['organization_id'], $membershipOrganization);
    }

    public function test_dbt_res_004_archived_staff_cannot_receive_active_staff_membership_link(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor);
        app(StaffService::class)->archive(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
            'employment ended',
        );

        $exception = $this->captureDomainException(fn () => app(StaffService::class)->createPanelAccount(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
        ));

        $this->assertSame('RESOURCE_NOT_FOUND', $exception->machineCode);
        $this->assertFalse(DB::table('staff_membership_links')
            ->where('staff_profile_id', $staff['id'])
            ->whereNull('unlinked_at')
            ->exists());
    }

    public function test_dbt_res_005_staff_can_have_multiple_categories_and_locations(): void
    {
        $actor = FoundationSchema::actor();
        $locationA = $this->createLocation($actor, 'Sala A');
        $locationB = $this->createLocation($actor, 'Plac B');
        $categoryIds = $this->categoryIds(2);

        $staff = $this->createStaff($actor, [
            'category_ids' => $categoryIds,
            'location_ids' => [$locationA['id'], $locationB['id']],
        ]);

        $this->assertEqualsCanonicalizing($categoryIds, $staff['category_ids']);
        $this->assertEqualsCanonicalizing([$locationA['id'], $locationB['id']], $staff['location_ids']);
        $this->assertSame(2, DB::table('staff_category_assignments')->where('staff_profile_id', $staff['id'])->count());
        $this->assertSame(2, DB::table('staff_location_assignments')->where('staff_profile_id', $staff['id'])->count());
    }

    public function test_dbt_res_006_staff_location_assignment_cross_tenant_pair_is_rejected(): void
    {
        $actor = FoundationSchema::actor();
        $other = FoundationSchema::actor();
        $foreignLocation = $this->createLocation($other, 'Foreign');

        $exception = $this->captureDomainException(fn () => $this->createStaff($actor, [
            'location_ids' => [$foreignLocation['id']],
        ]));

        $this->assertSame('VALIDATION_FAILED', $exception->machineCode);
        $this->assertSame(0, DB::table('staff_location_assignments')
            ->where('organization_id', $actor['organization_id'])
            ->where('location_id', $foreignLocation['id'])
            ->count());
    }

    public function test_dbt_res_007_vehicle_can_have_multiple_categories_and_locations(): void
    {
        $actor = FoundationSchema::actor();
        $locationA = $this->createLocation($actor, 'Garaż');
        $locationB = $this->createLocation($actor, 'Plac');
        $categoryIds = $this->categoryIds(2);

        $vehicle = $this->createVehicle($actor, [
            'category_ids' => $categoryIds,
            'location_ids' => [$locationA['id'], $locationB['id']],
        ]);

        $this->assertEqualsCanonicalizing($categoryIds, $vehicle['category_ids']);
        $this->assertEqualsCanonicalizing([$locationA['id'], $locationB['id']], $vehicle['location_ids']);
        $this->assertSame(2, DB::table('vehicle_category_assignments')->where('vehicle_id', $vehicle['id'])->count());
        $this->assertSame(2, DB::table('vehicle_location_assignments')->where('vehicle_id', $vehicle['id'])->count());
    }

    public function test_dbt_res_008_vehicle_location_assignment_cross_tenant_pair_is_rejected(): void
    {
        $actor = FoundationSchema::actor();
        $other = FoundationSchema::actor();
        $foreignLocation = $this->createLocation($other, 'Foreign');

        $exception = $this->captureDomainException(fn () => $this->createVehicle($actor, [
            'location_ids' => [$foreignLocation['id']],
        ]));

        $this->assertSame('VALIDATION_FAILED', $exception->machineCode);
        $this->assertSame(0, DB::table('vehicle_location_assignments')
            ->where('organization_id', $actor['organization_id'])
            ->where('location_id', $foreignLocation['id'])
            ->count());
    }

    public function test_dbt_res_009_private_assets_require_same_tenant_correct_purpose_and_ready_state(): void
    {
        $actor = FoundationSchema::actor();
        $other = FoundationSchema::actor();

        $foreignVehicleAsset = $this->asset($other['organization_id'], 'vehicle_photo', 'ready');
        $this->assertSame(
            'VALIDATION_FAILED',
            $this->captureDomainException(fn () => $this->createVehicle($actor, [
                'photo_asset_id' => $foreignVehicleAsset,
            ]))->machineCode,
        );

        $wrongPurpose = $this->asset($actor['organization_id'], 'staff_photo', 'ready');
        $this->assertSame(
            'VALIDATION_FAILED',
            $this->captureDomainException(fn () => $this->createVehicle($actor, [
                'photo_asset_id' => $wrongPurpose,
            ]))->machineCode,
        );

        $notReady = $this->asset($actor['organization_id'], 'vehicle_photo', 'scanning');
        $this->assertSame(
            'VALIDATION_FAILED',
            $this->captureDomainException(fn () => $this->createVehicle($actor, [
                'photo_asset_id' => $notReady,
            ]))->machineCode,
        );

        $ready = $this->asset($actor['organization_id'], 'vehicle_photo', 'ready');
        $vehicle = $this->createVehicle($actor, ['photo_asset_id' => $ready]);

        $this->assertSame($ready, $vehicle['photo_asset_id']);
    }

    public function test_dbt_res_010_staff_document_has_at_most_one_current_row_per_type(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor, ['card_valid_until' => '2027-01-15']);

        app(StaffService::class)->update(
            $actor['session_id'],
            $staff['id'],
            ['card_valid_until' => '2028-02-16'],
            (string) Str::uuid7(),
        );

        $this->assertSame(2, DB::table('staff_documents')
            ->where('staff_profile_id', $staff['id'])
            ->where('document_type', 'card_or_authorization')
            ->count());
        $this->assertSame(1, DB::table('staff_documents')
            ->where('staff_profile_id', $staff['id'])
            ->where('document_type', 'card_or_authorization')
            ->whereNull('superseded_at')
            ->count());
        $this->assertSame('2028-02-16', (string) DB::table('staff_documents')
            ->where('staff_profile_id', $staff['id'])
            ->where('document_type', 'card_or_authorization')
            ->whereNull('superseded_at')
            ->value('valid_until'));
    }

    public function test_dbt_res_011_vehicle_document_has_at_most_one_current_row_per_type(): void
    {
        $actor = FoundationSchema::actor();
        $vehicle = $this->createVehicle($actor, ['oc_valid_until' => '2027-03-01']);

        app(VehicleService::class)->update(
            $actor['session_id'],
            $vehicle['id'],
            ['oc_valid_until' => '2028-04-02'],
            (string) Str::uuid7(),
        );

        $this->assertSame(2, DB::table('vehicle_documents')
            ->where('vehicle_id', $vehicle['id'])
            ->where('document_type', 'oc_insurance')
            ->count());
        $this->assertSame(1, DB::table('vehicle_documents')
            ->where('vehicle_id', $vehicle['id'])
            ->where('document_type', 'oc_insurance')
            ->whereNull('superseded_at')
            ->count());
        $this->assertSame('2028-04-02', (string) DB::table('vehicle_documents')
            ->where('vehicle_id', $vehicle['id'])
            ->where('document_type', 'oc_insurance')
            ->whereNull('superseded_at')
            ->value('valid_until'));
    }

    public function test_dbt_res_012_archived_staff_still_blocks_duplicate_pesel_in_same_organization(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor, ['pesel' => '44051401458']);
        app(StaffService::class)->archive(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
            null,
        );

        $exception = $this->captureDomainException(fn () => $this->createStaff($actor, [
            'email' => 'second@example.test',
            'pesel' => '44051401458',
        ]));

        $this->assertSame('RESOURCE_VERSION_CONFLICT', $exception->machineCode);
    }

    public function test_dbt_res_013_archived_vehicle_still_blocks_duplicate_vin_in_same_organization(): void
    {
        $actor = FoundationSchema::actor();
        $vehicle = $this->createVehicle($actor, [
            'registration_number' => 'KR10001',
            'vin' => 'WVWZZZ1JZXW000001',
        ]);
        app(VehicleService::class)->archive(
            $actor['session_id'],
            $vehicle['id'],
            (string) Str::uuid7(),
            null,
        );

        $exception = $this->captureDomainException(fn () => $this->createVehicle($actor, [
            'registration_number' => 'KR10002',
            'vin' => 'WVWZZZ1JZXW000001',
        ]));

        $this->assertSame('RESOURCE_VERSION_CONFLICT', $exception->machineCode);
    }

    public function test_dbt_res_014_vehicle_archive_releases_registration_for_current_fleet_but_preserves_history(): void
    {
        $actor = FoundationSchema::actor();
        $first = $this->createVehicle($actor, ['registration_number' => 'KR20001']);
        app(VehicleService::class)->archive(
            $actor['session_id'],
            $first['id'],
            (string) Str::uuid7(),
            null,
        );
        $second = $this->createVehicle($actor, ['registration_number' => 'KR20001']);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertNotNull(DB::table('vehicles')->where('id', $first['id'])->value('archived_at'));
        $this->assertSame(2, DB::table('vehicles')
            ->where('organization_id', $actor['organization_id'])
            ->where('registration_number_normalized', 'KR20001')
            ->count());
        $this->assertSame(1, DB::table('vehicles')
            ->where('organization_id', $actor['organization_id'])
            ->where('registration_number_normalized', 'KR20001')
            ->whereNull('archived_at')
            ->count());
    }

    public function test_dbt_res_015_vehicle_restore_conflicts_if_registration_is_held_by_another_current_vehicle(): void
    {
        $actor = FoundationSchema::actor();
        $first = $this->createVehicle($actor, ['registration_number' => 'KR30001']);
        app(VehicleService::class)->archive(
            $actor['session_id'],
            $first['id'],
            (string) Str::uuid7(),
            null,
        );
        $this->createVehicle($actor, ['registration_number' => 'KR30001']);

        $exception = $this->captureDomainException(fn () => app(VehicleService::class)->restore(
            $actor['session_id'],
            $first['id'],
            (string) Str::uuid7(),
        ));

        $this->assertSame('RESOURCE_VERSION_CONFLICT', $exception->machineCode);
        $this->assertNotNull(DB::table('vehicles')->where('id', $first['id'])->value('archived_at'));
    }

    public function test_dbt_res_016_staff_archive_nonowner_unlinks_suspends_and_clears_bound_sessions_atomically(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor);
        app(StaffService::class)->createPanelAccount(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
        );

        $link = DB::table('staff_membership_links')
            ->where('staff_profile_id', $staff['id'])
            ->whereNull('unlinked_at')
            ->firstOrFail();
        $membership = DB::table('organization_memberships')
            ->where('id', $link->organization_membership_id)
            ->firstOrFail();
        DB::table('organization_memberships')->where('id', $membership->id)->update(['status' => 'active']);
        $boundSession = $this->boundSession((string) $membership->user_id, (string) $membership->id);

        app(StaffService::class)->archive(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
            'employment ended',
        );

        $this->assertNotNull(DB::table('staff_profiles')->where('id', $staff['id'])->value('archived_at'));
        $this->assertNotNull(DB::table('staff_membership_links')->where('id', $link->id)->value('unlinked_at'));
        $this->assertSame('suspended', DB::table('organization_memberships')->where('id', $membership->id)->value('status'));
        $this->assertNull(DB::table('auth_sessions')->where('id', $boundSession)->value('organization_membership_id'));
        $this->assertSame(1, DB::table('organization_memberships')
            ->where('organization_id', $actor['organization_id'])
            ->where('status', 'active')
            ->where('is_owner', true)
            ->count());
    }

    public function test_dbt_res_017_staff_archive_owner_unlinks_staff_without_changing_owner_governance(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor);
        $linkId = (string) Str::uuid7();
        DB::table('staff_membership_links')->insert([
            'id' => $linkId,
            'organization_id' => $actor['organization_id'],
            'staff_profile_id' => $staff['id'],
            'organization_membership_id' => $actor['membership_id'],
            'linked_at' => now(),
            'linked_by_user_id' => $actor['user_id'],
        ]);

        app(StaffService::class)->archive(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
            'staff record closed',
        );

        $membership = DB::table('organization_memberships')->where('id', $actor['membership_id'])->firstOrFail();
        $this->assertSame('active', $membership->status);
        $this->assertTrue((bool) $membership->is_owner);
        $this->assertNotNull(DB::table('staff_membership_links')->where('id', $linkId)->value('unlinked_at'));
    }

    public function test_dbt_res_018_staff_restore_does_not_auto_restore_panel_access_or_old_sessions(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor);
        app(StaffService::class)->createPanelAccount(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
        );
        $link = DB::table('staff_membership_links')
            ->where('staff_profile_id', $staff['id'])
            ->whereNull('unlinked_at')
            ->firstOrFail();
        $membership = DB::table('organization_memberships')->where('id', $link->organization_membership_id)->firstOrFail();
        DB::table('organization_memberships')->where('id', $membership->id)->update(['status' => 'active']);
        $boundSession = $this->boundSession((string) $membership->user_id, (string) $membership->id);

        app(StaffService::class)->archive($actor['session_id'], $staff['id'], (string) Str::uuid7(), null);
        $restored = app(StaffService::class)->restore($actor['session_id'], $staff['id'], (string) Str::uuid7());

        $this->assertNull($restored['archived_at']);
        $this->assertFalse($restored['has_login_account']);
        $this->assertFalse(DB::table('staff_membership_links')
            ->where('staff_profile_id', $staff['id'])
            ->whereNull('unlinked_at')
            ->exists());
        $this->assertSame('suspended', DB::table('organization_memberships')->where('id', $membership->id)->value('status'));
        $this->assertNull(DB::table('auth_sessions')->where('id', $boundSession)->value('organization_membership_id'));
    }

    public function test_dbt_res_048_create_staff_membership_link_is_same_tenant_and_idempotent_at_domain_level(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor);

        app(StaffService::class)->createPanelAccount($actor['session_id'], $staff['id'], (string) Str::uuid7());
        app(StaffService::class)->createPanelAccount($actor['session_id'], $staff['id'], (string) Str::uuid7());

        $links = DB::table('staff_membership_links')
            ->where('organization_id', $actor['organization_id'])
            ->where('staff_profile_id', $staff['id'])
            ->whereNull('unlinked_at')
            ->get();
        $this->assertCount(1, $links);
        $membershipOrganization = DB::table('organization_memberships')
            ->where('id', $links->first()->organization_membership_id)
            ->value('organization_id');
        $this->assertSame($actor['organization_id'], $membershipOrganization);
    }

    public function test_dbt_res_049_replace_staff_or_vehicle_document_preserves_superseded_history(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor, ['medical_exam_valid_until' => '2027-05-01']);
        app(StaffService::class)->update(
            $actor['session_id'],
            $staff['id'],
            ['medical_exam_valid_until' => '2028-05-01'],
            (string) Str::uuid7(),
        );

        $vehicle = $this->createVehicle($actor, ['next_inspection_at' => '2027-06-01']);
        app(VehicleService::class)->update(
            $actor['session_id'],
            $vehicle['id'],
            ['next_inspection_at' => '2028-06-01'],
            (string) Str::uuid7(),
        );

        $this->assertSame(1, DB::table('staff_documents')
            ->where('staff_profile_id', $staff['id'])
            ->where('document_type', 'medical_exam')
            ->whereNotNull('superseded_at')
            ->count());
        $this->assertSame(1, DB::table('vehicle_documents')
            ->where('vehicle_id', $vehicle['id'])
            ->where('document_type', 'technical_inspection')
            ->whereNotNull('superseded_at')
            ->count());
    }

    public function test_dbt_res_050_restore_staff_profile_restores_record_only(): void
    {
        $actor = FoundationSchema::actor();
        $staff = $this->createStaff($actor);
        app(StaffService::class)->createPanelAccount($actor['session_id'], $staff['id'], (string) Str::uuid7());
        app(StaffService::class)->archive($actor['session_id'], $staff['id'], (string) Str::uuid7(), null);

        $restored = app(StaffService::class)->restore(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
        );

        $this->assertNull($restored['archived_at']);
        $this->assertFalse($restored['has_login_account']);
        $this->assertFalse(DB::table('staff_membership_links')
            ->where('staff_profile_id', $staff['id'])
            ->whereNull('unlinked_at')
            ->exists());
    }

    public function test_staff_pesel_never_enters_audit_payload(): void
    {
        $actor = FoundationSchema::actor();
        $pesel = '44051401458';
        $staff = $this->createStaff($actor, ['pesel' => $pesel]);

        $audit = DB::table('audit_logs')
            ->where('entity_type', 'staff_profile')
            ->where('entity_id', $staff['id'])
            ->where('action', 'staff.created')
            ->firstOrFail();

        $payload = (string) $audit->before_redacted_json.' '.(string) $audit->after_redacted_json;
        $this->assertStringNotContainsString($pesel, $payload);
        $this->assertStringNotContainsString('pesel_ciphertext', $payload);
        $this->assertStringNotContainsString('pesel_lookup_hash', $payload);
    }

    public function test_resource_create_idempotency_replays_without_duplicate_business_effect(): void
    {
        $actor = FoundationSchema::actor();
        $payload = [
            'type_code' => 'branch',
            'name' => 'Idempotent Branch',
            'street_and_number' => 'Testowa 1',
            'postal_code' => '00-001',
            'city_reference' => 'Warszawa',
        ];
        $key = (string) Str::uuid7();
        $service = app(ResourceIdempotency::class);

        $first = $service->execute(
            $actor['organization_id'],
            'locations.create',
            $key,
            $payload,
            function () use ($actor, $payload): array {
                $body = app(LocationService::class)->create(
                    $actor['session_id'],
                    $payload,
                    (string) Str::uuid7(),
                );

                return [
                    'status' => 201,
                    'resource_type' => 'location',
                    'resource_id' => $body['id'],
                    'body' => $body,
                ];
            },
        );
        $second = $service->execute(
            $actor['organization_id'],
            'locations.create',
            $key,
            $payload,
            fn (): array => throw new \RuntimeException('Replay must not execute the business callback.'),
        );

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('locations')
            ->where('organization_id', $actor['organization_id'])
            ->where('name', 'Idempotent Branch')
            ->count());
    }

    /**
     * @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function createLocation(array $actor, string $name = 'Main Branch', array $overrides = []): array
    {
        return app(LocationService::class)->create(
            $actor['session_id'],
            [
                'type_code' => 'branch',
                'name' => $name,
                'street_and_number' => 'Testowa 1',
                'postal_code' => '00-001',
                'city_reference' => 'Warszawa',
                ...$overrides,
            ],
            (string) Str::uuid7(),
        );
    }

    /**
     * @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function createStaff(array $actor, array $overrides = []): array
    {
        return app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'staff.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'staff_type_codes' => ['OfficeWorker'],
                'category_ids' => [],
                'location_ids' => [],
                ...$overrides,
            ],
            (string) Str::uuid7(),
        );
    }

    /**
     * @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function createVehicle(array $actor, array $overrides = []): array
    {
        return app(VehicleService::class)->create(
            $actor['session_id'],
            [
                'registration_number' => 'KR'.substr(str_replace('-', '', (string) Str::uuid7()), 0, 6),
                'make' => 'Toyota',
                'model' => 'Yaris',
                'category_ids' => [],
                'location_ids' => [],
                ...$overrides,
            ],
            (string) Str::uuid7(),
        );
    }

    /** @return list<string> */
    private function categoryIds(int $count): array
    {
        return DB::table('driving_categories')
            ->where('active', true)
            ->orderBy('code')
            ->limit($count)
            ->pluck('id')
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }

    private function asset(string $organizationId, string $purpose, string $status): string
    {
        $id = (string) Str::uuid7();
        DB::table('file_assets')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'storage_disk' => 's3',
            'storage_key' => 'tests/'.str_replace('-', '', $id),
            'size_bytes' => 128,
            'purpose' => $purpose,
            'status' => $status,
            'created_at' => now(),
            'ready_at' => $status === 'ready' ? now() : null,
        ]);

        return $id;
    }

    private function boundSession(string $userId, string $membershipId): string
    {
        $id = (string) Str::uuid7();
        DB::table('auth_sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'organization_membership_id' => $membershipId,
            'token_or_framework_session_hash' => hash('sha256', $id),
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @param callable():mixed $callback */
    private function captureDomainException(callable $callback): ResourceDomainException
    {
        try {
            $callback();
        } catch (ResourceDomainException $exception) {
            return $exception;
        }

        $this->fail('Expected ResourceDomainException.');
    }
}
