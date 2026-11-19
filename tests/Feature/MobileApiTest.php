<?php

namespace Tests\Feature;

use App\Models\RobotCommand;
use App\Models\RobotLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_login_returns_sanctum_token_for_admin_and_rejects_non_admin_or_invalid_credentials(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        $member = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $this->postJson(route('api.mobile.login'), [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->postJson(route('api.mobile.login'), [
            'email' => $member->email,
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $response = $this->postJson(route('api.mobile.login'), [
            'email' => $admin->email,
            'password' => 'password',
            'device_name' => 'Pixel-7-Emulator',
        ])->assertOk()->assertJsonStructure([
            'message',
            'token',
            'user' => ['id', 'name', 'email', 'is_admin'],
        ]);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.mobile.me'))
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@example.com');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('api.mobile.logout'))
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_mobile_status_and_commands_endpoints_return_eloquent_resources_and_queue_commands(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['mobile:control']);

        RobotLog::factory()->create([
            'robot_id' => 'ROBOT-001',
            'movement_status' => 'Idle',
            'battery_level' => 88,
            'detection_source' => null,
            'created_at' => now(),
        ]);

        $this->getJson(route('api.mobile.status', ['robot_id' => 'ROBOT-001']))
            ->assertOk()
            ->assertJsonPath('robot_id', 'ROBOT-001')
            ->assertJsonPath('telemetry.battery_level', 88);

        $this->postJson(route('api.mobile.commands.store'), [
            'robot_id' => 'ROBOT-001',
            'command' => 'START',
        ])->assertCreated()->assertJsonPath('data.command', 'START');

        $this->postJson(route('api.mobile.commands.store'), [
            'robot_id' => 'ROBOT-001',
            'command' => 'STOP',
        ])->assertCreated()->assertJsonPath('data.command', 'STOP');

        $this->assertSame('cancelled', RobotCommand::query()->where('command', 'START')->firstOrFail()->status);

        $this->getJson(route('api.mobile.commands', ['robot_id' => 'ROBOT-001']))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
