<?php

namespace App\Support\Migrations\TriggerGuards;

use App\Support\Migrations\TriggerWriteFence;

final class LicensesTriggerGuards
{
    public static function install(): void
    {
        TriggerWriteFence::install('MIG-TRG-LICENSES', self::definitions());
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
                'name' => 'licenses_learning_account_durable_identity',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student learning accounts are durable and cannot be deleted';
    END IF;

    IF ROW(NEW.organization_id, NEW.student_id, NEW.user_id)
       IS DISTINCT FROM
       ROW(OLD.organization_id, OLD.student_id, OLD.user_id) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'learning-account organization, student and global user identity are immutable';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'student_learning_accounts',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'licenses_auth_identifier_user_immutable',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.user_id IS DISTINCT FROM OLD.user_id THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'global auth login identifier cannot be reparented to another user';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'auth_login_identifiers',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE'],
                    ],
                ],
            ],
            [
                'name' => 'licenses_current_login_identifier_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_identifier_id uuid;
    v_revoked_at timestamptz;
    v_reference_count bigint;
BEGIN
    IF TG_TABLE_NAME = 'student_learning_accounts' THEN
        v_identifier_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.auth_login_identifier_id
            ELSE NEW.auth_login_identifier_id
        END;
    ELSE
        v_identifier_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    END IF;

    SELECT revoked_at
      INTO v_revoked_at
      FROM auth_login_identifiers
     WHERE id = v_identifier_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_reference_count
      FROM student_learning_accounts
     WHERE auth_login_identifier_id = v_identifier_id;

    IF v_reference_count > 0 AND v_revoked_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'learning account cannot reference a revoked global login identifier';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'student_learning_accounts',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'auth_login_identifiers',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'licenses_password_management_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_user_id uuid;
    v_password_hash text;
    v_mode varchar;
    v_managing_organization_id uuid;
    v_credential_version bigint;
BEGIN
    IF TG_TABLE_NAME = 'users' THEN
        v_user_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME = 'user_password_management' THEN
        v_user_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
    ELSE
        v_user_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
    END IF;

    SELECT password_hash
      INTO v_password_hash
      FROM users
     WHERE id = v_user_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT management_mode, managing_organization_id, credential_version
      INTO v_mode, v_managing_organization_id, v_credential_version
      FROM user_password_management
     WHERE user_id = v_user_id;

    IF NOT FOUND THEN
        IF v_password_hash IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'local password hash requires password-management authority row';
        END IF;

        RETURN NULL;
    END IF;

    IF v_credential_version < 0
       OR v_mode NOT IN ('unclassified', 'self_service', 'organization_managed') THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'password-management mode or credential version is invalid';
    END IF;

    IF v_password_hash IS NOT NULL AND v_credential_version < 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'local password hash requires positive credential epoch';
    END IF;

    IF v_mode IN ('unclassified', 'self_service') THEN
        IF v_managing_organization_id IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'non-organization password management cannot name a managing organization';
        END IF;

        RETURN NULL;
    END IF;

    IF v_managing_organization_id IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization-managed password requires managing organization';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM organization_memberships
         WHERE user_id = v_user_id
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization-managed learner password cannot coexist with organization membership';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM student_learning_accounts
         WHERE user_id = v_user_id
           AND organization_id <> v_managing_organization_id
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization-managed learner password cannot span organizations';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM auth_social_accounts
         WHERE user_id = v_user_id
           AND revoked_at IS NULL
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization-managed learner password cannot coexist with current social login';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'users',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'user_password_management',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'organization_memberships',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'student_learning_accounts',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'auth_social_accounts',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'licenses_access_handoff_metadata_matrix',
                'body' => <<<'PLPGSQL'
BEGIN
    IF NEW.handoff_type NOT IN ('initial_credentials', 'password_reset', 'credentials_document')
       OR NEW.credential_version_snapshot < 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student access handoff type or credential snapshot is invalid';
    END IF;

    IF NEW.contains_fresh_secret THEN
        IF NEW.handoff_type NOT IN ('initial_credentials', 'password_reset')
           OR NEW.credential_version_snapshot < 1
           OR NEW.fresh_secret_issued_at IS NULL
           OR NEW.document_asset_id IS NOT NULL THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'fresh-secret handoff metadata is inconsistent';
        END IF;
    ELSIF NEW.fresh_secret_issued_at IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'nonsecret handoff cannot carry fresh-secret issuance timestamp';
    END IF;

    IF (NEW.batch_id IS NULL) <> (NEW.batch_ordinal IS NULL)
       OR (NEW.batch_ordinal IS NOT NULL AND NEW.batch_ordinal < 1) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student access handoff batch tuple is incomplete';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'student_access_handoffs',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE'],
                    ],
                ],
            ],
            [
                'name' => 'licenses_access_export_batch_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_batch_id uuid;
    v_batch student_access_export_batches%ROWTYPE;
    v_item_count bigint;
    v_wrong_tenant_count bigint;
BEGIN
    IF TG_TABLE_NAME = 'student_access_export_batches' THEN
        v_batch_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_batch_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.batch_id ELSE NEW.batch_id END;
    END IF;

    IF v_batch_id IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT *
      INTO v_batch
      FROM student_access_export_batches
     WHERE id = v_batch_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    IF v_batch.export_mode NOT IN ('nonsecret_combined_pdf', 'reset_and_secret_combined_pdf')
       OR v_batch.selected_account_count < 1 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student access export batch shape is invalid';
    END IF;

    SELECT COUNT(*),
           COUNT(*) FILTER (WHERE organization_id <> v_batch.organization_id)
      INTO v_item_count, v_wrong_tenant_count
      FROM student_access_handoffs
     WHERE batch_id = v_batch_id;

    IF v_item_count <> v_batch.selected_account_count OR v_wrong_tenant_count <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'student access export batch count or tenant does not match final handoff set';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'student_access_export_batches',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'student_access_handoffs',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'licenses_product_duration_guard',
                'body' => <<<'PLPGSQL'
BEGIN
    IF NEW.duration_days <= 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license product duration must be positive';
    END IF;

    IF TG_OP = 'UPDATE'
       AND NEW.duration_days IS DISTINCT FROM OLD.duration_days
       AND EXISTS (
           SELECT 1
             FROM license_inventory_entries
            WHERE license_product_id = OLD.id
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license product duration is immutable after inventory reference';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_products',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE'],
                    ],
                ],
            ],
            [
                'name' => 'licenses_inventory_product_binding_immutable',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'UPDATE'
       AND NEW.license_product_id IS DISTINCT FROM OLD.license_product_id THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license inventory product binding is immutable';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_inventory_entries',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE'],
                    ],
                ],
            ],
            [
                'name' => 'licenses_assignment_durable_history',
                'body' => <<<'PLPGSQL'
DECLARE
    v_transition_allowed boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license assignments are durable and cannot be deleted';
    END IF;

    IF ROW(
        NEW.organization_id,
        NEW.license_inventory_entry_id,
        NEW.student_id,
        NEW.student_learning_account_id,
        NEW.license_product_language_capability_id,
        NEW.language_code,
        NEW.assignment_sequence,
        NEW.assigned_by_user_id,
        NEW.assigned_at,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.organization_id,
        OLD.license_inventory_entry_id,
        OLD.student_id,
        OLD.student_learning_account_id,
        OLD.license_product_language_capability_id,
        OLD.language_code,
        OLD.assignment_sequence,
        OLD.assigned_by_user_id,
        OLD.assigned_at,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license assignment identity and assignment-time snapshot are immutable';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        v_transition_allowed := OLD.status = 'assigned'
            AND NEW.status IN ('activated', 'revoked_before_activation');

        IF NOT v_transition_allowed OR NEW.version <> OLD.version + 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'license assignment transition or version step is invalid';
        END IF;
    ELSIF NEW.version IS DISTINCT FROM OLD.version THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license assignment version may change only with a material state transition';
    END IF;

    IF OLD.status IN ('activated', 'revoked_before_activation')
       AND ROW(NEW.status, NEW.revoked_at, NEW.revoked_by_user_id, NEW.revoke_reason)
           IS DISTINCT FROM
           ROW(OLD.status, OLD.revoked_at, OLD.revoked_by_user_id, OLD.revoke_reason) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'terminal license assignment state is irreversible';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_assignments',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'licenses_language_capability_history',
                'body' => <<<'PLPGSQL'
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license product language capability history cannot be deleted';
    END IF;

    IF ROW(
        NEW.license_product_id,
        NEW.language_code,
        NEW.enabled_at,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.license_product_id,
        OLD.language_code,
        OLD.enabled_at,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license product language capability identity and enabled_at are immutable';
    END IF;

    IF OLD.disabled_at IS NOT NULL
       AND NEW.disabled_at IS DISTINCT FROM OLD.disabled_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license product language capability disabled_at is write-once';
    END IF;

    IF NEW.disabled_at IS NOT NULL AND NEW.disabled_at <= NEW.enabled_at THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license product language capability disable time must be after enable time';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_product_language_capabilities',
                        'timing' => 'BEFORE',
                        'events' => ['UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'licenses_assignment_sequence_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_organization_id uuid;
    v_account_id uuid;
    v_count bigint;
    v_min bigint;
    v_max bigint;
BEGIN
    v_organization_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    v_account_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.student_learning_account_id ELSE NEW.student_learning_account_id END;

    SELECT COUNT(*), MIN(assignment_sequence), MAX(assignment_sequence)
      INTO v_count, v_min, v_max
      FROM license_assignments
     WHERE organization_id = v_organization_id
       AND student_learning_account_id = v_account_id;

    IF v_count > 0 AND (v_min <> 1 OR v_max <> v_count) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license assignment sequence must be contiguous from one';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_assignments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'licenses_assignment_capability_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_assignment_id uuid;
    v_inventory_id uuid;
    v_capability_id uuid;
    v_bad_count bigint;
BEGIN
    IF TG_TABLE_NAME = 'license_assignments' THEN
        v_assignment_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME = 'license_inventory_entries' THEN
        v_inventory_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        v_capability_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    END IF;

    SELECT COUNT(*)
      INTO v_bad_count
      FROM license_assignments assignment
      JOIN license_inventory_entries inventory
        ON inventory.id = assignment.license_inventory_entry_id
       AND inventory.organization_id = assignment.organization_id
      JOIN license_product_language_capabilities capability
        ON capability.id = assignment.license_product_language_capability_id
     WHERE (
           (
               v_assignment_id IS NOT NULL AND assignment.id = v_assignment_id
           )
           OR (
               v_inventory_id IS NOT NULL AND assignment.license_inventory_entry_id = v_inventory_id
           )
           OR (
               v_capability_id IS NOT NULL AND assignment.license_product_language_capability_id = v_capability_id
           )
       )
       AND (
           capability.license_product_id IS DISTINCT FROM inventory.license_product_id
           OR assignment.assigned_at < capability.enabled_at
           OR (
               capability.disabled_at IS NOT NULL
               AND assignment.assigned_at >= capability.disabled_at
           )
       );

    IF v_bad_count <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license assignment capability must match inventory product and be effective at assignment time';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_assignments',
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
                        'table' => 'license_product_language_capabilities',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'licenses_activation_insert_and_immutability',
                'body' => <<<'PLPGSQL'
DECLARE
    v_assignment license_assignments%ROWTYPE;
    v_account student_learning_accounts%ROWTYPE;
    v_product_duration integer;
BEGIN
    IF TG_OP = 'UPDATE' OR TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license activation ledger is immutable';
    END IF;

    IF NEW.activation_origin = 'legacy_unknown'
       OR NEW.duration_snapshot_source = 'legacy_effect_reconstructed' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'migration-only activation provenance cannot be inserted after runtime cutover';
    END IF;

    IF NEW.activation_origin NOT IN ('learner_self', 'organization_user')
       OR NEW.duration_snapshot_source <> 'product_at_activation' THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'runtime license activation provenance is invalid';
    END IF;

    SELECT *
      INTO v_assignment
      FROM license_assignments
     WHERE id = NEW.license_assignment_id
       AND organization_id = NEW.organization_id;

    IF NOT FOUND THEN
        RETURN NEW;
    END IF;

    SELECT *
      INTO v_account
      FROM student_learning_accounts
     WHERE id = NEW.student_learning_account_id
       AND organization_id = NEW.organization_id;

    IF NOT FOUND THEN
        RETURN NEW;
    END IF;

    IF v_assignment.student_learning_account_id IS DISTINCT FROM v_account.id
       OR v_assignment.language_code IS DISTINCT FROM v_account.language_code THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license activation must preserve exact assignment account and current language';
    END IF;

    SELECT product.duration_days
      INTO v_product_duration
      FROM license_inventory_entries inventory
      JOIN license_products product ON product.id = inventory.license_product_id
     WHERE inventory.id = v_assignment.license_inventory_entry_id
       AND inventory.organization_id = NEW.organization_id;

    IF NOT FOUND OR NEW.duration_days_snapshot IS DISTINCT FROM v_product_duration THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'runtime activation duration snapshot must equal assigned inventory product duration';
    END IF;

    IF NEW.activation_origin = 'learner_self' THEN
        IF NEW.activated_by_user_id IS DISTINCT FROM v_account.user_id THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'learner-self activation actor must equal learning-account user';
        END IF;
    ELSIF NEW.activated_by_user_id IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'organization-user activation requires actor';
    END IF;

    RETURN NEW;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_activations',
                        'timing' => 'BEFORE',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                    ],
                ],
            ],
            [
                'name' => 'licenses_inventory_assignment_activation_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_inventory_id uuid;
    v_inventory_status varchar;
    v_current_count bigint;
    v_current_assigned bigint;
    v_current_activated bigint;
    v_bad_assignment_count bigint;
BEGIN
    IF TG_TABLE_NAME = 'license_inventory_entries' THEN
        v_inventory_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME = 'license_assignments' THEN
        v_inventory_id := CASE
            WHEN TG_OP = 'DELETE' THEN OLD.license_inventory_entry_id
            ELSE NEW.license_inventory_entry_id
        END;
    ELSE
        SELECT license_inventory_entry_id
          INTO v_inventory_id
          FROM license_assignments
         WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.license_assignment_id ELSE NEW.license_assignment_id END;
    END IF;

    IF v_inventory_id IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT status
      INTO v_inventory_status
      FROM license_inventory_entries
     WHERE id = v_inventory_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT
        COUNT(*) FILTER (WHERE status IN ('assigned', 'activated') AND revoked_at IS NULL),
        COUNT(*) FILTER (WHERE status = 'assigned' AND revoked_at IS NULL),
        COUNT(*) FILTER (WHERE status = 'activated' AND revoked_at IS NULL)
      INTO v_current_count, v_current_assigned, v_current_activated
      FROM license_assignments
     WHERE license_inventory_entry_id = v_inventory_id;

    IF v_inventory_status IN ('available', 'expired', 'adjusted') THEN
        IF v_current_count <> 0 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'nonallocated license inventory must have zero current assignments';
        END IF;
    ELSIF v_inventory_status = 'assigned' THEN
        IF v_current_count <> 1 OR v_current_assigned <> 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'assigned license inventory requires exactly one assigned current assignment';
        END IF;
    ELSIF v_inventory_status = 'consumed' THEN
        IF v_current_count <> 1 OR v_current_activated <> 1 THEN
            RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'consumed license inventory requires exactly one activated current assignment';
        END IF;
    END IF;

    SELECT COUNT(*)
      INTO v_bad_assignment_count
      FROM license_assignments assignment
     WHERE assignment.license_inventory_entry_id = v_inventory_id
       AND (
           (
               assignment.status = 'assigned'
               AND (
                   v_inventory_status <> 'assigned'
                   OR EXISTS (
                       SELECT 1
                         FROM license_activations activation
                        WHERE activation.license_assignment_id = assignment.id
                   )
               )
           )
           OR
           (
               assignment.status = 'activated'
               AND (
                   v_inventory_status <> 'consumed'
                   OR (
                       SELECT COUNT(*)
                         FROM license_activations activation
                        WHERE activation.license_assignment_id = assignment.id
                   ) <> 1
               )
           )
           OR
           (
               assignment.status = 'revoked_before_activation'
               AND EXISTS (
                   SELECT 1
                     FROM license_activations activation
                    WHERE activation.license_assignment_id = assignment.id
               )
           )
       );

    IF v_bad_assignment_count <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license inventory assignment activation final state is inconsistent';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_inventory_entries',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'license_assignments',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                    [
                        'table' => 'license_activations',
                        'timing' => 'AFTER',
                        'events' => ['INSERT', 'UPDATE', 'DELETE'],
                        'constraint' => true,
                        'deferrable' => true,
                        'initially_deferred' => true,
                    ],
                ],
            ],
            [
                'name' => 'licenses_entitlement_chain_final_state',
                'body' => <<<'PLPGSQL'
DECLARE
    v_organization_id uuid;
    v_account_id uuid;
    v_count bigint;
    v_min bigint;
    v_max bigint;
    v_bad bigint;
BEGIN
    v_organization_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    v_account_id := CASE
        WHEN TG_OP = 'DELETE' THEN OLD.student_learning_account_id
        ELSE NEW.student_learning_account_id
    END;

    SELECT COUNT(*), MIN(entitlement_sequence), MAX(entitlement_sequence)
      INTO v_count, v_min, v_max
      FROM license_activations
     WHERE organization_id = v_organization_id
       AND student_learning_account_id = v_account_id;

    IF v_count > 0 AND (v_min <> 1 OR v_max <> v_count) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license entitlement sequence must be contiguous from one';
    END IF;

    SELECT COUNT(*)
      INTO v_bad
      FROM (
          SELECT
              entitlement_sequence,
              expiry_before,
              activated_at,
              effective_from,
              effective_to,
              duration_days_snapshot,
              LAG(effective_to) OVER (ORDER BY entitlement_sequence) AS previous_effective_to
            FROM license_activations
           WHERE organization_id = v_organization_id
             AND student_learning_account_id = v_account_id
      ) chain
     WHERE (
           chain.entitlement_sequence = 1
           AND (
               chain.expiry_before IS NOT NULL
               OR chain.effective_from IS DISTINCT FROM chain.activated_at
           )
       )
        OR (
           chain.entitlement_sequence > 1
           AND (
               chain.expiry_before IS DISTINCT FROM chain.previous_effective_to
               OR chain.effective_from IS DISTINCT FROM GREATEST(chain.activated_at, chain.expiry_before)
           )
       )
        OR chain.effective_to IS DISTINCT FROM (
            chain.effective_from + (chain.duration_days_snapshot * 86400) * INTERVAL '1 second'
        );

    IF v_bad <> 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'license entitlement chain timing or duration snapshot is inconsistent';
    END IF;

    RETURN NULL;
END;
PLPGSQL,
                'triggers' => [
                    [
                        'table' => 'license_activations',
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
