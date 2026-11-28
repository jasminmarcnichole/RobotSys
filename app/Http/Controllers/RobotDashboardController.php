<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterRobotLogsRequest;
use App\Models\CameraSetting;
use App\Models\RobotCommand;
use App\Models\RobotLog;
use App\Services\RobotWorkspaceInsights;
use Illuminate\View\View;

class RobotDashboardController extends Controller
{
    public function __construct(private RobotWorkspaceInsights $insights) {}

    public function index(FilterRobotLogsRequest $request): View
    {
        $activePage = (string) $request->route('page', 'overview');
        $dashboardPages = $this->dashboardPages();

        abort_unless(array_key_exists($activePage, $dashboardPages), 404);

        $filters = $request->safe()->except('page');
        $selectedRobot = $filters['robot_id'] ?? null;
        $latestLog = RobotLog::query()->telemetry()
            ->when(filled($selectedRobot), fn ($query) => $query->where('robot_id', $selectedRobot))
            ->latest()->orderByDesc('id')->first();
        $logs = $activePage === 'logs'
            ? RobotLog::query()->filtered($filters)->latest()->orderByDesc('id')
                ->paginate((int) ($filters['per_page'] ?? 15))->withQueryString()
            : null;
        $robotId = $selectedRobot ?? $latestLog?->robot_id ?? 'ROBOT-001';
        $robotOptions = RobotLog::query()->select('robot_id')->distinct()->orderBy('robot_id')->limit(100)->pluck('robot_id')
            ->merge(RobotCommand::query()->select('robot_id')->distinct()->orderBy('robot_id')->limit(100)->pluck('robot_id'))
            ->push($robotId)->unique()->sort()->values();
        $recentCommands = RobotCommand::query()->where('robot_id', $robotId)->latest()->orderByDesc('id')->take(8)->get();
        $cameraDetections = RobotLog::query()
            ->where('robot_id', $robotId)
            ->whereIn('detection_source', ['laptop_camera', 'robot_camera'])
            ->latest()->orderByDesc('id')->take(8)->get();
        $latestDetection = $cameraDetections->first();
        $cameraSetting = CameraSetting::forRobot($robotId);
        $workspaceInsights = in_array($activePage, ['overview', 'evaluation'], true) ? $this->insights->snapshot($robotId) : null;

        return view('robot.dashboard', compact('activePage', 'dashboardPages', 'latestLog', 'logs', 'filters', 'robotId', 'robotOptions', 'workspaceInsights', 'recentCommands', 'cameraDetections', 'latestDetection', 'cameraSetting'));
    }

    /**
     * @return array<string, array{label: string, route: string, description: string}>
     */
    private function dashboardPages(): array
    {
        return [
            'control' => [
                'label' => 'Robot app',
                'route' => 'robot.dashboard.control',
                'description' => 'Control & machine vision',
            ],
            'overview' => [
                'label' => 'Overview',
                'route' => 'robot.dashboard',
                'description' => 'Reported status & collection activity',
            ],
            'camera' => [
                'label' => 'Camera',
                'route' => 'robot.dashboard.camera',
                'description' => 'Camera sources & reviewed detections',
            ],
            'camera-settings' => [
                'label' => 'Camera Settings',
                'route' => 'robot.dashboard.camera-settings',
                'description' => 'Configure and save ESP32-CAM & detection settings',
            ],
            'dataset-capture' => [
                'label' => 'Dataset Capture',
                'route' => 'robot.dashboard.dataset-capture',
                'description' => 'Capture YOLO26n training photos remotely from your phone',
            ],
            'capabilities' => [
                'label' => 'Capabilities',
                'route' => 'robot.dashboard.capabilities',
                'description' => 'Connections & supported modules',
            ],
            'waste' => [
                'label' => 'Waste Sorting',
                'route' => 'robot.dashboard.waste',
                'description' => 'Reported collection totals & bin levels',
            ],
            'commands' => [
                'label' => 'Commands',
                'route' => 'robot.dashboard.commands',
                'description' => 'Queued actions & robot acknowledgements',
            ],
            'evaluation' => [
                'label' => 'Evaluation',
                'route' => 'robot.dashboard.evaluation',
                'description' => 'Collection performance & activity trends',
            ],
            'logs' => [
                'label' => 'Logs',
                'route' => 'robot.dashboard.logs',
                'description' => 'Filter, inspect, and export recorded activity',
            ],
        ];
    }
}
