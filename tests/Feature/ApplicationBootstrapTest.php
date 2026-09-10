<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationBootstrapTest extends TestCase
{
    public function test_laravel_application_container_boots_in_testing_environment(): void
    {
        $this->assertTrue($this->app->bound('config'));
        $this->assertSame('testing', $this->app->environment());
    }
}
