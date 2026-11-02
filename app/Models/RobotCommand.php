<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RobotCommand extends Model
{
    protected $fillable = [
        'robot_id',
        'command',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
