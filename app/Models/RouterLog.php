<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouterLog extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'router_id',
        'logged_at',
        'topics',
        'message',
        'severity',
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
            'time' => $this->logged_at?->format('Y-m-d H:i:s'),
            'topics' => $this->topics,
            'message' => $this->message,
            'severity' => $this->severity,
        ];
    }
}
