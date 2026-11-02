<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardVisionFrameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge($this->payload, [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'valid_frame' => (bool) ($this->payload['valid_frame'] ?? false),
            'received_at' => $this->created_at->toIso8601String(),
            'snapshot_url' => $this->snapshot_path ? route('dashboard.vision.image', $this->id) : null,
            'image_sha256' => $this->snapshot_sha256,
        ]);
    }
}
