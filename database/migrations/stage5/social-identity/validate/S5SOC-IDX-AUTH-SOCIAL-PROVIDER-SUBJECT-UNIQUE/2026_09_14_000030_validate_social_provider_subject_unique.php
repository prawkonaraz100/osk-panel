<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive(
            'validate',
            'S5SOC-IDX-AUTH-SOCIAL-PROVIDER-SUBJECT-UNIQUE',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Social identity uniqueness validation requires PostgreSQL.');
        }

        if (! Schema::hasTable('auth_social_accounts')) {
            throw new LogicException('auth_social_accounts must exist before social identity uniqueness validation.');
        }

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

        if ($index === null
            || ! filter_var($index->indisunique ?? false, FILTER_VALIDATE_BOOL)
            || ! filter_var($index->indisvalid ?? false, FILTER_VALIDATE_BOOL)
            || ! filter_var($index->indisready ?? false, FILTER_VALIDATE_BOOL)
            || ! filter_var($index->predicate_is_null ?? false, FILTER_VALIDATE_BOOL)
            || ! filter_var($index->expressions_are_null ?? false, FILTER_VALIDATE_BOOL)
            || (int) ($index->indnkeyatts ?? 0) !== 2
            || ($index->amname ?? null) !== 'btree'
            || ($index->key_columns ?? null) !== 'provider,provider_subject') {
            throw new LogicException('Social identity provider-subject unique index catalog validation failed.');
        }

        $duplicate = DB::selectOne(<<<'SQL'
SELECT provider, provider_subject
FROM auth_social_accounts
GROUP BY provider, provider_subject
HAVING COUNT(*) > 1
LIMIT 1
SQL);

        if ($duplicate !== null) {
            throw new LogicException('Social identity uniqueness validation found a duplicate provider-subject pair.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the social identity uniqueness corrective.');
    }
};
