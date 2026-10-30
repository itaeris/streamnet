<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Inertia\Inertia;
use Inertia\Response;

class TrafficAgentController extends Controller
{
    public function __invoke(AnalyticsQuery $analytics, CurrentMikrotikSession $current): Response
    {
        $session = $current->resolve();

        return Inertia::render('TrafficAgent', [
            'pulse' => $analytics->pulse($session?->id),
        ]);
    }
}
