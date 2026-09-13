<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4MissingTablesExpandTest extends TestCase
{
    public function test_six_missing_stage_four_tables_are_materialized_without_later_integrity_nodes(): void
    {
        FoundationSchema::ensureMigrated();

        $expectedColumns = [
            'legal_documents' => [
                'id', 'document_type', 'version', 'content_hash', 'storage_asset_id',
                'published_at', 'effective_from', 'created_at',
            ],
            'auth_social_accounts' => [
                'id', 'user_id', 'provider', 'provider_subject', 'created_at', 'revoked_at',
            ],
            'account_closure_requests' => [
                'id', 'user_id', 'organization_id', 'requested_at', 'reason', 'status',
                'resolved_at', 'resolved_by_user_id', 'resolution_note', 'request_id',
            ],
            'terms_acceptances' => [
                'id', 'organization_id', 'user_id', 'legal_document_id', 'accepted_at',
                'ip_hash', 'user_agent', 'request_id',
            ],
            'event_projection_migration_cases' => [
                'id', 'source_table', 'source_row_id', 'source_row_fingerprint', 'issue_code',
                'evidence_class', 'resolution_state', 'evidence_reference_json_safe',
                'resolution_kind', 'resolution_reason', 'reviewed_by_user_id', 'reviewed_at',
                'created_at',
            ],
            'data_retention_execution_runs' => [
                'id', 'policy_version_reference', 'data_class', 'cutoff_at', 'organization_id',
                'initiated_by_user_id', 'reason', 'candidate_count', 'deleted_or_redacted_count',
                'skipped_hold_count', 'started_at', 'completed_at', 'result',
            ],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Missing Stage-4 table {$table}.");
            $this->assertTrue(Schema::hasColumns($table, $columns), "Stage-4 table {$table} is missing canonical expand columns.");
        }

        $tables = array_keys($expectedColumns);
        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        $laterConstraints = DB::select(
            "SELECT cls.relname AS table_name, con.conname, con.contype
             FROM pg_constraint con
             JOIN pg_class cls ON cls.oid = con.conrelid
             JOIN pg_namespace ns ON ns.oid = cls.relnamespace
             WHERE ns.nspname = current_schema()
               AND cls.relname IN ({$placeholders})
               AND con.contype IN ('f', 'u', 'c', 'x')",
            $tables,
        );
        $this->assertSame([], $laterConstraints, 'Expand table gate must not pre-materialize FK/unique/check/exclusion nodes.');

        $userTriggers = DB::select(
            "SELECT cls.relname AS table_name, trg.tgname
             FROM pg_trigger trg
             JOIN pg_class cls ON cls.oid = trg.tgrelid
             JOIN pg_namespace ns ON ns.oid = cls.relnamespace
             WHERE ns.nspname = current_schema()
               AND cls.relname IN ({$placeholders})
               AND NOT trg.tgisinternal",
            $tables,
        );
        $this->assertSame([], $userTriggers, 'Expand table gate must not pre-materialize trigger nodes.');

        $primaryKeys = DB::select(
            "SELECT cls.relname AS table_name
             FROM pg_constraint con
             JOIN pg_class cls ON cls.oid = con.conrelid
             JOIN pg_namespace ns ON ns.oid = cls.relnamespace
             WHERE ns.nspname = current_schema()
               AND cls.relname IN ({$placeholders})
               AND con.contype = 'p'",
            $tables,
        );
        $this->assertCount(6, $primaryKeys);

        $plan = app(MigrationPlan::class);
        $plan->validate();
        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(147, $plan->implementedNodeCount());
        $this->assertSame(147, $plan->implementedStepCount());
        $this->assertSame(
            'f9a0d1adb8b64bf624a31c9c1d3c622c04f60559d07ec0531295ebc6485cfae9',
            $plan->executionIdentity(),
        );
    }
}
