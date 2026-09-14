<?php

namespace Tests\Feature;

use App\Support\Migrations\Stage5SocialIdentityMigrationPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage5SocialSubjectUniqueCorrectiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_social_identity_corrective_keeps_frozen_migration_identities(): void
    {
        $summary = app(Stage5SocialIdentityMigrationPlan::class)->summary();

        self::assertSame(
            '85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d',
            $summary['plan_identity'],
        );
        self::assertSame(
            'b4a73588390d837fbb460d374114585064445462ddcba667d3919c7052f76cd0',
            $summary['execution_identity'],
        );
        self::assertSame(
            'd2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10',
            $summary['stage4_plan_identity'],
        );
        self::assertSame(
            '82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712',
            $summary['stage4_execution_identity'],
        );
        self::assertSame(1, $summary['nodes']);
        self::assertSame(1, $summary['implemented_nodes']);
        self::assertSame(3, $summary['implemented_steps']);
    }

    public function test_catalog_contains_exact_non_partial_unique_btree(): void
    {
        $index = DB::selectOne(<<<'SQL'
SELECT
    i.indisunique,
    i.indisvalid,
    i.indisready,
    i.indpred IS NULL AS predicate_is_null,
    i.indexprs IS NULL AS expressions_are_null,
    i.indnkeyatts,
    am.amname,
    array_to_string(array_agg(a.attname::text ORDER BY k.ordinality), ',') AS key_columns
FROM pg_index i
JOIN pg_class idx ON idx.oid = i.indexrelid
JOIN pg_class tbl ON tbl.oid = i.indrelid
JOIN pg_namespace ns ON ns.oid = tbl.relnamespace
JOIN pg_am am ON am.oid = idx.relam
CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, ordinality)
JOIN pg_attribute a ON a.attrelid = tbl.oid AND a.attnum = k.attnum
WHERE ns.nspname = current_schema()
  AND tbl.relname = 'auth_social_accounts'
  AND idx.relname = 'auth_social_accounts_provider_subject_unique'
GROUP BY
    i.indisunique,
    i.indisvalid,
    i.indisready,
    i.indpred,
    i.indexprs,
    i.indnkeyatts,
    am.amname
SQL);

        self::assertNotNull($index);
        self::assertTrue(filter_var($index->indisunique, FILTER_VALIDATE_BOOL));
        self::assertTrue(filter_var($index->indisvalid, FILTER_VALIDATE_BOOL));
        self::assertTrue(filter_var($index->indisready, FILTER_VALIDATE_BOOL));
        self::assertTrue(filter_var($index->predicate_is_null, FILTER_VALIDATE_BOOL));
        self::assertTrue(filter_var($index->expressions_are_null, FILTER_VALIDATE_BOOL));
        self::assertSame(2, (int) $index->indnkeyatts);
        self::assertSame('btree', $index->amname);
        self::assertSame('provider,provider_subject', $index->key_columns);
    }

    public function test_duplicate_provider_subject_is_rejected_even_for_revoked_history_and_distinct_pairs_are_allowed(): void
    {
        $actor = FoundationSchema::actor();

        DB::table('auth_social_accounts')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $actor['user_id'],
            'provider' => 'provider-a',
            'provider_subject' => 'subject-1',
            'created_at' => now(),
            'revoked_at' => now(),
        ]);

        $rejected = false;
        try {
            DB::table('auth_social_accounts')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $actor['user_id'],
                'provider' => 'provider-a',
                'provider_subject' => 'subject-1',
                'created_at' => now(),
                'revoked_at' => null,
            ]);
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
            $rejected = $sqlState === '23505';
        }

        self::assertTrue($rejected, 'Duplicate provider-subject pair must be rejected by PostgreSQL.');

        DB::table('auth_social_accounts')->insert([
            [
                'id' => (string) Str::uuid7(),
                'user_id' => $actor['user_id'],
                'provider' => 'provider-a',
                'provider_subject' => 'subject-2',
                'created_at' => now(),
                'revoked_at' => null,
            ],
            [
                'id' => (string) Str::uuid7(),
                'user_id' => $actor['user_id'],
                'provider' => 'provider-b',
                'provider_subject' => 'subject-1',
                'created_at' => now(),
                'revoked_at' => null,
            ],
        ]);

        self::assertSame(3, DB::table('auth_social_accounts')->count());
    }
}
