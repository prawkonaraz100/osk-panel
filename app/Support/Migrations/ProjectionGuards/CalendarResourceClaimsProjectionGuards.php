<?php

namespace App\Support\Migrations\ProjectionGuards;

use App\Support\Migrations\TriggerWriteFence;

final class CalendarResourceClaimsProjectionGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-PRJ-CALENDAR-RESOURCE-CLAIMS', self::definitions());
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
                'name' => 'projection_calendar_resource_claim_row_authority',
                'body' => <<<'PLPGSQL'
DECLARE
    v_match_count integer;
BEGIN
    v_match_count := 0;

    IF NEW.claim_owner_kind = 'calendar_event' THEN
        SELECT COUNT(*)
          INTO v_match_count
          FROM calendar_events event
         WHERE event.id = NEW.claim_owner_id
           AND event.organization_id = NEW.organization_id
           AND event.status = 'scheduled'
           AND event.starts_at IS NOT DISTINCT FROM NEW.starts_at
           AND event.ends_at IS NOT DISTINCT FROM NEW.ends_at
           AND (
               (NEW.student_id IS NOT NULL AND NEW.student_id = event.student_id)
               OR (NEW.instructor_id IS NOT NULL AND NEW.instructor_id = event.instructor_id)
               OR (NEW.vehicle_id IS NOT NULL AND NEW.vehicle_id = event.vehicle_id)
               OR (NEW.location_id IS NOT NULL AND NEW.location_id = event.location_id)
           );
    ELSIF NEW.claim_owner_kind = 'availability_slot_booking' THEN
        SELECT COUNT(*)
          INTO v_match_count
          FROM availability_slots slot
         WHERE slot.id = NEW.claim_owner_id
           AND slot.organization_id = NEW.organization_id
           AND slot.status = 'booked'
           AND slot.training_session_id IS NULL
           AND slot.starts_at IS NOT DISTINCT FROM NEW.starts_at
           AND slot.ends_at IS NOT DISTINCT FROM NEW.ends_at
           AND (
               (NEW.student_id IS NOT NULL AND NEW.student_id = slot.booked_student_id)
               OR (NEW.instructor_id IS NOT NULL AND NEW.instructor_id = slot.instructor_id)
               OR (NEW.vehicle_id IS NOT NULL AND NEW.vehicle_id = slot.vehicle_id)
               OR (NEW.location_id IS NOT NULL AND NEW.location_id = slot.location_id)
           );
    ELSIF NEW.claim_owner_kind = 'training_session' THEN
        SELECT COUNT(*)
          INTO v_match_count
          FROM training_sessions session
          JOIN course_enrollments course
            ON course.id = session.course_enrollment_id
           AND course.organization_id = session.organization_id
         WHERE session.id = NEW.claim_owner_id
           AND session.organization_id = NEW.organization_id
           AND session.status = 'planned'
           AND session.starts_at IS NOT DISTINCT FROM NEW.starts_at
           AND session.ends_at IS NOT DISTINCT FROM NEW.ends_at
           AND (
               (NEW.student_id IS NOT NULL AND NEW.student_id = course.student_id)
               OR (NEW.instructor_id IS NOT NULL AND NEW.instructor_id = session.instructor_id)
               OR (NEW.vehicle_id IS NOT NULL AND NEW.vehicle_id = session.vehicle_id)
               OR (NEW.location_id IS NOT NULL AND NEW.location_id = session.location_id)
           );
    END IF;

    IF v_match_count <> 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'calendar resource claim must exactly match its canonical schedule owner';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'calendar_resource_claims',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE'],
                    ],
                ],
            ],
        ];
    }
}
