<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class DashboardNotificationCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_activity_is_tenant_scoped_filtered_and_exposes_only_safe_projection(): void
    {
        $actor = FoundationSchema::actor();
        $source = $this->sourceEvent($actor['organization_id'], 'commerce.payment.started');
        $activityId = $this->activity(
            $actor,
            $source,
            'commerce.payment.started',
            ['amount_minor' => 1200, 'currency' => 'PLN'],
        );

        $foreign = FoundationSchema::actor();
        $foreignSource = $this->sourceEvent($foreign['organization_id'], 'commerce.payment.started');
        $this->activity($foreign, $foreignSource, 'commerce.payment.started', ['amount_minor' => 9999]);

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/activity?event_type=commerce.payment.started')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $activityId)
            ->assertJsonPath('data.0.event_type', 'commerce.payment.started')
            ->assertJsonPath('data.0.actor_display_name', 'Test Owner')
            ->assertJsonPath('data.0.related_entity_type', 'platform_payment')
            ->assertJsonPath('data.0.description', 'Rozpoczęto płatność')
            ->assertJsonPath('data.0.safe_details.currency', 'PLN');

        $this->assertArrayNotHasKey('source_event_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('audit_log_id', $response->json('data.0'));
    }

    public function test_notification_list_is_exact_recipient_scoped_and_unread_filter_runs_after_scope(): void
    {
        $actor = FoundationSchema::actor();
        $unread = $this->notification($actor, $this->sourceEvent($actor['organization_id'], 'notice.unread'), null);
        $this->notification($actor, $this->sourceEvent($actor['organization_id'], 'notice.read'), now()->subMinute());

        $otherMembership = FoundationSchema::member($actor['organization_id']);
        $otherUser = (string) DB::table('organization_memberships')->where('id', $otherMembership)->value('user_id');
        $otherActor = [
            'organization_id' => $actor['organization_id'],
            'membership_id' => $otherMembership,
            'user_id' => $otherUser,
        ];
        $this->notification($otherActor, $this->sourceEvent($actor['organization_id'], 'notice.other'), null);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/notifications?unread_only=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $unread)
            ->assertJsonPath('data.0.read_at', null);
    }

    public function test_mark_read_sets_timestamp_once_and_is_idempotent_across_replays(): void
    {
        $actor = FoundationSchema::actor();
        $notification = $this->notification(
            $actor,
            $this->sourceEvent($actor['organization_id'], 'notice.mark'),
            null,
        );
        $domainEventsBefore = DB::table('domain_events')->count();
        $outboxBefore = DB::table('outbox_messages')->count();
        $firstKey = (string) Str::uuid7();

        $first = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $firstKey)
            ->postJson("/api/v1/notifications/{$notification}/read")
            ->assertOk();
        $firstReadAt = (string) $first->json('read_at');
        $this->assertNotSame('', $firstReadAt);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $firstKey)
            ->postJson("/api/v1/notifications/{$notification}/read")
            ->assertOk()
            ->assertJsonPath('read_at', $firstReadAt);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/notifications/{$notification}/read")
            ->assertOk()
            ->assertJsonPath('read_at', $firstReadAt);

        $this->assertSame($firstReadAt, (string) DB::table('notifications')->where('id', $notification)->value('read_at'));
        $this->assertSame($domainEventsBefore, DB::table('domain_events')->count());
        $this->assertSame($outboxBefore, DB::table('outbox_messages')->count());
    }

    public function test_mark_read_fails_closed_for_other_recipient_and_inactive_membership(): void
    {
        $actor = FoundationSchema::actor();
        $otherMembership = FoundationSchema::member($actor['organization_id']);
        $otherUser = (string) DB::table('organization_memberships')->where('id', $otherMembership)->value('user_id');
        $otherActor = [
            'organization_id' => $actor['organization_id'],
            'membership_id' => $otherMembership,
            'user_id' => $otherUser,
        ];
        $foreignNotification = $this->notification(
            $otherActor,
            $this->sourceEvent($actor['organization_id'], 'notice.foreign'),
            null,
        );

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/notifications/{$foreignNotification}/read")
            ->assertNotFound();

        $ownNotification = $this->notification(
            $actor,
            $this->sourceEvent($actor['organization_id'], 'notice.own'),
            null,
        );
        DB::table('organization_memberships')
            ->where('id', $actor['membership_id'])
            ->update(['status' => 'suspended']);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/notifications/{$ownNotification}/read")
            ->assertForbidden();

        $this->assertNull(DB::table('notifications')->where('id', $ownNotification)->value('read_at'));
    }

    private function sourceEvent(string $organizationId, string $eventType): string
    {
        $id = (string) Str::uuid7();
        DB::table('domain_events')->insert([
            'id' => $id,
            'event_scope' => 'organization',
            'organization_id' => $organizationId,
            'event_type' => $eventType,
            'aggregate_reference_mode' => 'snapshot_only',
            'aggregate_type' => 'synthetic',
            'aggregate_id' => (string) Str::uuid7(),
            'request_id' => (string) Str::uuid7(),
            'causation_event_id' => null,
            'required_audit_log_id' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array{organization_id:string,membership_id:string,user_id:string}  $actor
     * @param  array<string,mixed>  $safePayload
     */
    private function activity(array $actor, string $sourceEventId, string $eventType, array $safePayload): string
    {
        $id = (string) Str::uuid7();
        DB::table('organization_activity_events')->insert([
            'id' => $id,
            'organization_id' => $actor['organization_id'],
            'source_event_id' => $sourceEventId,
            'projection_policy_version' => 1,
            'event_type' => $eventType,
            'occurred_at' => now(),
            'description_snapshot' => 'Rozpoczęto płatność',
            'safe_payload' => json_encode($safePayload, JSON_THROW_ON_ERROR),
            'actor_reference_mode' => 'membership_snapshot',
            'actor_organization_membership_id' => $actor['membership_id'],
            'actor_user_id' => $actor['user_id'],
            'actor_display_name_snapshot' => 'Test Owner',
            'actor_role_snapshot' => 'Owner',
            'subject_reference_mode' => 'snapshot_only',
            'subject_type' => 'platform_payment',
            'subject_id' => (string) Str::uuid7(),
            'related_student_id' => null,
            'created_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array{organization_id:string,membership_id:string,user_id:string}  $actor
     */
    private function notification(array $actor, string $sourceEventId, mixed $readAt): string
    {
        $id = (string) Str::uuid7();
        DB::table('notifications')->insert([
            'id' => $id,
            'organization_id' => $actor['organization_id'],
            'source_event_id' => $sourceEventId,
            'organization_membership_id' => $actor['membership_id'],
            'user_id' => $actor['user_id'],
            'audience_kind' => 'direct',
            'type' => 'system_notice',
            'payload' => json_encode(['message' => 'Bezpieczne powiadomienie'], JSON_THROW_ON_ERROR),
            'read_at' => $readAt,
            'created_at' => now(),
        ]);

        return $id;
    }
}
