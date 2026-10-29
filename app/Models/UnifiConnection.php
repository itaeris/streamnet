<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnifiConnection extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'url',
        'api_key',
        'site',
        'verify_tls',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'verify_tls' => 'boolean',
        ];
    }

    public static function forSession(int $sessionId): ?self
    {
        return static::query()->where('mikrotik_session_id', $sessionId)->first();
    }
}
