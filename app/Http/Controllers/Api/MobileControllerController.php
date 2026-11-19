<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendRobotCommandRequest;
use App\Http\Resources\RobotCommandResource;
use App\Http\Resources\RobotTelemetryResource;
use App\Models\RobotCommand;
use App\Models\RobotLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MobileControllerController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $robotId = $request->query('robot_id', 'ROBOT-001');
        $robotId = is_string($robotId) && $robotId !== '' ? $robotId : 'ROBOT-001';

        $this->expirePendingCommands($robotId);

        $latestTelemetry = RobotLog::query()
            ->telemetry()
            ->where('robot_id', $robotId)
            ->latest()
            ->orderByDesc('id')
            ->first();

        $recentCommands = RobotCommand::query()
            ->where('robot_id', $robotId)
            ->latest()
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $knownRobots = RobotLog::query()
            ->select('robot_id')
            ->whereNotNull('robot_id')
            ->distinct()
            ->orderBy('robot_id')
            ->pluck('robot_id')
            ->push('ROBOT-001')
            ->unique()
            ->values();

        return response()->json([
            'robot_id' => $robotId,
            'robots' => $knownRobots,
            'telemetry' => $latestTelemetry ? new RobotTelemetryResource($latestTelemetry) : null,
            'recent_commands' => RobotCommandResource::collection($recentCommands),
        ]);
    }

    public function commands(Request $request): AnonymousResourceCollection
    {
        $robotId = $request->query('robot_id', 'ROBOT-001');
        $robotId = is_string($robotId) && $robotId !== '' ? $robotId : 'ROBOT-001';

        $this->expirePendingCommands($robotId);

        $paginator = RobotCommand::query()
            ->where('robot_id', $robotId)
            ->latest()
            ->orderByDesc('id')
            ->paginate(15);

        return RobotCommandResource::collection($paginator);
    }

    public function sendCommand(SendRobotCommandRequest $request): JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403, 'Administrator access is required.');

        $validated = $request->validated();

        $command = RobotCommand::query()->getConnection()->transaction(function () use ($validated): RobotCommand {
            $robotId = $validated['robot_id'] ?? 'ROBOT-001';

            if ($validated['command'] === 'STOP') {
                RobotCommand::query()
                    ->where('robot_id', $robotId)
                    ->where('status', 'pending')
                    ->where('command', '!=', 'STOP')
                    ->update(['status' => 'cancelled']);
            }

            return RobotCommand::create([
                'robot_id' => $robotId,
                'command' => $validated['command'],
                'status' => 'pending',
                'expires_at' => $validated['command'] !== 'STOP' ? now()->addSeconds(5) : null,
            ]);
        });

        return response()->json([
            'message' => 'Command '.$command->command.' queued for '.$command->robot_id.'.',
            'data' => new RobotCommandResource($command),
        ], 201);
    }

    private function expirePendingCommands(string $robotId): void
    {
        RobotCommand::query()
            ->where('robot_id', $robotId)
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }
}
