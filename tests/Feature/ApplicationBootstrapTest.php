<?php

namespace Tests\Feature;

use App\Modules\IdentityTenant\Models\User;
use Tests\TestCase;

class ApplicationBootstrapTest extends TestCase
{
    public function test_laravel_application_container_boots_in_testing_environment(): void
    {
        $this->assertTrue($this->app->bound('config'));
        $this->assertSame('testing', $this->app->environment());
    }

    public function test_foundation_gate_enables_only_the_real_user_session_guard(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertNull(config('auth.defaults.passwords'));
        $this->assertSame('session', config('auth.guards.web.driver'));
        $this->assertSame('users', config('auth.guards.web.provider'));
        $this->assertSame('eloquent', config('auth.providers.users.driver'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertNull(config('auth.passwords.users.table'));
    }

    public function test_web_guard_is_instantiable_after_identity_foundation_materialization(): void
    {
        $this->assertNotNull($this->app['auth']->guard('web'));
    }
}
