<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsQuery;
use App\Services\DnsActivity;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    public function __invoke(Request $request, AnalyticsQuery $analytics, CurrentMikrotikSession $current, DnsActivity $dns): Response
    {
        $window = $analytics->window($request);
        $session = $current->resolve();

        return Inertia::render('Dns', [
            'pulse' => $analytics->pulse($session?->id),
            'minutes' => $window['minutes'],
            'from' => $window['from'],
            'to' => $window['to'],
            'live' => $window['live'],
            'appSites' => $dns->websites(),
            'queries' => fn () => $analytics->dnsQueries($session?->id, $window['start'], $window['end']),
        ]);
    }
}
