<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class OrganizationAcceptedTermsRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();
    }

    public function test_accepted_terms_get_returns_exact_tenant_acceptance_without_request_metadata(): void
    {
        $actor = FoundationSchema::actor();
        $foreign = FoundationSchema::actor();

        $acceptedDocumentId = (string) Str::uuid7();
        $newerUnacceptedDocumentId = (string) Str::uuid7();
        $foreignDocumentId = (string) Str::uuid7();

        DB::table('legal_documents')->insert([
            [
                'id' => $acceptedDocumentId,
                'document_type' => 'terms',
                'version' => 'terms-2026-08-01',
                'content_hash' => hash('sha256', 'accepted terms'),
                'storage_asset_id' => null,
                'published_at' => '2026-08-01 08:00:00+00',
                'effective_from' => '2026-08-01 08:00:00+00',
                'created_at' => '2026-08-01 08:00:00+00',
            ],
            [
                'id' => $newerUnacceptedDocumentId,
                'document_type' => 'terms',
                'version' => 'terms-2026-09-01',
                'content_hash' => hash('sha256', 'newer terms'),
                'storage_asset_id' => null,
                'published_at' => '2026-09-01 08:00:00+00',
                'effective_from' => '2026-09-01 08:00:00+00',
                'created_at' => '2026-09-01 08:00:00+00',
            ],
            [
                'id' => $foreignDocumentId,
                'document_type' => 'terms',
                'version' => 'foreign-terms-2026-09-01',
                'content_hash' => hash('sha256', 'foreign terms'),
                'storage_asset_id' => null,
                'published_at' => '2026-09-01 08:00:00+00',
                'effective_from' => '2026-09-01 08:00:00+00',
                'created_at' => '2026-09-01 08:00:00+00',
            ],
        ]);

        DB::table('terms_acceptances')->insert([
            [
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'user_id' => $actor['user_id'],
                'legal_document_id' => $acceptedDocumentId,
                'accepted_at' => '2026-08-15 10:15:00+00',
                'ip_hash' => 'private-ip-hash',
                'user_agent' => 'private-user-agent',
                'request_id' => 'private-request-id',
            ],
            [
                'id' => (string) Str::uuid7(),
                'organization_id' => $foreign['organization_id'],
                'user_id' => $foreign['user_id'],
                'legal_document_id' => $foreignDocumentId,
                'accepted_at' => '2026-09-02 11:30:00+00',
                'ip_hash' => 'foreign-private-ip-hash',
                'user_agent' => 'foreign-private-user-agent',
                'request_id' => 'foreign-private-request-id',
            ],
        ]);

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/organization/accepted-terms')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.version', 'terms-2026-08-01')
            ->assertJsonPath('0.accepted_by_user_id', $actor['user_id'])
            ->assertJsonPath('0.document_url', null);

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertIsArray($payload[0]);
        $this->assertSame('2026-08-15T10:15:00+00:00', $payload[0]['accepted_at']);

        foreach (['ip_hash', 'user_agent', 'request_id', 'legal_document_id', 'organization_id'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $payload[0]);
        }

        $this->assertStringNotContainsString('terms-2026-09-01', $response->getContent());
        $this->assertStringNotContainsString('foreign-terms-2026-09-01', $response->getContent());
        $this->assertStringNotContainsString('private-user-agent', $response->getContent());
        $this->assertStringNotContainsString('private-request-id', $response->getContent());
    }

    public function test_accepted_terms_get_requires_current_organization_view_permission(): void
    {
        $actor = FoundationSchema::actor();

        DB::table('membership_permissions')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.view')
            ->update(['granted' => false]);
        DB::table('membership_permission_scopes')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.view')
            ->delete();

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/organization/accepted-terms')
            ->assertForbidden();
    }
}
