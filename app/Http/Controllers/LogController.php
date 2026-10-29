<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    public function __invoke(Request $request, AnalyticsQuery $analytics, CurrentMikrotikSession $current): Response
    {
        $window = $analytics->window($request);
        $session = $current->resolve();

        return Inertia::render('Logs', [
            'pulse' => $analytics->pulse($session?->id),
            'minutes' => $window['minutes'],
            'from' => $window['from'],
            'to' => $window['to'],
            'live' => $window['live'],
            'logs' => $analytics->recentLogs($session?->id, $window['start'], $window['end'], 250),
        ]);
    }
}
