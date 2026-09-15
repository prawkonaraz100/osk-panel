<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FoundationReferenceCatalogSeeder::class,
            ResourceReferenceCatalogSeeder::class,
            FormalTrainingDocumentTemplateSeeder::class,
        ]);

        if ((bool) config('sample_data.enabled', false)) {
            $this->call(DevelopmentSampleDataSeeder::class);
        }
    }
}
