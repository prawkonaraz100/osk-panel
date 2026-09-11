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
        $this->assertSame(50, DB::table('audit_action_policy_currents')->count());
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

        app(FoundationReferenceCatalogSeeder::class)->run();

        $this->assertSame(4, DB::table('data_scopes')->count());
        $this->assertSame(50, DB::table('audit_action_policy_currents')->count());
    }
}
