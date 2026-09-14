<?php

namespace App\Support\Migrations\ProjectionGuards;

use App\Support\Migrations\TriggerWriteFence;

final class NotificationsProjectionGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-PRJ-NOTIFICATIONS', self::definitions());
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
                'name' => 'projection_notification_row_lifecycle',
                'body' => <<<'PLPGSQL'
DECLARE
    v_membership_status varchar;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'notification projection cannot be deleted by normal runtime';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.read_at IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'new runtime notification must start unread';
        END IF;

        IF NOT EXISTS (
            SELECT 1
              FROM domain_events event
             WHERE event.id = NEW.source_event_id
               AND event.organization_id = NEW.organization_id
               AND event.event_scope = 'organization'
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'notification projection requires exact same-tenant organization domain event';
        END IF;

        SELECT membership.status
          INTO v_membership_status
          FROM organization_memberships membership
         WHERE membership.organization_id = NEW.organization_id
           AND membership.id = NEW.organization_membership_id
           AND membership.user_id = NEW.user_id;

        IF NOT FOUND OR v_membership_status <> 'active' THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'new runtime notification requires exact active recipient membership';
        END IF;

        RETURN NEW;
    END IF;

    IF ROW(
        NEW.id,
        NEW.organization_id,
        NEW.source_event_id,
        NEW.organization_membership_id,
        NEW.user_id,
        NEW.audience_kind,
        NEW.type,
        NEW.payload,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.id,
        OLD.organization_id,
        OLD.source_event_id,
        OLD.organization_membership_id,
        OLD.user_id,
        OLD.audience_kind,
        OLD.type,
        OLD.payload,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'notification source recipient payload audience and creation snapshot are immutable';
    END IF;

    IF OLD.read_at IS NULL THEN
        IF NEW.read_at IS NULL THEN
            RETURN NEW;
        END IF;

        NEW.read_at := CURRENT_TIMESTAMP;
        RETURN NEW;
    END IF;

    IF NEW.read_at IS DISTINCT FROM OLD.read_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'notification read_at is write-once';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'notifications',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'projection_notification_broadcast_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_active_count integer;
    v_projection_count integer;
    v_invalid_count integer;
BEGIN
    SELECT COUNT(*)
      INTO v_active_count
      FROM organization_memberships membership
     WHERE membership.organization_id = NEW.organization_id
       AND membership.status = 'active';

    SELECT COUNT(*)
      INTO v_projection_count
      FROM notifications notification
     WHERE notification.organization_id = NEW.organization_id
       AND notification.source_event_id = NEW.source_event_id
       AND notification.audience_kind = 'organization_broadcast';

    SELECT COUNT(*)
      INTO v_invalid_count
      FROM notifications notification
      LEFT JOIN organization_memberships membership
        ON membership.organization_id = notification.organization_id
       AND membership.id = notification.organization_membership_id
       AND membership.user_id = notification.user_id
       AND membership.status = 'active'
     WHERE notification.organization_id = NEW.organization_id
       AND notification.source_event_id = NEW.source_event_id
       AND notification.audience_kind = 'organization_broadcast'
       AND membership.id IS NULL;

    IF v_projection_count <> v_active_count OR v_invalid_count <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization broadcast must materialize exactly one row per active membership snapshot';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'notifications',
                        'timing' => 'AFTER',
                        'events' => ['INSERT'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                        'when' => "NEW.audience_kind = 'organization_broadcast'",
                    ],
                ],
            ],
        ];
    }
}
