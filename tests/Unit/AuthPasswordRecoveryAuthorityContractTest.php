<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AuthPasswordRecoveryAuthorityContractTest extends TestCase
{
    public function test_password_recovery_authority_is_explicit_and_provider_neutral(): void
    {
        $root = dirname(__DIR__, 2);
        $design = (string) file_get_contents($root.'/specs/design/auth-password-recovery.yml');
        $api = (string) file_get_contents($root.'/specs/api/paths/auth-organization.yaml');
        $components = (string) file_get_contents($root.'/specs/api/openapi-components-v1.yaml');
        $schema = (string) file_get_contents($root.'/specs/database/core-schema.yml');

        self::assertMatchesRegularExpression('/status: (?:CANDIDATE|PASS)/', $design);
        self::assertStringContainsString('auth.password_forgot', $design);
        self::assertStringContainsString('auth.password_reset', $design);
        self::assertStringContainsString('credential_epoch: user_password_management.credential_version', $design);
        self::assertStringContainsString('management_mode_required: self_service', $design);
        self::assertStringContainsString('organization_managed: forbidden', $design);
        self::assertStringContainsString('storage: server_side_cache', $design);
        self::assertStringContainsString('raw_token_random_bytes: 32', $design);
        self::assertStringContainsString('ttl_seconds: 1800', $design);
        self::assertStringContainsString('reissue_invalidates_previous_token: true', $design);
        self::assertStringContainsString('atomic_cache_lock_get_delete: required', $design);
        self::assertStringContainsString('auth_sessions: revoke_all_active_for_user', $design);
        self::assertStringContainsString('provider_or_PKK_calls: forbidden', $design);
        self::assertStringContainsString('authority_gate_schema_or_migration_change: false', $design);

        self::assertStringContainsString('/auth/password/forgot:', $api);
        self::assertStringContainsString('x-requirement-id: auth.password_forgot', $api);
        self::assertStringContainsString("        '202': { description: Reset flow accepted without account enumeration }", $api);
        self::assertStringContainsString('/auth/password/reset:', $api);
        self::assertStringContainsString('x-requirement-id: auth.password_reset', $api);

        self::assertStringContainsString('PasswordForgotRequest:', $components);
        self::assertStringContainsString('required: [identifier]', $components);
        self::assertStringContainsString('PasswordResetRequest:', $components);
        self::assertStringContainsString('required: [token, password]', $components);

        self::assertStringContainsString('purpose: fail_closed_global_local_password_management_authority_and_credential_epoch', $schema);
        self::assertStringContainsString('management_mode_values: [unclassified, self_service, organization_managed]', $schema);
        self::assertStringContainsString('credential_version_type: bigint_not_null_default_0_check_gte_0', $schema);
    }
}
