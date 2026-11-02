<?php

namespace Database\Factories;

use App\Models\OnboardVisionFrame;
use App\Models\OnboardVisionSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardVisionFrame>
 */
class OnboardVisionFrameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'onboard_vision_session_id' => OnboardVisionSession::factory(),
            'device_id' => 'CAM-S3-001',
            'sequence' => 1,
            'status' => 'no_target',
            'target_count' => 0,
            'payload' => [],
            'payload_sha256' => hash('sha256', 'test-fixture'),
        ];
    }
}
