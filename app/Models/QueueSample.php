<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueSample extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'sampled_at',
        'name',
        'rx_bps',
        'tx_bps',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
        ];
    }
}
