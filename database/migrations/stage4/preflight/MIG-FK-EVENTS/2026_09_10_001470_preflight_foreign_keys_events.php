<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyPreflight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-FK-EVENTS');

        ForeignKeyPreflight::assertRelations('MIG-FK-EVENTS', [
            [
                'name' => 'audit_actor_membership_user',
                'source_table' => 'audit_logs',
                'source_columns' => ['organization_id', 'actor_organization_membership_id', 'actor_user_id'],
                'target_table' => 'organization_memberships',
                'target_columns' => ['organization_id', 'id', 'user_id'],
            ],
            [
                'name' => 'audit_policy_revision',
                'source_table' => 'audit_logs',
                'source_columns' => ['action', 'audit_policy_version'],
                'target_table' => 'audit_action_policy_revisions',
                'target_columns' => ['action', 'policy_version'],
            ],
            [
                'name' => 'domain_event_required_audit',
                'source_table' => 'domain_events',
                'source_columns' => ['required_audit_log_id'],
                'target_table' => 'audit_logs',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'domain_event_causation',
                'source_table' => 'domain_events',
                'source_columns' => ['causation_event_id'],
                'target_table' => 'domain_events',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'outbox_domain_event',
                'source_table' => 'outbox_messages',
                'source_columns' => ['domain_event_id'],
                'target_table' => 'domain_events',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'outbox_same_tenant_domain_event',
                'source_table' => 'outbox_messages',
                'source_columns' => ['organization_id', 'domain_event_id'],
                'target_table' => 'domain_events',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'activity_source_event',
                'source_table' => 'organization_activity_events',
                'source_columns' => ['organization_id', 'source_event_id'],
                'target_table' => 'domain_events',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'activity_actor_membership_user',
                'source_table' => 'organization_activity_events',
                'source_columns' => ['organization_id', 'actor_organization_membership_id', 'actor_user_id'],
                'target_table' => 'organization_memberships',
                'target_columns' => ['organization_id', 'id', 'user_id'],
            ],
            [
                'name' => 'activity_related_student',
                'source_table' => 'organization_activity_events',
                'source_columns' => ['organization_id', 'related_student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'activity_policy_revision',
                'source_table' => 'organization_activity_events',
                'source_columns' => ['event_type', 'projection_policy_version'],
                'target_table' => 'activity_projection_policy_revisions',
                'target_columns' => ['event_type', 'policy_version'],
            ],
            [
                'name' => 'notification_source_event',
                'source_table' => 'notifications',
                'source_columns' => ['organization_id', 'source_event_id'],
                'target_table' => 'domain_events',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'notification_recipient_membership',
                'source_table' => 'notifications',
                'source_columns' => ['organization_id', 'organization_membership_id', 'user_id'],
                'target_table' => 'organization_memberships',
                'target_columns' => ['organization_id', 'id', 'user_id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
