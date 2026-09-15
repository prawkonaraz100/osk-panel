<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DevelopmentSampleDataSeeder extends Seeder
{
    private const TERMS_ID = '019a0000-0000-7000-8000-000000000001';

    /** @var array<string,array{id:string,catalog_id:string,duration_days:int}> */
    private const LICENSES = [
        'SAMPLE-LICENSE-1M' => [
            'id' => '019a0000-0000-7000-8000-000000000101',
            'catalog_id' => '019a0000-0000-7000-8000-000000000201',
            'duration_days' => 30,
        ],
        'SAMPLE-LICENSE-3M' => [
            'id' => '019a0000-0000-7000-8000-000000000102',
            'catalog_id' => '019a0000-0000-7000-8000-000000000202',
            'duration_days' => 90,
        ],
        'SAMPLE-LICENSE-6M' => [
            'id' => '019a0000-0000-7000-8000-000000000103',
            'catalog_id' => '019a0000-0000-7000-8000-000000000203',
            'duration_days' => 180,
        ],
    ];

    public function run(): void
    {
        if (! (bool) config('sample_data.enabled', false)) {
            return;
        }
        if (app()->environment('production')) {
            throw new LogicException('Development sample data cannot be seeded in production.');
        }

        $terms = config('sample_data.terms');
        if (! is_array($terms)) {
            throw new LogicException('Sample terms configuration is missing.');
        }

        $view = (string) ($terms['view'] ?? '');
        $viewPath = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');
        if ($view === '' || ! is_file($viewPath)) {
            throw new LogicException('Sample terms view is missing.');
        }
        $contentHash = hash_file('sha256', $viewPath);
        if (! is_string($contentHash)) {
            throw new LogicException('Sample terms content hash could not be calculated.');
        }

        DB::transaction(function () use ($terms, $contentHash): void {
            $documentType = (string) ($terms['document_type'] ?? '');
            $version = (string) ($terms['version'] ?? '');
            $existing = DB::table('legal_documents')
                ->where('document_type', $documentType)
                ->where('version', $version)
                ->first();

            if ($existing === null) {
                DB::table('legal_documents')->insert([
                    'id' => self::TERMS_ID,
                    'document_type' => $documentType,
                    'version' => $version,
                    'content_hash' => $contentHash,
                    'storage_asset_id' => null,
                    'published_at' => (string) $terms['published_at'],
                    'effective_from' => (string) $terms['effective_from'],
                    'created_at' => now(),
                ]);
            } elseif ((string) $existing->content_hash !== $contentHash) {
                throw new LogicException('Sample legal-document version is immutable and its content hash changed.');
            }

            $pricing = config('sample_data.license_pricing');
            if (! is_array($pricing)) {
                throw new LogicException('Sample license pricing configuration is missing.');
            }

            foreach (self::LICENSES as $catalogCode => $definition) {
                if (! array_key_exists($catalogCode, $pricing)) {
                    throw new LogicException("Missing sample pricing for {$catalogCode}.");
                }

                $product = DB::table('license_products')->where('code', $catalogCode)->first();
                if ($product === null) {
                    DB::table('license_products')->insert([
                        'id' => $definition['id'],
                        'code' => $catalogCode,
                        'duration_days' => $definition['duration_days'],
                        'active' => true,
                        'activation_mode' => 'manual',
                        'metadata' => json_encode([
                            'sample_data' => true,
                            'display_label' => $pricing[$catalogCode]['display_name'] ?? $catalogCode,
                        ], JSON_THROW_ON_ERROR),
                    ]);
                } elseif ((string) $product->id !== $definition['id']) {
                    throw new LogicException("Sample license code {$catalogCode} is already owned by another product.");
                }

                $catalog = DB::table('commerce_catalog_items')->where('code', $catalogCode)->first();
                if ($catalog === null) {
                    DB::table('commerce_catalog_items')->insert([
                        'id' => $definition['catalog_id'],
                        'code' => $catalogCode,
                        'product_kind' => 'license',
                        'license_product_id' => $definition['id'],
                        'active' => true,
                        'created_at' => now(),
                    ]);
                } elseif ((string) $catalog->license_product_id !== $definition['id']
                    || (string) $catalog->product_kind !== 'license') {
                    throw new LogicException("Sample commerce catalog code {$catalogCode} conflicts with existing authority.");
                }

                DB::table('license_product_language_capabilities')->insertOrIgnore([
                    'id' => str_replace('0000000001', '0000000003', $definition['id']),
                    'license_product_id' => $definition['id'],
                    'language_code' => 'pl',
                    'enabled_at' => (string) $terms['effective_from'],
                    'disabled_at' => null,
                    'created_at' => now(),
                ]);
            }
        });
    }
}
