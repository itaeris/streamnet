<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSample extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'sampled_at',
        'identity',
        'board',
        'version',
        'uptime',
        'cpu_load',
        'memory_used',
        'memory_total',
        'disk_used',
        'disk_total',
        'temperature',
        'voltage',
        'connections',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'temperature' => 'float',
            'voltage' => 'float',
        ];
    }
}
