<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class CommerceTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-COMMERCE', self::definitions());
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
                'name' => 'commerce_catalog_identity_guard',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (
            SELECT 1
              FROM order_items
             WHERE commerce_catalog_item_id = OLD.id
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'referenced commerce catalog identity cannot be deleted';
        END IF;

        RETURN OLD;
    END IF;

    IF NEW.product_kind = 'license' AND NEW.license_product_id IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license catalog item requires exact license product';
    END IF;

    IF NEW.product_kind <> 'license' AND NEW.license_product_id IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'nonlicense catalog item cannot bind a license product';
    END IF;

    IF TG_OP = 'UPDATE'
       AND ROW(NEW.code, NEW.product_kind, NEW.license_product_id)
           IS DISTINCT FROM ROW(OLD.code, OLD.product_kind, OLD.license_product_id)
       AND EXISTS (
           SELECT 1
             FROM order_items
            WHERE commerce_catalog_item_id = OLD.id
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'commerce catalog identity is immutable after first order reference';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'commerce_catalog_items',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_order_durable_snapshot',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'commerce order history cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.order_sequence,
        NEW.ordered_at,
        NEW.currency,
        NEW.total_amount_minor,
        NEW.created_by_user_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.order_sequence,
        OLD.ordered_at,
        OLD.currency,
        OLD.total_amount_minor,
        OLD.created_by_user_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'commerce order identity, sequence and payable snapshot are immutable';
    END IF;

    IF OLD.booked_at IS NOT NULL AND NEW.booked_at IS DISTINCT FROM OLD.booked_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'commerce order booked_at is write-once';
    END IF;

    IF OLD.zero_total_settled_at IS NOT NULL
       AND NEW.zero_total_settled_at IS DISTINCT FROM OLD.zero_total_settled_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'zero-total settlement timestamp is write-once';
    END IF;

    IF NEW.zero_total_settled_at IS NOT NULL AND NEW.total_amount_minor <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'only an exact zero-total order may use zero-total settlement';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'orders',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_order_item_immutable_snapshot',
                'body' => <<<'PLPGSQL'
DECLARE
    v_catalog_kind varchar;
    v_catalog_license_product_id uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'commerce order item product and pricing snapshot is append-only';
    END IF;

    IF NEW.quantity < 1
       OR NEW.total_amount_minor <> NEW.quantity * NEW.unit_amount_minor
       OR NEW.list_unit_amount_minor < 0
       OR NEW.unit_amount_minor < 0
       OR NEW.unit_discount_amount_minor < 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'commerce order item quantity or money snapshot is invalid';
    END IF;

    SELECT product_kind, license_product_id
      INTO v_catalog_kind, v_catalog_license_product_id
      FROM commerce_catalog_items
     WHERE id = NEW.commerce_catalog_item_id;

    IF NOT FOUND
       OR NEW.product_kind IS DISTINCT FROM v_catalog_kind
       OR (
           NEW.product_kind = 'license'
           AND NEW.license_product_id IS DISTINCT FROM v_catalog_license_product_id
       )
       OR (
           NEW.product_kind <> 'license'
           AND NEW.license_product_id IS NOT NULL
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'commerce order item must preserve exact catalog product-kind lineage';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'order_items',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_order_total_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_order_id uuid;
    v_order orders%ROWTYPE;
    v_count bigint;
    v_sum bigint;
    v_wrong_currency bigint;
BEGIN
    IF TG_TABLE_NAME = 'orders' THEN
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.order_id ELSE NEW.order_id END;
    END IF;

    SELECT *
      INTO v_order
      FROM orders
     WHERE id = v_order_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT
        COUNT(*),
        COALESCE(SUM(total_amount_minor), 0),
        COUNT(*) FILTER (
            WHERE organization_id <> v_order.organization_id
               OR currency <> v_order.currency
        )
      INTO v_count, v_sum, v_wrong_currency
      FROM order_items
     WHERE order_id = v_order.id;

    IF v_count < 1
       OR v_sum IS DISTINCT FROM v_order.total_amount_minor
       OR v_wrong_currency <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'commerce order total and currency must equal exact immutable line set';
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
                        'table' => 'order_items',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'commerce_payment_durable_transition',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'platform payment attempt history cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.order_id,
        NEW.provider,
        NEW.public_payment_reference,
        NEW.amount_minor,
        NEW.currency,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.order_id,
        OLD.provider,
        OLD.public_payment_reference,
        OLD.amount_minor,
        OLD.currency,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'platform payment order, provider and payable snapshot are immutable';
    END IF;

    IF OLD.provider_payment_id IS NOT NULL
       AND NEW.provider_payment_id IS DISTINCT FROM OLD.provider_payment_id THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'provider payment id is immutable once captured';
    END IF;

    IF OLD.confirmed_at IS NOT NULL AND NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'payment confirmed_at is write-once';
    END IF;

    IF OLD.failed_at IS NOT NULL AND NEW.failed_at IS DISTINCT FROM OLD.failed_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'payment failed_at is write-once';
    END IF;

    IF OLD.status = 'pending' THEN
        IF NEW.status NOT IN ('pending', 'confirmed', 'failed') THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'payment pending transition is invalid';
        END IF;
    ELSIF NEW.status IS DISTINCT FROM OLD.status THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'terminal payment status cannot be rewritten';
    END IF;

    IF (NEW.status = 'pending' AND (NEW.confirmed_at IS NOT NULL OR NEW.failed_at IS NOT NULL))
       OR (NEW.status = 'confirmed' AND (NEW.confirmed_at IS NULL OR NEW.failed_at IS NOT NULL))
       OR (NEW.status = 'failed' AND (NEW.failed_at IS NULL OR NEW.confirmed_at IS NOT NULL)) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'payment status and terminal timestamp matrix is inconsistent';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'payments',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_payment_event_durable_identity',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'payment provider event history cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.payment_id,
        NEW.provider,
        NEW.provider_payment_id,
        NEW.provider_event_id,
        NEW.event_type,
        NEW.normalized_outcome,
        NEW.payload_hash,
        NEW.received_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.payment_id,
        OLD.provider,
        OLD.provider_payment_id,
        OLD.provider_event_id,
        OLD.event_type,
        OLD.normalized_outcome,
        OLD.payload_hash,
        OLD.received_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'payment event raw identity, normalized outcome and payload hash are immutable';
    END IF;

    IF OLD.processed_at IS NOT NULL
       AND ROW(NEW.processed_at, NEW.application_result)
           IS DISTINCT FROM ROW(OLD.processed_at, OLD.application_result) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'payment event processing result is write-once';
    END IF;

    IF OLD.processed_at IS NULL
       AND NEW.processed_at IS NULL
       AND NEW.application_result IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'payment event application result requires processed_at';
    END IF;

    IF NEW.application_result IS NOT NULL
       AND NEW.application_result NOT IN (
           'state_applied',
           'no_change_duplicate_or_stale',
           'conflict_requires_reconciliation'
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'payment event application result is invalid';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'payment_events',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_settlement_durable',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'order payment settlement is immutable business authority';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'order_payment_settlements',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_settlement_and_booking_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_order_id uuid;
    v_order orders%ROWTYPE;
    v_settlement order_payment_settlements%ROWTYPE;
    v_payment payments%ROWTYPE;
    v_event payment_events%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'orders' THEN
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME = 'payments' THEN
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.order_id ELSE NEW.order_id END;
    ELSIF TG_TABLE_NAME = 'order_payment_settlements' THEN
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.order_id ELSE NEW.order_id END;
    ELSE
        SELECT order_id
          INTO v_order_id
          FROM payments
         WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.payment_id ELSE NEW.payment_id END;
    END IF;

    IF v_order_id IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_order
      FROM orders
     WHERE id = v_order_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_settlement
      FROM order_payment_settlements
     WHERE organization_id = v_order.organization_id
       AND order_id = v_order.id;

    IF FOUND THEN
        IF v_order.total_amount_minor <= 0 OR v_order.zero_total_settled_at IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'nonzero payment settlement cannot coexist with zero-total settlement';
        END IF;

        SELECT *
          INTO v_payment
          FROM payments
         WHERE id = v_settlement.payment_id
           AND organization_id = v_order.organization_id
           AND order_id = v_order.id;

        IF NOT FOUND
           OR v_payment.status <> 'confirmed'
           OR v_payment.confirmed_at IS NULL
           OR v_payment.amount_minor IS DISTINCT FROM v_order.total_amount_minor
           OR v_payment.currency IS DISTINCT FROM v_order.currency THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'order settlement requires exact confirmed full-order payment';
        END IF;

        IF v_settlement.confirmation_source = 'provider_event' THEN
            IF v_settlement.source_payment_event_id IS NULL
               OR v_settlement.reconciled_by_user_id IS NOT NULL
               OR v_settlement.reconciliation_reason IS NOT NULL THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'provider-event settlement source tuple is invalid';
            END IF;

            SELECT *
              INTO v_event
              FROM payment_events
             WHERE id = v_settlement.source_payment_event_id
               AND organization_id = v_order.organization_id
               AND payment_id = v_settlement.payment_id;

            IF NOT FOUND OR v_event.normalized_outcome <> 'confirmed' THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'provider-event settlement requires exact trusted confirmed payment event';
            END IF;
        ELSIF v_settlement.confirmation_source = 'reconciliation' THEN
            IF v_settlement.source_payment_event_id IS NOT NULL
               OR v_settlement.reconciled_by_user_id IS NULL
               OR NULLIF(BTRIM(v_settlement.reconciliation_reason), '') IS NULL THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'reconciliation settlement source tuple is invalid';
            END IF;
        ELSE
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'settlement confirmation source is invalid';
        END IF;

        IF v_order.booked_at IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'settled commerce order requires booked_at';
        END IF;
    ELSIF v_order.total_amount_minor > 0 AND v_order.booked_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'nonzero booked order requires exact payment settlement';
    END IF;

    IF v_order.zero_total_settled_at IS NOT NULL THEN
        IF v_order.total_amount_minor <> 0
           OR v_order.booked_at IS NULL
           OR EXISTS (
               SELECT 1
                 FROM order_payment_settlements
                WHERE organization_id = v_order.organization_id
                  AND order_id = v_order.id
           ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'zero-total settled order final state is inconsistent';
        END IF;
    ELSIF v_order.total_amount_minor = 0 AND v_order.booked_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'zero-total booked order requires zero-total settlement timestamp';
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
                        'table' => 'payment_events',
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
                ],
            ],
            [
                'name' => 'commerce_fulfillment_durable_transition',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'order fulfillment history cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.order_id,
        NEW.source_kind,
        NEW.settlement_payment_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.order_id,
        OLD.source_kind,
        OLD.settlement_payment_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'order fulfillment source identity is immutable';
    END IF;

    IF OLD.state = 'fulfilled'
       AND ROW(NEW.state, NEW.fulfilled_at)
           IS DISTINCT FROM ROW(OLD.state, OLD.fulfilled_at) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'fulfilled order is write-once business completion';
    END IF;

    IF OLD.state = 'pending'
       AND NEW.state NOT IN ('pending', 'fulfilled', 'requires_reconciliation') THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'order fulfillment transition is invalid';
    END IF;

    IF NEW.state = 'pending' THEN
        IF NEW.fulfilled_at IS NOT NULL
           OR NEW.requires_reconciliation_at IS NOT NULL
           OR NEW.reconciliation_reason IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'pending fulfillment cannot carry terminal metadata';
        END IF;
    ELSIF NEW.state = 'fulfilled' THEN
        IF NEW.fulfilled_at IS NULL
           OR NEW.requires_reconciliation_at IS NOT NULL
           OR NEW.reconciliation_reason IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'fulfilled order fulfillment terminal tuple is invalid';
        END IF;
    ELSIF NEW.state = 'requires_reconciliation' THEN
        IF NEW.fulfilled_at IS NOT NULL
           OR NEW.requires_reconciliation_at IS NULL
           OR NULLIF(BTRIM(NEW.reconciliation_reason), '') IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'reconciliation fulfillment terminal tuple is invalid';
        END IF;
    ELSE
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'order fulfillment state is invalid';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'order_fulfillments',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_license_purchase_lineage',
                'body' => <<<'PLPGSQL'
DECLARE
    v_item order_items%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE'
       AND ROW(NEW.source_order_item_id, NEW.source_order_item_grant_ordinal)
           IS DISTINCT FROM ROW(OLD.source_order_item_id, OLD.source_order_item_grant_ordinal) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license purchase lineage is immutable';
    END IF;

    IF NEW.source_order_item_id IS NULL THEN
        IF NEW.source_order_item_grant_ordinal IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'license purchase grant ordinal requires source order item';
        END IF;

        RETURN NEW;
    END IF;

    SELECT *
      INTO v_item
      FROM order_items
     WHERE id = NEW.source_order_item_id
       AND organization_id = NEW.organization_id;

    IF NOT FOUND
       OR v_item.product_kind <> 'license'
       OR NEW.license_product_id IS DISTINCT FROM v_item.license_product_id
       OR NEW.source_order_item_grant_ordinal IS NULL
       OR NEW.source_order_item_grant_ordinal < 1
       OR NEW.source_order_item_grant_ordinal > v_item.quantity THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license inventory purchase lineage does not match exact order item';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_inventory_entries',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_exam_purchase_lineage',
                'body' => <<<'PLPGSQL'
DECLARE
    v_item order_items%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE'
       AND ROW(NEW.source_order_item_id, NEW.source_order_item_grant_ordinal)
           IS DISTINCT FROM ROW(OLD.source_order_item_id, OLD.source_order_item_grant_ordinal) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam purchase lineage is immutable';
    END IF;

    IF NEW.source_order_item_id IS NULL THEN
        IF NEW.source_order_item_grant_ordinal IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'exam purchase grant ordinal requires source order item';
        END IF;

        RETURN NEW;
    END IF;

    SELECT *
      INTO v_item
      FROM order_items
     WHERE id = NEW.source_order_item_id
       AND organization_id = NEW.organization_id;

    IF NOT FOUND
       OR v_item.product_kind <> 'internal_exam'
       OR NEW.source_type <> 'paid'
       OR NEW.source_order_item_grant_ordinal IS NULL
       OR NEW.source_order_item_grant_ordinal < 1
       OR NEW.source_order_item_grant_ordinal > v_item.quantity THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'internal exam purchase lineage does not match exact order item';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'internal_exam_inventory_entries',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_service_entitlement_durable_source',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'service entitlement business grant cannot be deleted';
    END IF;

    IF TG_OP = 'UPDATE'
       AND ROW(
           NEW.organization_id,
           NEW.service_type,
           NEW.activation_mode,
           NEW.source_order_item_id,
           NEW.source_order_item_grant_ordinal,
           NEW.source_grant_reference,
           NEW.created_at
       ) IS DISTINCT FROM ROW(
           OLD.organization_id,
           OLD.service_type,
           OLD.activation_mode,
           OLD.source_order_item_id,
           OLD.source_order_item_grant_ordinal,
           OLD.source_grant_reference,
           OLD.created_at
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'service entitlement source and product snapshot are immutable';
    END IF;

    IF NOT (
        (
            NEW.source_order_item_id IS NOT NULL
            AND NEW.source_order_item_grant_ordinal IS NOT NULL
            AND NEW.source_grant_reference IS NULL
        )
        OR
        (
            NEW.source_order_item_id IS NULL
            AND NEW.source_order_item_grant_ordinal IS NULL
            AND NULLIF(BTRIM(NEW.source_grant_reference), '') IS NOT NULL
        )
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'service entitlement requires exact purchase XOR operator-grant source';
    END IF;

    IF NEW.activation_mode NOT IN ('immediate', 'explicit')
       OR NULLIF(BTRIM(NEW.service_type), '') IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'service entitlement product snapshot is invalid';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.status IN ('expired', 'revoked')
           AND NEW.status IS DISTINCT FROM OLD.status THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'terminal service entitlement status cannot be reopened';
        END IF;

        IF OLD.expires_at IS NOT NULL AND NEW.expires_at IS DISTINCT FROM OLD.expires_at THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'service entitlement expires_at is write-once';
        END IF;
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'service_entitlements',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_service_activation_append_only',
                'body' => <<<'PLPGSQL'
DECLARE
    v_status varchar;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'service activation evidence is append-only';
    END IF;

    SELECT status
      INTO v_status
      FROM service_entitlements
     WHERE id = NEW.service_entitlement_id
       AND organization_id = NEW.organization_id;

    IF NOT FOUND THEN
        RETURN NEW;
    END IF;

    IF v_status IN ('expired', 'revoked') THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'terminal service entitlement cannot receive a new activation';
    END IF;

    IF NEW.effective_to IS NOT NULL AND NEW.effective_to < NEW.effective_from THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'service activation effective interval is invalid';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'service_activations',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_service_entitlement_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_entitlement_id uuid;
    v_entitlement service_entitlements%ROWTYPE;
    v_activation_count bigint;
    v_item order_items%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'service_entitlements' THEN
        v_entitlement_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_entitlement_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.service_entitlement_id
            ELSE NEW.service_entitlement_id
        END;
    END IF;

    SELECT *
      INTO v_entitlement
      FROM service_entitlements
     WHERE id = v_entitlement_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    IF v_entitlement.source_order_item_id IS NOT NULL THEN
        SELECT *
          INTO v_item
          FROM order_items
         WHERE id = v_entitlement.source_order_item_id
           AND organization_id = v_entitlement.organization_id;

        IF NOT FOUND
           OR v_item.product_kind <> 'generic_service'
           OR v_entitlement.source_order_item_grant_ordinal < 1
           OR v_entitlement.source_order_item_grant_ordinal > v_item.quantity
           OR v_entitlement.service_type IS DISTINCT FROM (v_item.product_snapshot ->> 'service_type')
           OR v_entitlement.activation_mode IS DISTINCT FROM (v_item.product_snapshot ->> 'activation_mode') THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'purchase service entitlement must equal immutable order-item service snapshot';
        END IF;
    END IF;

    SELECT COUNT(*)
      INTO v_activation_count
      FROM service_activations
     WHERE organization_id = v_entitlement.organization_id
       AND service_entitlement_id = v_entitlement.id;

    IF v_entitlement.status = 'granted' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'transitional granted service entitlement cannot survive transaction boundary';
    ELSIF v_entitlement.status = 'available' THEN
        IF v_entitlement.activation_mode <> 'explicit' OR v_activation_count <> 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'available service entitlement requires explicit mode and zero activations';
        END IF;
    ELSIF v_entitlement.status = 'activated' THEN
        IF v_activation_count <> 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'activated service entitlement requires exactly one activation';
        END IF;
    ELSIF v_entitlement.status IN ('expired', 'revoked') THEN
        IF v_entitlement.status = 'expired' AND v_entitlement.expires_at IS NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'expired service entitlement requires expires_at';
        END IF;

        IF v_entitlement.activation_mode = 'immediate' AND v_activation_count <> 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'terminal immediate service entitlement retains exact activation evidence';
        END IF;

        IF v_entitlement.activation_mode = 'explicit' AND v_activation_count > 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'explicit service entitlement cannot have duplicate activation evidence';
        END IF;
    ELSE
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'service entitlement status is invalid';
    END IF;

    IF v_entitlement.activation_mode = 'immediate'
       AND v_entitlement.status = 'available' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'immediate service entitlement cannot remain available';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'service_entitlements',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'service_activations',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'commerce_fulfillment_source_and_grants_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_order_id uuid;
    v_fulfillment order_fulfillments%ROWTYPE;
    v_order orders%ROWTYPE;
    v_item record;
    v_count bigint;
    v_min integer;
    v_max integer;
BEGIN
    IF TG_TABLE_NAME = 'order_fulfillments' THEN
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.order_id ELSE NEW.order_id END;
    ELSIF TG_TABLE_NAME = 'orders' THEN
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME = 'order_items' THEN
        v_order_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.order_id ELSE NEW.order_id END;
    ELSE
        SELECT order_id
          INTO v_order_id
          FROM order_items
         WHERE id = CASE
             WHEN TG_TABLE_NAME = 'license_inventory_entries' THEN
                 CASE WHEN TG_OP = 'DELETE' THEN OLD.source_order_item_id ELSE NEW.source_order_item_id END
             WHEN TG_TABLE_NAME = 'internal_exam_inventory_entries' THEN
                 CASE WHEN TG_OP = 'DELETE' THEN OLD.source_order_item_id ELSE NEW.source_order_item_id END
             ELSE
                 CASE WHEN TG_OP = 'DELETE' THEN OLD.source_order_item_id ELSE NEW.source_order_item_id END
         END;
    END IF;

    IF v_order_id IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_order
      FROM orders
     WHERE id = v_order_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_fulfillment
      FROM order_fulfillments
     WHERE organization_id = v_order.organization_id
       AND order_id = v_order.id;

    IF NOT FOUND THEN
        IF EXISTS (
            SELECT 1
              FROM license_inventory_entries inventory
              JOIN order_items item ON item.id = inventory.source_order_item_id
             WHERE item.order_id = v_order.id
        ) OR EXISTS (
            SELECT 1
              FROM internal_exam_inventory_entries inventory
              JOIN order_items item ON item.id = inventory.source_order_item_id
             WHERE item.order_id = v_order.id
        ) OR EXISTS (
            SELECT 1
              FROM service_entitlements entitlement
              JOIN order_items item ON item.id = entitlement.source_order_item_id
             WHERE item.order_id = v_order.id
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'purchase grant cannot commit without order fulfillment authority';
        END IF;

        RETURN NULL;
    END IF;

    IF v_fulfillment.source_kind = 'payment_settlement' THEN
        IF v_order.total_amount_minor <= 0
           OR v_order.zero_total_settled_at IS NOT NULL
           OR v_fulfillment.settlement_payment_id IS NULL
           OR NOT EXISTS (
               SELECT 1
                 FROM order_payment_settlements settlement
                WHERE settlement.organization_id = v_order.organization_id
                  AND settlement.order_id = v_order.id
                  AND settlement.payment_id = v_fulfillment.settlement_payment_id
           ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'payment-settlement fulfillment source is inconsistent';
        END IF;
    ELSIF v_fulfillment.source_kind = 'zero_total' THEN
        IF v_order.total_amount_minor <> 0
           OR v_order.zero_total_settled_at IS NULL
           OR v_fulfillment.settlement_payment_id IS NOT NULL
           OR EXISTS (
               SELECT 1
                 FROM order_payment_settlements settlement
                WHERE settlement.organization_id = v_order.organization_id
                  AND settlement.order_id = v_order.id
           ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'zero-total fulfillment source is inconsistent';
        END IF;
    ELSE
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'order fulfillment source kind is invalid';
    END IF;

    IF v_fulfillment.state <> 'fulfilled' THEN
        IF EXISTS (
            SELECT 1
              FROM license_inventory_entries inventory
              JOIN order_items item ON item.id = inventory.source_order_item_id
             WHERE item.order_id = v_order.id
        ) OR EXISTS (
            SELECT 1
              FROM internal_exam_inventory_entries inventory
              JOIN order_items item ON item.id = inventory.source_order_item_id
             WHERE item.order_id = v_order.id
        ) OR EXISTS (
            SELECT 1
              FROM service_entitlements entitlement
              JOIN order_items item ON item.id = entitlement.source_order_item_id
             WHERE item.order_id = v_order.id
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'purchase grants may commit only with fulfilled order state';
        END IF;

        RETURN NULL;
    END IF;

    FOR v_item IN
        SELECT *
          FROM order_items
         WHERE organization_id = v_order.organization_id
           AND order_id = v_order.id
         ORDER BY id
    LOOP
        IF v_item.product_kind = 'license' THEN
            SELECT COUNT(*), MIN(source_order_item_grant_ordinal), MAX(source_order_item_grant_ordinal)
              INTO v_count, v_min, v_max
              FROM license_inventory_entries
             WHERE organization_id = v_order.organization_id
               AND source_order_item_id = v_item.id;
        ELSIF v_item.product_kind = 'internal_exam' THEN
            SELECT COUNT(*), MIN(source_order_item_grant_ordinal), MAX(source_order_item_grant_ordinal)
              INTO v_count, v_min, v_max
              FROM internal_exam_inventory_entries
             WHERE organization_id = v_order.organization_id
               AND source_order_item_id = v_item.id;
        ELSIF v_item.product_kind = 'generic_service' THEN
            SELECT COUNT(*), MIN(source_order_item_grant_ordinal), MAX(source_order_item_grant_ordinal)
              INTO v_count, v_min, v_max
              FROM service_entitlements
             WHERE organization_id = v_order.organization_id
               AND source_order_item_id = v_item.id;
        ELSE
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'fulfilled order contains unsupported product kind';
        END IF;

        IF v_count <> v_item.quantity
           OR v_min IS DISTINCT FROM 1
           OR v_max IS DISTINCT FROM v_item.quantity THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'fulfilled order item requires exact quantity and contiguous purchase grant ordinals';
        END IF;
    END LOOP;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'order_fulfillments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'orders',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'order_items',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'license_inventory_entries',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'internal_exam_inventory_entries',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'service_entitlements',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'commerce_course_cost_origin_append_only',
                'body' => <<<'PLPGSQL'
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '23514',
        MESSAGE = 'course-cost charge origin is immutable source evidence';
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'course_cost_charge_origins',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'commerce_course_cost_origin_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_origin_id uuid;
    v_origin course_cost_charge_origins%ROWTYPE;
    v_charge student_charges%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'course_cost_charge_origins' THEN
        v_origin_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        SELECT id
          INTO v_origin_id
          FROM course_cost_charge_origins
         WHERE organization_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END
           AND student_charge_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END
         LIMIT 1;
    END IF;

    IF v_origin_id IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_origin
      FROM course_cost_charge_origins
     WHERE id = v_origin_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_charge
      FROM student_charges
     WHERE id = v_origin.student_charge_id
       AND organization_id = v_origin.organization_id
       AND student_id = v_origin.student_id
       AND currency = v_origin.source_currency;

    IF NOT FOUND
       OR v_charge.amount_minor IS DISTINCT FROM v_origin.source_amount_minor THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course-cost origin snapshot must equal exact created student charge amount and currency';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM course_enrollments course
         WHERE course.id = v_origin.course_enrollment_id
           AND course.organization_id = v_origin.organization_id
           AND course.student_id = v_origin.student_id
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'course-cost origin must bind exact course student';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'course_cost_charge_origins',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'student_charges',
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
