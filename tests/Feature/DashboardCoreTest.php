<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class DashboardCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_dashboard_aggregates_dynamic_tenant_scoped_license_exam_activity_and_calendar_projections(): void
    {
        $actor = FoundationSchema::actor();
        $this->availableLicense($actor['organization_id']);
        $this->availableLicense($actor['organization_id']);
        $this->activeLicense($actor);
        $this->availableExamUnits($actor, 3);
        $activityId = $this->activity($actor);
        $calendarId = $this->calendarEvent($actor['organization_id'], 'Dashboardowe wydarzenie');

        $foreign = FoundationSchema::actor();
        $this->availableLicense($foreign['organization_id']);
        $this->availableExamUnits($foreign, 4);
        $this->activity($foreign);
        $this->calendarEvent($foreign['organization_id'], 'Obce wydarzenie');

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('licenses.available_count', 2)
            ->assertJsonPath('licenses.active_count', 1)
            ->assertJsonPath('internal_exams.available_count', 3)
            ->assertJsonPath('activity.0.id', $activityId);

        $events = $response->json('calendar.events');
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $this->assertSame($calendarId, $events[0]['id']);
        $this->assertSame('Dashboardowe wydarzenie', $events[0]['name']);
    }

    public function test_dashboard_fails_closed_when_internal_exam_ledger_and_operational_projection_drift(): void
    {
        $actor = FoundationSchema::actor();
        $this->availableExamUnits($actor, 1);

        DB::table('internal_exam_inventory_entries')
            ->where('organization_id', $actor['organization_id'])
            ->update(['current_state' => 'consumed']);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/dashboard')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');
    }

    public function test_dashboard_requires_current_active_membership(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('organization_memberships')
            ->where('id', $actor['membership_id'])
            ->update(['status' => 'suspended']);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }

    private function availableLicense(string $organizationId): string
    {
        $id = (string) Str::uuid7();
        DB::table('license_inventory_entries')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'license_product_id' => (string) Str::uuid7(),
            'source_order_item_id' => null,
            'source_order_item_grant_ordinal' => null,
            'status' => 'available',
            'granted_at' => now(),
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @param array{organization_id:string,user_id:string} $actor */
    private function activeLicense(array $actor): void
    {
        $inventoryId = (string) Str::uuid7();
        $assignmentId = (string) Str::uuid7();
        $accountId = (string) Str::uuid7();
        DB::table('license_inventory_entries')->insert([
            'id' => $inventoryId,
            'organization_id' => $actor['organization_id'],
            'license_product_id' => (string) Str::uuid7(),
            'source_order_item_id' => null,
            'source_order_item_grant_ordinal' => null,
            'status' => 'consumed',
            'granted_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        DB::table('license_assignments')->insert([
            'id' => $assignmentId,
            'organization_id' => $actor['organization_id'],
            'license_inventory_entry_id' => $inventoryId,
            'student_id' => (string) Str::uuid7(),
            'student_learning_account_id' => $accountId,
            'license_product_language_capability_id' => (string) Str::uuid7(),
            'language_code' => 'pl',
            'assignment_sequence' => 1,
            'status' => 'activated',
            'assigned_by_user_id' => $actor['user_id'],
            'assigned_at' => now()->subDay(),
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revoke_reason' => null,
            'version' => 2,
            'created_at' => now()->subDay(),
        ]);
        DB::table('license_activations')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'license_assignment_id' => $assignmentId,
            'student_learning_account_id' => $accountId,
            'entitlement_sequence' => 1,
            'activation_origin' => 'manual',
            'duration_snapshot_source' => 'license_product',
            'duration_days_snapshot' => 30,
            'expiry_before' => null,
            'activated_by_user_id' => $actor['user_id'],
            'activated_at' => now()->subDay(),
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDays(29),
            'created_at' => now()->subDay(),
        ]);
    }

    /** @param array{organization_id:string,user_id:string} $actor */
    private function availableExamUnits(array $actor, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $inventoryId = (string) Str::uuid7();
            DB::table('internal_exam_inventory_entries')->insert([
                'id' => $inventoryId,
                'organization_id' => $actor['organization_id'],
                'source_type' => 'free',
                'source_order_item_id' => null,
                'source_adjustment_id' => null,
                'current_state' => 'available',
                'created_at' => now(),
            ]);
            DB::table('internal_exam_inventory_ledger_entries')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'internal_exam_inventory_entry_id' => $inventoryId,
                'internal_exam_reservation_id' => null,
                'internal_exam_attempt_id' => null,
                'internal_exam_inventory_adjustment_id' => null,
                'event_sequence' => 1,
                'event_type' => 'unit_granted',
                'available_delta' => 1,
                'actor_user_id' => $actor['user_id'],
                'reason' => null,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    /** @param array{organization_id:string,user_id:string,membership_id:string} $actor */
    private function activity(array $actor): string
    {
        $sourceId = (string) Str::uuid7();
        $activityId = (string) Str::uuid7();
        DB::table('domain_events')->insert([
            'id' => $sourceId,
            'event_scope' => 'organization',
            'organization_id' => $actor['organization_id'],
            'event_type' => 'dashboard.synthetic',
            'aggregate_reference_mode' => 'snapshot_only',
            'aggregate_type' => 'synthetic',
            'aggregate_id' => (string) Str::uuid7(),
            'request_id' => (string) Str::uuid7(),
            'causation_event_id' => null,
            'required_audit_log_id' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
        DB::table('organization_activity_events')->insert([
            'id' => $activityId,
            'organization_id' => $actor['organization_id'],
            'source_event_id' => $sourceId,
            'projection_policy_version' => 1,
            'event_type' => 'dashboard.synthetic',
            'occurred_at' => now(),
            'description_snapshot' => 'Bezpieczne zdarzenie dashboardu',
            'safe_payload' => json_encode(['kind' => 'synthetic'], JSON_THROW_ON_ERROR),
            'actor_reference_mode' => 'membership_snapshot',
            'actor_organization_membership_id' => $actor['membership_id'],
            'actor_user_id' => $actor['user_id'],
            'actor_display_name_snapshot' => 'Test Owner',
            'actor_role_snapshot' => 'Owner',
            'subject_reference_mode' => 'snapshot_only',
            'subject_type' => 'synthetic',
            'subject_id' => (string) Str::uuid7(),
            'related_student_id' => null,
            'created_at' => now(),
        ]);

        return $activityId;
    }

    private function calendarEvent(string $organizationId, string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('calendar_events')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'event_type' => 'meeting',
            'name' => $name,
            'starts_at' => now()->startOfMonth()->addDays(2)->setTime(10, 0),
            'ends_at' => now()->startOfMonth()->addDays(2)->setTime(11, 0),
            'student_id' => null,
            'instructor_id' => null,
            'vehicle_id' => null,
            'location_id' => null,
            'custom_meeting_place' => null,
            'status' => 'scheduled',
            'created_by_user_id' => (string) Str::uuid7(),
            'version' => 1,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
