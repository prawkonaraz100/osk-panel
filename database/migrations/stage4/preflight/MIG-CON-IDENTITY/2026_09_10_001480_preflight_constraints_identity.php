<?php

use App\Support\Migrations\ConstraintPreflight;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CON-IDENTITY');

        ConstraintPreflight::assertChecks('MIG-CON-IDENTITY', [
            [
                'name' => 'organization_membership_status_closed',
                'table' => 'organization_memberships',
                'columns' => ['status'],
                'predicate' => 'src.status IN (\'active\',\'suspended\',\'revoked\')',
            ],
            [
                'name' => 'organization_membership_version_positive',
                'table' => 'organization_memberships',
                'columns' => ['version'],
                'predicate' => 'src.version >= 1',
            ],
            [
                'name' => 'organization_membership_authorization_version_positive',
                'table' => 'organization_memberships',
                'columns' => ['authorization_version'],
                'predicate' => 'src.authorization_version >= 1',
            ],
            [
                'name' => 'revoked_membership_not_owner',
                'table' => 'organization_memberships',
                'columns' => ['status', 'is_owner'],
                'predicate' => 'src.status <> \'revoked\' OR src.is_owner = false',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
