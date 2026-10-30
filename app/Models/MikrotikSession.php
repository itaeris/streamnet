<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MikrotikSession extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'host',
        'scheme',
        'port',
        'username',
        'password',
        'wan_interface',
        'enabled',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MikrotikSession $session): void {
            if ($session->slug && ! $session->isDirty('name')) {
                return;
            }

            $session->slug = static::uniqueSlug((string) $session->name, $session->id);
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $reserved = [
            'sesi', 'baru', 'login', 'logout', 'pengaturan', 'up',
            'bandwidth', 'dhcp', 'dns', 'dns-filter', 'logs', 'security', 'agent',
            'access-points', 'bandwidth-policy', 'topology',
        ];
        $base = Str::slug($name) ?: 'sesi';
        if (in_array($base, $reserved, true)) {
            $base .= '-router';
        }

        $slug = $base;
        $suffix = 2;
        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array{username: string, password: string, base_url: string, timeout: int, transport: string, host: string, port: int, tls: bool}
     */
    public function connection(): array
    {
        return [
            'username' => $this->username,
            'password' => (string) $this->password,
            'base_url' => $this->transport() === 'rest' ? $this->baseUrl() : '',
            'timeout' => (int) config('mikrotik.timeout', 8),
            'transport' => $this->transport(),
            'host' => $this->host,
            'port' => $this->listenPort(),
            'tls' => $this->transport() === 'api' && $this->usesTls(),
        ];
    }

    public function transport(): string
    {
        if (in_array($this->scheme, ['api', 'api-ssl'], true) || in_array((int) $this->port, [8728, 8729], true)) {
            return 'api';
        }

        return 'rest';
    }

    public function listenPort(): int
    {
        if ($this->port) {
            return (int) $this->port;
        }

        return match ($this->scheme) {
            'api' => 8728,
            'api-ssl' => 8729,
            'https' => 443,
            default => 80,
        };
    }

    public function usesTls(): bool
    {
        return $this->scheme === 'api-ssl' || (int) $this->port === 8729;
    }

    public function baseUrl(): string
    {
        $scheme = $this->scheme === 'https' ? 'https' : 'http';
        $port = $this->port ? ':'.$this->port : '';

        return $scheme.'://'.$this->host.$port.'/rest';
    }
}
