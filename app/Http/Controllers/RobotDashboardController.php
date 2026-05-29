<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendRobotCommandRequest;
use App\Http\Requests\StoreRobotLogRequest;
use App\Models\RobotCommand;
use App\Models\RobotLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RobotDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $activePage = (string) $request->route('page', 'overview');
        $dashboardPages = $this->dashboardPages();

        abort_unless(array_key_exists($activePage, $dashboardPages), 404);

        $latestLog = RobotLog::latest()->first();
        $logs = RobotLog::latest()->take(10)->get();

        return view('robot.dashboard', compact('activePage', 'dashboardPages', 'latestLog', 'logs'));
    }

    public function store(StoreRobotLogRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['camera_snapshot'])) {
            $validated['camera_snapshot_path'] = $this->storeCameraSnapshot($validated['camera_snapshot']);
            unset($validated['camera_snapshot']);
        }

        $log = RobotLog::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Robot data saved successfully.',
            'data' => $log,
        ]);
    }

    public function latest(): JsonResponse
    {
        $latestLog = RobotLog::latest()->first();

        return response()->json($latestLog);
    }

    public function sendCommand(SendRobotCommandRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $command = RobotCommand::create([
            'robot_id' => $validated['robot_id'] ?? 'ROBOT-001',
            'command' => $validated['command'],
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Command sent successfully.',
            'data' => $command,
        ]);
    }

    private function storeCameraSnapshot(string $snapshot): string
    {
        if (! preg_match('/^data:image\/(?<type>png|jpeg|jpg|webp);base64,(?<data>[A-Za-z0-9+\/=]+)$/', $snapshot, $matches)) {
            throw ValidationException::withMessages([
                'camera_snapshot' => 'The camera snapshot must be a valid base64 image.',
            ]);
        }

        $image = base64_decode($matches['data'], true);

        if ($image === false) {
            throw ValidationException::withMessages([
                'camera_snapshot' => 'The camera snapshot could not be decoded.',
            ]);
        }

        $extension = $matches['type'] === 'jpeg' ? 'jpg' : $matches['type'];
        $path = 'robot-detections/'.Str::uuid().'.'.$extension;

        if (! Storage::disk('public')->put($path, $image)) {
            throw ValidationException::withMessages([
                'camera_snapshot' => 'The camera snapshot could not be stored.',
            ]);
        }

        return $path;
    }

    public function getPendingCommand(Request $request): JsonResponse
    {
        $robotId = $request->query('robot_id', 'ROBOT-001');

        $command = RobotCommand::where('robot_id', $robotId)
            ->where('status', 'pending')
            ->latest()
            ->first();

        return response()->json($command);
    }

    public function acknowledgeCommand(int $id): JsonResponse
    {
        $command = RobotCommand::findOrFail($id);

        $command->update([
            'status' => 'received',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Command acknowledged by robot.',
            'data' => $command,
        ]);
    }

    /**
     * @return array<string, array{label: string, route: string, description: string}>
     */
    private function dashboardPages(): array
    {
        return [
            'overview' => [
                'label' => 'Overview',
                'route' => 'robot.dashboard',
                'description' => 'Live robot metrics',
            ],
            'camera' => [
                'label' => 'Camera',
                'route' => 'robot.dashboard.camera',
                'description' => 'Laptop detection',
            ],
            'capabilities' => [
                'label' => 'Capabilities',
                'route' => 'robot.dashboard.capabilities',
                'description' => 'System modules',
            ],
            'waste' => [
                'label' => 'Waste Sorting',
                'route' => 'robot.dashboard.waste',
                'description' => 'Bin loads',
            ],
            'commands' => [
                'label' => 'Commands',
                'route' => 'robot.dashboard.commands',
                'description' => 'Robot actions',
            ],
            'evaluation' => [
                'label' => 'Evaluation',
                'route' => 'robot.dashboard.evaluation',
                'description' => 'Performance scores',
            ],
            'logs' => [
                'label' => 'Logs',
                'route' => 'robot.dashboard.logs',
                'description' => 'Telemetry records',
            ],
        ];
    }
}
