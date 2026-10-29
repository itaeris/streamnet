<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DnsQuery extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'router_id',
        'logged_at',
        'address',
        'domain',
        'qtype',
        'app',
        'browser',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->router_id,
            'time' => $this->logged_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'address' => $this->address,
            'domain' => $this->domain,
            'qtype' => $this->qtype,
            'app' => $this->app,
            'browser' => $this->browser,
        ];
    }
}
