<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_page_renders_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in to your account');
    }

    public function test_active_user_can_sign_in_with_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'station.admin',
            'password' => 'admin123',
            'status' => 'Active',
        ]);

        $response = $this->post(route('login'), [
            'username' => 'station.admin',
            'password' => 'admin123',
        ]);

        $response->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create(['username' => 'station.admin', 'password' => 'admin123']);

        $this->from(route('login'))
            ->post(route('login'), ['username' => 'station.admin', 'password' => 'incorrect'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');
    }
}
