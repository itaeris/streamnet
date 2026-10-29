<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DhcpLease extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'router_id',
        'address',
        'mac_address',
        'host_name',
        'server',
        'status',
        'blocked',
        'dynamic',
        'last_seen',
        'expires_after',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'blocked' => 'boolean',
            'dynamic' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'address' => $this->address,
            'mac' => $this->mac_address,
            'host' => $this->host_name,
            'server' => $this->server,
            'status' => $this->status,
            'blocked' => $this->blocked,
            'dynamic' => $this->dynamic,
            'last_seen' => $this->last_seen,
            'expires_after' => $this->expires_after,
            'comment' => $this->comment,
        ];
    }
}
