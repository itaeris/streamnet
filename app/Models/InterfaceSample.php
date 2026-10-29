<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterfaceSample extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'sampled_at',
        'name',
        'type',
        'running',
        'disabled',
        'rx_bytes',
        'tx_bytes',
        'rx_bps',
        'tx_bps',
        'rx_error',
        'tx_error',
        'rx_drop',
        'tx_drop',
        'link_downs',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'running' => 'boolean',
            'disabled' => 'boolean',
        ];
    }
}
