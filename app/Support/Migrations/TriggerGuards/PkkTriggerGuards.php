<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class PkkTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-PKK', self::definitions());
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
                'name' => 'pkk_provider_snapshot_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'PKK provider profile snapshots are append-only';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_provider_profile_snapshots',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_operation_durable_identity_and_transition',
                'body' => <<<'PLPGSQL'
DECLARE
    v_allowed boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK operations are durable business history and cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.course_enrollment_id,
        NEW.pkk_profile_id,
        NEW.operation_type,
        NEW.operation_origin,
        NEW.initial_idempotency_record_id,
        NEW.course_operation_sequence,
        NEW.actor_user_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.course_enrollment_id,
        OLD.pkk_profile_id,
        OLD.operation_type,
        OLD.operation_origin,
        OLD.initial_idempotency_record_id,
        OLD.course_operation_sequence,
        OLD.actor_user_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK operation identity, type and course sequence are immutable';
    END IF;

    IF OLD.business_status IS DISTINCT FROM NEW.business_status THEN
        IF OLD.business_status = 'draft' THEN
            v_allowed := NEW.business_status IN ('pending', 'cancelled');
        ELSIF OLD.business_status = 'pending' AND OLD.operation_type = 'fetch_profile' THEN
            v_allowed := NEW.business_status IN ('success', 'failed');
        ELSIF OLD.business_status = 'pending' THEN
            v_allowed := NEW.business_status IN ('requires_signature', 'submitted', 'failed');
        ELSIF OLD.business_status = 'requires_signature' THEN
            v_allowed := NEW.business_status IN ('submitted', 'failed', 'cancelled');
        ELSIF OLD.business_status = 'submitted' THEN
            v_allowed := NEW.business_status IN ('success', 'failed');
        ELSIF OLD.business_status = 'failed' THEN
            v_allowed := NEW.business_status = 'pending';
        END IF;

        IF NOT v_allowed THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'PKK operation business status transition is forbidden';
        END IF;
    END IF;

    IF OLD.business_status IN ('success', 'cancelled') THEN
        IF NEW.business_status IS DISTINCT FROM OLD.business_status
           OR NEW.completed_at IS DISTINCT FROM OLD.completed_at THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'successful or cancelled PKK operation is irreversible';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_operations',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_operation_lifecycle_append_only',
                'body' => <<<'PLPGSQL'
DECLARE
    v_previous_status varchar;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK operation lifecycle is append-only';
    END IF;

    IF NEW.operation_version < 1
       OR NEW.to_business_status NOT IN ('draft', 'pending', 'requires_signature', 'submitted', 'success', 'failed', 'cancelled') THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK lifecycle row has invalid version or status';
    END IF;

    IF NEW.operation_version = 1 THEN
        IF NEW.from_business_status IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'first PKK lifecycle row must have null previous status';
        END IF;
    ELSE
        SELECT to_business_status
          INTO v_previous_status
          FROM pkk_operation_lifecycle_events
         WHERE organization_id = NEW.organization_id
           AND pkk_operation_id = NEW.pkk_operation_id
           AND operation_version = NEW.operation_version - 1;

        IF NOT FOUND OR NEW.from_business_status IS DISTINCT FROM v_previous_status THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'PKK lifecycle successor must continue the exact previous status';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_operation_lifecycle_events',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_operation_lifecycle_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_operation_id uuid;
    v_operation pkk_operations%ROWTYPE;
    v_match_count integer;
    v_match_status varchar;
    v_latest_version bigint;
    v_submitted_count integer;
