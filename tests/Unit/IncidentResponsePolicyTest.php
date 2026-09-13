<?php

namespace Tests\Unit;

use App\Support\Operations\IncidentResponsePolicy;
use Tests\TestCase;

final class IncidentResponsePolicyTest extends TestCase
{
    public function test_severity_targets_and_required_owner_roles_are_explicit(): void
    {
        $policy = app(IncidentResponsePolicy::class);

        $this->assertSame('2026-09-13-v1', $policy->version());
        $this->assertSame(10, $policy->severity('SEV1')['ack_minutes']);
        $this->assertSame(15, $policy->severity('SEV1')['incident_commander_assigned_minutes']);
        $this->assertSame(30, $policy->severity('SEV1')['status_update_minutes']);
        $this->assertSame(30, $policy->severity('SEV2')['ack_minutes']);
        $this->assertSame(240, $policy->severity('SEV3')['ack_minutes']);

        $this->assertSame([
            'incident_commander',
            'technical_lead',
            'privacy_lead',
            'business_liaison',
            'communications_lead',
            'scribe',
        ], $policy->requiredOwnerRoles());
    }

    public function test_all_core_runbooks_exist_and_forbid_state_invention(): void
    {
        $policy = app(IncidentResponsePolicy::class);

        $expected = [
            'database_or_data_integrity',
            'object_storage',
            'redis_or_queue',
            'payment_or_reconciliation',
            'internal_exam',
            'security_or_credentials',
            'personal_data_breach',
            'deployment_or_destructive_operation',
            'PKK_provider_deferred_boundary',
        ];

        foreach ($expected as $runbook) {
            $definition = $policy->runbook($runbook);
            $this->assertNotEmpty($definition['primary_owner']);
            $this->assertContains('incident_commander', $definition['required_roles']);
            $this->assertContains('scribe', $definition['required_roles']);
            $this->assertNotEmpty($definition['never']);
        }

        $payment = $policy->runbook('payment_or_reconciliation');
        $this->assertContains('mark_paid_from_browser_return', $payment['never']);

        $exam = $policy->runbook('internal_exam');
        $this->assertContains('invent_pass_result', $exam['never']);

        $pkk = $policy->runbook('PKK_provider_deferred_boundary');
        $this->assertContains('enable_provider_specific_runtime_without_PWPW_authority', $pkk['never']);
    }

    public function test_personal_data_breach_flow_is_conditional_not_automatic(): void
    {
        $policy = app(IncidentResponsePolicy::class);

        $this->assertTrue($policy->everyPersonalDataBreachMustBeDocumented());
        $this->assertFalse($policy->regulatorNotificationIsAutomatic());
        $this->assertSame(72, $policy->regulatorDeadlineHoursWhenRequired());

        $breach = config('incident_response.personal_data_breach');
        $this->assertTrue($breach['controller_risk_assessment_required']);
        $this->assertSame(
            'likely_risk_to_rights_or_freedoms',
            $breach['supervisory_authority']['condition'],
        );
        $this->assertTrue($breach['supervisory_authority']['late_notification_requires_delay_reasons']);
        $this->assertTrue($breach['supervisory_authority']['staged_information_allowed_when_not_all_information_available']);
        $this->assertSame(
            'likely_high_risk_to_rights_or_freedoms',
            $breach['data_subjects']['condition'],
        );
        $this->assertSame('without_undue_delay', $breach['data_subjects']['deadline']);
    }

    public function test_repository_defaults_do_not_fake_a_production_contact_roster(): void
    {
        $policy = app(IncidentResponsePolicy::class);

        foreach (array_keys((array) config('incident_response.contact_refs')) as $key) {
            config()->set('incident_response.contact_refs.'.$key, null);
        }

        $this->assertFalse($policy->productionContactRosterComplete());

        foreach (array_keys((array) config('incident_response.contact_refs')) as $key) {
            config()->set('incident_response.contact_refs.'.$key, 'deployment-secret-reference');
        }

        $this->assertTrue($policy->productionContactRosterComplete());
        $this->assertTrue((bool) config('incident_response.production_paging_smoke_test_required'));
    }
}
