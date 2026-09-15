<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'security.sensitive_identifiers.lookup_key' => hash('sha256', 'osk-panel-test-sensitive-lookup-v1'),
            'security.sensitive_identifiers.previous_lookup_keys' => [],
        ]);
    }
}
