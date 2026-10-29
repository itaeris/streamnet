<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AnalyticsQuery $analytics, CurrentMikrotikSession $current): Response
    {
        $window = $analytics->window($request);
        $session = $current->resolve();
        $pulse = $analytics->pulse($session?->id);
        $wan = $pulse['wan']['name'] ?? $session?->wan_interface ?? config('mikrotik.wan_interface');
        $bridge = $this->bridgeName(is_array($pulse) ? $pulse : null);

        return Inertia::render('Dashboard', [
            'pulse' => $pulse,
            'minutes' => $window['minutes'],
            'from' => $window['from'],
            'to' => $window['to'],
            'live' => $window['live'],
            'series' => $analytics->interfaceSeries($session?->id, $wan, $window['start'], $window['end']),
            'bridge' => [
                'name' => $bridge,
                'series' => $analytics->interfaceSeries($session?->id, $bridge, $window['start'], $window['end']),
            ],
            'logs' => $analytics->recentLogs($session?->id, $window['start'], $window['end'], 8),
            'events' => $analytics->recentEvents($session?->id, $window['start'], $window['end'], 5),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $pulse
     */
    private function bridgeName(?array $pulse): ?string
    {
        $interfaces = collect($pulse['interfaces'] ?? []);
        $named = $interfaces->first(fn ($row) => strtolower((string) ($row['name'] ?? '')) === 'bridge');
        $typed = $interfaces->first(fn ($row) => strtolower((string) ($row['type'] ?? '')) === 'bridge');
        $match = is_array($named) ? $named : $typed;
        $name = is_array($match) ? ($match['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
