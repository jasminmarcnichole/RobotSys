<?php

namespace Database\Factories;

use App\Models\RobotLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RobotLog>
 */
class RobotLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'robot_id' => 'ROBOT-001',
            'battery_level' => 80,
            'movement_status' => 'Idle',
            'detection_source' => 'robot_sensor',
            'detected_trash_count' => 0,
            'successful_pickup_count' => 0,
            'failed_pickup_count' => 0,
            'obstacle_count' => 0,
            'operating_duration' => 0,
            'plastic_waste_count' => 0,
            'general_waste_count' => 0,
        ];
    }
}
