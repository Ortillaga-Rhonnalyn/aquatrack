<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        $user = User::factory()->create(['full_name' => 'Aqua Administrator', 'role' => 'Admin']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Aqua Administrator')
            ->assertSee('STATION OVERVIEW');
    }

    public function test_staff_user_can_view_staff_dashboard_but_not_admin_dashboard(): void
    {
        $staff = User::factory()->create(['role' => 'Staff']);

        $this->actingAs($staff)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('YOUR WORKSPACE');

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertForbidden();
    }
}
