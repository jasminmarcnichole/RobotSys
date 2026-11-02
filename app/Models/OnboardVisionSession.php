<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardVisionSession extends Model
{
    /** @use HasFactory<\Database\Factories\OnboardVisionSessionFactory> */
    use HasFactory;

    protected $fillable = ['device_id', 'boot_id', 'last_sequence', 'last_timestamp_ms'];

    protected function casts(): array
    {
        return ['last_sequence' => 'integer', 'last_timestamp_ms' => 'integer'];
    }

    public function frames(): HasMany
    {
        return $this->hasMany(OnboardVisionFrame::class);
    }
}
