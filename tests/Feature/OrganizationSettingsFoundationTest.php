<?php

namespace Tests\Feature;

use App\Modules\OrganizationSettings\OrganizationSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class OrganizationSettingsFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_dbt_iam_005_settings_version_increments_once_per_successful_atomic_write(): void
    {
        $actor = FoundationSchema::actor();

        $result = app(OrganizationSettingsService::class)->update(
            $actor['session_id'],
            1,
            [
                'first_name' => 'Anna',
                'company_name' => 'OSK Test',
                'address' => [
                    'street' => 'Testowa',
                    'house_number' => '1',
                    'postal_code' => '00-001',
                    'city_name' => 'Warszawa',
                ],
            ],
            (string) Str::uuid7(),
        );

        $this->assertSame(2, $result['version']);
        $this->assertSame(2, (int) DB::table('organization_settings')->where('organization_id', $actor['organization_id'])->value('version'));
        $this->assertSame('Anna', DB::table('users')->where('id', $actor['user_id'])->value('first_name'));
        $this->assertSame('OSK Test', DB::table('organizations')->where('id', $actor['organization_id'])->value('name'));
        $this->assertSame('Warszawa', DB::table('organization_contact_addresses')->where('organization_id', $actor['organization_id'])->value('city_name'));
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_stale_settings_version_rolls_back_without_partial_write(): void
    {
        $actor = FoundationSchema::actor();

        try {
            app(OrganizationSettingsService::class)->update(
                $actor['session_id'],
                99,
                ['company_name' => 'Must Not Persist'],
                (string) Str::uuid7(),
            );
            $this->fail('Expected stale version failure.');
        } catch (LogicException) {
            $this->assertSame('Synthetic OSK', DB::table('organizations')->where('id', $actor['organization_id'])->value('name'));
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseCount('domain_events', 0);
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }

    public function test_settings_write_rechecks_current_permission_before_mutation(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('membership_permissions')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.settings.manage')
            ->update(['granted' => false]);
        DB::table('membership_permission_scopes')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.settings.manage')
            ->delete();

        try {
            app(OrganizationSettingsService::class)->update(
                $actor['session_id'],
                1,
                ['company_name' => 'Must Not Persist'],
                (string) Str::uuid7(),
            );
            $this->fail('Expected current authorization rejection.');
        } catch (AuthorizationException) {
            $this->assertSame('Synthetic OSK', DB::table('organizations')->where('id', $actor['organization_id'])->value('name'));
            $this->assertSame(1, (int) DB::table('organization_settings')->where('organization_id', $actor['organization_id'])->value('version'));
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }
}
