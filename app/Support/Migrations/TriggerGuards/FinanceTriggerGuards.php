<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class FinanceTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-FINANCE', self::definitions());
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
                'name' => 'finance_student_charge_immutable_and_cancellation_write_once',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student_charges are durable financial history and cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.student_id,
        NEW.course_enrollment_id,
        NEW.amount_minor,
        NEW.currency
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.student_id,
        OLD.course_enrollment_id,
        OLD.amount_minor,
        OLD.currency
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student_charge financial identity is immutable';
    END IF;

    IF OLD.cancelled_at IS NOT NULL
       AND ROW(
           NEW.cancelled_at,
           NEW.cancelled_by_user_id,
           NEW.cancellation_reason
       ) IS DISTINCT FROM ROW(
           OLD.cancelled_at,
           OLD.cancelled_by_user_id,
           OLD.cancellation_reason
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student_charge cancellation tuple is write-once';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'student_charges',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'finance_student_payment_immutable_and_reversal_write_once',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student_payments are durable financial history and cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.student_id,
        NEW.charge_id,
        NEW.amount_minor,
        NEW.currency,
        NEW.paid_at,
        NEW.payment_method,
        NEW.note,
        NEW.received_by_user_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.student_id,
        OLD.charge_id,
        OLD.amount_minor,
        OLD.currency,
        OLD.paid_at,
        OLD.payment_method,
        OLD.note,
        OLD.received_by_user_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student_payment financial identity is immutable';
    END IF;

    IF OLD.reversed_at IS NOT NULL
       AND ROW(
           NEW.reversed_at,
           NEW.reversed_by_user_id,
           NEW.reversal_reason
       ) IS DISTINCT FROM ROW(
           OLD.reversed_at,
           OLD.reversed_by_user_id,
           OLD.reversal_reason
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student_payment reversal tuple is write-once';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'student_payments',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'finance_charge_payment_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_charge_id uuid;
    v_charge_amount bigint;
    v_cancelled_at timestamptz;
    v_active_paid numeric;
BEGIN
    IF TG_TABLE_NAME = 'student_charges' THEN
        IF TG_OP = 'DELETE' THEN
            v_charge_id := OLD.id;
        ELSE
            v_charge_id := NEW.id;
        END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN
            v_charge_id := OLD.charge_id;
        ELSE
            v_charge_id := NEW.charge_id;
        END IF;
    END IF;

    SELECT amount_minor, cancelled_at
      INTO v_charge_amount, v_cancelled_at
      FROM student_charges
     WHERE id = v_charge_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COALESCE(SUM(amount_minor), 0)
      INTO v_active_paid
      FROM student_payments
     WHERE charge_id = v_charge_id
       AND reversed_at IS NULL;

    IF v_active_paid > v_charge_amount THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'active student payments cannot exceed charge amount';
    END IF;

    IF v_cancelled_at IS NOT NULL AND v_active_paid <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'cancelled student charge cannot retain active payments';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'student_charges',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'student_payments',
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
