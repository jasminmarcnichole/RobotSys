<?php

namespace Database\Factories;

use App\Models\CameraSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CameraSetting>
 */
class CameraSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return array_merge(
            ['robot_id' => 'ROBOT-001'],
            CameraSetting::defaults(),
        );
    }
}
