<?php

namespace Tests\Support;

use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\Stage5FormalDocumentsMigrationPlan;
use Database\Seeders\FoundationReferenceCatalogSeeder;
use Database\Seeders\ResourceReferenceCatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class FoundationSchema
{
    /** @var list<string> */
    private const TABLES = [
        'data_retention_execution_runs',
        'event_projection_migration_cases',
        'terms_acceptances',
        'account_closure_requests',
        'auth_social_accounts',
        'legal_documents',
        'notifications',
        'organization_activity_events',
        'activity_projection_policy_currents',
        'activity_projection_policy_revisions',
        'service_activations',
        'service_entitlements',
        'order_fulfillments',
        'order_payment_settlements',
        'payment_events',
        'payments',
        'order_items',
        'orders',
        'commerce_catalog_items',
        'formal_training_document_events',
        'formal_training_documents',
        'formal_training_document_templates',
        'internal_exam_documents',
        'internal_exam_results',
        'internal_exam_attempt_questions',
        'internal_exam_station_sessions',
        'internal_exam_access_tokens',
        'internal_exam_access_lifecycle_events',
        'internal_exam_accesses',
        'internal_exam_reservations',
        'internal_exam_attempt_lifecycle_events',
        'internal_exam_attempts',
        'internal_exam_inventory_ledger_entries',
        'internal_exam_inventory_adjustments',
        'internal_exam_inventory_entries',
        'internal_exam_document_templates',
        'internal_exam_definitions',
        'exam_station_credentials',
        'exam_stations',
        'internal_exam_capabilities',
        'license_activations',
        'license_assignments',
        'license_inventory_entries',
        'license_product_language_capabilities',
        'license_products',
        'student_access_handoffs',
        'student_access_export_batches',
        'student_learning_accounts',
        'user_password_management',
        'languages',
        'training_session_calendar_details',
        'calendar_resource_claims',
        'availability_slot_lifecycle_events',
        'availability_slots',
        'calendar_event_lifecycle_events',
        'calendar_events',
        'training_hour_ledger_entries',
        'training_session_attendance',
        'training_sessions',
        'course_cost_charge_origins',
        'student_payments',
        'student_charges',
        'pkk_profiles',
        'recognized_external_training',
        'course_exemption_decisions',
        'training_requirement_profiles',
        'course_requirement_override_decisions',
        'course_requirement_context_held_categories',
        'course_requirement_contexts',
        'training_requirement_rule_sets',
        'course_enrollment_lifecycle_events',
        'course_enrollments',
        'students',
        'vehicle_location_assignments',
        'vehicle_category_assignments',
        'vehicle_documents',
        'staff_documents',
        'staff_membership_links',
        'staff_location_assignments',
        'staff_category_assignments',
        'staff_type_assignments',
        'staff_profiles',
        'vehicles',
        'locations',
        'idempotency_records',
        'file_assets',
        'staff_types',
        'location_types',
        'driving_categories',
        'outbox_messages',
        'domain_events',
        'audit_logs',
        'audit_action_policy_currents',
        'audit_action_policy_revisions',
        'auth_sessions',
        'membership_permission_scopes',
        'permission_scope_options',
        'membership_permissions',
        'organization_memberships',
        'auth_login_identifiers',
        'organization_contact_addresses',
        'organization_settings',
        'data_scopes',
        'permissions',
        'users',
        'organizations',
    ];

    public static function ensureMigrated(): void
    {
        $plan = app(MigrationPlan::class);
        $plan->validate();

        if (! DB::getSchemaBuilder()->hasTable('internal_exam_documents')
            || ! DB::getSchemaBuilder()->hasTable('notifications')
            || ! DB::getSchemaBuilder()->hasTable('legal_documents')
            || ! DB::getSchemaBuilder()->hasTable('auth_social_accounts')
            || ! DB::getSchemaBuilder()->hasTable('account_closure_requests')
            || ! DB::getSchemaBuilder()->hasTable('terms_acceptances')
            || ! DB::getSchemaBuilder()->hasTable('event_projection_migration_cases')
            || ! DB::getSchemaBuilder()->hasTable('data_retention_execution_runs')) {
            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'expand',
                '--force' => true,
            ]);
            if ($exit !== 0) {
                throw new LogicException('Controlled migration failed: '.Artisan::output());
            }
        }

        $candidateKeysReady = DB::selectOne(
            "SELECT 1 AS ready
             FROM pg_constraint con
             JOIN pg_class cls ON cls.oid = con.conrelid
             JOIN pg_namespace ns ON ns.oid = cls.relnamespace
             WHERE ns.nspname = current_schema()
               AND cls.relname = 'organization_memberships'
               AND con.conname = 'ck_org_memberships_id_user'
               AND con.contype = 'u'",
        ) !== null;

        if (! $candidateKeysReady) {
            foreach (['preflight', 'write_fence'] as $phase) {
                $exit = Artisan::call('migration:controlled', [
                    '--plan' => $plan->identity(),
                    '--execution' => $plan->executionIdentity(),
                    '--phase' => $phase,
                    '--force' => true,
                ]);
                if ($exit !== 0) {
                    throw new LogicException("Controlled candidate-key migration failed in {$phase}: ".Artisan::output());
                }
            }
        }

        $formalDocumentsPlan = app(Stage5FormalDocumentsMigrationPlan::class);
        $formalDocumentsPlan->validate();

        $formalDocumentsExpandIncomplete = ! DB::getSchemaBuilder()->hasTable('formal_training_document_templates')
            || ! DB::getSchemaBuilder()->hasTable('formal_training_documents')
            || ! DB::getSchemaBuilder()->hasTable('formal_training_document_events')
            || ! DB::getSchemaBuilder()->hasColumns('course_enrollments', [
                'document_mode',
                'document_mode_selected_at',
                'document_mode_selected_by_user_id',
            ]);

        if ($formalDocumentsExpandIncomplete) {
            $exit = Artisan::call('migration:stage5:formal-docs:controlled', [
                '--plan' => $formalDocumentsPlan->identity(),
                '--execution' => $formalDocumentsPlan->executionIdentity(),
                '--phase' => 'expand',
                '--force' => true,
            ]);
            if ($exit !== 0) {
                throw new LogicException('Stage-5 formal-documents controlled migration failed: '.Artisan::output());
            }
        }
    }

    public static function reset(): void
    {
        self::ensureMigrated();
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }

        app(FoundationReferenceCatalogSeeder::class)->run();
        app(ResourceReferenceCatalogSeeder::class)->run();
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    public static function actor(bool $owner = true): array
    {
        $org = (string) Str::uuid7();
        $user = (string) Str::uuid7();
        $membership = (string) Str::uuid7();
        $session = (string) Str::uuid7();
        $now = now();

        DB::table('organizations')->insert([
            'id' => $org,
            'name' => 'Synthetic OSK',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('organization_settings')->insert([
            'organization_id' => $org,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('users')->insert([
            'id' => $user,
            'first_name' => 'Test',
            'last_name' => 'Owner',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('organization_memberships')->insert([
            'id' => $membership,
            'organization_id' => $org,
            'user_id' => $user,
            'status' => 'active',
            'is_owner' => $owner,
            'version' => 1,
            'authorization_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('auth_sessions')->insert([
            'id' => $session,
            'user_id' => $user,
            'organization_membership_id' => $membership,
            'token_or_framework_session_hash' => hash('sha256', $session),
            'created_at' => $now,
        ]);

        foreach ([
            'staff.permissions.manage',
            'organization.members.manage',
            'organization.settings.manage',
            'locations.view', 'locations.create', 'locations.edit', 'locations.archive', 'locations.restore',
            'staff.view', 'staff.create', 'staff.edit', 'staff.archive', 'staff.restore', 'staff.accounts.manage',
            'vehicles.view', 'vehicles.create', 'vehicles.edit', 'vehicles.archive', 'vehicles.restore',
        ] as $permission) {
            self::grant($membership, $permission, ['organization']);
        }

        if ($owner) {
            foreach (['organization.view', 'sessions.manage.organization'] as $permission) {
                self::grant($membership, $permission, ['organization']);
            }
        }

        return [
            'organization_id' => $org,
            'user_id' => $user,
            'membership_id' => $membership,
            'session_id' => $session,
        ];
    }

    /** @param list<string> $scopes */
    public static function grant(string $membershipId, string $permission, array $scopes): void
    {
        if (! DB::table('permissions')->where('code', $permission)->exists()) {
            throw new LogicException("Synthetic fixture attempted unknown permission {$permission}.");
        }

        foreach ($scopes as $scope) {
            if (! DB::table('permission_scope_options')
                ->where('permission_code', $permission)
                ->where('scope_code', $scope)
                ->exists()) {
                throw new LogicException("Synthetic fixture attempted unsupported scope {$scope} for {$permission}.");
            }
        }

        DB::table('membership_permissions')->updateOrInsert(
            ['membership_id' => $membershipId, 'permission_code' => $permission],
            ['granted' => true, 'created_at' => now()],
        );
        DB::table('membership_permission_scopes')
            ->where('membership_id', $membershipId)
            ->where('permission_code', $permission)
            ->delete();
        foreach ($scopes as $scope) {
            DB::table('membership_permission_scopes')->insert([
                'membership_id' => $membershipId,
                'permission_code' => $permission,
                'scope_code' => $scope,
                'created_at' => now(),
            ]);
        }
    }

    public static function member(string $organizationId, bool $owner = false): string
    {
        $user = (string) Str::uuid7();
        $membership = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $user,
            'first_name' => 'Synthetic',
            'last_name' => 'Member',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('organization_memberships')->insert([
            'id' => $membership,
            'organization_id' => $organizationId,
            'user_id' => $user,
            'status' => 'active',
            'is_owner' => $owner,
            'version' => 1,
            'authorization_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membership;
    }
}
