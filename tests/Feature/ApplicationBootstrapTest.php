<?php

namespace Tests\Feature;

use InvalidArgumentException;
use Tests\TestCase;

class ApplicationBootstrapTest extends TestCase
{
    public function test_laravel_application_container_boots_in_testing_environment(): void
    {
        $this->assertTrue($this->app->bound('config'));
        $this->assertSame('testing', $this->app->environment());
    }

    public function test_authentication_configuration_neutralizes_framework_defaults_until_foundation_gate(): void
    {
        $this->assertNull(config('auth.defaults.guard'));
        $this->assertNull(config('auth.defaults.passwords'));
        $this->assertNull(config('auth.guards.web.driver'));
        $this->assertNull(config('auth.guards.web.provider'));
        $this->assertNull(config('auth.providers.users.driver'));
        $this->assertNull(config('auth.providers.users.model'));
        $this->assertNull(config('auth.passwords.users.provider'));
        $this->assertNull(config('auth.passwords.users.table'));
    }

    public function test_web_guard_cannot_be_instantiated_before_foundation_gate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->app['auth']->guard('web');
    }
}
