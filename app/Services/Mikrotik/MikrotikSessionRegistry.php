<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikSession;
use Illuminate\Support\Facades\DB;

class MikrotikSessionRegistry
{
    public function ensureDefault(): void
    {
        if (MikrotikSession::query()->exists()) {
            return;
        }

        $host = trim((string) config('mikrotik.host'));
        $password = (string) config('mikrotik.password');

        if ($host === '' || $password === '') {
            return;
        }

        $session = MikrotikSession::query()->create([
            'name' => $host,
            'host' => $host,
            'scheme' => config('mikrotik.scheme') === 'https' ? 'https' : 'http',
            'username' => (string) config('mikrotik.username'),
            'password' => $password,
            'wan_interface' => trim((string) config('mikrotik.wan_interface')) ?: null,
            'enabled' => true,
        ]);

        foreach ([
            'live_snapshots',
            'system_samples',
            'interface_samples',
            'dhcp_leases',
            'router_logs',
            'security_events',
        ] as $table) {
            DB::table($table)->whereNull('mikrotik_session_id')->update([
                'mikrotik_session_id' => $session->id,
            ]);
        }
    }
}
