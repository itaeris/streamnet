<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    protected $fillable = [
        'mikrotik_session_id',
        'occurred_at',
        'severity',
        'category',
        'title',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'time' => $this->occurred_at?->format('Y-m-d H:i:s'),
            'severity' => $this->severity,
            'category' => $this->category,
            'title' => $this->title,
            'detail' => $this->detail,
        ];
    }
}
