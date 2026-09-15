<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class TrainingTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-TRAINING', self::definitions());
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
                'name' => 'training_student_formal_course_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_student_id uuid;
    v_formal boolean;
    v_archived_at timestamptz;
BEGIN
    IF TG_TABLE_NAME = 'students' THEN
        IF TG_OP = 'DELETE' THEN
            v_student_id := OLD.id;
        ELSE
            v_student_id := NEW.id;
        END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN
            v_student_id := OLD.student_id;
        ELSE
            v_student_id := NEW.student_id;
        END IF;
    END IF;

    SELECT (
               (no_pesel_declared = false AND pesel_ciphertext IS NOT NULL AND pesel_lookup_hash IS NOT NULL)
               OR
               (no_pesel_declared = true AND pesel_ciphertext IS NULL AND pesel_lookup_hash IS NULL AND birth_date IS NOT NULL)
           ),
           archived_at
      INTO v_formal, v_archived_at
      FROM students
     WHERE id = v_student_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    IF EXISTS (
        SELECT 1
          FROM course_enrollments
         WHERE student_id = v_student_id
    ) AND NOT v_formal THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student with formal course history must retain formal identity';
    END IF;

    IF v_archived_at IS NOT NULL AND EXISTS (
        SELECT 1
          FROM course_enrollments
         WHERE student_id = v_student_id
           AND completed_at IS NULL
           AND interrupted_at IS NULL
           AND cancelled_at IS NULL
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'archived student cannot retain an open course enrollment';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'students',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'course_enrollments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'training_course_lifecycle_history_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course lifecycle history is append-only';
    END IF;

    IF NEW.event_type NOT IN (
        'created',
        'migration_baseline',
        'updated',
        'stage_changed',
        'completed',
        'interrupted',
        'cancelled',
        'restored',
        'closed_course_corrected',
        'lifecycle_corrected'
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course lifecycle event type is outside the closed catalog';
    END IF;

    IF NEW.course_version_before IS NULL THEN
        IF NEW.event_type NOT IN ('created', 'migration_baseline') THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'non-baseline lifecycle event requires previous course version';
        END IF;
    ELSIF NEW.course_version_after <> NEW.course_version_before + 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course lifecycle event version step must equal one';
    END IF;

    IF NEW.event_type <> 'migration_baseline' AND NEW.actor_user_id IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'runtime course lifecycle event requires actor';
    END IF;

    IF NEW.event_type IN ('interrupted', 'cancelled', 'closed_course_corrected', 'lifecycle_corrected')
       AND NULLIF(BTRIM(COALESCE(NEW.reason, '')), '') IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course lifecycle event requires reason';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'course_enrollment_lifecycle_events',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'training_course_version_history_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_course_id uuid;
    v_course_version bigint;
    v_current_event_count integer;
    v_max_history_version bigint;
BEGIN
    IF TG_TABLE_NAME = 'course_enrollments' THEN
        IF TG_OP = 'DELETE' THEN
            v_course_id := OLD.id;
        ELSE
            v_course_id := NEW.id;
        END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN
            v_course_id := OLD.course_enrollment_id;
        ELSE
            v_course_id := NEW.course_enrollment_id;
        END IF;
    END IF;

    SELECT version
      INTO v_course_version
      FROM course_enrollments
     WHERE id = v_course_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*), MAX(course_version_after)
      INTO v_current_event_count, v_max_history_version
      FROM course_enrollment_lifecycle_events
     WHERE course_enrollment_id = v_course_id
       AND course_version_after = v_course_version;

    IF v_current_event_count <> 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course current version requires exactly one matching lifecycle event';
    END IF;

    SELECT MAX(course_version_after)
      INTO v_max_history_version
      FROM course_enrollment_lifecycle_events
     WHERE course_enrollment_id = v_course_id;

    IF v_max_history_version IS DISTINCT FROM v_course_version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course version must equal latest lifecycle history version';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'course_enrollments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'course_enrollment_lifecycle_events',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'training_requirement_profile_current_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_course_id uuid;
    v_revision bigint;
    v_current_count integer;
    v_profile_revision bigint;
BEGIN
    IF TG_TABLE_NAME = 'course_enrollments' THEN
        IF TG_OP = 'DELETE' THEN
            v_course_id := OLD.id;
        ELSE
            v_course_id := NEW.id;
        END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN
            v_course_id := OLD.course_enrollment_id;
        ELSE
            v_course_id := NEW.course_enrollment_id;
        END IF;
    END IF;

    SELECT requirements_revision
      INTO v_revision
      FROM course_enrollments
     WHERE id = v_course_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*), MAX(requirements_revision)
      INTO v_current_count, v_profile_revision
      FROM training_requirement_profiles
     WHERE course_enrollment_id = v_course_id
       AND superseded_at IS NULL;

    IF v_current_count <> 1 OR v_profile_revision IS DISTINCT FROM v_revision THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course requires one current requirement profile at exact requirements revision';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'course_enrollments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'training_requirement_profiles',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'training_ledger_append_only_and_entry_shape',
                'body' => <<<'PLPGSQL'
