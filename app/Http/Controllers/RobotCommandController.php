<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendRobotCommandRequest;
use App\Http\Requests\UpdateRobotCommandStatusRequest;
use App\Models\RobotCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RobotCommandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $robotId = $request->query('robot_id', 'ROBOT-001');
        if (is_string($robotId)) {
            $this->expirePendingCommands($robotId);
        }

        return response()->json(RobotCommand::query()
            ->where('robot_id', $robotId)
            ->latest()->orderByDesc('id')->limit(8)->get());
    }

    public function store(SendRobotCommandRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $command = RobotCommand::query()->getConnection()->transaction(function () use ($request, $validated): RobotCommand {
            $robotId = $validated['robot_id'] ?? 'ROBOT-001';

            if ($validated['command'] === 'STOP') {
                RobotCommand::query()->where('robot_id', $robotId)
                    ->where('status', 'pending')->where('command', '!=', 'STOP')
                    ->update(['status' => 'cancelled']);
            }

            return RobotCommand::create([
                'robot_id' => $robotId,
                'command' => $validated['command'],
                'status' => 'pending',
                'expires_at' => $request->routeIs('dashboard.robot.control') && $validated['command'] !== 'STOP'
                    ? now()->addSeconds(5) : null,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Command queued. Waiting for the robot to acknowledge it.',
            'data' => $command,
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $robotId = $request->query('robot_id', 'ROBOT-001');

        if (is_string($robotId)) {
            $this->expirePendingCommands($robotId);
        }

        $pending = RobotCommand::query()
            ->where('robot_id', $robotId)
            ->where('status', 'pending');

        $command = (clone $pending)->where('command', 'STOP')->latest()->orderByDesc('id')->first()
            ?? $pending->latest()->orderByDesc('id')->first();

        return response()->json($command);
    }

    public function acknowledge(Request $request, RobotCommand $robotCommand): JsonResponse
    {
        $this->abortWhenRobotDoesNotMatch($request, $robotCommand);
        $this->abortWhenCommandIsNoLongerAvailable($robotCommand);

        if ($robotCommand->status === 'pending') {
            $robotCommand->update([
                'status' => 'received',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Command acknowledged by robot.',
            'data' => $robotCommand->refresh(),
        ]);
    }

    public function updateStatus(UpdateRobotCommandStatusRequest $request, RobotCommand $robotCommand): JsonResponse
    {
        $this->abortWhenRobotDoesNotMatch($request, $robotCommand);
        $this->abortWhenCommandIsNoLongerAvailable($robotCommand);

        $robotCommand->update([
            'status' => $request->validated('status'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Command status updated successfully.',
            'data' => $robotCommand->refresh(),
        ]);
    }

    private function expirePendingCommands(string $robotId): void
    {
        RobotCommand::query()->where('robot_id', $robotId)->where('status', 'pending')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    private function abortWhenCommandIsNoLongerAvailable(RobotCommand $robotCommand): void
    {
        if ($robotCommand->expires_at?->lessThanOrEqualTo(now()) && $robotCommand->status === 'pending') {
            $robotCommand->update(['status' => 'expired']);
        }

        abort_if(in_array($robotCommand->status, ['expired', 'cancelled'], true), 409, 'This command has expired or was cancelled.');
    }

    private function abortWhenRobotDoesNotMatch(Request $request, RobotCommand $robotCommand): void
    {
        $robotId = $request->input('robot_id', $request->query('robot_id'));

        if ($robotId === null) {
            return;
        }

        abort_if((string) $robotId !== (string) $robotCommand->robot_id, 404);
    }
}
