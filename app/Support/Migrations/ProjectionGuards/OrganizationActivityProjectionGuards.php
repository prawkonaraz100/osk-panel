<?php

namespace App\Support\Migrations\ProjectionGuards;

use App\Support\Migrations\TriggerWriteFence;

final class OrganizationActivityProjectionGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-PRJ-ORGANIZATION-ACTIVITY', [
            [
                'name' => 'projection_activity_policy_revision_immutable',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'activity projection policy revisions are immutable';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'activity_projection_policy_revisions',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'projection_activity_policy_current_binding',
                'body' => <<<'PLPGSQL'
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM activity_projection_policy_revisions revision
         WHERE revision.event_type = NEW.event_type
           AND revision.policy_version = NEW.policy_version
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'current activity projection policy must bind an exact immutable revision';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'activity_projection_policy_currents',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE'],
                    ],
                ],
            ],
            [
                'name' => 'projection_activity_row_lifecycle',
                'body' => <<<'PLPGSQL'
DECLARE
    v_event domain_events%ROWTYPE;
    v_current_version bigint;
    v_audit audit_logs%ROWTYPE;
    v_key text;
BEGIN
    IF TG_OP = 'UPDATE' OR TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization activity projection is immutable after insert';
    END IF;

    SELECT *
      INTO v_event
      FROM domain_events
     WHERE id = NEW.source_event_id
       AND organization_id = NEW.organization_id
       AND event_scope = 'organization';

    IF NOT FOUND THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'activity projection requires exact same-tenant organization domain event';
    END IF;

    IF NEW.event_type IS DISTINCT FROM v_event.event_type
       OR NEW.occurred_at IS DISTINCT FROM v_event.occurred_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'activity projection copied source fields must equal domain event';
    END IF;

    SELECT current_policy.policy_version
      INTO v_current_version
      FROM activity_projection_policy_currents current_policy
      JOIN activity_projection_policy_revisions revision
        ON revision.event_type = current_policy.event_type
       AND revision.policy_version = current_policy.policy_version
     WHERE current_policy.event_type = NEW.event_type;

    IF NOT FOUND OR NEW.projection_policy_version IS DISTINCT FROM v_current_version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'new activity projection must bind the exact current policy revision';
    END IF;

    IF NULLIF(BTRIM(NEW.description_snapshot), '') IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'activity projection description snapshot must be nonblank';
    END IF;

    IF NEW.safe_payload IS NOT NULL THEN
        IF jsonb_typeof(NEW.safe_payload) <> 'object' THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'activity safe payload must be a JSON object';
        END IF;

        FOR v_key IN SELECT key FROM jsonb_each(NEW.safe_payload)
        LOOP
            IF lower(v_key) ~ '(password|token|pesel|pkk|secret|external_osk_login)' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'activity safe payload contains forbidden sensitive key';
            END IF;
        END LOOP;
    END IF;

    IF NEW.actor_reference_mode = 'organization_membership' THEN
        IF NEW.actor_organization_membership_id IS NULL
           OR NEW.actor_user_id IS NULL
           OR NULLIF(BTRIM(COALESCE(NEW.actor_display_name_snapshot, '')), '') IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'relational activity actor requires exact membership user and display snapshot';
        END IF;
    ELSIF NEW.actor_reference_mode = 'system' THEN
        IF NEW.actor_organization_membership_id IS NOT NULL OR NEW.actor_user_id IS NOT NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'system activity actor cannot claim membership or user identity';
        END IF;
    ELSIF NEW.actor_reference_mode = 'snapshot_only' THEN
        IF NEW.actor_organization_membership_id IS NOT NULL
           OR NEW.actor_user_id IS NOT NULL
           OR NULLIF(BTRIM(COALESCE(NEW.actor_display_name_snapshot, '')), '') IS NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'snapshot-only activity actor requires display snapshot without relational identity';
        END IF;
    ELSIF NEW.actor_reference_mode = 'none' THEN
        IF NEW.actor_organization_membership_id IS NOT NULL
           OR NEW.actor_user_id IS NOT NULL
           OR NEW.actor_display_name_snapshot IS NOT NULL
           OR NEW.actor_role_snapshot IS NOT NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'none activity actor must have no actor identity snapshot';
        END IF;
    ELSE
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'unsupported activity actor reference mode';
    END IF;

    IF NEW.subject_reference_mode = 'none' THEN
        IF NEW.subject_type IS NOT NULL OR NEW.subject_id IS NOT NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'none activity subject must have no subject identity';
        END IF;
    ELSIF NEW.subject_reference_mode IN ('tenant_relational', 'snapshot_only') THEN
        IF NULLIF(BTRIM(COALESCE(NEW.subject_type, '')), '') IS NULL
           OR NULLIF(BTRIM(COALESCE(NEW.subject_id, '')), '') IS NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'activity subject reference requires type and id';
        END IF;
    ELSE
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'unsupported activity subject reference mode';
    END IF;

    IF v_event.required_audit_log_id IS NOT NULL
       AND NEW.actor_reference_mode = 'organization_membership' THEN
        SELECT *
          INTO v_audit
          FROM audit_logs
         WHERE id = v_event.required_audit_log_id;

        IF NOT FOUND
           OR v_audit.organization_id IS DISTINCT FROM NEW.organization_id
           OR v_audit.actor_organization_membership_id IS DISTINCT FROM NEW.actor_organization_membership_id
           OR v_audit.actor_user_id IS DISTINCT FROM NEW.actor_user_id THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'relational activity actor must equal required audit actor';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'organization_activity_events',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
        ]);
    }
}
