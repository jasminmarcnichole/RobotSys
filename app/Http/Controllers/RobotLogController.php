<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRobotLogRequest;
use App\Models\RobotLog;
use App\Services\CameraSnapshotStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RobotLogController extends Controller
{
    public function __construct(private CameraSnapshotStorage $cameraSnapshotStorage) {}

    public function store(StoreRobotLogRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['camera_snapshot'])) {
            $validated['camera_snapshot_path'] = $this->cameraSnapshotStorage->store($validated['camera_snapshot']);
            unset($validated['camera_snapshot']);
        }

        $log = RobotLog::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Robot data saved successfully.',
            'data' => $log,
        ]);
    }

    public function latest(Request $request): JsonResponse
    {
        $latestLog = RobotLog::query()
            ->telemetry()
            ->when($request->filled('robot_id'), fn ($query) => $query->where('robot_id', $request->query('robot_id')))
            ->latest()
            ->orderByDesc('id')
            ->first();

        return response()->json($latestLog);
    }
}
