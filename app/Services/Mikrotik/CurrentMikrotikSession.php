<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikSession;

class CurrentMikrotikSession
{
    public function __construct(private MikrotikSessionRegistry $registry) {}

    public function resolve(): ?MikrotikSession
    {
        $this->registry->ensureDefault();

        $chosen = session('mikrotik_session_id');
        $current = $chosen
            ? MikrotikSession::query()->whereKey($chosen)->first()
            : null;

        $current ??= MikrotikSession::query()->where('enabled', true)->orderBy('id')->first()
            ?? MikrotikSession::query()->orderBy('id')->first();

        if ($current && (int) session('mikrotik_session_id') !== $current->id) {
            session(['mikrotik_session_id' => $current->id]);
        }

        return $current;
    }
}
