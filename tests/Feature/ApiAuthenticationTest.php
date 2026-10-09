<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_api_login_returns_bearer_token_for_active_user(): void
    {
        User::factory()->create(['username' => 'api.staff', 'password' => 'api-password']);

        $response = $this->postJson('/api/login', [
            'username' => 'api.staff',
            'password' => 'api-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.username', 'api.staff');
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_api_user_endpoint_requires_bearer_authentication(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_api_user_endpoint_returns_authenticated_token_owner(): void
    {
        User::factory()->create(['username' => 'api.staff', 'password' => 'api-password']);

        $token = $this->postJson('/api/login', [
            'username' => 'api.staff',
            'password' => 'api-password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.username', 'api.staff');
    }
}
