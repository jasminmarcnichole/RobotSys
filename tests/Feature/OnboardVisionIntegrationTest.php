<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardVisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_redirects_to_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('robot.dashboard'));
    }

    public function test_vision_provision_command_creates_scoped_sanctum_token(): void
    {
        $admin = User::factory()->admin()->create();

        $this->artisan('vision:provision', ['device' => 'CAM-S3-002'])
            ->expectsOutputToContain('Provisioned onboard vision token for CAM-S3-002:')
            ->assertSuccessful();

        $token = $admin->tokens()->first();
        $this->assertNotNull($token);
        $this->assertSame('onboard-vision:CAM-S3-002', $token->name);
        $this->assertSame(['vision:ingest', 'vision:device:CAM-S3-002'], $token->abilities);
    }
}
