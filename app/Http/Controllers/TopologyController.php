<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use App\Services\Unifi\UnifiClient;
use Inertia\Inertia;
use Inertia\Response;

class TopologyController extends Controller
{
    public function __invoke(AnalyticsQuery $analytics, CurrentMikrotikSession $current, UnifiClient $unifi): Response
    {
        return Inertia::render('Topology', [
            'pulse' => $analytics->pulse($current->resolve()?->id),
            'unifi' => fn () => $unifi->snapshot(),
        ]);
    }
}
