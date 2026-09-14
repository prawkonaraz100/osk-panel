<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class ExamsTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-EXAMS', self::definitions());
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
                'name' => 'exam_inventory_ledger_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam inventory ledger is append-only';
    END IF;

    IF NEW.event_sequence < 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam inventory ledger sequence must be positive';
    END IF;

    IF NEW.event_type = 'migration_baseline' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'migration-only internal exam ledger baseline cannot be inserted after write-fence cutover';
    END IF;

    IF NEW.event_type NOT IN (
        'unit_granted',
        'unit_adjustment_granted',
        'unit_reserved',
        'unit_released',
        'unit_consumed',
        'unit_adjusted_out'
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam inventory ledger event type is not runtime legal';
    END IF;

    IF (
        NEW.event_type IN ('unit_granted', 'unit_adjustment_granted', 'unit_released')
        AND NEW.available_delta <> 1
    ) OR (
        NEW.event_type IN ('unit_reserved', 'unit_adjusted_out')
        AND NEW.available_delta <> -1
    ) OR (
        NEW.event_type = 'unit_consumed'
        AND NEW.available_delta <> 0
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam inventory ledger delta does not match event type';
    END IF;

    IF NEW.event_type IN ('unit_reserved', 'unit_released', 'unit_consumed') THEN
        IF NEW.internal_exam_reservation_id IS NULL OR NEW.internal_exam_attempt_id IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'reservation inventory ledger event requires exact reservation and attempt';
        END IF;

        IF NOT EXISTS (
            SELECT 1
              FROM internal_exam_reservations reservation
             WHERE reservation.id = NEW.internal_exam_reservation_id
               AND reservation.organization_id = NEW.organization_id
               AND reservation.internal_exam_inventory_entry_id = NEW.internal_exam_inventory_entry_id
               AND reservation.internal_exam_attempt_id = NEW.internal_exam_attempt_id
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'inventory ledger reservation binding does not match exact attempt and inventory';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_inventory_ledger_entries',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_inventory_ledger_sequence_and_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_inventory_id uuid;
    v_inventory internal_exam_inventory_entries%ROWTYPE;
    v_count bigint;
    v_min bigint;
    v_max bigint;
    v_state varchar := NULL;
    v_event record;
    v_reserved_count bigint;
    v_consumed_count bigint;
BEGIN
    IF TG_TABLE_NAME = 'internal_exam_inventory_entries' THEN
        v_inventory_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME = 'internal_exam_inventory_ledger_entries' THEN
        v_inventory_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_inventory_entry_id
            ELSE NEW.internal_exam_inventory_entry_id
        END;
    ELSE
        v_inventory_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_inventory_entry_id
            ELSE NEW.internal_exam_inventory_entry_id
        END;
    END IF;

    SELECT *
      INTO v_inventory
      FROM internal_exam_inventory_entries
     WHERE id = v_inventory_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*), MIN(event_sequence), MAX(event_sequence)
      INTO v_count, v_min, v_max
      FROM internal_exam_inventory_ledger_entries
     WHERE organization_id = v_inventory.organization_id
       AND internal_exam_inventory_entry_id = v_inventory.id;

    IF v_count = 0 OR v_min <> 1 OR v_max <> v_count THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam inventory ledger sequence must be contiguous from one';
    END IF;

    FOR v_event IN
        SELECT event_sequence, event_type
          FROM internal_exam_inventory_ledger_entries
         WHERE organization_id = v_inventory.organization_id
           AND internal_exam_inventory_entry_id = v_inventory.id
         ORDER BY event_sequence
    LOOP
        IF v_event.event_type = 'migration_baseline' THEN
            IF v_event.event_sequence <> 1 THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'internal exam migration baseline must be the first ledger event';
            END IF;

            v_state := v_inventory.current_state;
        ELSIF v_event.event_type IN ('unit_granted', 'unit_adjustment_granted') THEN
            IF v_event.event_sequence <> 1 OR v_state IS NOT NULL THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'internal exam inventory grant must initialize the ledger exactly once';
            END IF;

            IF v_event.event_type = 'unit_granted' AND v_inventory.source_type NOT IN ('free', 'paid') THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'standard inventory grant requires free or paid source';
            END IF;

            IF v_event.event_type = 'unit_adjustment_granted' AND v_inventory.source_type <> 'adjustment' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'adjustment inventory grant requires adjustment source';
            END IF;

            v_state := 'available';
        ELSIF v_event.event_type = 'unit_reserved' THEN
            IF v_state <> 'available' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'internal exam inventory may be reserved only from available';
            END IF;
            v_state := 'reserved';
        ELSIF v_event.event_type = 'unit_released' THEN
            IF v_state <> 'reserved' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'internal exam inventory may be released only from reserved';
            END IF;
            v_state := 'available';
        ELSIF v_event.event_type = 'unit_consumed' THEN
            IF v_state <> 'reserved' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'internal exam inventory may be consumed only from reserved';
            END IF;
            v_state := 'consumed';
        ELSIF v_event.event_type = 'unit_adjusted_out' THEN
            IF v_state <> 'available' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'internal exam inventory may be adjusted out only from available';
            END IF;
            v_state := 'adjusted_out';
        ELSE
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam inventory ledger contains unknown event';
        END IF;
    END LOOP;

    IF v_state IS DISTINCT FROM v_inventory.current_state THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam inventory current state must equal ledger-derived state';
    END IF;

    SELECT
        COUNT(*) FILTER (WHERE status = 'reserved'),
        COUNT(*) FILTER (WHERE status = 'consumed')
      INTO v_reserved_count, v_consumed_count
      FROM internal_exam_reservations
     WHERE organization_id = v_inventory.organization_id
       AND internal_exam_inventory_entry_id = v_inventory.id;

    IF v_inventory.current_state = 'reserved' AND v_reserved_count <> 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'reserved internal exam inventory requires exactly one reserved reservation';
    END IF;

    IF v_inventory.current_state = 'consumed' AND v_consumed_count <> 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'consumed internal exam inventory requires exactly one consumed reservation';
    END IF;

    IF v_inventory.current_state IN ('available', 'adjusted_out')
       AND (v_reserved_count <> 0 OR v_consumed_count <> 0) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'nonallocated internal exam inventory cannot retain reserved or consumed reservation';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_inventory_entries',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_inventory_ledger_entries',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_reservations',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'exam_attempt_durable_identity_and_transition',
                'body' => <<<'PLPGSQL'
DECLARE
    v_allowed boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt is durable history and cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.student_id,
        NEW.course_enrollment_id,
        NEW.exam_part,
        NEW.driving_category_id,
        NEW.language_code,
        NEW.training_requirement_profile_id,
        NEW.requirements_revision,
        NEW.internal_exam_capability_id,
        NEW.requirement_basis_snapshot,
        NEW.course_attempt_sequence,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.student_id,
        OLD.course_enrollment_id,
        OLD.exam_part,
        OLD.driving_category_id,
        OLD.language_code,
        OLD.training_requirement_profile_id,
        OLD.requirements_revision,
        OLD.internal_exam_capability_id,
        OLD.requirement_basis_snapshot,
        OLD.course_attempt_sequence,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt formal identity and creation basis are immutable';
    END IF;

    IF NEW.candidate_snapshot IS DISTINCT FROM OLD.candidate_snapshot THEN
        IF OLD.status <> 'created' OR OLD.started_at IS NOT NULL OR NEW.status <> 'created' THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'candidate snapshot is editable only while attempt remains unstarted';
        END IF;
    END IF;

    IF OLD.started_at IS NOT NULL AND NEW.started_at IS DISTINCT FROM OLD.started_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt started_at is write-once';
    END IF;
    IF OLD.finished_at IS NOT NULL AND NEW.finished_at IS DISTINCT FROM OLD.finished_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt finished_at is write-once';
    END IF;
    IF OLD.technical_aborted_at IS NOT NULL AND NEW.technical_aborted_at IS DISTINCT FROM OLD.technical_aborted_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt technical_aborted_at is write-once';
    END IF;
    IF OLD.invalidated_at IS NOT NULL AND NEW.invalidated_at IS DISTINCT FROM OLD.invalidated_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt invalidated_at is write-once';
    END IF;

    IF OLD.internal_exam_definition_id IS NOT NULL
       AND ROW(
           NEW.internal_exam_definition_id,
           NEW.exam_definition_version_snapshot,
           NEW.exam_definition_hash_snapshot,
           NEW.evidence_schema_version,
           NEW.question_set_hash
       ) IS DISTINCT FROM ROW(
           OLD.internal_exam_definition_id,
           OLD.exam_definition_version_snapshot,
           OLD.exam_definition_hash_snapshot,
           OLD.evidence_schema_version,
           OLD.question_set_hash
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam definition and question-set evidence are write-once';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF OLD.status = 'created' THEN
            v_allowed := NEW.status = 'in_progress';
        ELSIF OLD.status = 'in_progress' THEN
            v_allowed := NEW.status IN ('passed', 'failed', 'technical_abort', 'invalidated');
        ELSIF OLD.status IN ('passed', 'failed', 'technical_abort') THEN
            v_allowed := NEW.status = 'invalidated';
        END IF;

        IF NOT v_allowed OR NEW.version <> OLD.version + 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam attempt status transition or version step is invalid';
        END IF;
    ELSIF NEW.version IS DISTINCT FROM OLD.version THEN
        IF NOT (
            OLD.status = 'created'
            AND NEW.candidate_snapshot IS DISTINCT FROM OLD.candidate_snapshot
            AND NEW.version = OLD.version + 1
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam attempt version may change only with one material lifecycle transition';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_attempts',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_attempt_lifecycle_append_only',
                'body' => <<<'PLPGSQL'
DECLARE
    v_previous_status varchar;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt lifecycle is append-only';
    END IF;

    IF NEW.version_after < 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt lifecycle version must be positive';
    END IF;

    IF NEW.version_after = 1 THEN
        IF NEW.version_before IS NOT NULL OR NEW.from_status IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'first internal exam attempt lifecycle event must start from null';
        END IF;
    ELSE
        IF NEW.version_before IS DISTINCT FROM NEW.version_after - 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam attempt lifecycle version step must be exactly one';
        END IF;

        SELECT to_status
          INTO v_previous_status
          FROM internal_exam_attempt_lifecycle_events
         WHERE organization_id = NEW.organization_id
           AND internal_exam_attempt_id = NEW.internal_exam_attempt_id
           AND version_after = NEW.version_before;

        IF NOT FOUND OR NEW.from_status IS DISTINCT FROM v_previous_status THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam attempt lifecycle must continue exact previous status';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_attempt_lifecycle_events',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_attempt_lifecycle_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_attempt_id uuid;
    v_attempt internal_exam_attempts%ROWTYPE;
    v_match_count bigint;
    v_match_status varchar;
    v_latest bigint;
BEGIN
    IF TG_TABLE_NAME = 'internal_exam_attempts' THEN
        v_attempt_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_attempt_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_attempt_id
            ELSE NEW.internal_exam_attempt_id
        END;
    END IF;

    SELECT *
      INTO v_attempt
      FROM internal_exam_attempts
     WHERE id = v_attempt_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*), MAX(to_status), MAX(version_after)
      INTO v_match_count, v_match_status, v_latest
      FROM internal_exam_attempt_lifecycle_events
     WHERE organization_id = v_attempt.organization_id
       AND internal_exam_attempt_id = v_attempt.id
       AND version_after = v_attempt.version;

    IF v_match_count <> 1
       OR v_match_status IS DISTINCT FROM v_attempt.status
       OR v_latest IS DISTINCT FROM v_attempt.version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt current version and status require one exact lifecycle event';
    END IF;

    SELECT MAX(version_after)
      INTO v_latest
      FROM internal_exam_attempt_lifecycle_events
     WHERE organization_id = v_attempt.organization_id
       AND internal_exam_attempt_id = v_attempt.id;

    IF v_latest IS DISTINCT FROM v_attempt.version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt version must equal latest lifecycle version';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_attempts',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_attempt_lifecycle_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'exam_attempt_course_sequence_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_organization_id uuid;
    v_course_id uuid;
    v_count bigint;
    v_min bigint;
    v_max bigint;
BEGIN
    v_organization_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    v_course_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.course_enrollment_id ELSE NEW.course_enrollment_id END;

    SELECT COUNT(*), MIN(course_attempt_sequence), MAX(course_attempt_sequence)
      INTO v_count, v_min, v_max
      FROM internal_exam_attempts
     WHERE organization_id = v_organization_id
       AND course_enrollment_id = v_course_id;

    IF v_count > 0 AND (v_min <> 1 OR v_max <> v_count) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam course attempt sequence must be contiguous from one';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_attempts',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'exam_access_durable_identity_and_transition',
                'body' => <<<'PLPGSQL'
DECLARE
    v_allowed boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam access is durable history and cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.internal_exam_attempt_id,
        NEW.launch_mode,
        NEW.station_id,
        NEW.expires_at,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.internal_exam_attempt_id,
        OLD.launch_mode,
        OLD.station_id,
        OLD.expires_at,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam access attempt, launch mode, station and expiry binding are immutable';
    END IF;

    IF OLD.ready_at IS NOT NULL AND NEW.ready_at IS DISTINCT FROM OLD.ready_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access ready_at is write-once';
    END IF;
    IF OLD.delivered_or_assigned_at IS NOT NULL AND NEW.delivered_or_assigned_at IS DISTINCT FROM OLD.delivered_or_assigned_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access delivered_or_assigned_at is write-once';
    END IF;
    IF OLD.opened_at IS NOT NULL AND NEW.opened_at IS DISTINCT FROM OLD.opened_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access opened_at is write-once';
    END IF;
    IF OLD.started_at IS NOT NULL AND NEW.started_at IS DISTINCT FROM OLD.started_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access started_at is write-once';
    END IF;
    IF OLD.completed_at IS NOT NULL AND NEW.completed_at IS DISTINCT FROM OLD.completed_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access completed_at is write-once';
    END IF;
    IF OLD.cancelled_at IS NOT NULL AND NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access cancelled_at is write-once';
    END IF;
    IF OLD.expired_at IS NOT NULL AND NEW.expired_at IS DISTINCT FROM OLD.expired_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access expired_at is write-once';
    END IF;
    IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access revoked_at is write-once';
    END IF;
    IF OLD.technical_aborted_at IS NOT NULL AND NEW.technical_aborted_at IS DISTINCT FROM OLD.technical_aborted_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access technical_aborted_at is write-once';
    END IF;
    IF OLD.invalidated_at IS NOT NULL AND NEW.invalidated_at IS DISTINCT FROM OLD.invalidated_at THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'internal exam access invalidated_at is write-once';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF OLD.status = 'draft' THEN
            v_allowed := NEW.status IN ('ready', 'delivered_or_assigned', 'cancelled', 'expired', 'revoked');
        ELSIF OLD.status = 'ready' THEN
            v_allowed := NEW.status IN ('delivered_or_assigned', 'opened', 'started', 'cancelled', 'expired', 'revoked');
        ELSIF OLD.status = 'delivered_or_assigned' THEN
            v_allowed := NEW.status IN ('opened', 'started', 'cancelled', 'expired', 'revoked');
        ELSIF OLD.status = 'opened' THEN
            v_allowed := NEW.status IN ('started', 'cancelled', 'expired', 'revoked');
        ELSIF OLD.status = 'started' THEN
            v_allowed := NEW.status IN ('completed', 'technical_abort', 'invalidated');
        ELSIF OLD.status IN ('completed', 'technical_abort') THEN
            v_allowed := NEW.status = 'invalidated';
        END IF;

        IF NOT v_allowed OR NEW.version <> OLD.version + 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam access status transition or version step is invalid';
        END IF;
    ELSIF NEW.version IS DISTINCT FROM OLD.version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam access version may change only with material lifecycle status';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_accesses',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_access_lifecycle_append_only',
                'body' => <<<'PLPGSQL'
DECLARE
    v_previous_status varchar;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam access lifecycle is append-only';
    END IF;

    IF NEW.version_after < 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam access lifecycle version must be positive';
    END IF;

    IF NEW.version_after = 1 THEN
        IF NEW.version_before IS NOT NULL OR NEW.from_status IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'first internal exam access lifecycle event must start from null';
        END IF;
    ELSE
        IF NEW.version_before IS DISTINCT FROM NEW.version_after - 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam access lifecycle version step must be exactly one';
        END IF;

        SELECT to_status
          INTO v_previous_status
          FROM internal_exam_access_lifecycle_events
         WHERE organization_id = NEW.organization_id
           AND internal_exam_access_id = NEW.internal_exam_access_id
           AND version_after = NEW.version_before;

        IF NOT FOUND OR NEW.from_status IS DISTINCT FROM v_previous_status THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam access lifecycle must continue exact previous status';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_access_lifecycle_events',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_access_lifecycle_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_access_id uuid;
    v_access internal_exam_accesses%ROWTYPE;
    v_match_count bigint;
    v_match_status varchar;
    v_latest bigint;
BEGIN
    IF TG_TABLE_NAME = 'internal_exam_accesses' THEN
        v_access_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_access_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_access_id
            ELSE NEW.internal_exam_access_id
        END;
    END IF;

    SELECT *
      INTO v_access
      FROM internal_exam_accesses
     WHERE id = v_access_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*), MAX(to_status), MAX(version_after)
      INTO v_match_count, v_match_status, v_latest
      FROM internal_exam_access_lifecycle_events
     WHERE organization_id = v_access.organization_id
       AND internal_exam_access_id = v_access.id
       AND version_after = v_access.version;

    IF v_match_count <> 1
       OR v_match_status IS DISTINCT FROM v_access.status
       OR v_latest IS DISTINCT FROM v_access.version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam access current version and status require one exact lifecycle event';
    END IF;

    SELECT MAX(version_after)
      INTO v_latest
      FROM internal_exam_access_lifecycle_events
     WHERE organization_id = v_access.organization_id
       AND internal_exam_access_id = v_access.id;

    IF v_latest IS DISTINCT FROM v_access.version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam access version must equal latest lifecycle version';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_accesses',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_access_lifecycle_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'exam_attempt_status_timestamp_and_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_attempt_id uuid;
    v_attempt internal_exam_attempts%ROWTYPE;
    v_reserved_count bigint;
    v_consumed_count bigint;
    v_started_access_count bigint;
    v_nonterminal_access_count bigint;
    v_result_count bigint;
    v_matching_access_count bigint;
    v_result_passed boolean;
BEGIN
    IF TG_TABLE_NAME = 'internal_exam_attempts' THEN
        v_attempt_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME = 'internal_exam_accesses' THEN
        v_attempt_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_attempt_id
            ELSE NEW.internal_exam_attempt_id
        END;
    ELSIF TG_TABLE_NAME = 'internal_exam_reservations' THEN
        v_attempt_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_attempt_id
            ELSE NEW.internal_exam_attempt_id
        END;
    ELSE
        v_attempt_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_attempt_id
            ELSE NEW.internal_exam_attempt_id
        END;
    END IF;

    SELECT *
      INTO v_attempt
      FROM internal_exam_attempts
     WHERE id = v_attempt_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT
        COUNT(*) FILTER (WHERE status = 'reserved'),
        COUNT(*) FILTER (WHERE status = 'consumed')
      INTO v_reserved_count, v_consumed_count
      FROM internal_exam_reservations
     WHERE organization_id = v_attempt.organization_id
       AND internal_exam_attempt_id = v_attempt.id;

    SELECT
        COUNT(*) FILTER (WHERE started_at IS NOT NULL),
        COUNT(*) FILTER (WHERE status IN ('draft', 'ready', 'delivered_or_assigned', 'opened', 'started'))
      INTO v_started_access_count, v_nonterminal_access_count
      FROM internal_exam_accesses
     WHERE organization_id = v_attempt.organization_id
       AND internal_exam_attempt_id = v_attempt.id;

    SELECT COUNT(*), MAX(passed)
      INTO v_result_count, v_result_passed
      FROM internal_exam_results
     WHERE organization_id = v_attempt.organization_id
       AND internal_exam_attempt_id = v_attempt.id;

    IF v_attempt.status = 'created' THEN
        IF v_attempt.started_at IS NOT NULL
           OR v_attempt.finished_at IS NOT NULL
           OR v_attempt.technical_aborted_at IS NOT NULL
           OR v_attempt.invalidated_at IS NOT NULL
           OR v_consumed_count <> 0
           OR v_reserved_count > 1
           OR v_started_access_count <> 0
           OR v_nonterminal_access_count > 1
           OR v_result_count <> 0
           OR (v_nonterminal_access_count = 1 AND v_reserved_count <> 1) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'created internal exam attempt final state is inconsistent';
        END IF;
    ELSIF v_attempt.status = 'in_progress' THEN
        SELECT COUNT(*)
          INTO v_matching_access_count
          FROM internal_exam_accesses
         WHERE organization_id = v_attempt.organization_id
           AND internal_exam_attempt_id = v_attempt.id
           AND status = 'started'
           AND started_at = v_attempt.started_at;

        IF v_attempt.started_at IS NULL
           OR v_attempt.finished_at IS NOT NULL
           OR v_attempt.technical_aborted_at IS NOT NULL
           OR v_attempt.invalidated_at IS NOT NULL
           OR v_consumed_count <> 1
           OR v_reserved_count <> 0
           OR v_started_access_count <> 1
           OR v_matching_access_count <> 1
           OR v_result_count <> 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'in-progress internal exam attempt final state is inconsistent';
        END IF;
    ELSIF v_attempt.status IN ('passed', 'failed') THEN
        SELECT COUNT(*)
          INTO v_matching_access_count
          FROM internal_exam_accesses
         WHERE organization_id = v_attempt.organization_id
           AND internal_exam_attempt_id = v_attempt.id
           AND status = 'completed'
           AND started_at IS NOT NULL
           AND completed_at = v_attempt.finished_at;

        IF v_attempt.started_at IS NULL
           OR v_attempt.finished_at IS NULL
           OR v_attempt.technical_aborted_at IS NOT NULL
           OR v_attempt.invalidated_at IS NOT NULL
           OR v_consumed_count <> 1
           OR v_reserved_count <> 0
           OR v_started_access_count <> 1
           OR v_matching_access_count <> 1
           OR v_result_count <> 1
           OR v_result_passed IS DISTINCT FROM (v_attempt.status = 'passed') THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'finished scored internal exam attempt final state is inconsistent';
        END IF;
    ELSIF v_attempt.status = 'technical_abort' THEN
        SELECT COUNT(*)
          INTO v_matching_access_count
          FROM internal_exam_accesses
         WHERE organization_id = v_attempt.organization_id
           AND internal_exam_attempt_id = v_attempt.id
           AND status = 'technical_abort'
           AND started_at IS NOT NULL
           AND technical_aborted_at = v_attempt.technical_aborted_at;

        IF v_attempt.started_at IS NULL
           OR v_attempt.finished_at IS NULL
           OR v_attempt.technical_aborted_at IS NULL
           OR v_attempt.invalidated_at IS NOT NULL
           OR v_consumed_count <> 1
           OR v_reserved_count <> 0
           OR v_started_access_count <> 1
           OR v_matching_access_count <> 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'technical-abort internal exam attempt final state is inconsistent';
        END IF;
    ELSIF v_attempt.status = 'invalidated' THEN
        SELECT COUNT(*)
          INTO v_matching_access_count
          FROM internal_exam_accesses
         WHERE organization_id = v_attempt.organization_id
           AND internal_exam_attempt_id = v_attempt.id
           AND status = 'invalidated'
           AND started_at IS NOT NULL
           AND invalidated_at = v_attempt.invalidated_at;

        IF v_attempt.started_at IS NULL
           OR v_attempt.finished_at IS NULL
           OR v_attempt.invalidated_at IS NULL
           OR v_consumed_count <> 1
           OR v_reserved_count <> 0
           OR v_started_access_count <> 1
           OR v_matching_access_count <> 1
           OR v_result_count > 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'invalidated internal exam attempt final state is inconsistent';
        END IF;
    ELSE
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam attempt has unsupported status';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_attempts',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_accesses',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_reservations',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_results',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'exam_station_session_durable_history',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam station session history cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.internal_exam_attempt_id,
        NEW.internal_exam_access_id,
        NEW.exam_station_id,
        NEW.session_sequence,
        NEW.transferred_from_session_id,
        NEW.started_at,
        NEW.created_by_user_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.internal_exam_attempt_id,
        OLD.internal_exam_access_id,
        OLD.exam_station_id,
        OLD.session_sequence,
        OLD.transferred_from_session_id,
        OLD.started_at,
        OLD.created_by_user_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam station session identity and transfer linkage are immutable';
    END IF;

    IF OLD.ended_at IS NOT NULL
       AND ROW(NEW.ended_at, NEW.end_reason)
           IS DISTINCT FROM ROW(OLD.ended_at, OLD.end_reason) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam station session terminal tuple is write-once';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_station_sessions',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_station_session_transfer_chain',
                'body' => <<<'PLPGSQL'
DECLARE
    v_organization_id uuid;
    v_attempt_id uuid;
    v_count bigint;
    v_min bigint;
    v_max bigint;
    v_bad bigint;
BEGIN
    v_organization_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    v_attempt_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_attempt_id ELSE NEW.internal_exam_attempt_id END;

    SELECT COUNT(*), MIN(session_sequence), MAX(session_sequence)
      INTO v_count, v_min, v_max
      FROM internal_exam_station_sessions
     WHERE organization_id = v_organization_id
       AND internal_exam_attempt_id = v_attempt_id;

    IF v_count > 0 AND (v_min <> 1 OR v_max <> v_count) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam station session sequence must be contiguous from one';
    END IF;

    SELECT COUNT(*)
      INTO v_bad
      FROM internal_exam_station_sessions current_session
      LEFT JOIN internal_exam_station_sessions previous_session
        ON previous_session.organization_id = current_session.organization_id
       AND previous_session.internal_exam_attempt_id = current_session.internal_exam_attempt_id
       AND previous_session.session_sequence = current_session.session_sequence - 1
     WHERE current_session.organization_id = v_organization_id
       AND current_session.internal_exam_attempt_id = v_attempt_id
       AND (
           (
               current_session.session_sequence = 1
               AND current_session.transferred_from_session_id IS NOT NULL
           )
           OR (
               current_session.session_sequence > 1
               AND (
                   previous_session.id IS NULL
                   OR current_session.transferred_from_session_id IS DISTINCT FROM previous_session.id
                   OR previous_session.ended_at IS NULL
                   OR previous_session.end_reason IS DISTINCT FROM 'transferred'
                   OR current_session.started_at < previous_session.ended_at
               )
           )
       );

    IF v_bad <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam station-session transfer chain is inconsistent';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_station_sessions',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'exam_question_finished_evidence_immutable',
                'body' => <<<'PLPGSQL'
DECLARE
    v_status varchar;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam question evidence cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.internal_exam_attempt_id,
        NEW.ordinal,
        NEW."group",
        NEW.source_question_identifier,
        NEW.source_question_revision_identifier,
        NEW.question_snapshot_schema_version,
        NEW.question_snapshot,
        NEW.question_snapshot_hash,
        NEW.media_evidence_snapshot,
        NEW.max_points_snapshot,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.internal_exam_attempt_id,
        OLD.ordinal,
        OLD."group",
        OLD.source_question_identifier,
        OLD.source_question_revision_identifier,
        OLD.question_snapshot_schema_version,
        OLD.question_snapshot,
        OLD.question_snapshot_hash,
        OLD.media_evidence_snapshot,
        OLD.max_points_snapshot,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam question snapshot and ordinal are immutable';
    END IF;

    SELECT status
      INTO v_status
      FROM internal_exam_attempts
     WHERE id = OLD.internal_exam_attempt_id
       AND organization_id = OLD.organization_id;

    IF v_status IN ('passed', 'failed', 'technical_abort', 'invalidated')
       AND ROW(NEW.candidate_answer, NEW.is_correct, NEW.points_awarded, NEW.answered_at)
           IS DISTINCT FROM ROW(OLD.candidate_answer, OLD.is_correct, OLD.points_awarded, OLD.answered_at) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'finished internal exam question answer and scoring evidence are immutable';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_attempt_questions',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_result_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'internal exam result evidence is append-only';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_results',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_result_question_evidence_equivalence',
                'body' => <<<'PLPGSQL'
DECLARE
    v_attempt_id uuid;
    v_attempt internal_exam_attempts%ROWTYPE;
    v_result internal_exam_results%ROWTYPE;
    v_question_count bigint;
    v_answered_count bigint;
    v_score bigint;
    v_max_score bigint;
BEGIN
    IF TG_TABLE_NAME = 'internal_exam_attempt_questions' THEN
        v_attempt_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_attempt_id
            ELSE NEW.internal_exam_attempt_id
        END;
    ELSIF TG_TABLE_NAME = 'internal_exam_results' THEN
        v_attempt_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_attempt_id
            ELSE NEW.internal_exam_attempt_id
        END;
    ELSE
        v_attempt_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    END IF;

    SELECT *
      INTO v_attempt
      FROM internal_exam_attempts
     WHERE id = v_attempt_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_result
      FROM internal_exam_results
     WHERE organization_id = v_attempt.organization_id
       AND internal_exam_attempt_id = v_attempt.id;

    IF NOT FOUND THEN
        IF v_attempt.status IN ('passed', 'failed') THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'passed or failed internal exam attempt requires immutable result evidence';
        END IF;

        RETURN NULL;
    END IF;

    SELECT
        COUNT(*),
        COUNT(*) FILTER (
            WHERE candidate_answer IS NOT NULL
              AND is_correct IS NOT NULL
              AND points_awarded IS NOT NULL
              AND answered_at IS NOT NULL
        ),
        COALESCE(SUM(points_awarded), 0),
        COALESCE(SUM(max_points_snapshot), 0)
      INTO v_question_count, v_answered_count, v_score, v_max_score
      FROM internal_exam_attempt_questions
     WHERE organization_id = v_attempt.organization_id
       AND internal_exam_attempt_id = v_attempt.id;

    IF v_question_count > 0 THEN
        IF v_answered_count <> v_question_count
           OR v_result.score IS DISTINCT FROM v_score
           OR v_result.max_score IS DISTINCT FROM v_max_score
           OR v_result.pass_threshold_snapshot IS NULL
           OR v_result.passed IS DISTINCT FROM (v_score >= v_result.pass_threshold_snapshot)
           OR v_result.question_set_hash IS DISTINCT FROM v_attempt.question_set_hash THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'internal exam result must equal finalized question evidence and frozen question set';
        END IF;
    END IF;

    IF v_attempt.status = 'passed' AND NOT v_result.passed THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'passed internal exam attempt requires passed immutable result';
    END IF;

    IF v_attempt.status = 'failed' AND v_result.passed THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'failed internal exam attempt requires failed immutable result';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_attempt_questions',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_results',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_attempts',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'exam_document_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'internal exam canonical document evidence is append-only';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_documents',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'exam_bound_document_asset_immutable',
                'body' => <<<'PLPGSQL'
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM internal_exam_documents
         WHERE asset_id = OLD.id
    ) THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'file asset bound to internal exam canonical document cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.storage_disk,
        NEW.storage_key,
        NEW.mime_type_detected,
        NEW.size_bytes,
        NEW.sha256,
        NEW.purpose,
        NEW.status,
        NEW.ready_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.storage_disk,
        OLD.storage_key,
        OLD.mime_type_detected,
        OLD.size_bytes,
        OLD.sha256,
        OLD.purpose,
        OLD.status,
        OLD.ready_at
    ) OR NEW.deleted_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'file asset bound to internal exam canonical document is immutable';
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
                'name' => 'exam_document_evidence_asset_hash',
                'body' => <<<'PLPGSQL'
