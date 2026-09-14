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
            'S5COM-TBL-ORGANIZATION-COMMERCE-ORDER-SEQUENCES',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Commerce order sequence validation requires PostgreSQL.');
        }

        if (! Schema::hasTable('organization_commerce_order_sequences')) {
            throw new LogicException('Allocator table is missing during commerce order sequence validation.');
        }

        $columns = DB::select(<<<'SQL'
SELECT
    a.attname AS column_name,
    format_type(a.atttypid, a.atttypmod) AS sql_type,
    a.attnotnull AS not_null
FROM pg_attribute a
JOIN pg_class t ON t.oid = a.attrelid
JOIN pg_namespace ns ON ns.oid = t.relnamespace
WHERE ns.nspname = current_schema()
  AND t.relname = 'organization_commerce_order_sequences'
  AND a.attnum > 0
  AND NOT a.attisdropped
ORDER BY a.attnum
SQL);

        if (count($columns) !== 2
            || ($columns[0]->column_name ?? null) !== 'organization_id'
            || ($columns[0]->sql_type ?? null) !== 'uuid'
            || ! filter_var($columns[0]->not_null ?? false, FILTER_VALIDATE_BOOL)
            || ($columns[1]->column_name ?? null) !== 'next_order_sequence'
            || ($columns[1]->sql_type ?? null) !== 'bigint'
            || ! filter_var($columns[1]->not_null ?? false, FILTER_VALIDATE_BOOL)) {
            throw new LogicException('Commerce order sequence column validation failed.');
        }

        $constraints = DB::select(<<<'SQL'
SELECT
    c.conname,
    c.contype,
    c.confupdtype,
    c.confdeltype,
    ref.relname AS referenced_table,
    pg_get_constraintdef(c.oid, true) AS definition
FROM pg_constraint c
JOIN pg_class t ON t.oid = c.conrelid
JOIN pg_namespace ns ON ns.oid = t.relnamespace
LEFT JOIN pg_class ref ON ref.oid = c.confrelid
WHERE ns.nspname = current_schema()
  AND t.relname = 'organization_commerce_order_sequences'
ORDER BY c.conname
SQL);

        if (count($constraints) !== 3) {
            throw new LogicException('Commerce order sequence constraint validation failed.');
        }

        $byName = [];
        foreach ($constraints as $constraint) {
            $byName[(string) $constraint->conname] = $constraint;
        }

        $primary = $byName['organization_commerce_order_sequences_pkey'] ?? null;
        $check = $byName['organization_commerce_order_sequences_next_order_sequence_gte_1'] ?? null;
        $foreign = $byName['organization_commerce_order_sequences_organization_id_fk'] ?? null;
        $checkDefinition = $check === null ? '' : preg_replace('/\s+/', '', (string) $check->definition);

        if ($primary === null
            || $primary->contype !== 'p'
            || $primary->definition !== 'PRIMARY KEY (organization_id)'
            || $check === null
            || $check->contype !== 'c'
            || ! in_array($checkDefinition, [
                'CHECK(next_order_sequence>=1)',
                'CHECK((next_order_sequence>=1))',
                'CHECK((next_order_sequence>=(1)::bigint))',
            ], true)
            || $foreign === null
            || $foreign->contype !== 'f'
            || $foreign->referenced_table !== 'organizations'
            || $foreign->confupdtype !== 'r'
            || $foreign->confdeltype !== 'r'
            || ! str_contains((string) $foreign->definition, 'FOREIGN KEY (organization_id) REFERENCES organizations(id)')) {
            throw new LogicException('Commerce order sequence exact constraint validation failed.');
        }

        $missing = DB::selectOne(<<<'SQL'
SELECT org.id
FROM organizations org
LEFT JOIN organization_commerce_order_sequences seq ON seq.organization_id = org.id
WHERE seq.organization_id IS NULL
LIMIT 1
SQL);

        if ($missing !== null) {
            throw new LogicException('Every existing organization must have a commerce order sequence allocator row.');
        }

        $stale = DB::selectOne(<<<'SQL'
SELECT seq.organization_id
FROM organization_commerce_order_sequences seq
LEFT JOIN orders o ON o.organization_id = seq.organization_id
GROUP BY seq.organization_id, seq.next_order_sequence
HAVING seq.next_order_sequence <= COALESCE(MAX(o.order_sequence), 0)
LIMIT 1
SQL);

        if ($stale !== null) {
            throw new LogicException('Commerce order sequence allocator pointer must be above all accepted existing order sequences.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the commerce order sequence corrective.');
    }
};
