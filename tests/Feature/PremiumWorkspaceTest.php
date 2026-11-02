<?php

namespace Tests\Feature;

use App\Models\RobotCommand;
use App\Models\RobotLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PremiumWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_robot_scopes_metrics_reviews_commands_and_navigation(): void
    {
        RobotLog::factory()->create(['robot_id' => 'ROBOT-001', 'battery_level' => 99]);
        $selected = RobotLog::factory()->create(['robot_id' => 'ROBOT-002', 'battery_level' => 42, 'created_at' => now()->subMinute()]);
        RobotLog::factory()->create(['robot_id' => 'ROBOT-002', 'detection_source' => 'robot_camera', 'detected_object_label' => 'Selected bottle']);
        RobotLog::factory()->create(['robot_id' => 'ROBOT-001', 'detection_source' => 'laptop_camera', 'detected_object_label' => 'Other robot review']);
        $command = RobotCommand::create(['robot_id' => 'ROBOT-002', 'command' => 'STOP', 'status' => 'pending']);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('robot.dashboard', ['robot_id' => 'ROBOT-002']))->assertOk();

        $this->assertSame('ROBOT-002', $response->viewData('robotId'));
        $this->assertSame($selected->id, $response->viewData('latestLog')->id);
        $this->assertSame($command->id, $response->viewData('recentCommands')->first()->id);
        $response->assertSee('Selected bottle')->assertDontSee('Other robot review')
            ->assertSee(route('robot.dashboard.camera', ['robot_id' => 'ROBOT-002']), false)
            ->assertSee('workspaceSearch')->assertSee('Battery trend');
    }

    public function test_unknown_and_zero_robot_ids_do_not_fall_back_to_another_robot(): void
    {
        RobotLog::factory()->create(['robot_id' => 'ROBOT-001']);
        $this->actingAs(User::factory()->admin()->create());

        foreach (['0', 'UNREPORTED-ROBOT'] as $robotId) {
            $response = $this->get(route('robot.dashboard', ['robot_id' => $robotId]))->assertOk();
            $this->assertSame($robotId, $response->viewData('robotId'));
            $this->assertNull($response->viewData('latestLog'));
            $this->assertSame([], $response->viewData('workspaceInsights')['battery_trend']);
        }
    }

    public function test_both_camera_observation_sources_are_excluded_from_robot_telemetry(): void
    {
        $telemetry = RobotLog::factory()->create(['created_at' => now()->subMinute(), 'battery_level' => 73]);
        RobotLog::factory()->create(['detection_source' => 'laptop_camera', 'battery_level' => 1]);
        RobotLog::factory()->create(['detection_source' => 'robot_camera', 'battery_level' => 2]);

        $this->actingAs(User::factory()->admin()->create())->getJson(route('dashboard.robot.latest'))
            ->assertOk()->assertJsonPath('id', $telemetry->id)->assertJsonPath('battery_level', '73.00');
    }

    public function test_insights_require_admin_access_and_validate_robot_selection(): void
    {
        $this->getJson(route('dashboard.robot.insights'))->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(route('dashboard.robot.insights'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('dashboard.robot.insights', ['robot_id' => ['ROBOT-001']]))
            ->assertUnprocessable()->assertJsonValidationErrors('robot_id');
    }

    public function test_insights_count_observations_separately_without_summing_cumulative_pickup_reports(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00'));
        RobotLog::factory()->create(['successful_pickup_count' => 100]);
        RobotLog::factory()->create(['created_at' => now()->subDay(), 'successful_pickup_count' => 90]);
        RobotLog::factory()->create(['robot_id' => 'ROBOT-002']);
        RobotLog::factory()->create(['detection_source' => 'laptop_camera', 'successful_pickup_count' => 100]);
        RobotLog::factory()->create(['detection_source' => 'robot_camera', 'successful_pickup_count' => 100]);
        RobotLog::factory()->create(['detection_source' => 'laptop_camera', 'created_at' => now()->subDay()]);
        RobotLog::factory()->create(['detection_source' => 'laptop_camera', 'created_at' => now()->addDay()]);
        foreach ([['pending', null], ['pending', now()->subSecond()], ['received', null], ['completed', null]] as [$status, $expiry]) {
            RobotCommand::create(['robot_id' => 'ROBOT-001', 'command' => 'START', 'status' => $status, 'expires_at' => $expiry]);
        }
        RobotCommand::create(['robot_id' => 'ROBOT-002', 'command' => 'STOP', 'status' => 'pending']);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('dashboard.robot.insights', ['robot_id' => 'ROBOT-001']))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('today.telemetry_reports', 1)
            ->assertJsonPath('today.camera_observations', 2)
            ->assertJsonPath('today.open_commands', 2)
            ->assertJsonCount(2, 'battery_trend')->assertJsonCount(3, 'recent_observations');

        $this->assertArrayNotHasKey('successful_pickups_today', $response->json('today'));
        $this->assertSame('pending', RobotCommand::query()->whereNotNull('expires_at')->first()->status);
    }

    public function test_battery_trend_is_chronological_bounded_and_excludes_future_and_camera_records(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00'));
        $ids = [];
        for ($index = 0; $index < 30; $index++) {
            $ids[] = RobotLog::factory()->create(['created_at' => now()->subMinutes(30 - $index), 'battery_level' => 90 - $index])->id;
        }
        RobotLog::factory()->create(['created_at' => now()->addMinute()]);
        RobotLog::factory()->create(['detection_source' => 'robot_camera']);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('dashboard.robot.insights'))->assertOk()->assertJsonCount(24, 'battery_trend');

        $this->assertSame(array_slice($ids, -24), array_column($response->json('battery_trend'), 'id'));
        $this->assertSame(61, (int) $response->json('battery_trend.23.battery'));
    }

    public function test_empty_insights_do_not_fabricate_measurements(): void
    {
        $this->actingAs(User::factory()->admin()->create())->getJson(route('dashboard.robot.insights'))
            ->assertOk()->assertJsonPath('today.telemetry_reports', 0)
            ->assertJsonPath('today.camera_observations', 0)->assertJsonPath('today.open_commands', 0)
            ->assertJsonCount(0, 'battery_trend')->assertJsonCount(0, 'recent_observations');
    }

    public function test_activity_presets_preserve_filters_reset_pagination_and_use_calendar_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00'));
        $response = $this->actingAs(User::factory()->admin()->create())->get(route('robot.dashboard.logs', [
            'robot_id' => 'ROBOT-002', 'source' => 'robot_camera', 'per_page' => 25, 'page' => 8,
        ]))->assertOk();

        $response->assertSee(e(route('robot.dashboard.logs', [
            'robot_id' => 'ROBOT-002', 'source' => 'robot_camera', 'per_page' => 25,
            'from' => '2026-10-21', 'to' => '2026-10-27',
        ])), false)->assertSee('Remove source filter');
        $response->assertSee(e(route('robot.dashboard.logs', ['robot_id' => 'ROBOT-002', 'per_page' => 25])), false);
    }

    public function test_review_labels_are_escaped_in_the_initial_insights_view(): void
    {
        RobotLog::factory()->create(['detection_source' => 'laptop_camera', 'detected_object_label' => '<script>alert("review")</script>']);
        $this->actingAs(User::factory()->admin()->create())->get(route('robot.dashboard'))
            ->assertOk()->assertDontSee('<script>alert("review")</script>', false)
            ->assertSee('&lt;script&gt;alert(', false);
    }

    public function test_health_signals_cover_missing_stale_low_battery_full_bins_and_invalid_timestamps(): void
    {
        $node = (new ExecutableFinder)->find('node');
        $this->assertNotNull($node);
        $code = <<<'JS'
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
const {healthSignals} = await import(pathToFileURL(process.argv[2]));
const now = Date.parse('2026-10-27T12:00:00Z');
const fresh = {created_at:new Date(now).toISOString(),battery_level:80,plastic_bin_level:'Low'};
assert.equal(healthSignals(fresh,true,now)[0].tone,'success');
assert.match(healthSignals(null,true,now)[0].title,/Waiting/);
assert.ok(healthSignals(fresh,false,now).some(s=>s.tone==='danger'));
assert.ok(healthSignals({...fresh,created_at:new Date(now-16000).toISOString()},true,now).some(s=>s.title.includes('stale')));
assert.ok(healthSignals({...fresh,created_at:new Date(now+1000).toISOString()},true,now).some(s=>s.title.includes('timestamp')));
assert.ok(healthSignals({...fresh,created_at:'invalid'},true,now).some(s=>s.title.includes('timestamp')));
assert.ok(healthSignals({...fresh,battery_level:0},true,now).some(s=>s.tone==='danger'&&s.title.includes('battery')));
assert.ok(healthSignals({...fresh,battery_level:19,general_bin_level:' FULL '},true,now).some(s=>s.title.includes('General bin')));
assert.ok(healthSignals({...fresh,plastic_bin_level:'High'},true,now).some(s=>s.title.includes('Plastic bin')));
assert.equal(healthSignals({...fresh,battery_level:null},true,now)[0].tone,'success');
assert.equal(healthSignals({...fresh,battery_level:'unknown'},true,now)[0].tone,'success');
console.log('Health signals verified');
JS;
        $process = new Process([$node, '--input-type=module', '-', resource_path('js/workspace-health.js')], base_path());
        $process->setInput($code)->setTimeout(30)->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }
}
