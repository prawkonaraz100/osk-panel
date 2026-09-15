<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class CalendarTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-CALENDAR', self::definitions());
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
                'name' => 'calendar_event_durable_terminal_history',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'calendar event history is durable and cannot be deleted';
    END IF;

    IF OLD.status IN ('completed', 'cancelled')
       AND ROW(
           NEW.event_type,
           NEW.name,
           NEW.starts_at,
           NEW.ends_at,
           NEW.student_id,
           NEW.instructor_id,
           NEW.vehicle_id,
           NEW.location_id,
           NEW.custom_meeting_place,
           NEW.status,
           NEW.completed_at,
           NEW.completed_by_user_id,
           NEW.cancelled_at,
           NEW.cancelled_by_user_id,
           NEW.cancellation_reason
       ) IS DISTINCT FROM ROW(
           OLD.event_type,
           OLD.name,
           OLD.starts_at,
           OLD.ends_at,
           OLD.student_id,
           OLD.instructor_id,
           OLD.vehicle_id,
           OLD.location_id,
           OLD.custom_meeting_place,
           OLD.status,
           OLD.completed_at,
           OLD.completed_by_user_id,
           OLD.cancelled_at,
           OLD.cancelled_by_user_id,
           OLD.cancellation_reason
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'terminal calendar event business state is immutable';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'calendar_events',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'calendar_event_lifecycle_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'calendar event lifecycle history is append-only';
    END IF;

    IF NEW.event_type NOT IN ('created', 'updated', 'completed', 'cancelled', 'migration_baseline') THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'calendar lifecycle event type is outside the closed catalog';
    END IF;

    IF NEW.event_version_before IS NULL THEN
        IF NEW.event_type NOT IN ('created', 'migration_baseline') THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'runtime calendar lifecycle successor requires previous version';
        END IF;
    ELSIF NEW.event_version_after <> NEW.event_version_before + 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'calendar lifecycle version step must equal one';
    END IF;

    IF NEW.event_type <> 'migration_baseline' AND NEW.actor_user_id IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'runtime calendar lifecycle event requires actor';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'calendar_event_lifecycle_events',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'calendar_event_history_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_event_id uuid;
    v_version bigint;
    v_status varchar;
    v_match_count integer;
    v_latest_version bigint;
    v_latest_status varchar;
BEGIN
    IF TG_TABLE_NAME = 'calendar_events' THEN
        v_event_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_event_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.calendar_event_id ELSE NEW.calendar_event_id END;
    END IF;

    SELECT version, status
      INTO v_version, v_status
      FROM calendar_events
     WHERE id = v_event_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*), MAX(to_status)
      INTO v_match_count, v_latest_status
      FROM calendar_event_lifecycle_events
     WHERE calendar_event_id = v_event_id
       AND event_version_after = v_version;

    IF v_match_count <> 1 OR v_latest_status IS DISTINCT FROM v_status THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'calendar event version and status require one exact lifecycle row';
    END IF;

    SELECT MAX(event_version_after)
      INTO v_latest_version
      FROM calendar_event_lifecycle_events
     WHERE calendar_event_id = v_event_id;

    IF v_latest_version IS DISTINCT FROM v_version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'calendar event version must equal latest lifecycle version';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'calendar_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'calendar_event_lifecycle_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'availability_slot_durable_snapshot',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'availability slot history is durable and cannot be deleted';
    END IF;

    IF OLD.training_session_id IS NOT NULL
       AND ROW(
           NEW.instructor_id,
           NEW.vehicle_id,
           NEW.location_id,
           NEW.starts_at,
           NEW.ends_at,
           NEW.status,
           NEW.booked_student_id,
           NEW.booked_at,
           NEW.training_session_id
       ) IS DISTINCT FROM ROW(
           OLD.instructor_id,
           OLD.vehicle_id,
           OLD.location_id,
           OLD.starts_at,
           OLD.ends_at,
           OLD.status,
           OLD.booked_student_id,
           OLD.booked_at,
           OLD.training_session_id
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'formalized availability slot booking snapshot is immutable';
    END IF;

    IF OLD.status = 'cancelled'
       AND ROW(
           NEW.instructor_id,
           NEW.vehicle_id,
           NEW.location_id,
           NEW.starts_at,
           NEW.ends_at,
           NEW.status,
           NEW.booked_student_id,
           NEW.booked_at,
           NEW.training_session_id
       ) IS DISTINCT FROM ROW(
           OLD.instructor_id,
           OLD.vehicle_id,
           OLD.location_id,
           OLD.starts_at,
           OLD.ends_at,
           OLD.status,
           OLD.booked_student_id,
           OLD.booked_at,
           OLD.training_session_id
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'cancelled availability slot business state is immutable';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'availability_slots',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'availability_slot_lifecycle_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'availability slot lifecycle history is append-only';
    END IF;

    IF NEW.event_type NOT IN ('created', 'updated', 'booked', 'formalized', 'cancelled', 'migration_baseline') THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'availability lifecycle event type is outside the closed catalog';
    END IF;

    IF NEW.slot_version_before IS NULL THEN
        IF NEW.event_type NOT IN ('created', 'migration_baseline') THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'runtime availability lifecycle successor requires previous version';
        END IF;
    ELSIF NEW.slot_version_after <> NEW.slot_version_before + 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'availability lifecycle version step must equal one';
    END IF;

    IF NEW.event_type <> 'migration_baseline' AND NEW.actor_user_id IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'runtime availability lifecycle event requires actor';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'availability_slot_lifecycle_events',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'availability_slot_history_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_slot_id uuid;
    v_version bigint;
    v_status varchar;
    v_match_count integer;
    v_match_status varchar;
    v_latest_version bigint;
BEGIN
    IF TG_TABLE_NAME = 'availability_slots' THEN
        v_slot_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_slot_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.availability_slot_id ELSE NEW.availability_slot_id END;
    END IF;

    SELECT version, status
      INTO v_version, v_status
      FROM availability_slots
     WHERE id = v_slot_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*), MAX(to_status)
      INTO v_match_count, v_match_status
      FROM availability_slot_lifecycle_events
     WHERE availability_slot_id = v_slot_id
       AND slot_version_after = v_version;

    IF v_match_count <> 1 OR v_match_status IS DISTINCT FROM v_status THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'availability slot version and status require one exact lifecycle row';
    END IF;

    SELECT MAX(slot_version_after)
      INTO v_latest_version
      FROM availability_slot_lifecycle_events
     WHERE availability_slot_id = v_slot_id;

    IF v_latest_version IS DISTINCT FROM v_version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'availability slot version must equal latest lifecycle version';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'availability_slots',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'availability_slot_lifecycle_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'calendar_schedule_claim_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_owner_kind varchar;
    v_owner_id uuid;
    v_org_id uuid;
    v_expected integer;
    v_actual integer;
    v_invalid integer;
    v_event calendar_events%ROWTYPE;
    v_slot availability_slots%ROWTYPE;
    v_session training_sessions%ROWTYPE;
    v_course_student_id uuid;
    v_link_ok integer;
BEGIN
    IF TG_TABLE_NAME = 'calendar_resource_claims' THEN
        IF TG_OP = 'DELETE' THEN
            v_owner_kind := OLD.claim_owner_kind;
            v_owner_id := OLD.claim_owner_id;
            v_org_id := OLD.organization_id;
        ELSE
            v_owner_kind := NEW.claim_owner_kind;
            v_owner_id := NEW.claim_owner_id;
            v_org_id := NEW.organization_id;
        END IF;
    ELSIF TG_TABLE_NAME = 'calendar_events' THEN
        v_owner_kind := 'calendar_event';
        v_owner_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
        v_org_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    ELSIF TG_TABLE_NAME = 'availability_slots' THEN
        v_owner_kind := 'availability_slot_booking';
        v_owner_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
        v_org_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    ELSE
        v_owner_kind := 'training_session';
        v_owner_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
        v_org_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    END IF;

    IF v_owner_kind = 'calendar_event' THEN
        SELECT * INTO v_event
          FROM calendar_events
         WHERE id = v_owner_id
           AND organization_id = v_org_id;

        IF NOT FOUND THEN
            IF TG_TABLE_NAME = 'calendar_resource_claims' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'calendar-event claim owner must resolve in the same organization';
            END IF;
            RETURN NULL;
        END IF;

        SELECT COUNT(*) INTO v_actual
          FROM calendar_resource_claims
         WHERE organization_id = v_event.organization_id
           AND claim_owner_kind = 'calendar_event'
           AND claim_owner_id = v_event.id;

        IF v_event.status <> 'scheduled' THEN
            IF v_actual <> 0 THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'terminal calendar event must have zero resource claims';
            END IF;
            RETURN NULL;
        END IF;

        v_expected :=
            (CASE WHEN v_event.student_id IS NULL THEN 0 ELSE 1 END)
            + (CASE WHEN v_event.instructor_id IS NULL THEN 0 ELSE 1 END)
            + (CASE WHEN v_event.vehicle_id IS NULL THEN 0 ELSE 1 END)
            + (CASE WHEN v_event.location_id IS NULL THEN 0 ELSE 1 END);

        SELECT COUNT(*) INTO v_invalid
          FROM calendar_resource_claims claim
         WHERE claim.organization_id = v_event.organization_id
           AND claim.claim_owner_kind = 'calendar_event'
           AND claim.claim_owner_id = v_event.id
           AND (
               claim.starts_at IS DISTINCT FROM v_event.starts_at
               OR claim.ends_at IS DISTINCT FROM v_event.ends_at
               OR NOT (
                   (claim.student_id IS NOT NULL AND claim.student_id = v_event.student_id)
                   OR (claim.instructor_id IS NOT NULL AND claim.instructor_id = v_event.instructor_id)
                   OR (claim.vehicle_id IS NOT NULL AND claim.vehicle_id = v_event.vehicle_id)
                   OR (claim.location_id IS NOT NULL AND claim.location_id = v_event.location_id)
               )
           );

        IF v_actual <> v_expected OR v_invalid <> 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'scheduled calendar event requires exact final resource claim set';
        END IF;

        RETURN NULL;
    END IF;

    IF v_owner_kind = 'availability_slot_booking' THEN
        SELECT * INTO v_slot
          FROM availability_slots
         WHERE id = v_owner_id
           AND organization_id = v_org_id;

        IF NOT FOUND THEN
            IF TG_TABLE_NAME = 'calendar_resource_claims' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'availability booking claim owner must resolve in the same organization';
            END IF;
            RETURN NULL;
        END IF;

        SELECT COUNT(*) INTO v_actual
          FROM calendar_resource_claims
         WHERE organization_id = v_slot.organization_id
           AND claim_owner_kind = 'availability_slot_booking'
           AND claim_owner_id = v_slot.id;

        IF v_slot.status = 'booked' AND v_slot.training_session_id IS NULL THEN
            v_expected := 1
                + (CASE WHEN v_slot.instructor_id IS NULL THEN 0 ELSE 1 END)
                + (CASE WHEN v_slot.vehicle_id IS NULL THEN 0 ELSE 1 END)
                + (CASE WHEN v_slot.location_id IS NULL THEN 0 ELSE 1 END);

            SELECT COUNT(*) INTO v_invalid
              FROM calendar_resource_claims claim
             WHERE claim.organization_id = v_slot.organization_id
               AND claim.claim_owner_kind = 'availability_slot_booking'
               AND claim.claim_owner_id = v_slot.id
               AND (
                   claim.starts_at IS DISTINCT FROM v_slot.starts_at
                   OR claim.ends_at IS DISTINCT FROM v_slot.ends_at
                   OR NOT (
                       (claim.student_id IS NOT NULL AND claim.student_id = v_slot.booked_student_id)
                       OR (claim.instructor_id IS NOT NULL AND claim.instructor_id = v_slot.instructor_id)
                       OR (claim.vehicle_id IS NOT NULL AND claim.vehicle_id = v_slot.vehicle_id)
                       OR (claim.location_id IS NOT NULL AND claim.location_id = v_slot.location_id)
                   )
               );

            IF v_actual <> v_expected OR v_invalid <> 0 THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'booked availability slot requires exact booking claim set';
            END IF;
        ELSE
            IF v_actual <> 0 THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'available cancelled or formalized slot must have zero booking claims';
            END IF;
        END IF;

        IF v_slot.training_session_id IS NOT NULL THEN
            SELECT COUNT(*)
              INTO v_link_ok
              FROM training_sessions session
              JOIN course_enrollments course ON course.id = session.course_enrollment_id
             WHERE session.id = v_slot.training_session_id
               AND session.organization_id = v_slot.organization_id
               AND session.session_type = 'practical'
               AND course.student_id = v_slot.booked_student_id;

            IF v_slot.status <> 'booked' OR v_link_ok <> 1 THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'formalized slot must link the booked student to one practical training session';
            END IF;
        END IF;

        RETURN NULL;
    END IF;

    IF v_owner_kind = 'training_session' THEN
        SELECT * INTO v_session
          FROM training_sessions
         WHERE id = v_owner_id
           AND organization_id = v_org_id;

        IF NOT FOUND THEN
            IF TG_TABLE_NAME = 'calendar_resource_claims' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'training-session claim owner must resolve in the same organization';
            END IF;
            RETURN NULL;
        END IF;

        SELECT student_id
          INTO v_course_student_id
          FROM course_enrollments
         WHERE id = v_session.course_enrollment_id
           AND organization_id = v_session.organization_id;

        SELECT COUNT(*) INTO v_actual
          FROM calendar_resource_claims
         WHERE organization_id = v_session.organization_id
           AND claim_owner_kind = 'training_session'
           AND claim_owner_id = v_session.id;

        IF v_session.status <> 'planned' THEN
            IF v_actual <> 0 THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'terminal training session must have zero schedule claims';
            END IF;
            RETURN NULL;
        END IF;

        v_expected := 2
            + (CASE WHEN v_session.vehicle_id IS NULL THEN 0 ELSE 1 END)
            + (CASE WHEN v_session.location_id IS NULL THEN 0 ELSE 1 END);

        SELECT COUNT(*) INTO v_invalid
          FROM calendar_resource_claims claim
         WHERE claim.organization_id = v_session.organization_id
           AND claim.claim_owner_kind = 'training_session'
           AND claim.claim_owner_id = v_session.id
           AND (
               claim.starts_at IS DISTINCT FROM v_session.starts_at
               OR claim.ends_at IS DISTINCT FROM v_session.ends_at
               OR NOT (
                   (claim.student_id IS NOT NULL AND claim.student_id = v_course_student_id)
                   OR (claim.instructor_id IS NOT NULL AND claim.instructor_id = v_session.instructor_id)
                   OR (claim.vehicle_id IS NOT NULL AND claim.vehicle_id = v_session.vehicle_id)
                   OR (claim.location_id IS NOT NULL AND claim.location_id = v_session.location_id)
               )
           );

        IF v_actual <> v_expected OR v_invalid <> 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'planned training session requires exact final schedule claim set';
        END IF;

        RETURN NULL;
    END IF;

    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'unknown calendar resource claim owner kind';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'calendar_resource_claims',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'calendar_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'availability_slots',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'training_sessions',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'calendar_training_session_details_terminal_write',
                'body' => <<<'PLPGSQL'
DECLARE
    v_session_id uuid;
    v_status varchar;
BEGIN
    v_session_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.training_session_id ELSE NEW.training_session_id END;

    SELECT status INTO v_status
      FROM training_sessions
     WHERE id = v_session_id;

    IF FOUND AND v_status <> 'planned' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'terminal training session calendar metadata is immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'training_session_calendar_details',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'calendar_training_session_details_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_session_id uuid;
    v_bad integer;
BEGIN
    IF TG_TABLE_NAME = 'training_session_calendar_details' THEN
        v_session_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.training_session_id ELSE NEW.training_session_id END;
    ELSE
        v_session_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    END IF;

    SELECT COUNT(*)
      INTO v_bad
      FROM training_session_calendar_details detail
      JOIN training_sessions session ON session.id = detail.training_session_id
     WHERE detail.training_session_id = v_session_id
       AND (
           session.session_type <> 'practical'
           OR (
               session.location_id IS NOT NULL
               AND NULLIF(BTRIM(COALESCE(detail.custom_meeting_place, '')), '') IS NOT NULL
           )
           OR (
               detail.custom_meeting_place IS NOT NULL
               AND NULLIF(BTRIM(detail.custom_meeting_place), '') IS NULL
           )
       );

    IF v_bad <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'training session calendar details violate practical or meeting-place final state';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'training_session_calendar_details',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'training_sessions',
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
