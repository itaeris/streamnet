<?php

namespace App\Http\Controllers;

use App\Models\DhcpLease;
use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DhcpController extends Controller
{
    public function __invoke(Request $request, AnalyticsQuery $analytics, CurrentMikrotikSession $current): Response
    {
        $window = $analytics->window($request);
        $session = $current->resolve();
        $now = now();
        $leases = DhcpLease::query()->where('mikrotik_session_id', $session?->id)->get()
            ->filter(fn (DhcpLease $lease) => $analytics->seenAt($lease->last_seen, $now)->between($window['start'], $window['end']))
            ->sortBy(fn (DhcpLease $lease) => sprintf('%u', ip2long((string) $lease->address) ?: 0))
            ->values()
            ->map->toPayload();

        return Inertia::render('Dhcp', [
            'pulse' => $analytics->pulse($session?->id),
            'minutes' => $window['minutes'],
            'from' => $window['from'],
            'to' => $window['to'],
            'live' => $window['live'],
            'leases' => $leases,
            'dns' => $analytics->dnsForClients($session?->id, $window['start'], $window['end']),
        ]);
    }
}
