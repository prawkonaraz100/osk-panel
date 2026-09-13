<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use LogicException;

final class ForeignKeyWriteFence
{
    /**
     * Exact PostgreSQL referenced-key prerequisites required by the frozen Stage-4
     * relation contract but not materialized by the earlier CK/IDX prefixes.
     *
     * @var array<string, array{name: string, table: string, columns: list<string>}>
     */
    private const SUPPORTING_TARGET_KEYS = [
        'training_sessions|organization_id,id,course_enrollment_id' => [
            'name' => 'training_session_candidate_key_org_id_course',
            'table' => 'training_sessions',
            'columns' => ['organization_id', 'id', 'course_enrollment_id'],
        ],
        'training_hour_ledger_entries|organization_id,id,course_enrollment_id,training_part' => [
            'name' => 'training_hour_ledger_candidate_key_org_id_course_part',
            'table' => 'training_hour_ledger_entries',
            'columns' => ['organization_id', 'id', 'course_enrollment_id', 'training_part'],
        ],
        'pkk_profiles|organization_id,id,course_enrollment_id' => [
            'name' => 'pkk_profile_candidate_key_org_id_course',
            'table' => 'pkk_profiles',
            'columns' => ['organization_id', 'id', 'course_enrollment_id'],
        ],
        'idempotency_records|organization_id,id' => [
            'name' => 'idempotency_record_candidate_key_org_id',
            'table' => 'idempotency_records',
            'columns' => ['organization_id', 'id'],
        ],
        'pkk_operations|organization_id,id,course_enrollment_id,pkk_profile_id' => [
            'name' => 'pkk_operation_candidate_key_org_id_context',
            'table' => 'pkk_operations',
            'columns' => ['organization_id', 'id', 'course_enrollment_id', 'pkk_profile_id'],
        ],
        'pkk_integration_configuration_revisions|organization_id,execution_configuration_revision' => [
            'name' => 'pkk_configuration_revision_candidate_key_org_revision',
            'table' => 'pkk_integration_configuration_revisions',
            'columns' => ['organization_id', 'execution_configuration_revision'],
        ],
        'pkk_signature_handoffs|organization_id,id,pkk_operation_id,course_enrollment_id,pkk_profile_id' => [
            'name' => 'pkk_signature_handoff_candidate_key_org_id_context',
            'table' => 'pkk_signature_handoffs',
            'columns' => ['organization_id', 'id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
        ],
        'pkk_operation_attempts|organization_id,id,pkk_operation_id,course_enrollment_id,pkk_profile_id' => [
            'name' => 'pkk_operation_attempt_candidate_key_org_id_context',
            'table' => 'pkk_operation_attempts',
            'columns' => ['organization_id', 'id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id'],
        ],
        'pkk_signature_handoffs|organization_id,id' => [
            'name' => 'pkk_signature_handoff_candidate_key_org_id',
            'table' => 'pkk_signature_handoffs',
            'columns' => ['organization_id', 'id'],
        ],
        'license_product_language_capabilities|id,language_code' => [
            'name' => 'license_product_language_capability_candidate_key_id_language',
            'table' => 'license_product_language_capabilities',
            'columns' => ['id', 'language_code'],
        ],
        'internal_exam_accesses|organization_id,id,internal_exam_attempt_id' => [
            'name' => 'internal_exam_access_candidate_key_org_id_attempt',
            'table' => 'internal_exam_accesses',
            'columns' => ['organization_id', 'id', 'internal_exam_attempt_id'],
        ],
        'internal_exam_station_sessions|organization_id,id,internal_exam_attempt_id' => [
            'name' => 'internal_exam_station_session_candidate_key_org_id_attempt',
            'table' => 'internal_exam_station_sessions',
            'columns' => ['organization_id', 'id', 'internal_exam_attempt_id'],
        ],
        'payments|organization_id,id,provider,provider_payment_id' => [
            'name' => 'payment_candidate_key_org_id_provider_reference',
            'table' => 'payments',
            'columns' => ['organization_id', 'id', 'provider', 'provider_payment_id'],
        ],
        'payments|organization_id,id,order_id' => [
            'name' => 'payment_candidate_key_org_id_order',
            'table' => 'payments',
            'columns' => ['organization_id', 'id', 'order_id'],
        ],
        'payment_events|organization_id,id,payment_id' => [
            'name' => 'payment_event_candidate_key_org_id_payment',
            'table' => 'payment_events',
            'columns' => ['organization_id', 'id', 'payment_id'],
        ],
        'order_payment_settlements|organization_id,order_id,payment_id' => [
            'name' => 'order_payment_settlement_candidate_key_org_order_payment',
            'table' => 'order_payment_settlements',
            'columns' => ['organization_id', 'order_id', 'payment_id'],
        ],
        'service_entitlements|organization_id,id' => [
            'name' => 'service_entitlement_candidate_key_org_id',
            'table' => 'service_entitlements',
            'columns' => ['organization_id', 'id'],
        ],
        'activity_projection_policy_revisions|event_type,policy_version' => [
            'name' => 'activity_projection_policy_revision_candidate_key_event_version',
            'table' => 'activity_projection_policy_revisions',
            'columns' => ['event_type', 'policy_version'],
        ],
    ];

    /**
     * @param list<array{
     *   name: string,
     *   source_table: string,
     *   source_columns: list<string>,
     *   target_table: string,
     *   target_columns: list<string>
     * }> $relations
     */
    public static function install(string $nodeId, array $relations): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException($nodeId.' write-fence requires PostgreSQL.');
        }

