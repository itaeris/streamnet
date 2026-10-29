<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MikrotikSession extends Model
{
    protected $fillable = [
        'name',
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
