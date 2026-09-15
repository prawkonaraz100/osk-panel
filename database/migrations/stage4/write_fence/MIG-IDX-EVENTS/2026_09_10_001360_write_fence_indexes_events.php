<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-EVENTS');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-EVENTS', [
            [
                'name' => 'outbox_domain_event_unique',
                'table' => 'outbox_messages',
                'columns' => ['domain_event_id'],
                'predicate' => null,
            ],
            [
                'name' => 'activity_projection_source_event_unique',
                'table' => 'organization_activity_events',
                'columns' => ['organization_id', 'source_event_id'],
                'predicate' => null,
            ],
            [
                'name' => 'notification_source_recipient_unique',
                'table' => 'notifications',
                'columns' => ['organization_id', 'source_event_id', 'organization_membership_id'],
                'predicate' => null,
            ],
            [
                'name' => 'event_projection_migration_case_unique',
                'table' => 'event_projection_migration_cases',
                'columns' => ['source_table', 'source_row_id', 'issue_code'],
                'predicate' => null,
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
