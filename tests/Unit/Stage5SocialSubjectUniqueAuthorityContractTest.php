<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class Stage5SocialSubjectUniqueAuthorityContractTest extends TestCase
{
    public function test_corrective_authority_is_isolated_fail_closed_and_non_destructive(): void
    {
        $authority = Yaml::parseFile(
            dirname(__DIR__, 2).'/specs/database/social-auth-identity-migration-extension.yml',
        );

        self::assertSame(
            'social_provider_subject_unique_stage5_migration_extension',
            $authority['meta']['id'],
        );
        self::assertFalse($authority['scope']['mutates_Stage_4_DAG']);
        self::assertFalse($authority['scope']['mutates_Stage_4_execution_identity']);
        self::assertFalse($authority['scope']['social_HTTP_runtime_in_scope']);

        self::assertSame(170, $authority['stage_4_frozen_baseline']['node_count']);
        self::assertSame(170, $authority['stage_4_frozen_baseline']['implemented_node_count']);
        self::assertSame(261, $authority['stage_4_frozen_baseline']['implemented_step_count']);

        $node = $authority['extension_nodes'][0];
        self::assertSame(
            'S5SOC-IDX-AUTH-SOCIAL-PROVIDER-SUBJECT-UNIQUE',
            $node['node_id'],
        );
        self::assertSame(['preflight', 'write_fence', 'validate'], $node['phases']);
        self::assertSame(['provider', 'provider_subject'], $node['invariant']['columns']);
        self::assertTrue($node['invariant']['unique']);
        self::assertNull($node['invariant']['predicate']);
        self::assertTrue($node['invariant']['applies_to_revoked_history']);

        self::assertSame(
            'fail_and_require_reviewed_remediation',
            $node['preflight']['duplicate_found_effect'],
        );
        self::assertSame('forbidden', $node['preflight']['automatic_delete']);
        self::assertSame('forbidden', $node['preflight']['automatic_revoke']);
        self::assertSame('forbidden', $node['preflight']['heuristic_winner_selection']);

        self::assertFalse($authority['execution_boundary']['default_php_artisan_migrate_discovers_extension_files']);
        self::assertTrue($authority['execution_boundary']['unregistered_extension_php_migration_forbidden']);
        self::assertFalse($authority['execution_boundary']['automatic_destructive_down']);
        self::assertFalse($authority['execution_boundary']['authority_gate_executes_DDL']);
        self::assertTrue($authority['preservation']['PKK_remains_frozen']);
    }
}
