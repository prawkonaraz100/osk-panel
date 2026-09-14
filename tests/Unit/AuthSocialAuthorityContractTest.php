<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AuthSocialAuthorityContractTest extends TestCase
{
    public function test_social_authority_is_schema_preserving_and_fail_closed(): void
    {
        $authority = Yaml::parseFile(base_path('specs/design/auth-social.yml'));

        self::assertSame('CORE-V1-AUTH-SOCIAL-AUTHORITY-001', $authority['authority']['id']);
        self::assertFalse($authority['scope']['schema_or_migration_change']);
        self::assertSame('forbidden', $authority['scope']['social_registration']);
        self::assertSame('forbidden', $authority['scope']['provider_token_persistence']);

        self::assertTrue($authority['provider_registry']['enabled_allowlist_required']);
        self::assertSame(
            'oauth2_authorization_code_pkce_userinfo_v1',
            $authority['provider_registry']['protocol_profile'],
        );

        self::assertTrue($authority['redirect_flow']['state']['one_time']);
        self::assertSame(600, $authority['redirect_flow']['state']['ttl_seconds']);
        self::assertSame('S256', $authority['redirect_flow']['pkce']['method']);

        self::assertTrue($authority['callback_state_claim']['atomic_one_time_claim_required']);
        self::assertTrue($authority['provider_exchange']['outside_database_transaction']);

        self::assertSame(
            'forbidden',
            $authority['identity_resolution']['historical_revoked_subject_link']['automatic_relink'],
        );
        self::assertTrue($authority['identity_resolution']['first_link']['verified_provider_email_required']);
        self::assertSame(
            'forbidden',
            $authority['identity_resolution']['first_link']['organization_managed_target'],
        );
        self::assertSame(
            'forbidden',
            $authority['identity_resolution']['no_matching_verified_local_email']['user_auto_creation'],
        );
        self::assertSame('forbidden', $authority['security']['return_url_open_redirect']);
    }
}
