<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class LanguageDictionaryRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();
    }

    public function test_languages_endpoint_returns_active_global_dictionary_without_product_availability_inference(): void
    {
        $actor = FoundationSchema::actor();

        DB::table('languages')->insert([
            'code' => 'de',
            'label_key' => 'Niemiecki',
            'active' => true,
        ]);
        DB::table('languages')->insert([
            'code' => 'zz',
            'label_key' => 'Nieaktywny',
            'active' => false,
        ]);

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/languages')
            ->assertOk();

        $this->assertSame([
            ['code' => 'de', 'label' => 'Niemiecki'],
            ['code' => 'pl', 'label' => 'Polski'],
        ], $response->json());

        $this->assertFalse(DB::table('license_product_language_capabilities')
            ->where('language_code', 'de')
            ->exists());
    }

    public function test_languages_endpoint_requires_an_authenticated_application_session(): void
    {
        $this->getJson('/api/v1/languages')->assertUnauthorized();
    }
}