DECLARE
    v_source training_hour_ledger_entries%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'training hour ledger is append-only';
    END IF;

    IF NEW.entry_type NOT IN ('credit', 'opening_balance', 'correction', 'reversal')
       OR NEW.training_part NOT IN ('theory', 'practical') THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'training ledger entry uses value outside closed catalog';
    END IF;

    IF NEW.entry_type = 'credit' THEN
        IF NEW.minutes <= 0 OR NEW.training_session_id IS NULL OR NEW.source_entry_id IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'credit ledger entry shape is invalid';
        END IF;
    ELSIF NEW.entry_type = 'opening_balance' THEN
        IF NEW.minutes <= 0
           OR NEW.training_session_id IS NOT NULL
           OR NEW.source_entry_id IS NOT NULL
           OR NULLIF(BTRIM(COALESCE(NEW.reason, '')), '') IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'opening balance ledger entry shape is invalid';
        END IF;
    ELSIF NEW.entry_type = 'correction' THEN
        IF NEW.minutes = 0 OR NULLIF(BTRIM(COALESCE(NEW.reason, '')), '') IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'correction ledger entry shape is invalid';
        END IF;

        IF NEW.source_entry_id IS NULL THEN
            IF NEW.training_session_id IS NOT NULL THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'unlinked correction cannot claim training session';
            END IF;
        ELSE
            SELECT *
              INTO v_source
              FROM training_hour_ledger_entries
             WHERE id = NEW.source_entry_id;

            IF NOT FOUND
               OR v_source.entry_type = 'reversal'
               OR NEW.training_session_id IS DISTINCT FROM v_source.training_session_id THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'correction source lineage is invalid';
            END IF;
        END IF;
    ELSE
        IF NEW.source_entry_id IS NULL OR NULLIF(BTRIM(COALESCE(NEW.reason, '')), '') IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'reversal ledger entry requires source and reason';
        END IF;

        SELECT *
          INTO v_source
          FROM training_hour_ledger_entries
         WHERE id = NEW.source_entry_id;

        IF NOT FOUND
           OR v_source.entry_type = 'reversal'
           OR NEW.training_session_id IS DISTINCT FROM v_source.training_session_id
           OR NEW.minutes <> -v_source.minutes THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'reversal ledger source or amount is invalid';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'training_hour_ledger_entries',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'training_attendance_terminal_immutability',
                'body' => <<<'PLPGSQL'
DECLARE
    v_session_id uuid;
    v_status varchar;
BEGIN
    IF TG_OP = 'DELETE' THEN
        v_session_id := OLD.training_session_id;
    ELSE
        v_session_id := NEW.training_session_id;
    END IF;

    SELECT status
      INTO v_status
      FROM training_sessions
     WHERE id = v_session_id;

    IF FOUND AND v_status <> 'planned' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'terminal training session attendance is immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'training_session_attendance',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'training_session_credit_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_session_id uuid;
    v_session training_sessions%ROWTYPE;
    v_attendance_count integer;
    v_attendance_status varchar;
    v_credit_count integer;
    v_bad_credit_count integer;
