<?php

namespace App\Models;

use Database\Factories\CameraSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CameraSetting extends Model
{
    /** @use HasFactory<CameraSettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'robot_id',
        'detection_model',
        'confidence_threshold',
        'auto_grab_enabled',
        'framesize',
        'quality',
        'brightness',
        'contrast',
        'saturation',
        'vflip',
        'hmirror',
        'led_intensity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence_threshold' => 'integer',
            'auto_grab_enabled' => 'boolean',
            'framesize' => 'integer',
            'quality' => 'integer',
            'brightness' => 'integer',
            'contrast' => 'integer',
            'saturation' => 'integer',
            'vflip' => 'boolean',
            'hmirror' => 'boolean',
            'led_intensity' => 'integer',
        ];
    }

    /**
     * @return array{
     *     detection_model: string,
     *     confidence_threshold: int,
     *     auto_grab_enabled: bool,
     *     framesize: int,
     *     quality: int,
     *     brightness: int,
     *     contrast: int,
     *     saturation: int,
     *     vflip: bool,
     *     hmirror: bool,
     *     led_intensity: int
     * }
     */
    public static function defaults(): array
    {
        $models = config('services.robot.vision_models', []);
        $defaultModel = config('services.robot.vision_default');

        if (! is_string($defaultModel) || ! array_key_exists($defaultModel, $models)) {
            $defaultModel = 'efficientdet_lite2';
        }

        return [
            'detection_model' => $defaultModel,
            'confidence_threshold' => 60,
            'auto_grab_enabled' => false,
            'framesize' => 8,
            'quality' => 14,
            'brightness' => 0,
            'contrast' => 1,
            'saturation' => 0,
            'vflip' => true,
            'hmirror' => false,
            'led_intensity' => 0,
        ];
    }

    public static function forRobot(string $robotId): self
    {
        $existing = static::query()->where('robot_id', $robotId)->first();

        if ($existing instanceof self) {
            return $existing;
        }

        return new static(array_merge(['robot_id' => $robotId], static::defaults()));
    }

    /**
     * @return array{
     *     robot_id: string,
     *     detection_model: string,
     *     confidence_threshold: int,
     *     auto_grab_enabled: bool,
     *     framesize: int,
     *     quality: int,
     *     brightness: int,
     *     contrast: int,
     *     saturation: int,
     *     vflip: bool,
     *     hmirror: bool,
     *     led_intensity: int,
     *     saved: bool,
     *     updated_at: string|null
     * }
     */
    public function toPayload(): array
    {
        return [
            'robot_id' => $this->robot_id,
            'detection_model' => $this->detection_model,
            'confidence_threshold' => (int) $this->confidence_threshold,
            'auto_grab_enabled' => (bool) $this->auto_grab_enabled,
            'framesize' => (int) $this->framesize,
            'quality' => (int) $this->quality,
            'brightness' => (int) $this->brightness,
            'contrast' => (int) $this->contrast,
            'saturation' => (int) $this->saturation,
            'vflip' => (bool) $this->vflip,
            'hmirror' => (bool) $this->hmirror,
            'led_intensity' => (int) $this->led_intensity,
            'saved' => $this->exists,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
