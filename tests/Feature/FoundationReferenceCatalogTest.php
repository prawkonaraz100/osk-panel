<?php

namespace Tests\Feature;

use App\Modules\ResourcesCore\ResourceCatalogService;
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

    public function test_legal_driving_entitlement_dictionary_keeps_tram_permit_alias_out_of_rule_engine_catalog(): void
    {
        $expected = [
            'AM', 'A1', 'A2', 'A', 'B1', 'B', 'B+E', 'C1',
            'C', 'C1+E', 'C+E', 'D1', 'D', 'D1+E', 'D+E', 'T',
        ];
        sort($expected);

        $actual = DB::table('driving_categories')
            ->where('active', true)
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->all();
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertCount(16, $actual);

        $tramAlias = DB::table('driving_categories')->where('code', 'PT')->firstOrFail();
        $metadata = json_decode((string) $tramAlias->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse((bool) $tramAlias->active);
        $this->assertSame('tram_permit', $metadata['entitlement_kind']);
        $this->assertSame('Pozwolenie na kierowanie tramwajem', $metadata['official_label']);
        $this->assertFalse($metadata['is_driving_licence_category']);
        $this->assertFalse($metadata['rule_engine_eligible']);
        $this->assertSame('USER_CONFIRMED_AUTH_SCREEN_ALIAS', $metadata['preservation_status']);

        $actor = FoundationSchema::actor();
        $catalog = app(ResourceCatalogService::class)->drivingCategories($actor['session_id']);
        $codes = array_column($catalog, 'code');

        $this->assertCount(16, $codes);
        $this->assertNotContains('PT', $codes);
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
        $this->assertSame(80, DB::table('audit_action_policy_currents')->count());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'auth.account_closure.requested')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'auth.registration.completed')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'student.created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'course.created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'course.completed')->exists());
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
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'commerce.service_entitlement.activated')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_updated')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_password_reset')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_handoff_created')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'learning_account_credentials_pdf_downloaded')->exists());
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
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'formal_document.approved')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'formal_document.downloaded')->exists());
        $this->assertTrue(DB::table('audit_action_policy_currents')->where('action', 'formal_document.delivery_recorded')->exists());

        app(FoundationReferenceCatalogSeeder::class)->run();

        $this->assertSame(4, DB::table('data_scopes')->count());
        $this->assertSame(80, DB::table('audit_action_policy_currents')->count());
    }
}
