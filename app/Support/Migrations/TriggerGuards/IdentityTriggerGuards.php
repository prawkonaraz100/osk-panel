<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class IdentityTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-IDENTITY', self::definitions());
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
                'name' => 'identity_membership_durable_identity',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization_memberships are durable and cannot be deleted';
    END IF;

    IF NEW.organization_id IS DISTINCT FROM OLD.organization_id
       OR NEW.user_id IS DISTINCT FROM OLD.user_id THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization_membership organization and user identity are immutable';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'organization_memberships',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'identity_membership_authorization_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_membership_id uuid;
    v_organization_id uuid;
    v_status varchar;
    v_is_owner boolean;
    v_missing_baseline integer;
    v_active_owner_count integer;
BEGIN
    IF TG_TABLE_NAME = 'organization_memberships' THEN
        IF TG_OP = 'DELETE' THEN
            v_membership_id := OLD.id;
            v_organization_id := OLD.organization_id;
        ELSE
            v_membership_id := NEW.id;
            v_organization_id := NEW.organization_id;
        END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN
            v_membership_id := OLD.membership_id;
        ELSE
            v_membership_id := NEW.membership_id;
        END IF;

        SELECT organization_id
          INTO v_organization_id
          FROM organization_memberships
         WHERE id = v_membership_id;
    END IF;

    SELECT status, is_owner
      INTO v_status, v_is_owner
      FROM organization_memberships
     WHERE id = v_membership_id;

    IF FOUND THEN
        IF EXISTS (
            SELECT 1
              FROM membership_permissions mp
             WHERE mp.membership_id = v_membership_id
               AND mp.granted = false
               AND EXISTS (
                   SELECT 1
                     FROM membership_permission_scopes mps
                    WHERE mps.membership_id = mp.membership_id
                      AND mps.permission_code = mp.permission_code
               )
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'denied membership permission cannot retain scope rows';
        END IF;

        IF EXISTS (
            SELECT 1
              FROM membership_permissions mp
             WHERE mp.membership_id = v_membership_id
               AND mp.granted = true
               AND NOT EXISTS (
                   SELECT 1
                     FROM membership_permission_scopes mps
                    WHERE mps.membership_id = mp.membership_id
                      AND mps.permission_code = mp.permission_code
               )
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'granted membership permission requires at least one legal scope';
        END IF;

        IF v_status = 'revoked' THEN
            IF EXISTS (
                SELECT 1
                  FROM membership_permissions
                 WHERE membership_id = v_membership_id
                   AND granted = true
            ) OR EXISTS (
                SELECT 1
                  FROM membership_permission_scopes
                 WHERE membership_id = v_membership_id
            ) THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'revoked membership cannot retain granted permission or scope rows';
            END IF;
        END IF;

        IF v_status = 'active' AND v_is_owner THEN
            SELECT COUNT(*)
              INTO v_missing_baseline
              FROM (
                  VALUES
                      ('organization.view'),
                      ('organization.members.manage'),
                      ('staff.permissions.manage'),
                      ('sessions.manage.organization')
              ) AS required(permission_code)
             WHERE NOT EXISTS (
                 SELECT 1
                   FROM membership_permissions mp
                  WHERE mp.membership_id = v_membership_id
                    AND mp.permission_code = required.permission_code
                    AND mp.granted = true
             )
                OR NOT EXISTS (
                 SELECT 1
                   FROM membership_permission_scopes mps
                  WHERE mps.membership_id = v_membership_id
                    AND mps.permission_code = required.permission_code
                    AND mps.scope_code = 'organization'
             );

            IF v_missing_baseline <> 0 THEN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'active owner must retain protected organization permission baseline';
            END IF;
        END IF;
    END IF;

    IF v_organization_id IS NOT NULL THEN
        SELECT COUNT(*)
          INTO v_active_owner_count
          FROM organization_memberships om
         WHERE om.organization_id = v_organization_id
           AND om.status = 'active'
           AND om.is_owner = true;

        IF EXISTS (
            SELECT 1
              FROM organization_memberships
             WHERE organization_id = v_organization_id
        ) AND v_active_owner_count < 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'organization must retain at least one active owner';
        END IF;
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'organization_memberships',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'membership_permissions',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'membership_permission_scopes',
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
