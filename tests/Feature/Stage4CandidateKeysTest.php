<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4CandidateKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_exact_candidate_key_nodes_are_materialized_without_later_integrity_nodes(): void
    {
        $expected = [
            'ck_org_memberships_id_user' => ['organization_memberships', ['id', 'user_id']],
            'ck_org_memberships_org_id' => ['organization_memberships', ['organization_id', 'id']],
            'ck_org_memberships_org_id_user' => ['organization_memberships', ['organization_id', 'id', 'user_id']],
            'ck_auth_login_identifiers_id_user' => ['auth_login_identifiers', ['id', 'user_id']],

            'ck_file_assets_org_id' => ['file_assets', ['organization_id', 'id']],
            'ck_staff_profiles_org_id' => ['staff_profiles', ['organization_id', 'id']],
            'ck_locations_org_id' => ['locations', ['organization_id', 'id']],
            'ck_vehicles_org_id' => ['vehicles', ['organization_id', 'id']],

            'ck_students_org_id' => ['students', ['organization_id', 'id']],
            'ck_learning_accounts_org_id' => ['student_learning_accounts', ['organization_id', 'id']],
            'ck_learning_accounts_org_id_student' => ['student_learning_accounts', ['organization_id', 'id', 'student_id']],
            'ck_course_enrollments_org_id' => ['course_enrollments', ['organization_id', 'id']],
            'ck_course_enrollments_org_id_student' => ['course_enrollments', ['organization_id', 'id', 'student_id']],
            'ck_training_sessions_org_id' => ['training_sessions', ['organization_id', 'id']],

            'ck_student_charges_org_id_student_currency' => ['student_charges', ['organization_id', 'id', 'student_id', 'currency']],

            'ck_license_inventory_org_id' => ['license_inventory_entries', ['organization_id', 'id']],
            'ck_license_assignments_org_id' => ['license_assignments', ['organization_id', 'id']],

            'ck_internal_exam_inventory_org_id' => ['internal_exam_inventory_entries', ['organization_id', 'id']],
            'ck_internal_exam_attempts_org_id' => ['internal_exam_attempts', ['organization_id', 'id']],
            'ck_internal_exam_accesses_org_id' => ['internal_exam_accesses', ['organization_id', 'id']],
            'ck_exam_stations_org_id' => ['exam_stations', ['organization_id', 'id']],

            'ck_orders_org_id' => ['orders', ['organization_id', 'id']],
            'ck_order_items_org_id' => ['order_items', ['organization_id', 'id']],
            'ck_order_items_org_id_kind' => ['order_items', ['organization_id', 'id', 'product_kind']],
            'ck_order_items_org_id_license_product' => ['order_items', ['organization_id', 'id', 'license_product_id']],
            'ck_order_items_org_id_catalog_item' => ['order_items', ['organization_id', 'id', 'commerce_catalog_item_id']],

            'ck_audit_logs_org_id' => ['audit_logs', ['organization_id', 'id']],
            'ck_domain_events_org_id' => ['domain_events', ['organization_id', 'id']],
        ];

        $this->assertCount(28, $expected);

        foreach ($expected as $constraint => [$table, $columns]) {
            $rows = DB::select(
                <<<'SQL'
SELECT att.attname AS column_name
FROM pg_constraint con
JOIN pg_class cls ON cls.oid = con.conrelid
JOIN pg_namespace ns ON ns.oid = cls.relnamespace
JOIN unnest(con.conkey) WITH ORDINALITY AS key(attnum, ordinality) ON true
JOIN pg_attribute att ON att.attrelid = cls.oid AND att.attnum = key.attnum
WHERE ns.nspname = current_schema()
  AND cls.relname = ?
  AND con.conname = ?
  AND con.contype = 'u'
ORDER BY key.ordinality
SQL,
                [$table, $constraint],
            );

            $this->assertSame(
                $columns,
                array_map(static fn (object $row): string => (string) $row->column_name, $rows),
                "Unexpected candidate key {$constraint}.",
            );
        }

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(126, $plan->implementedNodeCount());
        $this->assertSame(134, $plan->implementedStepCount());
        $this->assertSame(
            'e24cda32101345dca53a1e4b8c4a746c24cf60f1de8285192ae8abd1f15d5844',
            $plan->executionIdentity(),
        );
        $this->assertSame([
            'MIG-CK-IDENTITY',
            'MIG-CK-ASSETS_RESOURCES',
            'MIG-CK-TRAINING',
            'MIG-CK-FINANCE',
            'MIG-CK-LICENSES',
            'MIG-CK-EXAMS',
            'MIG-CK-COMMERCE',
            'MIG-CK-EVENTS',
        ], array_column($plan->phaseSteps('preflight'), 'node_id'));
        $this->assertSame(
            array_column($plan->phaseSteps('preflight'), 'node_id'),
            array_column($plan->phaseSteps('write_fence'), 'node_id'),
        );

        $this->assertSame([], $plan->phaseSteps('backfill'));
        $this->assertSame([], $plan->phaseSteps('reconcile'));
        $this->assertSame([], $plan->phaseSteps('validate'));
        $this->assertSame([], $plan->phaseSteps('contract'));
    }

    public function test_commerce_write_fence_materializes_exact_license_product_snapshot_shape(): void
    {
        $this->assertTrue(Schema::hasColumn('order_items', 'license_product_id'));

        $conflicts = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS conflict_count
FROM order_items oi
LEFT JOIN commerce_catalog_items ci ON ci.id = oi.commerce_catalog_item_id
WHERE (oi.product_kind = 'license'
       AND (
            ci.id IS NULL
            OR ci.product_kind <> 'license'
            OR ci.license_product_id IS NULL
            OR oi.license_product_id IS DISTINCT FROM ci.license_product_id
       ))
   OR (oi.product_kind <> 'license' AND oi.license_product_id IS NOT NULL)
SQL);

        $this->assertSame(0, (int) ($conflicts->conflict_count ?? 0));
    }
}
