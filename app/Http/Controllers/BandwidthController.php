<?php

namespace App\Http\Controllers;

use App\Models\InterfaceSample;
use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BandwidthController extends Controller
{
    public function __invoke(Request $request, AnalyticsQuery $analytics, CurrentMikrotikSession $current): Response
    {
        $window = $analytics->window($request);
        $session = $current->resolve();
        $pulse = $analytics->pulse($session?->id);
        $names = collect($pulse['interfaces'] ?? [])->pluck('name')->filter()->values();

        if ($names->isEmpty()) {
            $names = InterfaceSample::query()
                ->where('mikrotik_session_id', $session?->id)
                ->distinct()
                ->orderBy('name')
                ->pluck('name');
        }

        return Inertia::render('Bandwidth', [
            'pulse' => $pulse,
            'minutes' => $window['minutes'],
            'from' => $window['from'],
            'to' => $window['to'],
            'live' => $window['live'],
            'series' => fn () => $analytics->interfaceSeriesMap($session?->id, $names->all(), $window['start'], $window['end']),
        ]);
    }
}
