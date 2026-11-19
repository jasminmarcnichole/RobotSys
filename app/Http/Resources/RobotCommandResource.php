<?php

namespace App\Http\Resources;

use App\Models\RobotCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RobotCommand */
class RobotCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'robot_id' => $this->robot_id,
            'command' => $this->command,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans() ?? 'Just now',
        ];
    }
}
