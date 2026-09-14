<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class EventsTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-EVENTS', self::definitions());
    }

    /**
     * @return list<array{
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
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'events_audit_policy_revision_immutable',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'audit action policy revisions are immutable history';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'audit_action_policy_revisions',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'events_audit_policy_current_binding',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'current audit policy binding cannot be deleted by normal runtime';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM audit_action_policy_revisions revision
         WHERE revision.action = NEW.action
           AND revision.policy_version = NEW.policy_version
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'current audit policy must bind an exact immutable revision';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'audit_action_policy_currents',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'events_audit_log_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'audit logs are append-only';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'audit_logs',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'events_audit_log_payload_policy',
                'body' => <<<'PLPGSQL'
DECLARE
    v_policy audit_action_policy_revisions%ROWTYPE;
    v_key text;
    v_value jsonb;
    v_payload jsonb;
BEGIN
    SELECT revision.*
      INTO v_policy
      FROM audit_action_policy_currents current_policy
      JOIN audit_action_policy_revisions revision
        ON revision.action = current_policy.action
       AND revision.policy_version = current_policy.policy_version
     WHERE current_policy.action = NEW.action
       AND current_policy.policy_version = NEW.audit_policy_version;

    IF NOT FOUND THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'audit log must use the exact current audit policy revision';
    END IF;

    IF v_policy.before_payload_requirement = 'required' AND NEW.before_redacted_json IS NULL THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit before payload is required by policy';
    ELSIF v_policy.before_payload_requirement = 'forbidden' AND NEW.before_redacted_json IS NOT NULL THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit before payload is forbidden by policy';
    END IF;

    IF v_policy.after_payload_requirement = 'required' AND NEW.after_redacted_json IS NULL THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit after payload is required by policy';
    ELSIF v_policy.after_payload_requirement = 'forbidden' AND NEW.after_redacted_json IS NOT NULL THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit after payload is forbidden by policy';
    END IF;

    IF v_policy.reason_requirement = 'required'
       AND (NEW.reason IS NULL OR btrim(NEW.reason) = '') THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit reason is required by policy';
    END IF;

    IF NEW.reason IS NOT NULL AND btrim(NEW.reason) = '' THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'present audit reason cannot be blank';
    END IF;

    FOREACH v_payload IN ARRAY ARRAY[NEW.before_redacted_json, NEW.after_redacted_json]
    LOOP
        IF v_payload IS NULL THEN
            CONTINUE;
        END IF;

        IF jsonb_typeof(v_payload) <> 'object' THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit payload must be a JSON object';
        END IF;

        FOR v_key, v_value IN SELECT key, value FROM jsonb_each(v_payload)
        LOOP
            IF lower(v_key) ~ '(password|token|pesel|pkk|secret|external_osk_login)' THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit payload contains forbidden sensitive key';
            END IF;

            IF v_policy.payload_validator_code = 'foundation.authorization.v1' THEN
                IF NOT (v_key = ANY (ARRAY[
                    'membership_id', 'permission', 'granted', 'scopes', 'version', 'authorization_version',
                    'from_owner_membership_id', 'to_owner_membership_id'
                ]::text[])) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit payload key is not allowlisted by authorization policy';
                END IF;
            ELSIF v_policy.payload_validator_code = 'foundation.settings.v1' THEN
                IF NOT (v_key = ANY (ARRAY['fields', 'version']::text[])) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit payload key is not allowlisted by settings policy';
                END IF;
            ELSIF v_policy.payload_validator_code = 'resources.lifecycle.v1' THEN
                IF NOT (v_key = ANY (ARRAY[
                    'fields', 'state', 'document_type', 'membership_id', 'archived', 'has_login_account'
                ]::text[])) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'audit payload key is not allowlisted by lifecycle policy';
                END IF;
            ELSE
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'unknown audit payload validator code';
            END IF;

            IF jsonb_typeof(v_value) = 'array'
               AND EXISTS (
                   SELECT 1
                     FROM jsonb_array_elements(v_value) element
                    WHERE jsonb_typeof(element) IN ('object', 'array')
               ) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'nested complex audit payload arrays are forbidden';
            END IF;

            IF jsonb_typeof(v_value) = 'object'
               AND EXISTS (
                   SELECT 1
                     FROM jsonb_each(v_value) nested
                    WHERE jsonb_typeof(nested.value) IN ('object', 'array')
               ) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'nested complex audit payload objects are forbidden';
            END IF;
        END LOOP;
    END LOOP;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'audit_logs',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT'],
                    ],
                ],
            ],
            [
                'name' => 'events_domain_event_immutable',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'domain events are immutable history';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'domain_events',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'events_domain_event_required_audit_equivalence',
                'body' => <<<'PLPGSQL'
DECLARE
    v_event domain_events%ROWTYPE;
    v_audit audit_logs%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RETURN NULL;
    END IF;

    v_event := NEW;

    IF v_event.required_audit_log_id IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_audit
      FROM audit_logs
     WHERE id = v_event.required_audit_log_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    IF v_audit.audit_scope IS DISTINCT FROM v_event.event_scope
       OR v_audit.organization_id IS DISTINCT FROM v_event.organization_id THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'domain event required audit must preserve exact scope and organization';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'domain_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'events_outbox_state_and_lease_fence',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'outbox messages cannot be deleted by normal runtime';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.publication_state <> 'pending'
           OR NEW.next_attempt_at IS NULL
           OR NEW.lease_token IS NOT NULL
           OR NEW.leased_by IS NOT NULL
           OR NEW.lease_expires_at IS NOT NULL
           OR NEW.lease_version <> 0
           OR NEW.attempts <> 0
           OR NEW.attempts_in_cycle <> 0
           OR NEW.replay_count <> 0
           OR NEW.published_at IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'new outbox message must start in exact pending state';
        END IF;

        RETURN NEW;
    END IF;

    IF ROW(
        NEW.id,
        NEW.domain_event_id,
        NEW.event_scope,
        NEW.organization_id,
        NEW.aggregate_type,
        NEW.aggregate_id,
        NEW.event_type,
        NEW.payload,
        NEW.request_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.id,
        OLD.domain_event_id,
        OLD.event_scope,
        OLD.organization_id,
        OLD.aggregate_type,
        OLD.aggregate_id,
        OLD.event_type,
        OLD.payload,
        OLD.request_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'outbox domain-event identity and payload are immutable';
    END IF;

    IF NEW.lease_version < OLD.lease_version
       OR NEW.attempts < OLD.attempts
       OR NEW.attempts_in_cycle < 0
       OR NEW.replay_count < OLD.replay_count THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'outbox lease attempt and replay counters cannot move backwards';
    END IF;

    IF OLD.published_at IS NOT NULL AND NEW.published_at IS DISTINCT FROM OLD.published_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'outbox published_at is write-once';
    END IF;

    IF OLD.publication_state = 'published' THEN
        IF NEW.publication_state <> 'published'
           OR NEW.lease_version <> OLD.lease_version
           OR NEW.attempts <> OLD.attempts
           OR NEW.attempts_in_cycle <> OLD.attempts_in_cycle
           OR NEW.replay_count <> OLD.replay_count THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'published outbox message is terminal';
        END IF;

        RETURN NEW;
    END IF;

    IF OLD.publication_state = 'pending' AND NEW.publication_state = 'pending' THEN
        IF NEW.lease_version <> OLD.lease_version
           OR NEW.attempts <> OLD.attempts
           OR NEW.attempts_in_cycle <> OLD.attempts_in_cycle
           OR NEW.replay_count <> OLD.replay_count
           OR NEW.published_at IS DISTINCT FROM OLD.published_at THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'pending outbox scheduling cannot rewrite fence counters';
        END IF;
    ELSIF OLD.publication_state = 'pending' AND NEW.publication_state = 'leased' THEN
        IF NEW.lease_version <> OLD.lease_version + 1
           OR NEW.attempts <> OLD.attempts + 1
           OR NEW.attempts_in_cycle <> OLD.attempts_in_cycle + 1 THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'outbox claim must increment exact lease and attempt counters once';
        END IF;
    ELSIF OLD.publication_state = 'leased' AND NEW.publication_state = 'leased' THEN
        IF OLD.lease_expires_at IS NULL OR OLD.lease_expires_at > CURRENT_TIMESTAMP
           OR NEW.lease_version <> OLD.lease_version + 1
           OR NEW.attempts <> OLD.attempts + 1
           OR NEW.attempts_in_cycle <> OLD.attempts_in_cycle + 1 THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'outbox leased row may be reclaimed only after expiry with a new fence';
        END IF;
    ELSIF OLD.publication_state = 'leased' AND NEW.publication_state IN ('pending', 'published', 'requires_reconciliation') THEN
        IF NEW.lease_version <> OLD.lease_version
           OR NEW.attempts <> OLD.attempts
           OR NEW.attempts_in_cycle <> OLD.attempts_in_cycle THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'outbox lease acknowledgement must preserve current fence counters';
        END IF;

        IF NEW.publication_state = 'pending' AND NEW.next_attempt_at IS NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'retryable outbox failure must schedule next attempt';
        ELSIF NEW.publication_state = 'published' AND NEW.published_at IS NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'published outbox transition requires published_at';
        ELSIF NEW.publication_state = 'requires_reconciliation' AND NEW.next_attempt_at IS NOT NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'reconciliation state must not remain auto-scheduled';
        END IF;
    ELSIF OLD.publication_state = 'requires_reconciliation' AND NEW.publication_state = 'requires_reconciliation' THEN
        IF NEW.lease_version <> OLD.lease_version
           OR NEW.attempts <> OLD.attempts
           OR NEW.attempts_in_cycle <> OLD.attempts_in_cycle
           OR NEW.replay_count <> OLD.replay_count
           OR NEW.published_at IS DISTINCT FROM OLD.published_at THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'reconciliation outbox row cannot rewrite fence counters';
        END IF;
    ELSIF OLD.publication_state = 'requires_reconciliation' AND NEW.publication_state = 'pending' THEN
        IF NEW.replay_count <> OLD.replay_count + 1
           OR NEW.attempts <> OLD.attempts
           OR NEW.attempts_in_cycle <> 0
           OR NEW.next_attempt_at IS NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'manual replay must preserve lifetime attempts and reset cycle exactly once';
        END IF;
    ELSIF OLD.publication_state IS DISTINCT FROM NEW.publication_state THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'illegal outbox publication state transition';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'outbox_messages',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'events_outbox_domain_event_equivalence',
                'body' => <<<'PLPGSQL'
DECLARE
    v_outbox outbox_messages%ROWTYPE;
    v_event domain_events%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RETURN NULL;
    END IF;

    v_outbox := NEW;

    SELECT *
      INTO v_event
      FROM domain_events
     WHERE id = v_outbox.domain_event_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    IF v_outbox.event_scope IS DISTINCT FROM v_event.event_scope
       OR v_outbox.organization_id IS DISTINCT FROM v_event.organization_id
       OR v_outbox.event_type IS DISTINCT FROM v_event.event_type
       OR v_outbox.aggregate_type IS DISTINCT FROM v_event.aggregate_type
       OR v_outbox.aggregate_id IS DISTINCT FROM v_event.aggregate_id
       OR v_outbox.request_id IS DISTINCT FROM v_event.request_id THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'outbox identity snapshot must exactly equal bound domain event';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'outbox_messages',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'events_retention_execution_evidence_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'retention execution evidence is append-only for normal runtime';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'data_retention_execution_runs',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
        ];
    }
}
