<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class OrganizationSettingsProviderNeutralAuthorityContractTest extends TestCase
{
    public function test_provider_neutral_organization_settings_do_not_depend_on_pkk(): void
    {
        $root = dirname(__DIR__, 2);
        $authority = Yaml::parseFile($root.'/specs/design/organization-settings-provider-neutral.yml');
        $components = Yaml::parseFile($root.'/specs/api/openapi-components-v1.yaml');
        $settings = Yaml::parseFile($root.'/specs/api/openapi-settings-components.yaml');
        $paths = (string) file_get_contents($root.'/specs/api/paths/auth-organization.yaml');

        self::assertFalse($authority['decision']['service_requires_PKK_configuration']);
        self::assertFalse($authority['decision']['provider_neutral_settings_embed_PKK_projection']);

        self::assertArrayNotHasKey('osk_registry_number', $components['components']['schemas']['Organization']['properties']);
        self::assertArrayNotHasKey('osk_registry_number', $components['components']['schemas']['UpdateOrganizationRequest']['properties']);

        $projection = $settings['components']['schemas']['OskSettingsProjection']['properties'];
        $update = $settings['components']['schemas']['UpdateOskSettingsRequest']['properties'];
        self::assertArrayNotHasKey('pkk_api_data', $projection);
        self::assertArrayNotHasKey('pkk_api_data', $update);
        self::assertArrayNotHasKey('email', $update['basic_data']['properties']);
        self::assertArrayHasKey('email', $settings['components']['schemas']['UserBasicData']['properties']);

        self::assertStringContainsString('/organization:', $paths);
        self::assertStringContainsString('/organization/settings:', $paths);
        self::assertStringContainsString('x-provider-neutral: true', $paths);
        self::assertStringContainsString('x-pkk-configuration-required: false', $paths);
        self::assertStringContainsString('x-pkk-table-access: forbidden', $paths);
        self::assertStringContainsString(
            'x-pkk-field-mutation: forbidden_use_dedicated_integration_endpoint',
            $paths,
        );
        self::assertStringContainsString('/organization/integrations/pkk:', $paths);
        self::assertSame(
            'FROZEN_UNTIL_EXPLICIT_UNFREEZE',
            $authority['PKK_freeze']['status'],
        );
    }
}
