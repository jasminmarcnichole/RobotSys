<?php

namespace Tests\Feature;

use App\Models\CameraSetting;
use App\Models\RobotLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CameraSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_tab_omits_operating_time(): void
    {
        RobotLog::factory()->create([
            'robot_id' => 'ROBOT-001',
            'operating_duration' => 125,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('robot.dashboard', ['robot_id' => 'ROBOT-001']))
            ->assertOk()
            ->assertSee('Detected trash')
            ->assertSee('Pickup success')
            ->assertDontSee('Operating time')
            ->assertDontSee('id="operating_duration"', false);
    }

    public function test_camera_settings_tab_requires_admin_and_appears_in_navigation(): void
    {
        $this->get(route('robot.dashboard.camera-settings'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('robot.dashboard.camera-settings'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('robot.dashboard.camera-settings', ['robot_id' => 'ROBOT-001']))
            ->assertOk()
            ->assertSee('Camera Settings')
            ->assertSee('Save Camera Settings')
            ->assertSee('ESP32-S3-CAM Image &amp; Sensor Settings', false)
            ->assertSee('Live ESP32-CAM Preview');
    }

    public function test_admin_can_save_and_reload_camera_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = [
            'robot_id' => 'ROBOT-001',
            'detection_model' => 'efficientdet_lite0',
            'confidence_threshold' => 75,
            'auto_grab_enabled' => true,
            'framesize' => 6,
            'quality' => 14,
            'brightness' => 1,
            'contrast' => 2,
            'saturation' => -1,
            'vflip' => false,
            'hmirror' => true,
            'led_intensity' => 45,
        ];

        $this->actingAs($admin)
            ->putJson(route('dashboard.camera.settings.update'), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.detection_model', 'efficientdet_lite0')
            ->assertJsonPath('data.confidence_threshold', 75)
            ->assertJsonPath('data.auto_grab_enabled', true)
            ->assertJsonPath('data.framesize', 6)
            ->assertJsonPath('data.led_intensity', 45)
            ->assertJsonPath('data.saved', true);

        $this->assertDatabaseHas('camera_settings', [
            'robot_id' => 'ROBOT-001',
            'detection_model' => 'efficientdet_lite0',
            'confidence_threshold' => 75,
            'auto_grab_enabled' => 1,
            'framesize' => 6,
            'quality' => 14,
            'brightness' => 1,
            'contrast' => 2,
            'saturation' => -1,
            'vflip' => 0,
            'hmirror' => 1,
            'led_intensity' => 45,
        ]);

        $this->actingAs($admin)
            ->getJson(route('dashboard.camera.settings.show', ['robot_id' => 'ROBOT-001']))
            ->assertOk()
            ->assertJsonPath('data.confidence_threshold', 75)
            ->assertJsonPath('data.hmirror', true);

        $this->actingAs($admin)
            ->get(route('robot.dashboard.camera', ['robot_id' => 'ROBOT-001']))
            ->assertOk()
            ->assertSee('value="75"', false)
            ->assertSee('75%')
            ->assertDontSee('ESP32-CAM Live Image Settings')
            ->assertDontSee('Onboard Vision (ESP32-S3)');

        $this->actingAs($admin)
            ->get(route('robot.dashboard.control', ['robot_id' => 'ROBOT-001']))
            ->assertOk()
            ->assertDontSee('ESP32-CAM Live Image Settings')
            ->assertDontSee('Onboard Vision (ESP32-S3)');
    }

    public function test_camera_settings_validation_rejects_invalid_values(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->putJson(route('dashboard.camera.settings.update'), [
                'robot_id' => 'ROBOT-001',
                'detection_model' => 'nonexistent_model',
                'confidence_threshold' => 99,
                'auto_grab_enabled' => true,
                'framesize' => 99,
                'quality' => 2,
                'brightness' => 5,
                'contrast' => -5,
                'saturation' => 4,
                'vflip' => true,
                'hmirror' => false,
                'led_intensity' => 300,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'detection_model',
                'confidence_threshold',
                'framesize',
                'quality',
                'brightness',
                'contrast',
                'saturation',
                'led_intensity',
            ]);
    }

    public function test_admin_can_reset_camera_settings_to_defaults(): void
    {
        $admin = User::factory()->admin()->create();
        CameraSetting::factory()->create([
            'robot_id' => 'ROBOT-001',
            'confidence_threshold' => 85,
            'framesize' => 9,
            'quality' => 6,
            'led_intensity' => 120,
        ]);

        $this->actingAs($admin)
            ->postJson(route('dashboard.camera.settings.reset'), ['robot_id' => 'ROBOT-001'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.confidence_threshold', 60)
            ->assertJsonPath('data.framesize', 8)
            ->assertJsonPath('data.quality', 14)
            ->assertJsonPath('data.led_intensity', 0);
    }
}
