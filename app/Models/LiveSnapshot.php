<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveSnapshot extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'key',
        'payload',
        'sampled_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sampled_at' => 'datetime',
        ];
    }
}
