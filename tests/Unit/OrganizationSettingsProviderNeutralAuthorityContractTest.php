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
        $paths = Yaml::parseFile($root.'/specs/api/paths/auth-organization.yaml');

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

        self::assertTrue($paths['paths']['/organization']['patch']['x-provider-neutral']);
        self::assertFalse($paths['paths']['/organization']['patch']['x-pkk-configuration-required']);
        self::assertTrue($paths['paths']['/organization/settings']['get']['x-provider-neutral']);
        self::assertSame('forbidden', $paths['paths']['/organization/settings']['get']['x-pkk-table-access']);
        self::assertTrue($paths['paths']['/organization/settings']['patch']['x-provider-neutral']);
        self::assertSame(
            'forbidden_use_dedicated_integration_endpoint',
            $paths['paths']['/organization/settings']['patch']['x-pkk-field-mutation'],
        );

        self::assertArrayHasKey('/organization/integrations/pkk', $paths['paths']);
        self::assertSame(
            'FROZEN_UNTIL_EXPLICIT_UNFREEZE',
            $authority['PKK_freeze']['status'],
        );
    }
}
