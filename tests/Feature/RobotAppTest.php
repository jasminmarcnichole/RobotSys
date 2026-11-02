<?php

namespace Tests\Feature;

use App\Models\RobotCommand;
use App\Models\RobotLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RobotAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_workspace_requires_an_administrator_and_contains_both_workflows(): void
    {
        $this->get(route('robot.dashboard.control'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('robot.dashboard.control'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get(route('robot.dashboard.control'))
            ->assertOk()->assertSee('Control & machine vision', false)
            ->assertSee('Command Panel')->assertSee('Camera Detection')
            ->assertSee('app.webmanifest')->assertSee('data-app-install', false)
            ->assertSee('data-control-app', false)
            ->assertSee('compatible ESP32 motor-control firmware');
    }

    public function test_manifest_and_icons_define_a_protected_installable_workspace(): void
    {
        $manifest = json_decode(file_get_contents(public_path('app.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/dashboard/control', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);

        foreach ($manifest['icons'] as $icon) {
            $dimensions = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], $dimensions[0].'x'.$dimensions[1]);
            $this->assertSame('image/png', $dimensions['mime']);
        }

        $this->get($manifest['start_url'])->assertRedirect(route('login'));
        $this->get(route('manual'))->assertOk()->assertSee('Install the app')->assertSee('Add to Home Screen');
    }

    public function test_app_commands_require_administrator_access(): void
    {
        $this->withoutMiddleware(PreventStaleRobotCommands::class);
        $this->postJson(route('dashboard.robot.control'), ['robot_id' => 'ROBOT-001', 'command' => 'STOP'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(route('dashboard.robot.control'), ['robot_id' => 'ROBOT-001', 'command' => 'STOP'])->assertForbidden();
        $this->assertDatabaseCount('robot_commands', 0);
    }

    public function test_signing_in_after_an_app_launch_returns_to_the_control_workspace(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password']);
        $this->get(route('robot.dashboard.control'))->assertRedirect(route('login'));

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('robot.dashboard.control'));
    }

    public function test_start_and_reset_require_recent_robot_sensor_telemetry(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach (['START', 'RESET'] as $command) {
            $this->postJson(route('dashboard.robot.control'), ['robot_id' => 'ROBOT-001', 'command' => $command])->assertConflict();
        }

        RobotLog::factory()->create(['robot_id' => 'ROBOT-001', 'created_at' => now()->subSeconds(16)]);
        RobotLog::factory()->create(['robot_id' => 'ROBOT-002', 'detection_source' => null]);
        RobotLog::factory()->create(['robot_id' => 'ROBOT-001', 'detection_source' => 'laptop_camera']);
        RobotLog::factory()->create(['robot_id' => 'ROBOT-001', 'detection_source' => 'robot_camera']);
        RobotLog::factory()->create(['robot_id' => 'ROBOT-001', 'detection_source' => null, 'created_at' => now()->addMinute()]);

        $this->postJson(route('dashboard.robot.control'), ['robot_id' => 'ROBOT-001', 'command' => 'START'])->assertConflict();
        $this->assertDatabaseCount('robot_commands', 0);
    }

    public function test_app_start_expires_in_five_seconds_and_a_robot_can_acknowledge_before_expiry(): void
    {
        $this->withoutMiddleware(PreventStaleRobotCommands::class);
        $this->travelTo(now()->startOfSecond());
        $admin = User::factory()->admin()->create();
        RobotLog::factory()->create(['robot_id' => 'ROBOT-001', 'detection_source' => null]);

        $response = $this->actingAs($admin)->postJson(route('dashboard.robot.control'), ['robot_id' => 'ROBOT-001', 'command' => 'START'])
            ->assertOk()->assertJsonPath('data.status', 'pending');

        $command = RobotCommand::findOrFail($response->json('data.id'));
        $this->assertTrue($command->expires_at->equalTo(now()->addSeconds(5)));

        Sanctum::actingAs($admin, ['robot:command:ack']);
        $this->postJson(route('api.robot.command.acknowledge', $command), ['robot_id' => 'ROBOT-001'])
            ->assertOk()->assertJsonPath('data.status', 'received');
    }

    public function test_stop_can_be_queued_without_telemetry_and_cancels_pending_actions_only_for_that_robot(): void
    {
        $this->withoutMiddleware(PreventStaleRobotCommands::class);
        $older = RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'START', 'status' => 'pending']);
        $other = RobotCommand::create(['robot_id' => 'ROBOT-002', 'command' => 'START', 'status' => 'pending']);
        $active = RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'RESET', 'status' => 'received']);

        $this->actingAs(User::factory()->admin()->create())->postJson(route('dashboard.robot.control'), ['robot_id' => 'ROBOT-001', 'command' => 'STOP'])
            ->assertOk()->assertJsonPath('data.expires_at', null);

        $this->assertSame('cancelled', $older->fresh()->status);
        $this->assertSame('pending', $other->fresh()->status);
        $this->assertSame('received', $active->fresh()->status);
    }

    public function test_pending_api_excludes_expired_actions_and_prioritizes_stop(): void
    {
        $this->freezeTime();
        $expired = RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'START', 'status' => 'pending', 'expires_at' => now()]);
        $stop = RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'STOP', 'status' => 'pending']);
        RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'RESET', 'status' => 'pending', 'expires_at' => now()->addSeconds(5)]);

        Sanctum::actingAs(User::factory()->admin()->create(), ['robot:command:read']);
        $this->getJson(route('api.robot.command.pending'))->assertOk()->assertJsonPath('id', $stop->id);
        $this->assertSame('expired', $expired->fresh()->status);
    }

    public function test_history_reports_expiry_even_when_the_robot_has_not_polled_for_commands(): void
    {
        $expired = RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'START', 'status' => 'pending', 'expires_at' => now()->subSecond()]);
        $other = RobotCommand::create(['robot_id' => 'ROBOT-002', 'command' => 'START', 'status' => 'pending', 'expires_at' => now()->subSecond()]);

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('dashboard.robot.commands', ['robot_id' => 'ROBOT-001']))
            ->assertOk()->assertJsonPath('0.id', $expired->id)->assertJsonPath('0.status', 'expired');

        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_expired_and_cancelled_commands_cannot_be_acknowledged_or_completed(): void
    {
        $this->freezeTime();
        Sanctum::actingAs(User::factory()->admin()->create(), ['robot:command:ack']);

        foreach (['pending', 'cancelled'] as $status) {
            $command = RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'START', 'status' => $status, 'expires_at' => now()]);
            $this->postJson(route('api.robot.command.acknowledge', $command))->assertConflict();
            $this->postJson(route('api.robot.command.status', $command), ['status' => 'completed'])->assertConflict();
            $this->assertContains($command->fresh()->status, ['expired', 'cancelled']);
        }
    }

    public function test_app_rejects_unsupported_commands_and_invalid_robot_ids(): void
    {
        $this->withoutMiddleware(PreventStaleRobotCommands::class);
        $this->actingAs(User::factory()->admin()->create());
        $this->postJson(route('dashboard.robot.control'), ['robot_id' => 'ROBOT-001', 'command' => 'FORWARD'])->assertUnprocessable()->assertJsonValidationErrors('command');
        $this->postJson(route('dashboard.robot.control'), ['robot_id' => ['ROBOT-001'], 'command' => 'START'])->assertUnprocessable()->assertJsonValidationErrors('robot_id');
        $this->assertDatabaseCount('robot_commands', 0);
    }

    public function test_pending_delivery_and_acknowledgement_remain_scoped_to_the_robot(): void
    {
        $command = RobotCommand::create(['robot_id' => 'ROBOT-002', 'command' => 'START', 'status' => 'pending']);
        Sanctum::actingAs(User::factory()->admin()->create(), ['robot:command:read', 'robot:command:ack']);
        $this->getJson(route('api.robot.command.pending', ['robot_id' => 'ROBOT-001']))->assertOk()->assertJsonCount(0);
        $this->postJson(route('api.robot.command.acknowledge', $command), ['robot_id' => 'ROBOT-001'])->assertNotFound();
        $this->assertSame('pending', $command->fresh()->status);
    }

    public function test_configured_proxy_preserves_https_links_for_hosted_camera_and_installation(): void
    {
        TrustProxies::flushState();
        config(['trustedproxy.proxies' => '127.0.0.1']);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://robotsys.example/admin/login')->assertOk()
            ->assertSee('https://robotsys.example/app.webmanifest', false);

        TrustProxies::flushState();
    }
}
