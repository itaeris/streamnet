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
        if (! $chosen) {
            return null;
        }

        $current = MikrotikSession::query()->whereKey($chosen)->first();
        if (! $current) {
            session()->forget('mikrotik_session_id');
        }

        return $current;
    }
}
