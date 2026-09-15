<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS validate requires PostgreSQL.');
        }

        foreach ([
            'formal_training_document_events',
            'formal_training_documents',
            'file_assets',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new LogicException("FORMAL-DOC-006 event validation requires {$requiredTable}.");
            }
        }

        $invalidDocumentRelations = (int) DB::table('formal_training_document_events as events')
            ->leftJoin('formal_training_documents as documents', 'documents.id', '=', 'events.formal_training_document_id')
            ->where(function ($query): void {
                $query->whereNull('documents.id')
                    ->orWhereColumn('documents.organization_id', '<>', 'events.organization_id');
            })
            ->count();

        if ($invalidDocumentRelations !== 0) {
            throw new LogicException(
                "FORMAL-DOC-006 validate failed: {$invalidDocumentRelations} formal document events do not reference a document in the same organization."
            );
        }

        $invalidOptionalAssets = (int) DB::table('formal_training_document_events as events')
            ->leftJoin('file_assets as assets', 'assets.id', '=', 'events.optional_asset_id')
            ->whereNotNull('events.optional_asset_id')
            ->where(function ($query): void {
                $query->whereNull('assets.id')
                    ->orWhereNull('assets.organization_id')
                    ->orWhereColumn('assets.organization_id', '<>', 'events.organization_id')
                    ->orWhere('assets.status', '<>', 'ready');
            })
            ->count();

        if ($invalidOptionalAssets !== 0) {
            throw new LogicException(
                "FORMAL-DOC-006 validate failed: {$invalidOptionalAssets} formal document events reference an optional asset that is missing, cross-tenant, or not ready."
            );
        }

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'formal_training_document_events'::regclass
          AND conname = 'formal_training_document_events_document_same_tenant_fk'
    ) THEN
        ALTER TABLE formal_training_document_events
            ADD CONSTRAINT formal_training_document_events_document_same_tenant_fk
            FOREIGN KEY (organization_id, formal_training_document_id)
            REFERENCES formal_training_documents (organization_id, id)
            MATCH SIMPLE
            ON UPDATE RESTRICT
            ON DELETE RESTRICT
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'formal_training_document_events'::regclass
          AND conname = 'formal_training_document_events_optional_asset_same_tenant_fk'
    ) THEN
        ALTER TABLE formal_training_document_events
            ADD CONSTRAINT formal_training_document_events_optional_asset_same_tenant_fk
            FOREIGN KEY (organization_id, optional_asset_id)
            REFERENCES file_assets (organization_id, id)
            MATCH SIMPLE
            ON UPDATE RESTRICT
            ON DELETE RESTRICT
            NOT VALID;
    END IF;
END
$$
SQL);

        DB::statement('ALTER TABLE formal_training_document_events VALIDATE CONSTRAINT formal_training_document_events_document_same_tenant_fk');
        DB::statement('ALTER TABLE formal_training_document_events VALIDATE CONSTRAINT formal_training_document_events_optional_asset_same_tenant_fk');

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION s5doc_guard_formal_training_document_event_asset_ready()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    asset_status varchar(32);
BEGIN
    IF NEW.optional_asset_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT status
    INTO asset_status
    FROM file_assets
    WHERE id = NEW.optional_asset_id
      AND organization_id = NEW.organization_id
    FOR SHARE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'formal document event optional asset must exist in the same organization'
            USING ERRCODE = '23503';
    END IF;

    IF asset_status <> 'ready' THEN
        RAISE EXCEPTION 'formal document event optional asset must be ready'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END
$$
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION s5doc_guard_formal_training_document_event_append_only()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'formal training document events are append-only'
        USING ERRCODE = '23514';
END
$$
SQL);

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'formal_training_document_events'::regclass
          AND tgname = 'formal_training_document_events_asset_ready_guard'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER formal_training_document_events_asset_ready_guard
        BEFORE INSERT
        ON formal_training_document_events
        FOR EACH ROW
        EXECUTE FUNCTION s5doc_guard_formal_training_document_event_asset_ready();
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'formal_training_document_events'::regclass
          AND tgname = 'formal_training_document_events_append_only_guard'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER formal_training_document_events_append_only_guard
        BEFORE UPDATE OR DELETE
        ON formal_training_document_events
        FOR EACH ROW
        EXECUTE FUNCTION s5doc_guard_formal_training_document_event_append_only();
    END IF;
END
$$
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. FORMAL-DOC-006 validation constraints require an explicitly reviewed reversal.');
    }
};
