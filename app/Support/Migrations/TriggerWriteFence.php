<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Installs deterministic PostgreSQL trigger guards for the Stage-4 write-fence.
 *
 * A guard owns one trigger function and one or more table triggers. Resume is
 * fail-closed: existing objects are accepted only when their signed contract,
 * function body and PostgreSQL trigger metadata exactly match the requested
 * definition.
 */
final class TriggerWriteFence
{
    /**
     * @param  list<array{
     *   name: string,
     *   body: string,
     *   triggers: list<array{
     *     table: string,
     *     timing: 'BEFORE'|'AFTER',
     *     events: list<'INSERT'|'UPDATE'|'DELETE'>,
     *     constraint?: bool,
     *     deferrable?: bool,
     *     initially_deferred?: bool,
     *     when?: string|null
     *   }>
     * }>  $guards
     */
    public static function install(string $nodeId, array $guards): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException($nodeId.' trigger write-fence requires PostgreSQL.');
        }

        foreach ($guards as $guard) {
            self::installGuard($nodeId, $guard);
        }
    }

    /**
     * @param  array{
     *   name: string,
     *   body: string,
     *   triggers: list<array{
     *     table: string,
     *     timing: 'BEFORE'|'AFTER',
     *     events: list<'INSERT'|'UPDATE'|'DELETE'>,
     *     constraint?: bool,
     *     deferrable?: bool,
     *     initially_deferred?: bool,
     *     when?: string|null
     *   }>
     * }  $guard
     */
    private static function installGuard(string $nodeId, array $guard): void
    {
        if ($guard['triggers'] === []) {
            throw new LogicException($nodeId.' trigger guard '.$guard['name'].' has no trigger targets.');
        }

        $signature = self::signature($guard);
        $functionName = self::functionName($guard['name']);
        self::installFunction($nodeId, $guard['name'], $functionName, $guard['body'], $signature);

        foreach ($guard['triggers'] as $trigger) {
            self::installTrigger($nodeId, $guard['name'], $functionName, $signature, $trigger);
        }
    }

    private static function installFunction(
        string $nodeId,
        string $guardName,
        string $functionName,
        string $body,
        string $signature,
    ): void {
        $existing = self::functionMetadata($functionName);

        if ($existing !== null) {
            if ($existing['body'] !== trim($body) || $existing['signature'] !== $signature) {
                throw new LogicException($nodeId.' found conflicting existing trigger function for '.$guardName.'.');
            }

            return;
        }

        $function = self::quoteIdentifier($functionName);
        DB::unprepared(
            "CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$guard\$\n".
            trim($body).
            "\n\$guard\$",
        );
        DB::statement(
            "COMMENT ON FUNCTION {$function}() IS ".self::quoteLiteral($signature),
        );

        $created = self::functionMetadata($functionName);
        if ($created === null || $created['body'] !== trim($body) || $created['signature'] !== $signature) {
            throw new LogicException($nodeId.' failed to create exact trigger function for '.$guardName.'.');
        }
    }

    /**
     * @param  array{
     *   table: string,
     *   timing: 'BEFORE'|'AFTER',
     *   events: list<'INSERT'|'UPDATE'|'DELETE'>,
     *   constraint?: bool,
     *   deferrable?: bool,
     *   initially_deferred?: bool,
     *   when?: string|null
     * }  $trigger
     */
    private static function installTrigger(
        string $nodeId,
        string $guardName,
        string $functionName,
        string $signature,
        array $trigger,
    ): void {
        if (! Schema::hasTable($trigger['table'])) {
            throw new LogicException($nodeId.' trigger guard '.$guardName.' missing table '.$trigger['table'].'.');
        }

        $events = array_values(array_unique($trigger['events']));
        sort($events);
        if ($events === []) {
            throw new LogicException($nodeId.' trigger guard '.$guardName.' has no events.');
        }

        foreach ($events as $event) {
            if (! in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                throw new LogicException($nodeId.' trigger guard '.$guardName.' has unsupported event '.$event.'.');
            }
        }

        $constraint = (bool) ($trigger['constraint'] ?? false);
        $deferrable = (bool) ($trigger['deferrable'] ?? false);
        $initiallyDeferred = (bool) ($trigger['initially_deferred'] ?? false);

        if ($constraint && $trigger['timing'] !== 'AFTER') {
            throw new LogicException($nodeId.' constraint trigger '.$guardName.' must be AFTER.');
        }
        if (($deferrable || $initiallyDeferred) && ! $constraint) {
            throw new LogicException($nodeId.' non-constraint trigger '.$guardName.' cannot be deferrable.');
        }
        if ($initiallyDeferred && ! $deferrable) {
            throw new LogicException($nodeId.' initially deferred trigger '.$guardName.' must be deferrable.');
        }

        $triggerName = self::triggerName($guardName, $trigger, $events);
        $expectedType = self::triggerType($trigger['timing'], $events);
        $existing = self::triggerMetadata($trigger['table'], $triggerName);

        if ($existing !== null) {
            if ($existing['function'] !== $functionName
                || $existing['type'] !== $expectedType
                || $existing['constraint'] !== $constraint
                || $existing['deferrable'] !== $deferrable
                || $existing['initially_deferred'] !== $initiallyDeferred
                || $existing['signature'] !== $signature) {
                throw new LogicException($nodeId.' found conflicting existing trigger '.$triggerName.'.');
            }

            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable($trigger['table']);
        $name = self::quoteIdentifier($triggerName);
        $function = self::quoteIdentifier($functionName);
        $eventSql = implode(' OR ', $events);
        $when = trim((string) ($trigger['when'] ?? ''));
        $whenSql = $when === '' ? '' : ' WHEN ('.$when.')';

        if ($constraint) {
            $deferSql = $deferrable ? ' DEFERRABLE' : ' NOT DEFERRABLE';
            if ($deferrable) {
                $deferSql .= $initiallyDeferred ? ' INITIALLY DEFERRED' : ' INITIALLY IMMEDIATE';
            }

            DB::unprepared(
                "CREATE CONSTRAINT TRIGGER {$name} AFTER {$eventSql} ON {$table}".
                $deferSql.
                " FOR EACH ROW{$whenSql} EXECUTE FUNCTION {$function}()",
            );
        } else {
            DB::unprepared(
                "CREATE TRIGGER {$name} {$trigger['timing']} {$eventSql} ON {$table}".
                " FOR EACH ROW{$whenSql} EXECUTE FUNCTION {$function}()",
            );
        }

        DB::statement(
            "COMMENT ON TRIGGER {$name} ON {$table} IS ".self::quoteLiteral($signature),
        );

        $created = self::triggerMetadata($trigger['table'], $triggerName);
        if ($created === null
            || $created['function'] !== $functionName
            || $created['type'] !== $expectedType
            || $created['constraint'] !== $constraint
            || $created['deferrable'] !== $deferrable
            || $created['initially_deferred'] !== $initiallyDeferred
            || $created['signature'] !== $signature) {
            throw new LogicException($nodeId.' failed to create exact trigger '.$triggerName.'.');
        }
    }

    /**
     * @return array{body: string, signature: string}|null
     */
    private static function functionMetadata(string $functionName): ?array
    {
        $row = DB::selectOne(
            "SELECT pro.prosrc AS function_body,
                    COALESCE(obj_description(pro.oid, 'pg_proc'), '') AS signature
             FROM pg_proc pro
             JOIN pg_namespace ns ON ns.oid = pro.pronamespace
             WHERE ns.nspname = current_schema()
               AND pro.proname = ?
               AND pro.pronargs = 0
               AND pro.prorettype = 'trigger'::regtype
             LIMIT 1",
            [$functionName],
        );

        if ($row === null) {
            return null;
        }

        return [
            'body' => trim((string) ($row->function_body ?? '')),
            'signature' => (string) ($row->signature ?? ''),
        ];
    }

    /**
     * @return array{
     *   function: string,
     *   type: int,
     *   constraint: bool,
     *   deferrable: bool,
     *   initially_deferred: bool,
     *   signature: string
     * }|null
     */
    private static function triggerMetadata(string $table, string $triggerName): ?array
    {
        $row = DB::selectOne(
            "SELECT pro.proname AS function_name,
                    trg.tgtype AS trigger_type,
                    CASE WHEN trg.tgconstraint <> 0 THEN 1 ELSE 0 END AS is_constraint,
                    CASE WHEN trg.tgdeferrable THEN 1 ELSE 0 END AS is_deferrable,
                    CASE WHEN trg.tginitdeferred THEN 1 ELSE 0 END AS is_initially_deferred,
                    COALESCE(obj_description(trg.oid, 'pg_trigger'), '') AS signature
             FROM pg_trigger trg
             JOIN pg_class cls ON cls.oid = trg.tgrelid
             JOIN pg_namespace ns ON ns.oid = cls.relnamespace
             JOIN pg_proc pro ON pro.oid = trg.tgfoid
             WHERE ns.nspname = current_schema()
               AND cls.relname = ?
               AND trg.tgname = ?
               AND trg.tgisinternal = false
             LIMIT 1",
            [$table, $triggerName],
        );

        if ($row === null) {
            return null;
        }

        return [
            'function' => (string) ($row->function_name ?? ''),
            'type' => (int) ($row->trigger_type ?? 0),
            'constraint' => (int) ($row->is_constraint ?? 0) === 1,
            'deferrable' => (int) ($row->is_deferrable ?? 0) === 1,
            'initially_deferred' => (int) ($row->is_initially_deferred ?? 0) === 1,
            'signature' => (string) ($row->signature ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $guard
     */
    private static function signature(array $guard): string
    {
        return 'prawkonaraz:trigger-write-fence:v1:'.hash(
            'sha256',
            json_encode($guard, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function functionName(string $guardName): string
    {
        return self::objectName('fn_guard', $guardName);
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<string>  $events
     */
    private static function triggerName(string $guardName, array $trigger, array $events): string
    {
        return self::objectName(
            'trg_guard',
            implode('|', [
                $guardName,
                (string) $trigger['table'],
                (string) $trigger['timing'],
                implode(',', $events),
                (string) ((bool) ($trigger['constraint'] ?? false)),
                (string) ((bool) ($trigger['deferrable'] ?? false)),
                (string) ((bool) ($trigger['initially_deferred'] ?? false)),
                trim((string) ($trigger['when'] ?? '')),
            ]),
        );
    }

    private static function objectName(string $prefix, string $material): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $material));
        $slug = trim($slug, '_');
        $slug = substr($slug, 0, 38);

        return $prefix.'_'.$slug.'_'.substr(hash('sha256', $material), 0, 10);
    }

    /**
     * PostgreSQL tgtype bits: ROW=1, BEFORE=2, INSERT=4, DELETE=8, UPDATE=16.
     *
     * @param  list<string>  $events
     */
    private static function triggerType(string $timing, array $events): int
    {
        $type = 1;
        if ($timing === 'BEFORE') {
            $type |= 2;
        }
        foreach ($events as $event) {
            $type |= match ($event) {
                'INSERT' => 4,
                'DELETE' => 8,
                'UPDATE' => 16,
            };
        }

        return $type;
    }

    private static function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
