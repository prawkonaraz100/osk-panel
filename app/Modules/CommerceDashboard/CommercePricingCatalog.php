<?php

namespace App\Modules\CommerceDashboard;

use App\Modules\ResourcesCore\ResourceDomainException;

final class CommercePricingCatalog
{
    /**
     * @return array{
     *   currency:string,
     *   list_unit_amount_minor:int,
     *   charged_unit_amount_minor:int,
     *   vat_rate_basis_points:int,
     *   display_name:string,
     *   pricing_revision:string,
     *   sample_data:bool
     * }
     */
    public function require(string $catalogCode): array
    {
        [$registry, $sampleData] = $this->registry();

        if (! is_array($registry)
            || ! array_key_exists($catalogCode, $registry)
            || ! is_array($registry[$catalogCode])) {
            throw ResourceDomainException::conflict(
                'Current commerce pricing configuration is unavailable for the requested catalog item.',
            );
        }

        $entry = $registry[$catalogCode];
        $currency = $entry['currency'] ?? null;
        $list = $entry['list_unit_amount_minor'] ?? null;
        $charged = $entry['charged_unit_amount_minor'] ?? null;
        $vat = $entry['vat_rate_basis_points'] ?? null;
        $displayName = $entry['display_name'] ?? null;
        $revision = $entry['pricing_revision'] ?? null;

        if (! is_string($currency)
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || ! is_int($list)
            || $list < 0
            || ! is_int($charged)
            || $charged < 0
            || $charged > $list
            || ! is_int($vat)
            || $vat < 0
            || $vat > 10000
            || ! is_string($displayName)
            || trim($displayName) === ''
            || ! is_string($revision)
            || trim($revision) === '') {
            throw ResourceDomainException::conflict('Current commerce pricing configuration is malformed.');
        }

        return [
            'currency' => $currency,
            'list_unit_amount_minor' => $list,
            'charged_unit_amount_minor' => $charged,
            'vat_rate_basis_points' => $vat,
            'display_name' => trim($displayName),
            'pricing_revision' => trim($revision),
            'sample_data' => $sampleData,
        ];
    }

    /** @return array<string,mixed>|null */
    public function optional(string $catalogCode): ?array
    {
        try {
            return $this->require($catalogCode);
        } catch (ResourceDomainException) {
            return null;
        }
    }

    /** @return array{0:?array<string,mixed>,1:bool} */
    private function registry(): array
    {
        $configured = config('commerce.order_create.pricing_by_catalog_code');
        if (is_array($configured)) {
            return [$configured, false];
        }

        if ((bool) config('sample_data.enabled', false)) {
            $sample = config('sample_data.license_pricing');

            return [is_array($sample) ? $sample : null, true];
        }

        return [null, false];
    }
}
