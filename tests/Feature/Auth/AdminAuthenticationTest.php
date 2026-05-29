<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_page_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response
            ->assertOk()
            ->assertSee('Admin Login')
            ->assertSee('Admin operations console');
    }

    public function test_admin_can_authenticate(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response = $this->post(route('admin.login.store'), [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('robot.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_non_admin_user_cannot_authenticate_as_admin(): void
    {
        User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'password',
        ]);

        $response = $this->post(route('admin.login.store'), [
            'email' => 'operator@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        $response = $this->get(route('robot.dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_user_cannot_access_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('robot.dashboard'));

        $response->assertForbidden();
    }

    public function test_admin_can_logout(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.logout'));

        $response->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