BEGIN
    IF TG_TABLE_NAME = 'training_sessions' THEN
        IF TG_OP = 'DELETE' THEN
            v_session_id := OLD.id;
        ELSE
            v_session_id := NEW.id;
        END IF;
    ELSIF TG_TABLE_NAME = 'training_session_attendance' THEN
        IF TG_OP = 'DELETE' THEN
            v_session_id := OLD.training_session_id;
        ELSE
            v_session_id := NEW.training_session_id;
        END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN
            v_session_id := OLD.training_session_id;
        ELSE
            v_session_id := NEW.training_session_id;
        END IF;

        IF v_session_id IS NULL THEN
            RETURN NULL;
        END IF;
    END IF;

    SELECT *
      INTO v_session
      FROM training_sessions
     WHERE id = v_session_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_credit_count
      FROM training_hour_ledger_entries
     WHERE training_session_id = v_session_id
       AND entry_type = 'credit';

    IF v_session.status IN ('planned', 'cancelled') THEN
        IF v_credit_count <> 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'planned or cancelled training session cannot have base credit';
        END IF;

        RETURN NULL;
    END IF;

    SELECT COUNT(*), MAX(status)
      INTO v_attendance_count, v_attendance_status
      FROM training_session_attendance
     WHERE training_session_id = v_session_id
       AND confirmed_at IS NOT NULL
       AND confirmed_by_user_id IS NOT NULL;

    IF v_attendance_count <> 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'completed training session requires exactly one verified attendance row';
    END IF;

    IF v_attendance_status = 'present' THEN
        IF v_credit_count <> 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'completed present training session requires exactly one base credit';
        END IF;

        SELECT COUNT(*)
          INTO v_bad_credit_count
          FROM training_hour_ledger_entries
         WHERE training_session_id = v_session_id
           AND entry_type = 'credit'
           AND (
               course_enrollment_id <> v_session.course_enrollment_id
               OR training_part <> v_session.session_type
               OR minutes <> v_session.duration_minutes
           );

        IF v_bad_credit_count <> 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'training base credit must match exact session course part and duration';
        END IF;
    ELSIF v_attendance_status = 'absent' THEN
        IF v_credit_count <> 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'completed absent training session cannot have base credit';
        END IF;
    ELSE
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'verified attendance status is outside closed catalog';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'training_sessions',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'training_session_attendance',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'training_hour_ledger_entries',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'training_external_history_write_once',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'recognized external training history cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.course_enrollment_id,
        NEW.training_part,
        NEW.recognized_minutes,
        NEW.record_role,
        NEW.source_kind,
        NEW.source_school_reference,
        NEW.evidence_reference,
        NEW.reason,
        NEW.approved_by_user_id,
        NEW.recognized_for_driving_category_id,
        NEW.recognized_for_training_type,
        NEW.supersedes_record_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.course_enrollment_id,
        OLD.training_part,
        OLD.recognized_minutes,
        OLD.record_role,
        OLD.source_kind,
        OLD.source_school_reference,
        OLD.evidence_reference,
        OLD.reason,
        OLD.approved_by_user_id,
        OLD.recognized_for_driving_category_id,
        OLD.recognized_for_training_type,
        OLD.supersedes_record_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'recognized external training business history is immutable';
    END IF;

    IF OLD.superseded_at IS NOT NULL AND NEW.superseded_at IS DISTINCT FROM OLD.superseded_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'external training supersession timestamp is write-once';
    END IF;

    IF OLD.revoked_at IS NOT NULL
       AND ROW(NEW.revoked_at, NEW.revoked_by_user_id, NEW.revocation_reason)
           IS DISTINCT FROM
           ROW(OLD.revoked_at, OLD.revoked_by_user_id, OLD.revocation_reason) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'external training revocation tuple is write-once';
    END IF;

    IF NOT (
        (NEW.revoked_at IS NULL AND NEW.revoked_by_user_id IS NULL AND NEW.revocation_reason IS NULL)
        OR
        (
            NEW.revoked_at IS NOT NULL
            AND NEW.revoked_by_user_id IS NOT NULL
            AND NULLIF(BTRIM(COALESCE(NEW.revocation_reason, '')), '') IS NOT NULL
        )
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'external training revocation tuple is incomplete';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'recognized_external_training',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'training_external_context_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_course_id uuid;
    v_bad_count integer;
BEGIN
    IF TG_TABLE_NAME = 'course_enrollments' THEN
        IF TG_OP = 'DELETE' THEN
            v_course_id := OLD.id;
        ELSE
            v_course_id := NEW.id;
        END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN
            v_course_id := OLD.course_enrollment_id;
        ELSE
            v_course_id := NEW.course_enrollment_id;
        END IF;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM course_enrollments WHERE id = v_course_id) THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_bad_count
      FROM recognized_external_training ext
      JOIN course_enrollments course ON course.id = ext.course_enrollment_id
     WHERE ext.course_enrollment_id = v_course_id
       AND ext.superseded_at IS NULL
       AND ext.revoked_at IS NULL
       AND (
           ext.recognized_for_driving_category_id IS DISTINCT FROM course.driving_category_id
           OR ext.recognized_for_training_type IS DISTINCT FROM course.training_type
       );

    IF v_bad_count <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'current external training context must match final course category and training type';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'course_enrollments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'recognized_external_training',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'training_current_pkk_profile_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_course_id uuid;
    v_current_count integer;
    v_bad_count integer;
BEGIN
    IF TG_TABLE_NAME = 'course_enrollments' THEN
        IF TG_OP = 'DELETE' THEN
            v_course_id := OLD.id;
        ELSE
            v_course_id := NEW.id;
        END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN
            v_course_id := OLD.course_enrollment_id;
        ELSE
            v_course_id := NEW.course_enrollment_id;
        END IF;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM course_enrollments WHERE id = v_course_id) THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_current_count
      FROM pkk_profiles
     WHERE course_enrollment_id = v_course_id
       AND superseded_at IS NULL;

    IF v_current_count <> 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course requires exactly one current PKK identity profile';
    END IF;

    SELECT COUNT(*)
      INTO v_bad_count
      FROM pkk_profiles profile
      JOIN course_enrollments course ON course.id = profile.course_enrollment_id
     WHERE profile.course_enrollment_id = v_course_id
       AND profile.superseded_at IS NULL
       AND (
           profile.bound_driving_category_id IS DISTINCT FROM course.driving_category_id
           OR profile.bound_training_type IS DISTINCT FROM course.training_type
           OR profile.pkk_number_ciphertext IS NULL
           OR profile.pkk_lookup_hash IS NULL
       );

    IF v_bad_count <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'current PKK identity profile must match final course context';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'course_enrollments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'pkk_profiles',
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