BEGIN
    IF TG_TABLE_NAME = 'pkk_operations' THEN
        v_operation_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_operation_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.pkk_operation_id ELSE NEW.pkk_operation_id END;
    END IF;

    SELECT *
      INTO v_operation
      FROM pkk_operations
     WHERE id = v_operation_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*), MAX(to_business_status)
      INTO v_match_count, v_match_status
      FROM pkk_operation_lifecycle_events
     WHERE organization_id = v_operation.organization_id
       AND pkk_operation_id = v_operation.id
       AND operation_version = v_operation.version;

    IF v_match_count <> 1 OR v_match_status IS DISTINCT FROM v_operation.business_status THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK operation current version and status require one exact lifecycle row';
    END IF;

    SELECT MAX(operation_version)
      INTO v_latest_version
      FROM pkk_operation_lifecycle_events
     WHERE organization_id = v_operation.organization_id
       AND pkk_operation_id = v_operation.id;

    IF v_latest_version IS DISTINCT FROM v_operation.version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK operation version must equal latest lifecycle version';
    END IF;

    IF v_operation.business_status IN ('draft', 'pending', 'requires_signature', 'submitted')
       AND v_operation.completed_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'active PKK operation cannot carry completed_at';
    END IF;

    IF v_operation.business_status IN ('success', 'cancelled')
       AND v_operation.completed_at IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'successful or cancelled PKK operation requires completed_at';
    END IF;

    IF v_operation.operation_origin = 'runtime' THEN
        IF v_operation.operation_type = 'fetch_profile' THEN
            IF v_operation.confirmed_at IS NOT NULL OR v_operation.confirmed_by_user_id IS NOT NULL THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'fetch-profile PKK operation cannot carry mutation confirmation';
            END IF;
        ELSIF v_operation.business_status <> 'draft'
              AND (v_operation.confirmed_at IS NULL OR v_operation.confirmed_by_user_id IS NULL) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'runtime mutating PKK operation requires captured confirmation';
        END IF;
    END IF;

    IF v_operation.operation_type <> 'fetch_profile' AND v_operation.business_status = 'success' THEN
        SELECT COUNT(*)
          INTO v_submitted_count
          FROM pkk_operation_lifecycle_events
         WHERE organization_id = v_operation.organization_id
           AND pkk_operation_id = v_operation.id
           AND to_business_status = 'submitted';

        IF v_submitted_count = 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'successful mutating PKK operation requires submitted lifecycle evidence';
        END IF;
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_operations',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'pkk_operation_lifecycle_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'pkk_operation_course_sequence_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_organization_id uuid;
    v_course_id uuid;
    v_count bigint;
    v_min bigint;
    v_max bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        v_organization_id := OLD.organization_id;
        v_course_id := OLD.course_enrollment_id;
    ELSE
        v_organization_id := NEW.organization_id;
        v_course_id := NEW.course_enrollment_id;
    END IF;

    SELECT COUNT(*), MIN(course_operation_sequence), MAX(course_operation_sequence)
      INTO v_count, v_min, v_max
      FROM pkk_operations
     WHERE organization_id = v_organization_id
       AND course_enrollment_id = v_course_id;

    IF v_count > 0 AND (v_min <> 1 OR v_max <> v_count) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK course operation sequence must be contiguous from one';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_operations',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'pkk_attempt_context_immutable',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK provider attempts are durable and cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.pkk_operation_id,
        NEW.course_enrollment_id,
        NEW.pkk_profile_id,
        NEW.attempt_no,
        NEW.command_idempotency_record_id,
        NEW.integration_configuration_revision,
        NEW.signature_handoff_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.pkk_operation_id,
        OLD.course_enrollment_id,
        OLD.pkk_profile_id,
        OLD.attempt_no,
        OLD.command_idempotency_record_id,
        OLD.integration_configuration_revision,
        OLD.signature_handoff_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK provider attempt execution context is immutable';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_operation_attempts',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_reconciliation_append_only_sequence',
                'body' => <<<'PLPGSQL'
