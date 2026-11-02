<?php

namespace Tests\Feature;

use App\Models\RobotCommand;
use App\Models\RobotLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RobotDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_overview_displays_latest_robot_metrics_and_page_navigation(): void
    {
        $admin = $this->adminUser();

        RobotLog::create([
            'robot_id' => 'ROBOT-001',
            'battery_level' => 88.5,
            'movement_status' => 'Running',
            'detected_trash_count' => 12,
            'successful_pickup_count' => 9,
            'failed_pickup_count' => 1,
            'obstacle_count' => 3,
            'operating_duration' => 125,
            'plastic_waste_count' => 7,
            'general_waste_count' => 5,
            'last_detected_trash_type' => 'Plastic Bottle',
            'plastic_bin_level' => 'Medium',
            'general_bin_level' => 'Low',
            'remarks' => 'Nominal route.',
        ]);

        $response = $this->actingAs($admin)->get(route('robot.dashboard'));

        $response
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Dashboard pages')
            ->assertSee(route('robot.dashboard.camera'), false)
            ->assertSee(route('robot.dashboard.commands'), false)
            ->assertSee('Logout')
            ->assertSee('Running')
            ->assertSee('Plastic Bottle')
            ->assertDontSee('System Capability Map')
            ->assertDontSee('Use Laptop Camera');
    }

    public function test_dashboard_displays_laptop_camera_detection_panel(): void
    {
        $response = $this->actingAs($this->adminUser())->get(route('robot.dashboard.camera'));

        $response
            ->assertOk()
            ->assertSee('Camera Detection')
            ->assertSee('Use Laptop Camera')
            ->assertSee('Live Detection')
            ->assertSee('Confirm and save')
            ->assertSee('TensorFlow Lite')
            ->assertSee('EfficientDet Lite0')
            ->assertSee('id="cameraFeed"', false);
    }

    public function test_dashboard_sections_are_available_as_separate_pages(): void
    {
        $admin = $this->adminUser();

        $pages = [
            'robot.dashboard.capabilities' => 'System Capability Map',
            'robot.dashboard.waste' => 'Waste Sorting',
            'robot.dashboard.commands' => 'Command Panel',
            'robot.dashboard.evaluation' => 'Performance Evaluation',
            'robot.dashboard.logs' => 'Recent Activity Logs',
        ];

        foreach ($pages as $route => $heading) {
            $response = $this->actingAs($admin)->get(route($route));

            $response
                ->assertOk()
                ->assertSee($heading);
        }
    }

    public function test_waste_sorting_page_does_not_include_the_command_panel(): void
    {
        $response = $this->actingAs($this->adminUser())->get(route('robot.dashboard.waste'));

        $response
            ->assertOk()
            ->assertSee('Waste Sorting')
            ->assertDontSee('Command Panel');
    }

    public function test_robot_log_endpoint_persists_container_telemetry(): void
    {
        Sanctum::actingAs($this->adminUser(), ['robot:log']);

        $response = $this->postJson(route('api.robot.log'), [
            'robot_id' => 'ROBOT-002',
            'battery_level' => 74,
            'movement_status' => 'Moving',
            'detected_trash_count' => 4,
            'successful_pickup_count' => 3,
            'failed_pickup_count' => 1,
            'obstacle_count' => 2,
            'operating_duration' => 90,
            'plastic_waste_count' => 2,
            'general_waste_count' => 2,
            'last_detected_trash_type' => 'Paper Cup',
            'plastic_bin_level' => 'Low',
            'general_bin_level' => 'Medium',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.robot_id', 'ROBOT-002');

        $this->assertDatabaseHas('robot_logs', [
            'robot_id' => 'ROBOT-002',
            'movement_status' => 'Moving',
            'last_detected_trash_type' => 'Paper Cup',
            'plastic_bin_level' => 'Low',
            'general_bin_level' => 'Medium',
        ]);
    }

    public function test_robot_log_endpoint_persists_laptop_camera_detection(): void
    {
        Sanctum::actingAs($this->adminUser(), ['robot:log']);
        Storage::fake('public');

        $response = $this->postJson(route('api.robot.log'), [
            'robot_id' => 'ROBOT-001',
            'battery_level' => 91,
            'movement_status' => 'Camera Detection',
            'detected_trash_count' => 1,
            'successful_pickup_count' => 0,
            'failed_pickup_count' => 0,
            'obstacle_count' => 0,
            'operating_duration' => 0,
            'plastic_waste_count' => 1,
            'general_waste_count' => 0,
            'last_detected_trash_type' => 'Plastic Waste',
            'detection_source' => 'laptop_camera',
            'classification_model' => 'TensorFlow.js COCO-SSD 2.2.3',
            'detected_object_label' => 'bottle',
            'detection_confidence' => 86.25,
            'detection_bbox' => [
                'x' => 14,
                'y' => 22,
                'width' => 110,
                'height' => 190,
            ],
            'camera_snapshot' => 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('snapshot.png')->getContent()),
            'plastic_bin_level' => 'Low',
            'general_bin_level' => 'Empty',
            'remarks' => 'Laptop camera detected bottle and classified it as Plastic Waste.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.movement_status', 'Camera Detection')
            ->assertJsonPath('data.last_detected_trash_type', 'Plastic Waste')
            ->assertJsonPath('data.detected_object_label', 'bottle')
            ->assertJsonPath('data.detection_bbox.x', 14);

        $snapshotPath = $response->json('data.camera_snapshot_path');

        $this->assertDatabaseHas('robot_logs', [
            'robot_id' => 'ROBOT-001',
            'movement_status' => 'Camera Detection',
            'last_detected_trash_type' => 'Plastic Waste',
            'detection_source' => 'laptop_camera',
            'classification_model' => 'TensorFlow.js COCO-SSD 2.2.3',
            'detected_object_label' => 'bottle',
            'remarks' => 'Laptop camera detected bottle and classified it as Plastic Waste.',
        ]);

        Storage::disk('public')->assertExists($snapshotPath);
    }

    public function test_robot_command_endpoint_creates_pending_command(): void
    {
        Sanctum::actingAs($this->adminUser(), ['robot:command:create']);

        $response = $this->postJson(route('api.robot.command'), [
            'robot_id' => 'ROBOT-001',
            'command' => 'START',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.command', 'START')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('robot_commands', [
            'robot_id' => 'ROBOT-001',
            'command' => 'START',
            'status' => 'pending',
        ]);
    }

    public function test_robot_command_endpoint_rejects_unsupported_commands(): void
    {
        Sanctum::actingAs($this->adminUser(), ['robot:command:create']);

        $response = $this->postJson(route('api.robot.command'), [
            'robot_id' => 'ROBOT-001',
            'command' => 'FLY',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('command');
    }

    public function test_dashboard_session_can_create_a_robot_command(): void
    {
        $this->withoutMiddleware();

        $response = $this->actingAs($this->adminUser())->postJson(route('dashboard.robot.command'), [
            'robot_id' => 'ROBOT-001',
            'command' => 'START',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('robot_commands', [
            'robot_id' => 'ROBOT-001',
            'command' => 'START',
            'status' => 'pending',
        ]);
    }

    public function test_pending_command_can_be_acknowledged(): void
    {
        Sanctum::actingAs($this->adminUser(), ['robot:command:read', 'robot:command:ack']);

        RobotCommand::create([
            'robot_id' => 'ROBOT-001',
            'command' => 'RESET',
            'status' => 'pending',
        ]);

        $pendingResponse = $this->getJson(route('api.robot.command.pending', [
            'robot_id' => 'ROBOT-001',
        ]));

        $pendingResponse
            ->assertOk()
            ->assertJsonPath('command', 'RESET')
            ->assertJsonPath('status', 'pending');

        $commandId = $pendingResponse->json('id');

        $acknowledgeResponse = $this->postJson(route('api.robot.command.acknowledge', $commandId));

        $acknowledgeResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'received');

        $this->assertDatabaseHas('robot_commands', [
            'id' => $commandId,
            'status' => 'received',
        ]);
    }

    public function test_robot_api_requires_a_token(): void
    {
        $response = $this->postJson(route('api.robot.log'), [
            'movement_status' => 'Moving',
        ]);

        $response->assertUnauthorized();
    }

    public function test_robot_api_requires_the_correct_token_ability(): void
    {
        Sanctum::actingAs($this->adminUser(), ['robot:read']);

        $response = $this->postJson(route('api.robot.log'), [
            'movement_status' => 'Moving',
        ]);

        $response->assertForbidden();
    }

    public function test_robot_command_status_can_be_completed(): void
    {
        Sanctum::actingAs($this->adminUser(), ['robot:command:ack']);

        $command = RobotCommand::create([
            'robot_id' => 'ROBOT-001',
            'command' => 'START',
            'status' => 'received',
        ]);

        $response = $this->postJson(route('api.robot.command.status', $command), [
            'robot_id' => 'ROBOT-001',
            'status' => 'completed',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('robot_commands', [
            'id' => $command->id,
            'status' => 'completed',
        ]);
    }

    public function test_command_history_refresh_is_scoped_to_the_selected_robot(): void
    {
        RobotCommand::create(['robot_id' => 'ROBOT-002', 'command' => 'START', 'status' => 'pending']);
        $command = RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'STOP', 'status' => 'completed']);

        $this->actingAs($this->adminUser())
            ->getJson('/dashboard/api/robot/commands?robot_id=ROBOT-001')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $command->id)->assertJsonPath('0.status', 'completed');
    }

    private function adminUser(): User
    {
        return User::factory()->admin()->create();
    }
}