DECLARE
    v_document_id uuid;
    v_document internal_exam_documents%ROWTYPE;
    v_result internal_exam_results%ROWTYPE;
    v_template internal_exam_document_templates%ROWTYPE;
    v_asset file_assets%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'internal_exam_documents' THEN
        v_document_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME = 'file_assets' THEN
        SELECT id
          INTO v_document_id
          FROM internal_exam_documents
         WHERE asset_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END
         LIMIT 1;
    ELSIF TG_TABLE_NAME = 'internal_exam_results' THEN
        SELECT id
          INTO v_document_id
          FROM internal_exam_documents
         WHERE organization_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END
           AND internal_exam_attempt_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.internal_exam_attempt_id ELSE NEW.internal_exam_attempt_id END
         LIMIT 1;
    ELSE
        SELECT id
          INTO v_document_id
          FROM internal_exam_documents
         WHERE internal_exam_document_template_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END
         LIMIT 1;
    END IF;

    IF v_document_id IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_document
      FROM internal_exam_documents
     WHERE id = v_document_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_result
      FROM internal_exam_results
     WHERE organization_id = v_document.organization_id
       AND internal_exam_attempt_id = v_document.internal_exam_attempt_id;

    SELECT *
      INTO v_template
      FROM internal_exam_document_templates
     WHERE id = v_document.internal_exam_document_template_id;

    SELECT *
      INTO v_asset
      FROM file_assets
     WHERE id = v_document.asset_id
       AND organization_id = v_document.organization_id;

    IF v_result.id IS NULL
       OR v_template.id IS NULL
       OR v_asset.id IS NULL
       OR v_document.evidence_bundle_hash IS DISTINCT FROM v_result.evidence_bundle_hash
       OR v_document.template_version_snapshot IS DISTINCT FROM v_template.template_version
       OR v_document.renderer_version_snapshot IS DISTINCT FROM v_template.renderer_version
       OR v_document.template_hash_snapshot IS DISTINCT FROM v_template.template_content_hash
       OR v_asset.status IS DISTINCT FROM 'ready'
       OR v_asset.deleted_at IS NOT NULL
       OR v_asset.purpose IS DISTINCT FROM 'internal_exam_answer_sheet'
       OR v_asset.sha256 IS DISTINCT FROM v_document.content_hash THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam document must match immutable result, template and canonical file asset hash';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_documents',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'file_assets',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_results',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_document_templates',
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
