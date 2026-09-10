<?php

namespace Tests\Support;

use PDO;

final class SyntheticTenantFixtureFactory
{
    public const TENANT_A = '018f0000-0000-7000-8000-000000000001';
    public const TENANT_B = '018f0000-0000-7000-8000-000000000002';
    public const RESOURCE_A = '018f0000-0000-7000-8000-000000000101';
    public const RESOURCE_B = '018f0000-0000-7000-8000-000000000102';

    public static function reset(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS s5_test_tenant_resources');
        $pdo->exec(<<<'SQL'
CREATE TABLE s5_test_tenant_resources (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    label text NOT NULL,
    UNIQUE (organization_id, id)
)
SQL);
    }

    /** @return array{tenant_a: string, tenant_b: string, resource_a: string, resource_b: string} */
    public static function seedSeparatedResources(PDO $pdo): array
    {
        self::reset($pdo);

        $statement = $pdo->prepare(
            'INSERT INTO s5_test_tenant_resources (id, organization_id, label) VALUES (:id, :organization_id, :label)'
        );
        $statement->execute([
            'id' => self::RESOURCE_A,
            'organization_id' => self::TENANT_A,
            'label' => 'synthetic-tenant-a-resource',
        ]);
        $statement->execute([
            'id' => self::RESOURCE_B,
            'organization_id' => self::TENANT_B,
            'label' => 'synthetic-tenant-b-resource',
        ]);

        return [
            'tenant_a' => self::TENANT_A,
            'tenant_b' => self::TENANT_B,
            'resource_a' => self::RESOURCE_A,
            'resource_b' => self::RESOURCE_B,
        ];
    }
}
