<?php

use App\Support\Migrations\ConstraintWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-CON-EVENTS');

        ConstraintWriteFence::validate('MIG-CON-EVENTS', [
            [
                'name' => 'audit_scope_actor_entity_closed',
                'table' => 'audit_logs',
                'columns' => ['audit_scope', 'organization_id', 'actor_kind', 'actor_organization_membership_id', 'actor_user_id', 'audit_policy_version', 'entity_reference_mode'],
                'predicate' => 'src.audit_policy_version >= 1 AND src.audit_scope IN (\'organization\',\'platform_global\') AND src.actor_kind IN (\'organization_membership\',\'global_user\',\'system\') AND src.entity_reference_mode IN (\'none\',\'tenant_relational\',\'global_relational\',\'snapshot_only\') AND ((src.audit_scope = \'organization\' AND src.organization_id IS NOT NULL) OR (src.audit_scope = \'platform_global\' AND src.organization_id IS NULL)) AND ((src.actor_kind = \'organization_membership\' AND src.actor_organization_membership_id IS NOT NULL AND src.actor_user_id IS NOT NULL AND src.audit_scope = \'organization\') OR (src.actor_kind = \'global_user\' AND src.actor_organization_membership_id IS NULL AND src.actor_user_id IS NOT NULL AND src.audit_scope = \'platform_global\') OR (src.actor_kind = \'system\' AND src.actor_organization_membership_id IS NULL AND src.actor_user_id IS NULL))',
            ],
            [
                'name' => 'domain_event_scope_closed',
                'table' => 'domain_events',
                'columns' => ['event_scope', 'organization_id', 'aggregate_reference_mode'],
                'predicate' => 'src.event_scope IN (\'organization\',\'platform_global\') AND src.aggregate_reference_mode IN (\'none\',\'tenant_relational\',\'global_relational\',\'snapshot_only\') AND ((src.event_scope = \'organization\' AND src.organization_id IS NOT NULL) OR (src.event_scope = \'platform_global\' AND src.organization_id IS NULL))',
            ],
            [
                'name' => 'outbox_publication_state_matrix',
                'table' => 'outbox_messages',
                'columns' => ['publication_state', 'lease_token', 'lease_version', 'leased_by', 'lease_expires_at', 'attempts', 'attempts_in_cycle', 'replay_count', 'published_at'],
                'predicate' => 'src.publication_state IN (\'pending\',\'leased\',\'published\',\'requires_reconciliation\') AND src.lease_version >= 0 AND src.attempts >= 0 AND src.attempts_in_cycle >= 0 AND src.replay_count >= 0 AND ((src.publication_state = \'leased\' AND src.lease_token IS NOT NULL AND src.leased_by IS NOT NULL AND src.lease_expires_at IS NOT NULL AND src.published_at IS NULL) OR (src.publication_state = \'published\' AND src.lease_token IS NULL AND src.leased_by IS NULL AND src.lease_expires_at IS NULL AND src.published_at IS NOT NULL) OR (src.publication_state IN (\'pending\',\'requires_reconciliation\') AND src.lease_token IS NULL AND src.leased_by IS NULL AND src.lease_expires_at IS NULL AND src.published_at IS NULL))',
            ],
            [
                'name' => 'activity_projection_version_positive',
                'table' => 'organization_activity_events',
                'columns' => ['projection_policy_version'],
                'predicate' => 'src.projection_policy_version >= 1',
            ],
            [
                'name' => 'notification_audience_closed',
                'table' => 'notifications',
                'columns' => ['audience_kind'],
                'predicate' => 'src.audience_kind IN (\'direct_membership\',\'organization_broadcast\')',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
