<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CommerceOrderCreateAuthorityContractTest extends TestCase
{
    public function test_commerce_order_create_authority_is_server_priced_and_allocator_safe(): void
    {
        $root = dirname(__DIR__, 2);
        $design = (string) file_get_contents($root.'/specs/design/commerce-order-create.yml');
        $migration = (string) file_get_contents($root.'/specs/database/commerce-order-sequence-migration-extension.yml');
        $commerce = (string) file_get_contents($root.'/specs/database/student-finance-commerce.yml');
        $licensesApi = (string) file_get_contents($root.'/specs/api/paths/licenses.yaml');
        $examsApi = (string) file_get_contents($root.'/specs/api/paths/internal-exams.yaml');
        $components = (string) file_get_contents($root.'/specs/api/openapi-components-v1.yaml');

        self::assertMatchesRegularExpression('/status: (?:CANDIDATE|PASS)/', $design);
        self::assertStringContainsString('license_orders.create', $design);
        self::assertStringContainsString('exam_orders.create', $design);
        self::assertStringContainsString('source: trusted_server_config', $design);
        self::assertStringContainsString('config_key: commerce.order_create.pricing_by_catalog_code', $design);
        self::assertStringContainsString('repository_default_prices: forbidden', $design);
        self::assertStringContainsString('historical_order_items_as_current_price_source: forbidden', $design);
        self::assertStringContainsString('client_price_discount_vat_total_authority: forbidden', $design);
        self::assertStringContainsString('active_row_cardinality: exactly_one', $design);
        self::assertStringContainsString('config_key: commerce.order_create.internal_exam_catalog_code', $design);
        self::assertStringContainsString('create_pending_payment_attempt_atomically: true', $design);
        self::assertStringContainsString('create_payment_attempt: false', $design);
        self::assertStringContainsString('canonical_table: organization_commerce_order_sequences', $design);
        self::assertStringContainsString('runtime_MAX_order_sequence_plus_one: forbidden', $design);
        self::assertStringContainsString('missing_row_with_existing_orders: fail_closed', $design);
        self::assertStringContainsString('lock: exact_allocator_row_FOR_UPDATE', $design);
        self::assertStringContainsString('provider_network_IO: forbidden', $design);
        self::assertStringContainsString('authority_gate_executes_DDL: false', $design);
        self::assertStringContainsString('PKK_or_PWPW_runtime: forbidden', $design);

        self::assertMatchesRegularExpression('/status: (?:CANDIDATE|PASS)/', $migration);
        self::assertStringContainsString('extension_id: commerce_order_sequence_allocator_v1', $migration);
        self::assertStringContainsString('root: database/migrations/stage5/commerce-order-sequence', $migration);
        self::assertStringContainsString('shared_postgres_advisory_lock: [519662, 5001]', $migration);
        self::assertStringContainsString('node_id: S5COM-TBL-ORGANIZATION-COMMERCE-ORDER-SEQUENCES', $migration);
        self::assertStringContainsString('organization_commerce_order_sequences', $migration);
        self::assertStringContainsString('formula: COALESCE(MAX_existing_orders_order_sequence_0)_plus_1', $migration);
        self::assertStringContainsString('update_existing_order_rows: forbidden', $migration);
        self::assertStringContainsString('next_order_sequence_gt_every_existing_order_sequence_for_same_organization', $migration);

        self::assertStringContainsString('model: organization_commerce_order_sequences', $commerce);
        self::assertStringContainsString('application_MAX_plus_1_allocation: forbidden', $commerce);
        self::assertStringContainsString('client_supplied_sequence: forbidden', $commerce);
        self::assertStringContainsString('zero_total_paid_resolution_created_atomically_with_order_creation: true', $commerce);
        self::assertStringContainsString('server_resolves_catalog_and_pricing_before_insert: true', $commerce);

        self::assertStringContainsString('x-requirement-id: license_orders.create', $licensesApi);
        self::assertStringContainsString('x-requirement-id: exam_orders.create', $examsApi);
        self::assertStringContainsString('LicenseOrderRequest:', $components);
        self::assertStringContainsString('required: [items, payment_method]', $components);
        self::assertStringNotContainsString('price_minor', $licensesApi);
        self::assertStringNotContainsString('vat_rate', $licensesApi);
    }
}
