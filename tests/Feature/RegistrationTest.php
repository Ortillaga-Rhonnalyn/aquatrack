<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_page_renders_for_guests(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Create your staff account');
    }

    public function test_guest_can_register_a_staff_account(): void
    {
        $response = $this->post(route('register'), [
            'full_name' => 'Maria Santos',
            'username' => 'maria_santos',
            'email' => 'maria@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertRedirect(route('staff.dashboard'));
        $this->assertDatabaseHas('users', [
            'full_name' => 'Maria Santos',
            'username' => 'maria_santos',
            'role' => 'Staff',
            'status' => 'Active',
        ]);
        $this->assertAuthenticated();
    }

    public function test_registration_rejects_mismatched_password_confirmation(): void
    {
        $this->from(route('register'))
            ->post(route('register'), [
                'full_name' => 'Maria Santos',
                'username' => 'maria_santos',
                'email' => 'maria@example.com',
                'password' => 'new-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('password');
    }
}
