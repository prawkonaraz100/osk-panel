<?php

namespace Tests\Feature;

use Database\Seeders\FoundationReferenceCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class FoundationReferenceCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_production_seeder_materializes_permission_scope_and_audit_policy_catalogs(): void
    {
        $this->assertTrue(DB::table('permissions')->where('code', 'organization.settings.manage')->exists());
        $this->assertTrue(DB::table('permissions')->where('code', 'students.create')->exists());
        $this->assertTrue(DB::table('permissions')->where('code', 'exams.stations.view')->exists());
        $this->assertTrue(DB::table('permissions')->where('code', 'exams.stations.manage')->exists());
        $this->assertTrue(DB::table('permission_scope_options')
            ->where('permission_code', 'exams.stations.manage')
            ->where('scope_code', 'organization')
            ->exists());
        $this->assertSame(4, DB::table('data_scopes')->count());
        $this->assertSame(
            'tenant_resource',
            DB::table('permission_scope_options')
                ->where('permission_code', 'organization.settings.manage')
                ->where('scope_code', 'organization')
                ->value('resolver_code'),
        );
        $this->assertTrue(DB::table('permission_scope_options')
            ->where('permission_code', 'students.view')
            ->where('scope_code', 'assigned_students')
            ->exists());
        $this->assertSame(72, DB::table('audit_action_policy_currents')->count());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'student.created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'course.created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'training.session.completed')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'training.hours.corrected')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'calendar.event.completed')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'availability.slot.booked')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'availability.slot.formalized')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'student_charge_created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'student_charge_cancelled')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'student_payment_recorded')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'student_payment_reversed')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'commerce.payment.started')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_updated')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_password_reset')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_handoff_created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'license_assignment_created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'license_assignment_activated')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'license_assignment_revoked')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.attempt.created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.access.created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.access.sent')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.access.delivery_failed')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.access.revoked')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.started')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.submitted')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.technical_aborted')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.station_transferred')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.station.registered')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.station_credential.provisioned')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.station_credential.rotated')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.answer_sheet.downloaded')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'internal_exam.inventory.adjusted')->exists());

        app(FoundationReferenceCatalogSeeder::class)->run();

        $this->assertSame(4, DB::table('data_scopes')->count());
        $this->assertSame(71, DB::table('audit_action_policy_currents')->count());
    }
}
