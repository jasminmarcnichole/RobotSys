<?php

namespace App\Models;

use Database\Factories\OnboardVisionFrameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardVisionFrame extends Model
{
    /** @use HasFactory<OnboardVisionFrameFactory> */
    use HasFactory;

    protected $fillable = [
        'onboard_vision_session_id', 'device_id', 'sequence', 'status', 'target_count',
        'payload', 'payload_sha256', 'snapshot_path', 'snapshot_sha256',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sequence' => 'integer', 'target_count' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OnboardVisionSession::class, 'onboard_vision_session_id');
    }
}
