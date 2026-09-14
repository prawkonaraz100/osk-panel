<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

return new class extends Migration
{
    private const INDEX_NAME = 'auth_social_accounts_provider_subject_unique';

    public function up(): void
    {
        ControlledMigrationContext::assertActive(
            'write_fence',
            'S5SOC-IDX-AUTH-SOCIAL-PROVIDER-SUBJECT-UNIQUE',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Social identity uniqueness write fence requires PostgreSQL.');
        }

        if (! Schema::hasTable('auth_social_accounts')) {
            throw new LogicException('auth_social_accounts must exist before social identity uniqueness write fence.');
        }

        $existing = $this->indexMetadata();
        if ($existing !== null) {
            $this->assertExactIndex($existing);

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX_NAME.
            ' ON auth_social_accounts USING btree (provider, provider_subject)',
        );

        $created = $this->indexMetadata();
        if ($created === null) {
            throw new LogicException('Social identity unique index was not created.');
        }

        $this->assertExactIndex($created);
    }

    private function indexMetadata(): ?object
    {
        return DB::selectOne(<<<'SQL'
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
    }

    private function assertExactIndex(object $index): void
    {
        $valid = filter_var($index->indisunique ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($index->indisvalid ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($index->indisready ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($index->predicate_is_null ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($index->expressions_are_null ?? false, FILTER_VALIDATE_BOOL)
            && (int) ($index->indnkeyatts ?? 0) === 2
            && ($index->amname ?? null) === 'btree'
            && ($index->key_columns ?? null) === 'provider,provider_subject';

        if (! $valid) {
            throw new LogicException(
                'Existing auth_social_accounts_provider_subject_unique has the wrong definition; refusing replacement.',
            );
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the social identity uniqueness corrective.');
    }
};
