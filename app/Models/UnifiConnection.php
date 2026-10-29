<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnifiConnection extends Model
{
    protected $fillable = [
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

    /**
     * The single saved connection, adopting UNIFI_* from .env the first time.
     */
    public static function current(): ?self
    {
        $saved = static::query()->first();
        if ($saved !== null) {
            return $saved;
        }

        $url = rtrim((string) config('unifi.url'), '/');
        $key = (string) config('unifi.api_key');
        if ($url === '' || $key === '') {
            return null;
        }

        return static::query()->create([
            'url' => $url,
            'api_key' => $key,
            'site' => (string) config('unifi.site') ?: null,
            'verify_tls' => (bool) config('unifi.verify_tls'),
        ]);
    }
}
