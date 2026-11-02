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
            ->assertSee('RobotSys operations')
            ->assertSee('A cleaner space.')
            ->assertSee('Your first session,')
            ->assertSee(route('manual'), false)
            ->assertSee('data-login-form', false);
    }

    public function test_operator_manual_is_available_without_signing_in_and_does_not_expose_operations(): void
    {
        $this->get(route('manual'))
            ->assertOk()
            ->assertSee('Before your first session')
            ->assertSee('Choose how you want to see.')
            ->assertSee('Troubleshooting')
            ->assertSee(route('login').'#sign-in', false)
            ->assertDontSee('window.robotDashboard', false)
            ->assertDontSee('data-command=', false);

        $this->get(route('robot.dashboard.camera'))->assertRedirect(route('login'));
    }

    public function test_signed_in_admin_can_read_manual_and_return_to_workspace(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('manual'))
            ->assertOk()
            ->assertSee('Open your workspace')
            ->assertSee(route('robot.dashboard'), false)
            ->assertDontSee('Go to sign in');

        $this->get(route('robot.dashboard'))
            ->assertOk()
            ->assertSee(route('manual'), false);
    }

    public function test_failed_login_keeps_email_and_displays_accessible_errors_without_repopulating_password(): void
    {
        $this->from(route('login'))->post(route('admin.login.store'), [
            'email' => 'unknown@example.com',
            'password' => 'incorrect-password-value',
            'remember' => '1',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Unable to sign in')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('value="unknown@example.com"', false)
            ->assertDontSee('incorrect-password-value');
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
