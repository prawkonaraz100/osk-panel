<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('formal_training_document_events')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE formal_training_document_events (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    formal_training_document_id uuid NOT NULL,
    event_type varchar(64) NOT NULL,
    actor_user_id uuid NULL,
    reason text NULL,
    optional_asset_id uuid NULL,
    occurred_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL,
    CONSTRAINT formal_training_document_events_event_type_check
        CHECK (
            event_type IN (
                'generated',
                'approved',
                'printed',
                'signed_scan_attached',
                'electronic_presented',
                'regeneration_detected'
            )
        )
)
SQL);

        if (! Schema::hasTable('formal_training_document_events')) {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