        ForeignKeyPreflight::assertRelations($nodeId, $relations);

        $supportingKeys = [];
        foreach ($relations as $relation) {
            $signature = self::targetKeySignature($relation['target_table'], $relation['target_columns']);
            if (isset(self::SUPPORTING_TARGET_KEYS[$signature])) {
                $definition = self::SUPPORTING_TARGET_KEYS[$signature];
                $supportingKeys[$definition['name']] = $definition;
            }
        }

        if ($supportingKeys !== []) {
            CandidateKeyWriteFence::install($nodeId, array_values($supportingKeys));
        }

        foreach ($relations as $relation) {
            self::assertReferencedKeyAvailable($nodeId, $relation);
            self::installForeignKey($nodeId, $relation);
        }
    }

    /**
     * @param array{
     *   name: string,
     *   source_table: string,
     *   source_columns: list<string>,
     *   target_table: string,
     *   target_columns: list<string>
     * } $relation
     */
    private static function installForeignKey(string $nodeId, array $relation): void
    {
        $constraintName = 'fk_'.$relation['name'];
        if (strlen($constraintName) > 63) {
            throw new LogicException($nodeId.' write-fence FK name exceeds PostgreSQL identifier limit: '.$constraintName.'.');
        }

        $existing = self::constraintMetadata($relation['source_table'], $constraintName);
        if ($existing !== null) {
            self::assertExactDefinition($nodeId, $relation, $existing);

            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $sourceTable = $grammar->wrapTable($relation['source_table']);
        $targetTable = $grammar->wrapTable($relation['target_table']);
        $constraint = $grammar->wrap($constraintName);
        $sourceColumns = implode(', ', array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $relation['source_columns'],
        ));
        $targetColumns = implode(', ', array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $relation['target_columns'],
        ));

        DB::statement(
            "ALTER TABLE {$sourceTable} ADD CONSTRAINT {$constraint} "
            ."FOREIGN KEY ({$sourceColumns}) REFERENCES {$targetTable} ({$targetColumns}) "
            .'MATCH SIMPLE ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID',
        );

        DB::statement(
            "COMMENT ON CONSTRAINT {$constraint} ON {$sourceTable} IS ".self::quoteLiteral(self::signature($relation)),
        );

        $created = self::constraintMetadata($relation['source_table'], $constraintName);
        if ($created === null) {
            throw new LogicException($nodeId.' write-fence failed to create '.$constraintName.'.');
        }

        self::assertExactDefinition($nodeId, $relation, $created);

        if ($created['validated']) {
            throw new LogicException($nodeId.' write-fence unexpectedly created validated FK '.$constraintName.'.');
        }
    }

    /**
     * @param array{
     *   name: string,
     *   source_table: string,
     *   source_columns: list<string>,
     *   target_table: string,
     *   target_columns: list<string>
     * } $relation
     */
    private static function assertReferencedKeyAvailable(string $nodeId, array $relation): void
    {
        $rows = DB::select(
            "SELECT COALESCE((
                        SELECT json_agg(att.attname ORDER BY key_column.ordinal_position)::text
                        FROM unnest(idx.indkey) WITH ORDINALITY AS key_column(attnum, ordinal_position)
                        JOIN pg_attribute att
                          ON att.attrelid = idx.indrelid
                         AND att.attnum = key_column.attnum
                        WHERE key_column.ordinal_position <= idx.indnkeyatts
                    ), '[]') AS columns_json
             FROM pg_index idx
             JOIN pg_class tbl ON tbl.oid = idx.indrelid
             JOIN pg_namespace ns ON ns.oid = tbl.relnamespace
             WHERE ns.nspname = current_schema()
               AND tbl.relname = ?
               AND idx.indisunique
               AND idx.indisvalid
               AND idx.indisready
               AND idx.indpred IS NULL
               AND idx.indexprs IS NULL",
            [$relation['target_table']],
        );

        foreach ($rows as $row) {
            $columns = self::decodeColumns((string) ($row->columns_json ?? '[]'), $relation['name']);
            if ($columns === $relation['target_columns']) {
                return;
            }
        }

        throw new LogicException(
            $nodeId.' write-fence missing non-partial UNIQUE target key for '.$relation['name']
            .' on '.$relation['target_table'].'('.implode(', ', $relation['target_columns']).').',
        );
    }

    /**
     * @return array{
     *   source_table: string,
     *   target_table: string,
     *   type: string,
     *   source_columns: list<string>,
     *   target_columns: list<string>,
     *   update_action: string,
     *   delete_action: string,
     *   match_type: string,
     *   validated: bool,
     *   signature: string
     * }|null
     */
    private static function constraintMetadata(string $sourceTable, string $constraintName): ?array
    {
        $row = DB::selectOne(
            "SELECT src.relname AS source_table,
                    tgt.relname AS target_table,
                    con.contype AS constraint_type,
                    con.confupdtype AS update_action,
                    con.confdeltype AS delete_action,
                    con.confmatchtype AS match_type,
                    CASE WHEN con.convalidated THEN 1 ELSE 0 END AS is_validated,
                    COALESCE(obj_description(con.oid, 'pg_constraint'), '') AS signature,
                    COALESCE((
                        SELECT json_agg(att.attname ORDER BY key_column.ordinal_position)::text
                        FROM unnest(con.conkey) WITH ORDINALITY AS key_column(attnum, ordinal_position)
                        JOIN pg_attribute att
                          ON att.attrelid = con.conrelid
                         AND att.attnum = key_column.attnum
                    ), '[]') AS source_columns_json,
                    COALESCE((
                        SELECT json_agg(att.attname ORDER BY key_column.ordinal_position)::text
                        FROM unnest(con.confkey) WITH ORDINALITY AS key_column(attnum, ordinal_position)
                        JOIN pg_attribute att
                          ON att.attrelid = con.confrelid
                         AND att.attnum = key_column.attnum
                    ), '[]') AS target_columns_json
             FROM pg_constraint con
             JOIN pg_class src ON src.oid = con.conrelid
             JOIN pg_namespace src_ns ON src_ns.oid = src.relnamespace
             JOIN pg_class tgt ON tgt.oid = con.confrelid
             WHERE src_ns.nspname = current_schema()
               AND src.relname = ?
               AND con.conname = ?
             LIMIT 1",
            [$sourceTable, $constraintName],
        );

        if ($row === null) {
            return null;
        }

        return [
            'source_table' => (string) ($row->source_table ?? ''),
            'target_table' => (string) ($row->target_table ?? ''),
            'type' => (string) ($row->constraint_type ?? ''),
            'source_columns' => self::decodeColumns((string) ($row->source_columns_json ?? '[]'), $constraintName),
            'target_columns' => self::decodeColumns((string) ($row->target_columns_json ?? '[]'), $constraintName),
            'update_action' => (string) ($row->update_action ?? ''),
            'delete_action' => (string) ($row->delete_action ?? ''),
            'match_type' => (string) ($row->match_type ?? ''),
            'validated' => (int) ($row->is_validated ?? 0) === 1,
            'signature' => (string) ($row->signature ?? ''),
        ];
    }

    /**
     * @param array{
     *   name: string,
     *   source_table: string,
     *   source_columns: list<string>,
     *   target_table: string,
     *   target_columns: list<string>
     * } $expected
     * @param array{
     *   source_table: string,
     *   target_table: string,
     *   type: string,
     *   source_columns: list<string>,
     *   target_columns: list<string>,
     *   update_action: string,
     *   delete_action: string,
     *   match_type: string,
     *   validated: bool,
     *   signature: string
     * } $actual
     */
    private static function assertExactDefinition(string $nodeId, array $expected, array $actual): void
    {
        if ($actual['source_table'] !== $expected['source_table']
            || $actual['target_table'] !== $expected['target_table']
            || $actual['type'] !== 'f'
            || $actual['source_columns'] !== $expected['source_columns']
            || $actual['target_columns'] !== $expected['target_columns']
            || $actual['update_action'] !== 'r'
            || $actual['delete_action'] !== 'r'
            || $actual['match_type'] !== 's'
            || $actual['signature'] !== self::signature($expected)) {
            throw new LogicException(
                $nodeId.' write-fence found conflicting existing FK definition for fk_'.$expected['name'].'.',
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function decodeColumns(string $json, string $name): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new LogicException('Invalid PostgreSQL FK metadata for '.$name.'.');
        }

        $columns = [];
        foreach ($decoded as $column) {
            if (! is_string($column)) {
                throw new LogicException('Invalid PostgreSQL FK column metadata for '.$name.'.');
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * @param  list<string>  $columns
     */
    private static function targetKeySignature(string $table, array $columns): string
    {
        return $table.'|'.implode(',', $columns);
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    private static function signature(array $relation): string
    {
        return 'prawkonaraz:foreign-key-write-fence:v1:'.hash(
            'sha256',
            json_encode($relation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
