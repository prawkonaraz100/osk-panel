<?php

namespace Tests\Feature;

use App\Modules\OrganizationSettings\OrganizationSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AuditOutboxFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_dbt_aud_003_missing_current_audit_policy_rolls_back_business_effect(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('audit_action_policy_currents')->where('action', 'organization.settings.updated')->delete();

        try {
            app(OrganizationSettingsService::class)->update(
                $actor['session_id'],
                1,
                ['company_name' => 'Rollback Me'],
                (string) Str::uuid7(),
            );
            $this->fail('Expected audit policy failure.');
        } catch (LogicException) {
            $this->assertSame('Synthetic OSK', DB::table('organizations')->where('id', $actor['organization_id'])->value('name'));
            $this->assertSame(1, (int) DB::table('organization_settings')->where('organization_id', $actor['organization_id'])->value('version'));
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseCount('domain_events', 0);
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }

    public function test_dbt_aud_005_business_audit_domain_event_and_outbox_commit_together(): void
    {
        $actor = FoundationSchema::actor();
        $requestId = (string) Str::uuid7();

        app(OrganizationSettingsService::class)->update(
            $actor['session_id'],
            1,
            ['phone' => '+48123456789'],
            $requestId,
        );

        $audit = DB::table('audit_logs')->first();
        $event = DB::table('domain_events')->first();
        $outbox = DB::table('outbox_messages')->first();

        $this->assertNotNull($audit);
        $this->assertNotNull($event);
        $this->assertNotNull($outbox);
        $this->assertSame($actor['organization_id'], $audit->organization_id);
        $this->assertSame($actor['organization_id'], $event->organization_id);
        $this->assertSame($actor['organization_id'], $outbox->organization_id);
        $this->assertSame($audit->id, $event->required_audit_log_id);
        $this->assertSame($event->id, $outbox->domain_event_id);
        $this->assertSame($requestId, $audit->request_id);
        $this->assertSame($requestId, $event->request_id);
        $this->assertSame($requestId, $outbox->request_id);
    }
}
