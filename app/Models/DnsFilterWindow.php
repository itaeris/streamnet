<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DnsFilterWindow extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'domain',
        'starts_at',
        'ends_at',
        'blocked',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'blocked' => 'boolean',
        ];
    }
}
