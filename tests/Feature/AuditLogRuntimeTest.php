<?php

namespace Tests\Feature;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AuditLogRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();
    }

    public function test_audit_log_projection_is_tenant_scoped_filtered_and_does_not_serialize_raw_payloads(): void
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'organization.audit.view', ['organization']);

        $entityId = (string) Str::uuid7();
        $requestId = (string) Str::uuid7();
        $auditId = $this->audit($actor, $entityId, $requestId);

        $foreign = FoundationSchema::actor();
        $this->audit($foreign, (string) Str::uuid7(), (string) Str::uuid7());

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/audit-logs?entity_type=vehicle&entity_id='.$entityId.'&request_id='.$requestId)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $auditId)
            ->assertJsonPath('data.0.action', 'vehicle.updated')
            ->assertJsonPath('data.0.entity_type', 'vehicle')
            ->assertJsonPath('data.0.entity_id', $entityId)
            ->assertJsonPath('data.0.actor_user_id', $actor['user_id'])
            ->assertJsonPath('data.0.request_id', $requestId)
            ->assertJsonPath('data.0.reason', 'Synthetic audit reason');

        $entry = $response->json('data.0');
        $this->assertIsArray($entry);
        foreach ([
            'before_redacted_json',
            'after_redacted_json',
            'ip_hash',
            'user_agent',
            'audit_policy_version',
            'actor_organization_membership_id',
        ] as $forbiddenField) {
            $this->assertArrayNotHasKey($forbiddenField, $entry);
        }
    }

    public function test_audit_log_projection_requires_explicit_organization_audit_permission(): void
    {
        $actor = FoundationSchema::actor();
        $this->audit($actor, (string) Str::uuid7(), (string) Str::uuid7());

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();

        FoundationSchema::grant($actor['membership_id'], 'organization.audit.view', ['organization']);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     */
    private function audit(array $actor, string $entityId, string $requestId): string
    {
        return DB::transaction(function () use ($actor, $entityId, $requestId): string {
            $result = app(AtomicAuditOutbox::class)->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['membership_id'],
                $actor['user_id'],
                'vehicle.updated',
                'vehicle',
                $entityId,
                $requestId,
                ['fields' => ['make'], 'state' => 'active'],
                ['fields' => ['make'], 'state' => 'active'],
                'Synthetic audit reason',
            );

            return $result['audit_log_id'];
        });
    }
}
