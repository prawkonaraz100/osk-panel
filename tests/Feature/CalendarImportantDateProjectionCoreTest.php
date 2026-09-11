<?php

namespace Tests\Feature;

use App\Modules\ResourcesCore\LocationService;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\ResourcesCore\VehicleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class CalendarImportantDateProjectionCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_staff_and_vehicle_expiry_dates_project_without_calendar_rows_or_claims(): void
    {
        $actor = $this->actorWithCalendar();
        $staff = $this->createStaff($actor, [
            'card_valid_until' => '2027-01-15',
            'medical_exam_valid_until' => '2027-05-01',
            'psychological_exam_valid_until' => '2027-06-02',
        ]);
        $vehicle = $this->createVehicle($actor, [
            'next_inspection_at' => '2027-07-03',
            'oc_valid_until' => '2027-08-04',
            'ac_valid_until' => '2027-09-05',
        ]);

        $response = $this->importantDates($actor['session_id']);
        $response->assertOk()->assertJsonCount(6);

        $items = collect($response->json());
        $staffItems = $items->where('source_kind', 'staff_document')->values();
        $vehicleItems = $items->where('source_kind', 'vehicle_document')->values();

        $this->assertCount(3, $staffItems);
        $this->assertCount(3, $vehicleItems);
        $this->assertEqualsCanonicalizing(
            ['staff_document_expiry', 'staff_medical_expiry', 'staff_psychological_expiry'],
            $staffItems->pluck('important_date_kind')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['vehicle_inspection_expiry', 'vehicle_oc_expiry', 'vehicle_ac_expiry'],
            $vehicleItems->pluck('important_date_kind')->all(),
        );
        $this->assertTrue($staffItems->every(fn (array $item): bool => $item['all_day'] === true));
        $this->assertTrue($vehicleItems->every(fn (array $item): bool => $item['all_day'] === true));
        $this->assertTrue($staffItems->every(fn (array $item): bool => $item['instructor_id'] === $staff['id']));
        $this->assertTrue($vehicleItems->every(fn (array $item): bool => $item['vehicle_id'] === $vehicle['id']));

        $card = $staffItems->firstWhere('important_date_kind', 'staff_document_expiry');
        $this->assertSame('2027-01-15', $card['source_date']);
        $this->assertStringContainsString('T00:00:00+01:00', $card['starts_at']);

        $medical = $staffItems->firstWhere('important_date_kind', 'staff_medical_expiry');
        $this->assertSame('2027-05-01', $medical['source_date']);
        $this->assertStringContainsString('T00:00:00+02:00', $medical['starts_at']);

        $this->assertSame(0, DB::table('calendar_events')->count());
        $this->assertSame(0, DB::table('calendar_resource_claims')->count());
    }

    public function test_source_document_replacement_moves_important_date_without_calendar_mutation(): void
    {
        $actor = $this->actorWithCalendar();
        $staff = $this->createStaff($actor, ['medical_exam_valid_until' => '2027-05-01']);

        $before = $this->importantDates($actor['session_id']);
        $before->assertOk()->assertJsonCount(1)->assertJsonPath('0.source_date', '2027-05-01');
        $oldSourceId = (string) $before->json('0.source_id');

        app(StaffService::class)->update(
            $actor['session_id'],
            $staff['id'],
            ['medical_exam_valid_until' => '2028-05-01'],
            (string) Str::uuid7(),
        );

        $after = $this->importantDates($actor['session_id']);
        $after->assertOk()->assertJsonCount(1)->assertJsonPath('0.source_date', '2028-05-01');
        $newSourceId = (string) $after->json('0.source_id');

        $this->assertNotSame($oldSourceId, $newSourceId);
        $this->assertSame(1, DB::table('staff_documents')
            ->where('id', $oldSourceId)
            ->whereNotNull('superseded_at')
            ->count());
        $this->assertSame(1, DB::table('staff_documents')
            ->where('id', $newSourceId)
            ->whereNull('superseded_at')
            ->count());
        $this->assertSame(0, DB::table('calendar_events')->count());
        $this->assertSame(0, DB::table('calendar_resource_claims')->count());
    }

    public function test_important_date_filters_and_archived_sources_fail_closed(): void
    {
        $actor = $this->actorWithCalendar();
        $location = $this->createLocation($actor, 'Filia A');
        $staff = $this->createStaff($actor, [
            'location_ids' => [$location['id']],
            'card_valid_until' => '2027-03-10',
        ]);
        $vehicle = $this->createVehicle($actor, [
            'location_ids' => [$location['id']],
            'oc_valid_until' => '2027-03-11',
        ]);

        $all = $this->importantDates($actor['session_id']);
        $all->assertOk()->assertJsonCount(2);

        $staffOnly = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=important_date&staff_id='.$staff['id']);
        $staffOnly->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.source_kind', 'staff_document');

        $vehicleOnly = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=important_date&vehicle_id='.$vehicle['id']);
        $vehicleOnly->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.source_kind', 'vehicle_document');

        $locationItems = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=important_date&location_id='.$location['id']);
        $locationItems->assertOk()->assertJsonCount(2);

        $studentFilter = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=important_date&student_id='.(string) Str::uuid7());
        $studentFilter->assertOk()->assertJsonCount(0);

        $generalOnly = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=general_event');
        $generalOnly->assertOk()->assertJsonCount(0);

        app(StaffService::class)->archive(
            $actor['session_id'],
            $staff['id'],
            (string) Str::uuid7(),
            'employment ended',
        );
        app(VehicleService::class)->archive(
            $actor['session_id'],
            $vehicle['id'],
            (string) Str::uuid7(),
            'removed from fleet',
        );

        $afterArchive = $this->importantDates($actor['session_id']);
        $afterArchive->assertOk()->assertJsonCount(0);
    }

    public function test_assigned_location_calendar_scope_only_projects_source_resources_from_assigned_locations(): void
    {
        $actor = $this->actorWithCalendar();
        $locationA = $this->createLocation($actor, 'Filia A');
        $locationB = $this->createLocation($actor, 'Filia B');

        $reader = $this->createStaff($actor, [
            'staff_type_codes' => ['Instructor'],
            'location_ids' => [$locationA['id']],
            'card_valid_until' => '2027-04-01',
        ]);
        $foreignStaff = $this->createStaff($actor, [
            'location_ids' => [$locationB['id']],
            'medical_exam_valid_until' => '2027-04-02',
        ]);
        $vehicleA = $this->createVehicle($actor, [
            'location_ids' => [$locationA['id']],
            'oc_valid_until' => '2027-04-03',
        ]);
        $this->createVehicle($actor, [
            'location_ids' => [$locationB['id']],
            'oc_valid_until' => '2027-04-04',
        ]);

        app(StaffService::class)->createPanelAccount(
            $actor['session_id'],
            $reader['id'],
            (string) Str::uuid7(),
        );
        $link = DB::table('staff_membership_links')
            ->where('staff_profile_id', $reader['id'])
            ->whereNull('unlinked_at')
            ->firstOrFail();
        DB::table('organization_memberships')
            ->where('id', $link->organization_membership_id)
            ->update(['status' => 'active']);
        FoundationSchema::grant((string) $link->organization_membership_id, 'calendar.view', ['assigned_locations']);

        $userId = (string) DB::table('organization_memberships')
            ->where('id', $link->organization_membership_id)
            ->value('user_id');
        $readerSessionId = (string) Str::uuid7();
        DB::table('auth_sessions')->insert([
            'id' => $readerSessionId,
            'user_id' => $userId,
            'organization_membership_id' => $link->organization_membership_id,
            'token_or_framework_session_hash' => hash('sha256', $readerSessionId),
            'created_at' => now(),
        ]);

        $response = $this->importantDates($readerSessionId);
        $response->assertOk()->assertJsonCount(2);

        $sourceIds = collect($response->json())->pluck('source_id')->all();
        $this->assertContains(
            DB::table('staff_documents')
                ->where('staff_profile_id', $reader['id'])
                ->whereNull('superseded_at')
                ->value('id'),
            $sourceIds,
        );
        $this->assertContains(
            DB::table('vehicle_documents')
                ->where('vehicle_id', $vehicleA['id'])
                ->whereNull('superseded_at')
                ->value('id'),
            $sourceIds,
        );
        $this->assertNotContains(
            DB::table('staff_documents')
                ->where('staff_profile_id', $foreignStaff['id'])
                ->whereNull('superseded_at')
                ->value('id'),
            $sourceIds,
        );
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function actorWithCalendar(): array
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'calendar.view', ['organization']);

        return $actor;
    }

    private function importantDates(string $sessionId): TestResponse
    {
        return $this->withSession(['auth_session_id' => $sessionId])
            ->getJson('/api/v1/calendar/events?event_type[]=important_date');
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function createStaff(array $actor, array $overrides = []): array
    {
        return app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'important.staff.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
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
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function createVehicle(array $actor, array $overrides = []): array
    {
        return app(VehicleService::class)->create(
            $actor['session_id'],
            [
                'registration_number' => 'IM'.substr(str_replace('-', '', (string) Str::uuid7()), 0, 6),
                'make' => 'Toyota',
                'model' => 'Yaris',
                'category_ids' => [],
                'location_ids' => [],
                ...$overrides,
            ],
            (string) Str::uuid7(),
        );
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array<string,mixed>
     */
    private function createLocation(array $actor, string $name): array
    {
        return app(LocationService::class)->create(
            $actor['session_id'],
            [
                'type_code' => 'branch',
                'name' => $name,
                'street_and_number' => 'Testowa 1',
                'postal_code' => '00-001',
                'city_reference' => 'Warszawa',
            ],
            (string) Str::uuid7(),
        );
    }
}
