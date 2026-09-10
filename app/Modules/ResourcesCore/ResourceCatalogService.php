<?php

namespace App\Modules\ResourcesCore;

use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Support\Facades\DB;

final class ResourceCatalogService
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceScopeAuthorizer $scopeAuthorizer,
    ) {}

    /** @return list<array{code:string,label:string}> */
    public function locationTypes(string $sessionId): array
    {
        $this->scopeAuthorizer->visibility($sessionId, 'locations.view');

        return DB::table('location_types')
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(static fn ($row): array => [
                'code' => (string) $row->code,
                'label' => (string) $row->label_key,
            ])
            ->all();
    }

    /** @return list<array{code:string,label:string}> */
    public function staffTypes(string $sessionId): array
    {
        $this->scopeAuthorizer->visibility($sessionId, 'staff.view');

        return DB::table('staff_types')
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(static fn ($row): array => [
                'code' => (string) $row->code,
                'label' => (string) $row->label_key,
            ])
            ->all();
    }

    /** @return list<array{id:string,code:string,label:string,active:bool}> */
    public function drivingCategories(string $sessionId): array
    {
        $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::table('driving_categories')
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(static fn ($row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'label' => (string) $row->label,
                'active' => (bool) $row->active,
            ])
            ->all();
    }

    /**
     * Temporary own-product fallback until the canonical locality directory is selected.
     * The typed normalized locality becomes its stable request reference; no external
     * locality authority is invented here.
     *
     * @return list<array{reference:string,name:string,voivodeship_name:?string}>
     */
    public function cities(string $sessionId, ?string $q): array
    {
        $this->scopeAuthorizer->visibility($sessionId, 'locations.view');

        $query = trim((string) $q);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 160) {
            return [];
        }

        return [[
            'reference' => $query,
            'name' => $query,
            'voivodeship_name' => null,
        ]];
    }
}
