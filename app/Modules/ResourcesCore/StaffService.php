<?php

namespace App\Modules\ResourcesCore;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\MembershipGovernance;
use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StaffService
{
    private const DOCUMENT_FIELDS = [
        'card_valid_until' => 'card_or_authorization',
        'medical_exam_valid_until' => 'medical_exam',
        'psychological_exam_valid_until' => 'psychological_exam',
    ];

    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceScopeAuthorizer $scopeAuthorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
        private readonly MembershipGovernance $membershipGovernance,
    ) {}

    /** @return array{data:list<array<string,mixed>>,meta:array<string,int>} */
    public function list(string $sessionId, int $page, int $perPage, ?string $q, ?string $sort, string $direction): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'staff.view');
        $query = DB::table('staff_profiles')->where('organization_id', $visibility['membership']['organization_id']);

        if (! $visibility['unrestricted']) {
            if ($visibility['location_ids'] === []) {
                return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]];
            }
            $query->whereIn('id', DB::table('staff_location_assignments')
                ->where('organization_id', $visibility['membership']['organization_id'])
                ->whereIn('location_id', $visibility['location_ids'])
                ->select('staff_profile_id'));
        }

        if ($q !== null && trim($q) !== '') {
            $needle = '%'.mb_strtolower(trim($q)).'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email_normalized) LIKE ?', [$needle]);
            });
        }

        $sortMap = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email_normalized',
            'created_at' => 'created_at',
        ];
        $sortColumn = $sortMap[$sort ?? 'last_name'] ?? 'last_name';
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $total = (clone $query)->count();
        $rows = $query->orderBy($sortColumn, $direction)->orderBy('first_name')->forPage($page, $perPage)->get();

        return [
            'data' => array_values($rows->map(fn ($row): array => $this->present($row))->all()),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $staffId): array
    {
        $membership = $this->scopeAuthorizer->requireStaffTarget($sessionId, 'staff.view', $staffId);
        $row = DB::table('staff_profiles')->where('organization_id', $membership['organization_id'])->where('id', $staffId)->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->present($row);
    }

    /** @param array<string,mixed> $input */
    public function create(string $sessionId, array $input, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $input, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'staff.create');
            $this->assertRelations($actor['organization_id'], $input);
            $this->assertAsset($actor['organization_id'], $input['photo_asset_id'] ?? null, 'staff_photo');
            [$ciphertext, $lookupHash] = $this->peselStorage($input['pesel'] ?? null);
            $this->assertPeselAvailable($actor['organization_id'], $lookupHash, null);

            $id = (string) Str::uuid7();
            $now = now();
            DB::table('staff_profiles')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'first_name' => trim((string) $input['first_name']),
                'last_name' => trim((string) $input['last_name']),
                'email_normalized' => mb_strtolower(trim((string) $input['email'])),
                'phone' => $this->nullableString($input['phone'] ?? null),
                'pesel_ciphertext' => $ciphertext,
                'pesel_lookup_hash' => $lookupHash,
                'authorization_number' => $this->nullableString($input['authorization_number'] ?? null),
                'photo_asset_id' => $input['photo_asset_id'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->syncRelations($actor['organization_id'], $id, $input);

            foreach (self::DOCUMENT_FIELDS as $field => $type) {
                if (array_key_exists($field, $input) && $input[$field] !== null) {
                    $this->replaceDocumentLocked($actor, $id, $type, (string) $input[$field]);
                }
            }

            if (($input['create_login_account'] ?? false) === true) {
                $this->tenantAuthorizer->requireOrganizationPermission(
                    $sessionId,
                    $actor['organization_id'],
                    'staff.accounts.manage',
                );
                $this->createPanelAccountLocked($actor, $id, $requestId);
            }

            $auditFields = array_values(array_filter(array_keys($input), static fn (string $field): bool => $field !== 'pesel'));
            if (array_key_exists('pesel', $input)) {
                $auditFields[] = 'pesel_changed';
            }
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'staff.created', 'staff_profile', $id, $requestId,
                ['fields' => [], 'state' => 'absent', 'has_login_account' => false],
                ['fields' => $auditFields, 'state' => 'active', 'has_login_account' => $this->hasCurrentLink($actor['organization_id'], $id)],
            );

            return $this->present(DB::table('staff_profiles')->where('id', $id)->firstOrFail());
        });
    }

    /** @param array<string,mixed> $input */
    public function update(string $sessionId, string $staffId, array $input, string $requestId, ?string $expectedTag = null): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $staffId, $input, $requestId, $expectedTag): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'staff.edit');
            $row = DB::table('staff_profiles')->where('organization_id', $actor['organization_id'])->where('id', $staffId)->lockForUpdate()->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            if ($row->archived_at !== null) {
                throw ResourceDomainException::conflict('Archived staff profile must be restored before editing.');
            }
            if ($expectedTag !== null && trim($expectedTag) !== '' && ! hash_equals($this->etag($this->present($row)), trim($expectedTag))) {
                throw ResourceDomainException::conflict('Staff profile changed since it was loaded.');
            }

            $this->assertRelations($actor['organization_id'], $input);
            if (array_key_exists('photo_asset_id', $input)) {
                $this->assertAsset($actor['organization_id'], $input['photo_asset_id'], 'staff_photo');
            }

            $updates = ['updated_at' => now()];
            foreach ([
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'email' => 'email_normalized',
                'phone' => 'phone',
                'authorization_number' => 'authorization_number',
                'photo_asset_id' => 'photo_asset_id',
            ] as $source => $target) {
                if (! array_key_exists($source, $input)) {
                    continue;
                }
                $value = $input[$source];
                if ($source === 'email') {
                    $value = mb_strtolower(trim((string) $value));
                } elseif (in_array($source, ['phone', 'authorization_number'], true)) {
                    $value = $this->nullableString($value);
                } elseif (in_array($source, ['first_name', 'last_name'], true)) {
                    $value = trim((string) $value);
                }
                $updates[$target] = $value;
            }

            if (array_key_exists('pesel', $input)) {
                [$ciphertext, $lookupHash] = $this->peselStorage($input['pesel']);
                $this->assertPeselAvailable($actor['organization_id'], $lookupHash, $staffId);
                $updates['pesel_ciphertext'] = $ciphertext;
                $updates['pesel_lookup_hash'] = $lookupHash;
            }

            DB::table('staff_profiles')->where('id', $staffId)->update($updates);
            $this->syncRelations($actor['organization_id'], $staffId, $input);
            foreach (self::DOCUMENT_FIELDS as $field => $type) {
                if (array_key_exists($field, $input)) {
                    $this->replaceDocumentLocked($actor, $staffId, $type, $input[$field] === null ? null : (string) $input[$field]);
                }
            }
            if (($input['create_login_account'] ?? false) === true && ! $this->hasCurrentLink($actor['organization_id'], $staffId)) {
                $this->tenantAuthorizer->requireOrganizationPermission(
                    $sessionId,
                    $actor['organization_id'],
                    'staff.accounts.manage',
                );
                $this->createPanelAccountLocked($actor, $staffId, $requestId);
            }

            $auditFields = array_values(array_filter(array_keys($input), static fn (string $field): bool => $field !== 'pesel'));
            if (array_key_exists('pesel', $input)) {
                $auditFields[] = 'pesel_changed';
            }
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'staff.updated', 'staff_profile', $staffId, $requestId,
                ['fields' => $auditFields, 'state' => 'active', 'has_login_account' => $this->hasCurrentLink($actor['organization_id'], $staffId)],
                ['fields' => $auditFields, 'state' => 'active', 'has_login_account' => $this->hasCurrentLink($actor['organization_id'], $staffId)],
            );

            return $this->present(DB::table('staff_profiles')->where('id', $staffId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function archive(string $sessionId, string $staffId, string $requestId, ?string $reason): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $staffId, $requestId, $reason): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'staff.archive');
            $staff = DB::table('staff_profiles')->where('organization_id', $actor['organization_id'])->where('id', $staffId)->lockForUpdate()->first();
            if ($staff === null) {
                throw ResourceDomainException::notFound();
            }
            if ($staff->archived_at !== null) {
                return $this->present($staff);
            }

            $link = DB::table('staff_membership_links')
                ->where('organization_id', $actor['organization_id'])
                ->where('staff_profile_id', $staffId)
                ->whereNull('unlinked_at')
                ->lockForUpdate()
                ->first();

            if ($link !== null) {
                $membership = DB::table('organization_memberships')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('id', $link->organization_membership_id)
                    ->lockForUpdate()
                    ->first();
                if ($membership === null) {
                    throw ResourceDomainException::conflict('Staff membership link is internally inconsistent.');
                }

                DB::table('staff_membership_links')->where('id', $link->id)->update([
                    'unlinked_at' => now(),
                    'unlinked_by_user_id' => $actor['user_id'],
                ]);

                if (! (bool) $membership->is_owner && $membership->status === 'active') {
                    DB::table('organization_memberships')->where('id', $membership->id)->update([
                        'status' => 'suspended',
                        'version' => (int) $membership->version + 1,
                        'authorization_version' => (int) $membership->authorization_version + 1,
                        'updated_at' => now(),
                    ]);
                    DB::table('auth_sessions')->where('organization_membership_id', $membership->id)->update([
                        'organization_membership_id' => null,
                    ]);
                }
            }

            DB::table('staff_profiles')->where('id', $staffId)->update([
                'archived_at' => now(),
                'archived_by_user_id' => $actor['user_id'],
                'updated_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'staff.archived', 'staff_profile', $staffId, $requestId,
                ['fields' => ['archived_at'], 'state' => 'active', 'archived' => false, 'has_login_account' => $link !== null],
                ['fields' => ['archived_at'], 'state' => 'archived', 'archived' => true, 'has_login_account' => false],
                $reason,
            );

            return $this->present(DB::table('staff_profiles')->where('id', $staffId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function restore(string $sessionId, string $staffId, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $staffId, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'staff.restore');
            $staff = DB::table('staff_profiles')->where('organization_id', $actor['organization_id'])->where('id', $staffId)->lockForUpdate()->first();
            if ($staff === null) {
                throw ResourceDomainException::notFound();
            }
            if ($staff->archived_at === null) {
                return $this->present($staff);
            }

            DB::table('staff_profiles')->where('id', $staffId)->update([
                'archived_at' => null,
                'archived_by_user_id' => null,
                'updated_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'staff.restored', 'staff_profile', $staffId, $requestId,
                ['fields' => ['archived_at'], 'state' => 'archived', 'archived' => true, 'has_login_account' => false],
                ['fields' => ['archived_at'], 'state' => 'active', 'archived' => false, 'has_login_account' => false],
            );

            return $this->present(DB::table('staff_profiles')->where('id', $staffId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function createPanelAccount(string $sessionId, string $staffId, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $staffId, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'staff.accounts.manage');
            $staff = DB::table('staff_profiles')->where('organization_id', $actor['organization_id'])->where('id', $staffId)->lockForUpdate()->first();
            if ($staff === null || $staff->archived_at !== null) {
                throw ResourceDomainException::notFound();
            }
            $this->createPanelAccountLocked($actor, $staffId, $requestId);

            return $this->present(DB::table('staff_profiles')->where('id', $staffId)->firstOrFail());
        });
    }

    public function revokePanelAccount(string $sessionId, string $staffId, string $requestId): void
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        DB::transaction(function () use ($sessionId, $snapshot, $staffId, $requestId): void {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'staff.accounts.manage');
            $staff = DB::table('staff_profiles')->where('organization_id', $actor['organization_id'])->where('id', $staffId)->lockForUpdate()->first();
            if ($staff === null) {
                throw ResourceDomainException::notFound();
            }
            $link = DB::table('staff_membership_links')
                ->where('organization_id', $actor['organization_id'])->where('staff_profile_id', $staffId)
                ->whereNull('unlinked_at')->lockForUpdate()->first();
            if ($link === null) {
                return;
            }
            $membership = DB::table('organization_memberships')->where('organization_id', $actor['organization_id'])
                ->where('id', $link->organization_membership_id)->lockForUpdate()->first();
            if ($membership === null) {
                throw ResourceDomainException::conflict('Staff membership link is internally inconsistent.');
            }

            DB::table('staff_membership_links')->where('id', $link->id)->update([
                'unlinked_at' => now(),
                'unlinked_by_user_id' => $actor['user_id'],
            ]);
            if (! (bool) $membership->is_owner && $membership->status === 'active') {
                DB::table('organization_memberships')->where('id', $membership->id)->update([
                    'status' => 'suspended',
                    'version' => (int) $membership->version + 1,
                    'authorization_version' => (int) $membership->authorization_version + 1,
                    'updated_at' => now(),
                ]);
                DB::table('auth_sessions')->where('organization_membership_id', $membership->id)->update(['organization_membership_id' => null]);
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'staff.panel_account.revoked', 'staff_profile', $staffId, $requestId,
                ['fields' => ['panel_access'], 'state' => 'linked', 'membership_id' => (string) $membership->id, 'has_login_account' => true],
                ['fields' => ['panel_access'], 'state' => 'unlinked', 'membership_id' => (string) $membership->id, 'has_login_account' => false],
            );
        });
    }

    /** @return array{permissions:list<string>} */
    public function permissions(string $sessionId, string $staffId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'staff.permissions.manage');
        $link = DB::table('staff_membership_links')
            ->where('organization_id', $snapshot['organization_id'])->where('staff_profile_id', $staffId)
            ->whereNull('unlinked_at')->first();
        if ($link === null) {
            throw ResourceDomainException::notFound('Staff profile has no current panel account link.');
        }

        $permissions = DB::table('membership_permissions')
            ->where('membership_id', $link->organization_membership_id)
            ->where('granted', true)
            ->orderBy('permission_code')
            ->pluck('permission_code')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        return ['permissions' => array_values($permissions)];
    }

    /** @param list<string> $requested */
    /**
     * @param list<string> $requested
     * @return array{permissions:list<string>}
     */
    public function replacePermissions(string $sessionId, string $staffId, array $requested, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $staffId, $requested, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'staff.permissions.manage');
            $link = DB::table('staff_membership_links')->where('organization_id', $snapshot['organization_id'])
                ->where('staff_profile_id', $staffId)->whereNull('unlinked_at')->lockForUpdate()->first();
            if ($link === null) {
                throw ResourceDomainException::notFound('Staff profile has no current panel account link.');
            }
            $membership = DB::table('organization_memberships')->where('organization_id', $snapshot['organization_id'])
                ->where('id', $link->organization_membership_id)->lockForUpdate()->first();
            if ($membership === null || $membership->status === 'revoked') {
                throw ResourceDomainException::conflict('Panel membership cannot be configured.');
            }

            $requested = array_values(array_unique($requested));
            sort($requested);
            foreach ($requested as $permission) {
                if (! DB::table('permission_scope_options')->where('permission_code', $permission)->where('scope_code', 'organization')->exists()) {
                    throw ResourceDomainException::rule("Permission {$permission} has no unambiguous organization scope in this editor.");
                }
            }

            $current = DB::table('membership_permissions')->where('membership_id', $membership->id)->where('granted', true)
                ->pluck('permission_code')->map(static fn ($v): string => (string) $v)->all();
            $all = array_values(array_unique([...$current, ...$requested]));
            sort($all);
            $version = (int) $membership->version;
            foreach ($all as $permission) {
                $grant = in_array($permission, $requested, true);
                $scopes = $grant ? ['organization'] : [];
                $result = $this->membershipGovernance->replacePermissionScopes(
                    $sessionId, (string) $membership->id, $version, $permission, $grant, $scopes, $requestId,
                );
                $version = $result['version'];
            }

            return ['permissions' => $requested];
        });
    }

    /** @param array<string,mixed> $staff */
    public function etag(array $staff): string
    {
        return '"'.hash('sha256', json_encode($staff, JSON_THROW_ON_ERROR)).'"';
    }

    /** @param array<string,mixed> $input */
    private function assertRelations(string $organizationId, array $input): void
    {
        if (array_key_exists('staff_type_codes', $input)) {
            $codes = array_values(array_unique(array_map('strval', (array) $input['staff_type_codes'])));
            $count = $codes === [] ? 0 : DB::table('staff_types')->whereIn('code', $codes)->where('active', true)->count();
            if ($codes === [] || $count !== count($codes)) {
                throw ResourceDomainException::rule('Staff type selection must contain active dictionary values.');
            }
        }
        if (array_key_exists('category_ids', $input)) {
            $ids = array_values(array_unique(array_map('strval', (array) $input['category_ids'])));
            $count = $ids === [] ? 0 : DB::table('driving_categories')->whereIn('id', $ids)->where('active', true)->count();
            if ($count !== count($ids)) {
                throw ResourceDomainException::rule('Staff category selection contains an unknown or inactive category.');
            }
        }
        if (array_key_exists('location_ids', $input)) {
            $ids = array_values(array_unique(array_map('strval', (array) $input['location_ids'])));
            $count = $ids === [] ? 0 : DB::table('locations')->where('organization_id', $organizationId)
                ->whereIn('id', $ids)->whereNull('archived_at')->count();
            if ($count !== count($ids)) {
                throw ResourceDomainException::rule('Staff location selection contains an unavailable tenant location.');
            }
        }
    }

    /** @param array<string,mixed> $input */
    private function syncRelations(string $organizationId, string $staffId, array $input): void
    {
        if (array_key_exists('staff_type_codes', $input)) {
            DB::table('staff_type_assignments')->where('staff_profile_id', $staffId)->delete();
            foreach (array_values(array_unique(array_map('strval', (array) $input['staff_type_codes']))) as $code) {
                DB::table('staff_type_assignments')->insert(['staff_profile_id' => $staffId, 'staff_type_code' => $code]);
            }
        }
        if (array_key_exists('category_ids', $input)) {
            DB::table('staff_category_assignments')->where('staff_profile_id', $staffId)->delete();
            foreach (array_values(array_unique(array_map('strval', (array) $input['category_ids']))) as $id) {
                DB::table('staff_category_assignments')->insert(['staff_profile_id' => $staffId, 'driving_category_id' => $id]);
            }
        }
        if (array_key_exists('location_ids', $input)) {
            DB::table('staff_location_assignments')->where('organization_id', $organizationId)->where('staff_profile_id', $staffId)->delete();
            foreach (array_values(array_unique(array_map('strval', (array) $input['location_ids']))) as $id) {
                DB::table('staff_location_assignments')->insert(['organization_id' => $organizationId, 'staff_profile_id' => $staffId, 'location_id' => $id]);
            }
        }
    }

    /** @return array{0:?string,1:?string} */
    private function peselStorage(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [null, null];
        }
        $normalized = (string) preg_replace('/\D/', '', (string) $value);
        if (strlen($normalized) !== 11) {
            throw ResourceDomainException::rule('PESEL must contain 11 digits.');
        }
        $key = (string) config('app.key');
        if ($key === '') {
            throw ResourceDomainException::conflict('Application encryption key is unavailable.');
        }

        return [Crypt::encryptString($normalized), hash_hmac('sha256', $normalized, $key)];
    }

    private function assertPeselAvailable(string $organizationId, ?string $hash, ?string $ignoreId): void
    {
        if ($hash === null) {
            return;
        }
        $query = DB::table('staff_profiles')->where('organization_id', $organizationId)->where('pesel_lookup_hash', $hash);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->exists()) {
            throw ResourceDomainException::conflict('PESEL is already present in organization history.');
        }
    }

    private function assertAsset(string $organizationId, mixed $assetId, string $purpose): void
    {
        if ($assetId === null) {
            return;
        }
        if (! is_string($assetId) || ! DB::table('file_assets')
            ->where('id', $assetId)->where('organization_id', $organizationId)
            ->where('purpose', $purpose)->where('status', 'ready')->exists()) {
            throw ResourceDomainException::rule('Private asset is not ready for the required tenant purpose.');
        }
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $actor
     */
    private function replaceDocumentLocked(array $actor, string $staffId, string $documentType, ?string $validUntil): void
    {
        $current = DB::table('staff_documents')->where('organization_id', $actor['organization_id'])
            ->where('staff_profile_id', $staffId)->where('document_type', $documentType)
            ->whereNull('superseded_at')->lockForUpdate()->first();
        if ($current !== null) {
            DB::table('staff_documents')->where('id', $current->id)->update([
                'superseded_at' => now(),
                'superseded_by_user_id' => $actor['user_id'],
                'supersession_reason' => 'replacement',
            ]);
        }
        if ($validUntil !== null) {
            DB::table('staff_documents')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'staff_profile_id' => $staffId,
                'document_type' => $documentType,
                'valid_until' => $validUntil,
                'created_at' => now(),
                'created_by_user_id' => $actor['user_id'],
            ]);
        }
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $actor
     */
    private function createPanelAccountLocked(array $actor, string $staffId, string $requestId): void
    {
        if ($this->hasCurrentLink($actor['organization_id'], $staffId)) {
            return;
        }
        $staff = DB::table('staff_profiles')->where('organization_id', $actor['organization_id'])->where('id', $staffId)->lockForUpdate()->first();
        if ($staff === null || $staff->archived_at !== null) {
            throw ResourceDomainException::conflict('Archived or missing staff profile cannot receive panel access.');
        }

        $email = (string) $staff->email_normalized;
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['staff-account:'.$email]);
        $identifier = DB::table('auth_login_identifiers')
            ->where('identifier_type', 'email')->where('identifier_normalized', $email)->whereNull('revoked_at')->first();

        if ($identifier === null) {
            $userId = (string) Str::uuid7();
            DB::table('users')->insert([
                'id' => $userId,
                'first_name' => (string) $staff->first_name,
                'last_name' => (string) $staff->last_name,
                'status' => 'pending_activation',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('auth_login_identifiers')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'identifier_type' => 'email',
                'identifier_normalized' => $email,
                'is_primary_for_type' => true,
                'created_at' => now(),
            ]);
        } else {
            $userId = (string) $identifier->user_id;
        }

        $membership = DB::table('organization_memberships')->where('organization_id', $actor['organization_id'])
            ->where('user_id', $userId)->lockForUpdate()->first();
        if ($membership !== null && $membership->status === 'revoked') {
            throw ResourceDomainException::conflict('Revoked membership requires explicit governance recovery.');
        }
        if ($membership === null) {
            $membershipId = (string) Str::uuid7();
            DB::table('organization_memberships')->insert([
                'id' => $membershipId,
                'organization_id' => $actor['organization_id'],
                'user_id' => $userId,
                'status' => 'suspended',
                'is_owner' => false,
                'version' => 1,
                'authorization_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $membershipId = (string) $membership->id;
        }

        if (DB::table('staff_membership_links')->where('organization_id', $actor['organization_id'])
            ->where('organization_membership_id', $membershipId)->whereNull('unlinked_at')->exists()) {
            throw ResourceDomainException::conflict('Panel membership is already linked to another staff profile.');
        }

        DB::table('staff_membership_links')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'staff_profile_id' => $staffId,
            'organization_membership_id' => $membershipId,
            'linked_at' => now(),
            'linked_by_user_id' => $actor['user_id'],
        ]);

        $this->auditOutbox->recordOrganizationEvent(
            $actor['organization_id'], $actor['id'], $actor['user_id'],
            'staff.panel_account.created', 'staff_profile', $staffId, $requestId,
            ['fields' => ['panel_access'], 'state' => 'absent', 'membership_id' => null, 'has_login_account' => false],
            ['fields' => ['panel_access'], 'state' => 'pending_activation', 'membership_id' => $membershipId, 'has_login_account' => true],
        );
    }

    private function hasCurrentLink(string $organizationId, string $staffId): bool
    {
        return DB::table('staff_membership_links')->where('organization_id', $organizationId)
            ->where('staff_profile_id', $staffId)->whereNull('unlinked_at')->exists();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $data = get_object_vars($row);
        $id = (string) ($data['id'] ?? '');
        $organizationId = (string) ($data['organization_id'] ?? '');

        $types = DB::table('staff_type_assignments')->where('staff_profile_id', $id)
            ->pluck('staff_type_code')->map(static fn ($v): string => (string) $v)->all();
        $categories = DB::table('staff_category_assignments')->where('staff_profile_id', $id)
            ->pluck('driving_category_id')->map(static fn ($v): string => (string) $v)->all();
        $locations = DB::table('staff_location_assignments')->where('organization_id', $organizationId)
            ->where('staff_profile_id', $id)->pluck('location_id')->map(static fn ($v): string => (string) $v)->all();
        $documents = DB::table('staff_documents')->where('organization_id', $organizationId)
            ->where('staff_profile_id', $id)->whereNull('superseded_at')->get()->keyBy('document_type');

        return [
            'id' => $id,
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'email' => (string) ($data['email_normalized'] ?? ''),
            'pesel_masked' => ($data['pesel_ciphertext'] ?? null) === null ? null : '***********',
            'phone' => ($data['phone'] ?? null) === null ? null : (string) $data['phone'],
            'authorization_number' => ($data['authorization_number'] ?? null) === null ? null : (string) $data['authorization_number'],
            'staff_type_codes' => array_values($types),
            'category_ids' => array_values($categories),
            'location_ids' => array_values($locations),
            'card_valid_until' => isset($documents['card_or_authorization']) ? (string) $documents['card_or_authorization']->valid_until : null,
            'medical_exam_valid_until' => isset($documents['medical_exam']) ? (string) $documents['medical_exam']->valid_until : null,
            'psychological_exam_valid_until' => isset($documents['psychological_exam']) ? (string) $documents['psychological_exam']->valid_until : null,
            'photo_asset_id' => ($data['photo_asset_id'] ?? null) === null ? null : (string) $data['photo_asset_id'],
            'has_login_account' => $this->hasCurrentLink($organizationId, $id),
            'archived_at' => ($data['archived_at'] ?? null) === null ? null : (string) $data['archived_at'],
        ];
    }
}
