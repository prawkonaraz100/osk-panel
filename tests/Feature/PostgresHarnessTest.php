<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\IndependentPostgres;
use Tests\Support\MigrationPreflight;
use Tests\Support\SyntheticTenantFixtureFactory;
use Tests\TestCase;

class PostgresHarnessTest extends TestCase
{
    public function test_framework_runner_is_postgresql_backed(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertStringContainsString('PostgreSQL', (string) DB::scalar('select version()'));
    }

    public function test_synthetic_fixtures_are_tenant_separated(): void
    {
        $pdo = IndependentPostgres::connection();
        $fixture = SyntheticTenantFixtureFactory::seedSeparatedResources($pdo);

        $own = $pdo->prepare('select count(*) from s5_test_tenant_resources where organization_id = :organization_id');
        $own->execute(['organization_id' => $fixture['tenant_a']]);
        $this->assertSame(1, (int) $own->fetchColumn());

        $crossTenant = $pdo->prepare(
            'select count(*) from s5_test_tenant_resources where organization_id = :organization_id and id = :id'
        );
        $crossTenant->execute([
            'organization_id' => $fixture['tenant_a'],
            'id' => $fixture['resource_b'],
        ]);
        $this->assertSame(0, (int) $crossTenant->fetchColumn());

        $pdo->exec('DROP TABLE IF EXISTS s5_test_tenant_resources');
    }

    public function test_independent_connections_have_distinct_sessions_and_transaction_visibility(): void
    {
        [$first, $second] = IndependentPostgres::pair();
        SyntheticTenantFixtureFactory::reset($first);

        $this->assertNotSame(
            (int) $first->query('select pg_backend_pid()')->fetchColumn(),
            (int) $second->query('select pg_backend_pid()')->fetchColumn(),
        );

        $first->beginTransaction();
        $statement = $first->prepare(
            'insert into s5_test_tenant_resources (id, organization_id, label) values (:id, :organization_id, :label)'
        );
        $statement->execute([
            'id' => '018f0000-0000-7000-8000-000000000103',
            'organization_id' => SyntheticTenantFixtureFactory::TENANT_A,
            'label' => 'uncommitted-synthetic-resource',
        ]);

        $this->assertSame(
            0,
            (int) $second->query("select count(*) from s5_test_tenant_resources where id = '018f0000-0000-7000-8000-000000000103'")->fetchColumn(),
        );

        $first->commit();

        $this->assertSame(
            1,
            (int) $second->query("select count(*) from s5_test_tenant_resources where id = '018f0000-0000-7000-8000-000000000103'")->fetchColumn(),
        );

        $first->exec('DROP TABLE IF EXISTS s5_test_tenant_resources');
    }

    public function test_independent_connections_support_concurrency_lock_primitives(): void
    {
        [$first, $second] = IndependentPostgres::pair();

        $this->assertTrue($this->asBool($first->query('select pg_try_advisory_lock(519663, 6001)')->fetchColumn()));
        $this->assertFalse($this->asBool($second->query('select pg_try_advisory_lock(519663, 6001)')->fetchColumn()));
        $this->assertTrue($this->asBool($first->query('select pg_advisory_unlock(519663, 6001)')->fetchColumn()));
        $this->assertTrue($this->asBool($second->query('select pg_try_advisory_lock(519663, 6001)')->fetchColumn()));
        $this->assertTrue($this->asBool($second->query('select pg_advisory_unlock(519663, 6001)')->fetchColumn()));
    }

    public function test_migration_preflight_harness_fails_closed_on_unresolved_rows(): void
    {
        $pdo = IndependentPostgres::connection();
        $pdo->exec('DROP TABLE IF EXISTS s5_test_legacy_preflight');
        $pdo->exec('CREATE TABLE s5_test_legacy_preflight (id integer PRIMARY KEY, unresolved boolean NOT NULL)');
        $pdo->exec('INSERT INTO s5_test_legacy_preflight (id, unresolved) VALUES (1, true)');

        try {
            MigrationPreflight::requireZero(
                $pdo,
                'select count(*) from s5_test_legacy_preflight where unresolved is true',
                'synthetic_legacy_ambiguity',
            );
            $this->fail('Preflight must reject unresolved legacy rows.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('requires zero unresolved rows', $exception->getMessage());
        }

        $pdo->exec('UPDATE s5_test_legacy_preflight SET unresolved = false');
        MigrationPreflight::requireZero(
            $pdo,
            'select count(*) from s5_test_legacy_preflight where unresolved is true',
            'synthetic_legacy_ambiguity',
        );
        $this->assertTrue(true);
        $pdo->exec('DROP TABLE IF EXISTS s5_test_legacy_preflight');
    }

    private function asBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
