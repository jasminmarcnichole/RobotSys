<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RobotLog extends Model
{
    protected $fillable = [
        'robot_id',
        'battery_level',
        'movement_status',
        'detected_trash_count',
        'successful_pickup_count',
        'failed_pickup_count',
        'obstacle_count',
        'operating_duration',
        'plastic_waste_count',
        'general_waste_count',
        'last_detected_trash_type',
        'detection_source',
        'classification_model',
        'detected_object_label',
        'detection_confidence',
        'detection_bbox',
        'camera_snapshot_path',
        'plastic_bin_level',
        'general_bin_level',
        'remarks',
    ];

    protected $appends = [
        'camera_snapshot_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'battery_level' => 'decimal:2',
            'detected_trash_count' => 'integer',
            'successful_pickup_count' => 'integer',
            'failed_pickup_count' => 'integer',
            'obstacle_count' => 'integer',
            'operating_duration' => 'integer',
            'plastic_waste_count' => 'integer',
            'general_waste_count' => 'integer',
            'detection_confidence' => 'decimal:2',
            'detection_bbox' => 'array',
        ];
    }

    protected function cameraSnapshotUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->camera_snapshot_path
                ? Storage::disk('public')->url($this->camera_snapshot_path)
                : null
        );
    }
}
