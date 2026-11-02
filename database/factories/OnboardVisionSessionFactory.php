<?php

namespace Database\Factories;

use App\Models\OnboardVisionSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardVisionSession>
 */
class OnboardVisionSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => 'CAM-S3-001',
            'boot_id' => bin2hex(random_bytes(8)),
            'last_sequence' => 0,
            'last_timestamp_ms' => 0,
        ];
    }
}
