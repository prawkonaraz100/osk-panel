<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive(
            'preflight',
            'S5SOC-IDX-AUTH-SOCIAL-PROVIDER-SUBJECT-UNIQUE',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Social identity uniqueness preflight requires PostgreSQL.');
        }

        if (! Schema::hasTable('auth_social_accounts')) {
            throw new LogicException('auth_social_accounts must exist before social identity uniqueness preflight.');
        }

        $duplicate = DB::selectOne(<<<'SQL'
SELECT provider, provider_subject, COUNT(*) AS duplicate_count
FROM auth_social_accounts
GROUP BY provider, provider_subject
HAVING COUNT(*) > 1
ORDER BY provider, provider_subject
LIMIT 1
SQL);

        if ($duplicate !== null) {
            throw new LogicException(
                'Duplicate auth_social_accounts(provider, provider_subject) rows require reviewed remediation before write fence.',
            );
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the social identity uniqueness corrective.');
    }
};
