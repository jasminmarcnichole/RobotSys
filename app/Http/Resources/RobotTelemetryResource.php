<?php

namespace App\Http\Resources;

use App\Models\RobotLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RobotLog */
class RobotTelemetryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'robot_id' => $this->robot_id,
            'movement_status' => $this->movement_status,
            'battery_level' => (float) ($this->battery_level ?? 0),
            'detected_trash_count' => (int) ($this->detected_trash_count ?? 0),
            'successful_pickup_count' => (int) ($this->successful_pickup_count ?? 0),
            'failed_pickup_count' => (int) ($this->failed_pickup_count ?? 0),
            'obstacle_count' => (int) ($this->obstacle_count ?? 0),
            'operating_duration' => (int) ($this->operating_duration ?? 0),
            'plastic_waste_count' => (int) ($this->plastic_waste_count ?? 0),
            'general_waste_count' => (int) ($this->general_waste_count ?? 0),
            'plastic_bin_level' => $this->plastic_bin_level ?? 'Not reported',
            'general_bin_level' => $this->general_bin_level ?? 'Not reported',
            'last_detected_trash_type' => $this->last_detected_trash_type,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_human' => $this->created_at?->diffForHumans() ?? 'No telemetry',
        ];
    }
}
