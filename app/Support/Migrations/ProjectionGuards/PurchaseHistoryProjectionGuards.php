<?php

namespace App\Support\Migrations\ProjectionGuards;

use App\Support\Migrations\TriggerWriteFence;

final class PurchaseHistoryProjectionGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-PRJ-PURCHASE-HISTORY', [
            [
                'name' => 'projection_purchase_history_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_organization_id uuid;
    v_order_id uuid;
    v_order orders%ROWTYPE;
    v_settled_at timestamptz;
    v_fulfillment_state varchar;
    v_expected_booked_at timestamptz;
BEGIN
    IF TG_TABLE_NAME = 'orders' THEN
        v_organization_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_organization_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.order_id ELSE NEW.order_id END;
    END IF;

    SELECT *
      INTO v_order
      FROM orders
     WHERE organization_id = v_organization_id
       AND id = v_order_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT settlement.settled_at
      INTO v_settled_at
      FROM order_payment_settlements settlement
     WHERE settlement.organization_id = v_organization_id
       AND settlement.order_id = v_order_id
     LIMIT 1;

    SELECT fulfillment.state
      INTO v_fulfillment_state
      FROM order_fulfillments fulfillment
     WHERE fulfillment.organization_id = v_organization_id
       AND fulfillment.order_id = v_order_id
     LIMIT 1;

    IF v_settled_at IS NOT NULL OR v_order.zero_total_settled_at IS NOT NULL THEN
        v_expected_booked_at := COALESCE(v_settled_at, v_order.zero_total_settled_at);

        IF v_order.booked_at IS DISTINCT FROM v_expected_booked_at THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'purchase history booked_at must equal the exact trusted paid resolution time';
        END IF;

        IF v_fulfillment_state IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'paid purchase history row requires durable fulfillment authority';
        END IF;

        IF v_fulfillment_state NOT IN ('pending', 'fulfilled', 'requires_reconciliation') THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'purchase history fulfillment state is outside the canonical projection';
        END IF;
    ELSE
        IF v_order.booked_at IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'unpaid or payment-pending purchase history row must not be booked';
        END IF;

        IF v_fulfillment_state IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'unpaid or payment-pending purchase history row cannot have fulfillment authority';
        END IF;
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'orders',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'payments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'order_payment_settlements',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'order_fulfillments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
        ]);
    }
}
