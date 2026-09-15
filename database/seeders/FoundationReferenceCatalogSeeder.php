<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\Yaml\Yaml;

final class FoundationReferenceCatalogSeeder extends Seeder
{
    /** @var array<string, string> */
    private const SCOPE_RESOLVERS = [
        'organization' => 'tenant_resource',
        'own' => 'permission_target_owner',
        'assigned_students' => 'permission_target_assigned_student',
        'assigned_locations' => 'permission_target_assigned_location',
    ];

    /** @var array<string, string> */
    private const AUDIT_POLICIES = [
        'authorization.permission.changed' => 'foundation.authorization.v1',
        'authorization.owner.transferred' => 'foundation.authorization.v1',
        'organization.settings.updated' => 'foundation.settings.v1',
        'auth.account_closure.requested' => 'resources.lifecycle.v1',
    ];

    public function run(): void
    {
        $document = Yaml::parseFile(base_path('specs/security/permissions.yml'));
        if (! is_array($document)) {
            throw new LogicException('Permission catalog must decode to a map.');
        }

        $permissionGroups = $document['permission_groups'] ?? null;
        $scopeProfiles = $document['scope_profiles'] ?? null;
        $profileByPermission = $document['permission_scope_profile_by_code'] ?? null;
        $dataScopes = $document['data_scopes'] ?? null;
        if (! is_array($permissionGroups) || ! is_array($scopeProfiles) || ! is_array($profileByPermission) || ! is_array($dataScopes)) {
            throw new LogicException('Permission catalog is missing required foundation sections.');
        }

        /** @var list<string> $permissionCodes */
        $permissionCodes = [];
        foreach ($permissionGroups as $codes) {
            if (! is_array($codes)) {
                throw new LogicException('Permission group must contain a list.');
            }
            foreach ($codes as $code) {
                if (! is_string($code) || $code === '') {
                    throw new LogicException('Permission code must be a non-empty string.');
                }
                $permissionCodes[] = $code;
            }
        }
        $permissionCodes = array_values(array_unique($permissionCodes));
        sort($permissionCodes);

        DB::transaction(function () use ($permissionCodes, $scopeProfiles, $profileByPermission, $dataScopes): void {
            foreach ($dataScopes as $scopeCode => $definition) {
                if (! is_string($scopeCode) || ! isset(self::SCOPE_RESOLVERS[$scopeCode]) || ! is_array($definition)) {
                    throw new LogicException('Unsupported data scope in permission catalog.');
                }
                $semantics = $definition['semantics'] ?? $scopeCode;
                DB::table('data_scopes')->updateOrInsert(
                    ['code' => $scopeCode],
                    ['description' => is_string($semantics) ? $semantics : $scopeCode],
                );
            }

            foreach ($permissionCodes as $permissionCode) {
                DB::table('permissions')->updateOrInsert(
                    ['code' => $permissionCode],
                    ['description' => $permissionCode],
                );

                $profileCode = $profileByPermission[$permissionCode] ?? null;
                if (! is_string($profileCode)) {
                    throw new LogicException("Missing scope profile for permission {$permissionCode}.");
                }
                $profile = $scopeProfiles[$profileCode] ?? null;
                if (! is_array($profile) || ! isset($profile['allowed_scopes']) || ! is_array($profile['allowed_scopes'])) {
                    throw new LogicException("Invalid scope profile {$profileCode}.");
                }

                foreach ($profile['allowed_scopes'] as $scopeCode) {
                    if (! is_string($scopeCode) || ! isset(self::SCOPE_RESOLVERS[$scopeCode])) {
                        throw new LogicException("Unsupported scope {$scopeCode} for {$permissionCode}.");
                    }
                    DB::table('permission_scope_options')->updateOrInsert(
                        ['permission_code' => $permissionCode, 'scope_code' => $scopeCode],
                        ['resolver_code' => self::SCOPE_RESOLVERS[$scopeCode]],
                    );
                }
            }

            foreach (self::AUDIT_POLICIES as $action => $validator) {
                DB::table('audit_action_policy_revisions')->updateOrInsert(
                    ['action' => $action, 'policy_version' => 1],
                    [
                        'payload_validator_code' => $validator,
                        'before_payload_requirement' => 'required',
                        'after_payload_requirement' => 'required',
                        'reason_requirement' => 'optional',
                        'policy_hash' => hash('sha256', $action.'|1|'.$validator),
                        'created_at' => now(),
                    ],
                );
                DB::table('audit_action_policy_currents')->updateOrInsert(
                    ['action' => $action],
                    ['policy_version' => 1, 'updated_at' => now()],
                );
            }
        });
    }
}
