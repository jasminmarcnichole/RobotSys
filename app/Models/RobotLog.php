<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RobotLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RobotLog extends Model
{
    /** @use HasFactory<RobotLogFactory> */
    use HasFactory;

    public function scopeTelemetry(Builder $query): void
    {
        $query->where(fn (Builder $query): Builder => $query
            ->whereNull('detection_source')
            ->orWhere('detection_source', 'robot_sensor'));
    }

    public function scopeObservations(Builder $query): void
    {
        $query->whereIn('detection_source', ['laptop_camera', 'robot_camera']);
    }

    /** @param array<string, mixed> $filters */
    public function scopeFiltered(Builder $query, array $filters): void
    {
        $query
            ->when(filled($filters['robot_id'] ?? null), fn (Builder $query): Builder => $query->where('robot_id', $filters['robot_id']))
            ->when($filters['source'] ?? null, function (Builder $query, string $source): void {
                $query->where(function (Builder $query) use ($source): void {
                    $query->where('detection_source', $source);
                    if ($source === 'robot_sensor') {
                        $query->orWhereNull('detection_source');
                    }
                });
            })
            ->when(filled($filters['trash_type'] ?? null), fn (Builder $query): Builder => $query->where('last_detected_trash_type', 'like', '%'.$filters['trash_type'].'%'))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to): Builder => $query->where('created_at', '<', CarbonImmutable::parse($to)->addDay()->startOfDay()));
    }

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