DECLARE
    v_expected bigint;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK attempt reconciliation history is append-only';
    END IF;

    IF NEW.reconciliation_sequence < 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK reconciliation sequence must be positive';
    END IF;

    IF NEW.actor_kind <> 'legacy_migration' THEN
        SELECT COALESCE(MAX(reconciliation_sequence), 0) + 1
          INTO v_expected
          FROM pkk_operation_attempt_reconciliations
         WHERE organization_id = NEW.organization_id
           AND pkk_operation_attempt_id = NEW.pkk_operation_attempt_id;

        IF NEW.reconciliation_sequence <> v_expected THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'runtime PKK reconciliation sequence must append contiguously';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_operation_attempt_reconciliations',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_signature_handoff_durable_write_once',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature handoff evidence cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.course_enrollment_id,
        NEW.pkk_profile_id,
        NEW.pkk_operation_id,
        NEW.handoff_no,
        NEW.requires_signature_operation_version,
        NEW.source_pkk_operation_attempt_id,
        NEW.unsigned_file_asset_id,
        NEW.unsigned_sha256,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.course_enrollment_id,
        OLD.pkk_profile_id,
        OLD.pkk_operation_id,
        OLD.handoff_no,
        OLD.requires_signature_operation_version,
        OLD.source_pkk_operation_attempt_id,
        OLD.unsigned_file_asset_id,
        OLD.unsigned_sha256,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature handoff context and unsigned evidence are immutable';
    END IF;

    IF OLD.signed_file_asset_id IS NOT NULL
       AND ROW(
           NEW.signed_file_asset_id,
           NEW.signed_sha256,
           NEW.signed_attached_by_user_id,
           NEW.signed_attached_at
       ) IS DISTINCT FROM ROW(
           OLD.signed_file_asset_id,
           OLD.signed_sha256,
           OLD.signed_attached_by_user_id,
           OLD.signed_attached_at
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'accepted signed PKK artifact binding is write-once';
    END IF;

    IF NOT (
        (
            NEW.signed_file_asset_id IS NULL
            AND NEW.signed_sha256 IS NULL
            AND NEW.signed_attached_by_user_id IS NULL
            AND NEW.signed_attached_at IS NULL
        )
        OR
        (
            NEW.signed_file_asset_id IS NOT NULL
            AND NEW.signed_sha256 IS NOT NULL
            AND NEW.signed_attached_by_user_id IS NOT NULL
            AND NEW.signed_attached_at IS NOT NULL
        )
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'signed PKK artifact binding must be complete';
    END IF;

    IF OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature handoff consumed_at is write-once';
    END IF;

    IF OLD.cancelled_at IS NOT NULL AND NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature handoff cancelled_at is write-once';
    END IF;

    IF NEW.consumed_at IS NOT NULL AND NEW.cancelled_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature handoff cannot be both consumed and cancelled';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_signature_handoffs',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_signature_handoff_lifecycle_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_handoff_id uuid;
    v_handoff pkk_signature_handoffs%ROWTYPE;
    v_match_count integer;
BEGIN
    IF TG_TABLE_NAME = 'pkk_signature_handoffs' THEN
        v_handoff_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        IF TG_OP = 'DELETE' THEN
            SELECT id
              INTO v_handoff_id
              FROM pkk_signature_handoffs
             WHERE organization_id = OLD.organization_id
               AND pkk_operation_id = OLD.pkk_operation_id
               AND requires_signature_operation_version = OLD.operation_version;
        ELSE
            SELECT id
              INTO v_handoff_id
              FROM pkk_signature_handoffs
             WHERE organization_id = NEW.organization_id
               AND pkk_operation_id = NEW.pkk_operation_id
               AND requires_signature_operation_version = NEW.operation_version;
        END IF;

        IF v_handoff_id IS NULL THEN
            RETURN NULL;
        END IF;
    END IF;

    SELECT *
      INTO v_handoff
      FROM pkk_signature_handoffs
     WHERE id = v_handoff_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_match_count
      FROM pkk_operation_lifecycle_events
     WHERE organization_id = v_handoff.organization_id
       AND pkk_operation_id = v_handoff.pkk_operation_id
       AND operation_version = v_handoff.requires_signature_operation_version
       AND to_business_status = 'requires_signature';

    IF v_match_count <> 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature handoff requires exact requires-signature lifecycle version';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_signature_handoffs',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'pkk_operation_lifecycle_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'pkk_signature_upload_reservation_durable',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature upload reservation cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.pkk_signature_handoff_id,
        NEW.pkk_operation_id,
        NEW.course_enrollment_id,
        NEW.pkk_profile_id,
        NEW.file_asset_id,
        NEW.created_by_user_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.pkk_signature_handoff_id,
        OLD.pkk_operation_id,
        OLD.course_enrollment_id,
        OLD.pkk_profile_id,
        OLD.file_asset_id,
        OLD.created_by_user_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature upload reservation context is immutable';
    END IF;

    IF OLD.accepted_at IS NOT NULL AND NEW.accepted_at IS DISTINCT FROM OLD.accepted_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature upload reservation accepted_at is write-once';
    END IF;

    IF OLD.rejected_at IS NOT NULL AND NEW.rejected_at IS DISTINCT FROM OLD.rejected_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature upload reservation rejected_at is write-once';
    END IF;

    IF NEW.accepted_at IS NOT NULL AND NEW.rejected_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'PKK signature upload reservation cannot be accepted and rejected';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_signature_handoff_upload_reservations',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_encrypted_evidence_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'PKK encrypted and redacted evidence rows are append-only';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_protected_payloads',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                    [
                        'table' => 'pkk_protected_payload_key_wrappings',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                    [
                        'table' => 'pkk_payload_redacted_projections',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                    [
                        'table' => 'pkk_signature_file_asset_protections',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                    [
                        'table' => 'pkk_signature_file_asset_key_wrappings',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_bound_file_asset_immutable',
                'body' => <<<'PLPGSQL'
DECLARE
    v_bound boolean;
BEGIN
    SELECT
        EXISTS (
            SELECT 1
              FROM pkk_signature_handoffs
             WHERE unsigned_file_asset_id = OLD.id
                OR signed_file_asset_id = OLD.id
        )
        OR EXISTS (
            SELECT 1
              FROM pkk_signature_handoff_upload_reservations
             WHERE file_asset_id = OLD.id
        )
        OR EXISTS (
            SELECT 1
              FROM pkk_signature_file_asset_protections
             WHERE file_asset_id = OLD.id
        )
      INTO v_bound;

    IF NOT v_bound THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'file asset bound to PKK formal evidence cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.storage_disk,
        NEW.storage_key,
        NEW.mime_type_detected,
        NEW.size_bytes,
        NEW.sha256,
        NEW.purpose
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.storage_disk,
        OLD.storage_key,
        OLD.mime_type_detected,
        OLD.size_bytes,
        OLD.sha256,
        OLD.purpose
    ) OR NEW.deleted_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'file asset bound to PKK formal evidence is immutable';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'file_assets',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_integration_configuration_revision_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'PKK integration configuration revisions are append-only';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_integration_configuration_revisions',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'pkk_current_configuration_revision_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_organization_id uuid;
    v_current_revision bigint;
    v_match_count integer;
BEGIN
    IF TG_TABLE_NAME = 'pkk_integration_settings' THEN
        v_organization_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    ELSE
        v_organization_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    END IF;

    SELECT execution_configuration_revision
      INTO v_current_revision
      FROM pkk_integration_settings
     WHERE organization_id = v_organization_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_match_count
      FROM pkk_integration_configuration_revisions
     WHERE organization_id = v_organization_id
       AND execution_configuration_revision = v_current_revision;

    IF v_match_count <> 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'current PKK integration settings require one exact immutable configuration revision';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'pkk_integration_settings',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'pkk_integration_configuration_revisions',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
        ];
    }
}
