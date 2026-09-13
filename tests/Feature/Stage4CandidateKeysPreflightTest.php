<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4CandidateKeysPreflightTest extends TestCase
{
    public function test_eight_candidate_key_nodes_execute_as_a_read_only_preflight_prefix(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(136, $plan->implementedNodeCount());
        $this->assertSame(136, $plan->implementedStepCount());
        $this->assertSame(
            'bf71200c44672f2942071dd15f5c89d3a9a6991563dc3edcdb7bab6cb965af94',
            $plan->executionIdentity(),
        );

        $candidateNodes = [
            'MIG-CK-IDENTITY',
            'MIG-CK-ASSETS_RESOURCES',
            'MIG-CK-TRAINING',
            'MIG-CK-FINANCE',
            'MIG-CK-LICENSES',
            'MIG-CK-EXAMS',
            'MIG-CK-COMMERCE',
            'MIG-CK-EVENTS',
        ];
        $this->assertSame(
            $candidateNodes,
            array_slice(array_column($plan->phaseSteps('preflight'), 'node_id'), 0, count($candidateNodes)),
        );
        $this->assertSame([], $plan->phaseSteps('write_fence'));

        $candidateConstraintNames = [
            'organization_membership_candidate_key_id_user',
            'organization_membership_candidate_key_org_id',
            'organization_membership_candidate_key_org_id_user',
            'auth_login_identifier_candidate_key_id_user',
            'file_asset_candidate_key_org_id',
            'staff_profile_candidate_key_org_id',
            'location_candidate_key_org_id',
            'vehicle_candidate_key_org_id',
            'student_candidate_key_org_id',
            'student_learning_account_candidate_key_org_id',
            'student_learning_account_candidate_key_org_id_student',
            'course_enrollment_candidate_key_org_id',
            'course_enrollment_candidate_key_org_id_student',
            'training_session_candidate_key_org_id',
            'student_charge_candidate_key_org_id_student_currency',
            'license_inventory_entry_candidate_key_org_id',
            'license_assignment_candidate_key_org_id',
            'internal_exam_inventory_entry_candidate_key_org_id',
            'internal_exam_attempt_candidate_key_org_id',
            'internal_exam_access_candidate_key_org_id',
            'exam_station_candidate_key_org_id',
            'order_candidate_key_org_id',
            'order_item_candidate_key_org_id',
            'order_item_candidate_key_org_id_product_kind',
            'order_item_candidate_key_org_id_license_product',
            'order_item_candidate_key_org_id_catalog_item',
            'audit_log_candidate_key_org_id',
            'domain_event_candidate_key_org_id',
        ];

        $beforeConstraints = $this->constraintNames($candidateConstraintNames);
        $this->assertSame([], $beforeConstraints);
        $this->assertFalse(Schema::hasColumn('order_items', 'license_product_id'));

        $exit = Artisan::call('migration:controlled', [
            '--plan' => $plan->identity(),
            '--execution' => $plan->executionIdentity(),
            '--phase' => 'preflight',
            '--force' => true,
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $applied = DB::table('migrations')
            ->whereIn('migration', array_column($plan->phaseSteps('preflight'), 'migration_name'))
            ->pluck('migration')
            ->all();
        sort($applied);

        $expectedApplied = array_column($plan->phaseSteps('preflight'), 'migration_name');
        sort($expectedApplied);
        $this->assertSame($expectedApplied, array_values($applied));

        $this->assertSame([], $this->constraintNames($candidateConstraintNames));
        $this->assertFalse(
            Schema::hasColumn('order_items', 'license_product_id'),
            'Read-only candidate-key preflight must not materialize the Commerce compatibility column.',
        );

        $this->assertSame([], $plan->phaseSteps('write_fence'));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function constraintNames(array $names): array
    {
        $rows = DB::table('pg_constraint as con')
            ->join('pg_class as cls', 'cls.oid', '=', 'con.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->whereIn('con.conname', $names)
            ->orderBy('con.conname')
            ->pluck('con.conname')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        return array_values($rows);
    }
}
