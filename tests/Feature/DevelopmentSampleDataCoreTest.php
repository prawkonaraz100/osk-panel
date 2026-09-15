<?php

namespace Tests\Feature;

use Database\Seeders\DevelopmentSampleDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class DevelopmentSampleDataCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
        config()->set('sample_data.enabled', true);
        config()->set('commerce.order_create.pricing_by_catalog_code', null);
        app(DevelopmentSampleDataSeeder::class)->run();
    }

    public function test_sample_terms_are_discoverable_renderable_and_accepted_by_registration(): void
    {
        $this->getJson('/api/v1/development/sample/legal/terms/current')
            ->assertOk()
            ->assertJsonPath('document_type', 'terms')
            ->assertJsonPath('version', 'sample-terms-v1')
            ->assertJsonPath('document_url', '/regulamin/sample-terms-v1')
            ->assertJsonPath('sample_data', true);

        $this->get('/regulamin/sample-terms-v1')
            ->assertOk()
            ->assertSee('WERSJA PRZYKŁADOWA / DEWELOPERSKA')
            ->assertSee('sample-terms-v1');

        $email = 'sample-'.Str::lower((string) Str::ulid()).'@example.test';
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jan',
            'last_name' => 'Przykładowy',
            'email' => $email,
            'password' => 'Sample-Password-42!',
            'organization_name' => 'Przykładowy OSK',
            'accepted_terms_version' => 'sample-terms-v1',
            'marketing_consent' => false,
        ])->assertCreated();

        $this->assertSame(1, DB::table('terms_acceptances')
            ->join('legal_documents', 'legal_documents.id', '=', 'terms_acceptances.legal_document_id')
            ->where('legal_documents.document_type', 'terms')
            ->where('legal_documents.version', 'sample-terms-v1')
            ->count());

        app(DevelopmentSampleDataSeeder::class)->run();
        $this->assertSame(1, DB::table('legal_documents')
            ->where('document_type', 'terms')
            ->where('version', 'sample-terms-v1')
            ->count());
    }

    public function test_sample_license_catalog_projects_same_server_price_used_by_order_create(): void
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'licenses.view', ['organization']);
        FoundationSchema::grant($actor['membership_id'], 'licenses.purchase', ['organization']);

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/license-products')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.code', 'SAMPLE-LICENSE-1M')
            ->assertJsonPath('0.display_name', 'Przykładowa licencja 1 miesiąc')
            ->assertJsonPath('0.price.amount_minor', 1450)
            ->assertJsonPath('0.price.currency', 'PLN')
            ->assertJsonPath('0.list_price.amount_minor', 2900)
            ->assertJsonPath('0.pricing_revision', 'sample-dev-2026-09-15-v1')
            ->assertJsonPath('0.sample_data', true);

        $productId = (string) $response->json('0.id');

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/license-orders', [
                'items' => [[
                    'product_id' => $productId,
                    'quantity' => 1,
                ]],
                'payment_method' => 'bank_transfer',
            ])
            ->assertCreated()
            ->assertJsonPath('total.amount_minor', 1450)
            ->assertJsonPath('total.currency', 'PLN')
            ->assertJsonPath('items.0.list_unit_amount_minor', 2900)
            ->assertJsonPath('items.0.unit_amount_minor', 1450)
            ->assertJsonPath('items.0.pricing_snapshot.pricing_revision', 'sample-dev-2026-09-15-v1');
    }

    public function test_sample_internal_exam_offer_uses_same_server_price_used_by_order_create(): void
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'exams.purchase', ['organization']);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/internal-exam/purchase-offer')
            ->assertOk()
            ->assertJsonPath('display_name', 'Przykładowa pula egzaminów wewnętrznych')
            ->assertJsonPath('unit_price.amount_minor', 200)
            ->assertJsonPath('unit_price.currency', 'PLN')
            ->assertJsonPath('list_unit_price.amount_minor', 200)
            ->assertJsonPath('pricing_revision', 'sample-dev-2026-09-15-v1')
            ->assertJsonPath('sample_data', true);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/internal-exam/orders', [
                'quantity' => 2,
                'payment_method' => 'bank_transfer',
            ])
            ->assertCreated()
            ->assertJsonPath('total.amount_minor', 400)
            ->assertJsonPath('total.currency', 'PLN')
            ->assertJsonPath('items.0.product_kind', 'internal_exam')
            ->assertJsonPath('items.0.unit_amount_minor', 200)
            ->assertJsonPath('items.0.quantity', 2);
    }

    public function test_sample_accepted_terms_get_real_local_document_url_only_while_sample_mode_is_enabled(): void
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'organization.view', ['organization']);

        $termsId = (string) DB::table('legal_documents')
            ->where('document_type', 'terms')
            ->where('version', 'sample-terms-v1')
            ->value('id');

        DB::table('terms_acceptances')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'user_id' => $actor['user_id'],
            'legal_document_id' => $termsId,
            'accepted_at' => now(),
            'request_id' => (string) Str::uuid7(),
        ]);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/organization/accepted-terms')
            ->assertOk()
            ->assertJsonPath('0.version', 'sample-terms-v1')
            ->assertJsonPath('0.document_url', '/regulamin/sample-terms-v1');

        config()->set('sample_data.enabled', false);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/organization/accepted-terms')
            ->assertOk()
            ->assertJsonPath('0.document_url', null);

        $this->getJson('/api/v1/development/sample/legal/terms/current')->assertNotFound();
        $this->get('/regulamin/sample-terms-v1')->assertNotFound();
    }
}
