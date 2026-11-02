<?php

namespace App\Services;

use App\Models\RobotCommand;
use App\Models\RobotLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class RobotWorkspaceInsights
{
    /** @return array<string, mixed> */
    public function snapshot(string $robotId): array
    {
        $now = CarbonImmutable::now();
        $telemetry = RobotLog::query()->telemetry()->where('robot_id', $robotId);
        $observations = RobotLog::query()->observations()->where('robot_id', $robotId);
        $readings = (clone $telemetry)->where('created_at', '<=', $now)
            ->latest()->orderByDesc('id')->limit(24)
            ->get(['id', 'created_at', 'battery_level'])->reverse()->values();
        $reviews = (clone $observations)->where('created_at', '<=', $now)
            ->latest()->orderByDesc('id')->limit(5)->get();

        return [
            'robot_id' => $robotId,
            'generated_at' => $now->toIso8601String(),
            'timezone' => config('app.timezone'),
            'today' => [
                'telemetry_reports' => (clone $telemetry)->whereBetween('created_at', [$now->startOfDay(), $now])->count(),
                'camera_observations' => (clone $observations)->whereBetween('created_at', [$now->startOfDay(), $now])->count(),
                'open_commands' => RobotCommand::query()->where('robot_id', $robotId)
                    ->where(fn (Builder $query): Builder => $query->where('status', 'received')
                        ->orWhere(fn (Builder $pending): Builder => $pending->where('status', 'pending')
                            ->where(fn (Builder $expiry): Builder => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', $now))))
                    ->count(),
            ],
            'battery_trend' => $readings->map(fn (RobotLog $log): array => [
                'id' => $log->id,
                'reported_at' => $log->created_at->toIso8601String(),
                'battery' => max(0, min(100, (float) $log->battery_level)),
            ])->all(),
            'recent_observations' => $reviews->map(fn (RobotLog $log): array => [
                'id' => $log->id,
                'reported_at' => $log->created_at->toIso8601String(),
                'label' => $log->detected_object_label ?? 'Reviewed object',
                'category' => $log->last_detected_trash_type ?? 'Uncategorized',
                'confidence' => $log->detection_confidence !== null ? (float) $log->detection_confidence : null,
                'source' => $log->detection_source === 'robot_camera' ? 'Robot camera' : 'Device camera',
            ])->all(),
        ];
    }
}
