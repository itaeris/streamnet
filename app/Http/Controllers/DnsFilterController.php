<?php

namespace App\Http\Controllers;

use App\Models\DnsFilterWindow;
use App\Services\AnalyticsQuery;
use App\Services\DnsFilterSchedule;
use App\Services\Mikrotik\CurrentMikrotikSession;
use App\Services\Mikrotik\DnsFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DnsFilterController extends Controller
{
    public function index(AnalyticsQuery $analytics, CurrentMikrotikSession $current, DnsFilter $filter): Response
    {
        $session = $current->resolve();

        return Inertia::render('DnsFilter', [
            'pulse' => $analytics->pulse($session?->id),
            'timezone' => config('app.timezone'),
            'filter' => $session ? fn () => [
                ...$filter->present($filter->read($session)),
                'schedules' => $this->schedules($session->id),
            ] : [
                'online' => false,
                'rule' => false,
                'active' => false,
                'packets' => 0,
                'bytes' => 0,
                'sampled_at' => null,
                'regexp' => '',
                'sites' => [],
                'schedules' => [],
            ],
        ]);
    }

    public function store(Request $request, CurrentMikrotikSession $current, DnsFilter $filter): RedirectResponse
    {
        $session = $current->resolve();
        abort_unless($session, 404);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        $error = $filter->block($session, $data['domain']);
        if ($error !== null) {
            return back()->withErrors(['domain' => $error]);
        }

        return back();
    }

    public function update(Request $request, CurrentMikrotikSession $current, DnsFilter $filter): RedirectResponse
    {
        $session = $current->resolve();
        abort_unless($session, 404);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'enabled' => ['required', 'boolean'],
        ]);

        $error = $filter->toggle($session, $data['domain'], $request->boolean('enabled'));
        if ($error !== null) {
            return back()->withErrors(['domain' => $error]);
        }

        return back();
    }

    public function destroy(Request $request, CurrentMikrotikSession $current, DnsFilter $filter): RedirectResponse
    {
        $session = $current->resolve();
        abort_unless($session, 404);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        $error = $filter->unblock($session, $data['domain']);
        if ($error !== null) {
            return back()->withErrors(['domain' => $error]);
        }

        $domain = $filter->hostname($data['domain']);
        if ($domain !== null) {
            DnsFilterWindow::query()
                ->where('mikrotik_session_id', $session->id)
                ->where('domain', $domain)
                ->delete();
        }

        return back();
    }

    public function schedule(Request $request, CurrentMikrotikSession $current, DnsFilter $filter, DnsFilterSchedule $schedule): RedirectResponse
    {
        $session = $current->resolve();
        abort_unless($session, 404);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'windows' => ['present', 'array', 'max:12'],
            'windows.*.start' => ['required', 'date_format:H:i'],
            'windows.*.end' => ['required', 'date_format:H:i'],
            'windows.*.blocked' => ['required', 'boolean'],
            'windows.*.label' => ['required', 'string', 'max:40'],
        ]);

        $domain = $filter->hostname($data['domain']);
        if ($domain === null) {
            return back()->withErrors(['schedule' => 'Domain tidak valid.']);
        }

        foreach ($data['windows'] as $index => $window) {
            if ($window['start'] === $window['end'] || trim($window['label']) === '') {
                return back()->withErrors(['schedule' => 'Setiap jadwal butuh jam yang berbeda dan sebuah nama.']);
            }

            $data['windows'][$index]['blocked'] = $request->boolean('windows.'.$index.'.blocked');
            $data['windows'][$index]['label'] = trim($window['label']);
        }

        DnsFilterWindow::query()
            ->where('mikrotik_session_id', $session->id)
            ->where('domain', $domain)
            ->delete();

        foreach ($data['windows'] as $window) {
            DnsFilterWindow::query()->create([
                'mikrotik_session_id' => $session->id,
                'domain' => $domain,
                'starts_at' => $window['start'],
                'ends_at' => $window['end'],
                'blocked' => $window['blocked'],
                'label' => $window['label'],
            ]);
        }

        $schedule->enforce($session);

        return back();
    }

    /**
     * @return array<string, array<int, array{start: string, end: string, blocked: bool, label: string}>>
     */
    private function schedules(int $sessionId): array
    {
        return DnsFilterWindow::query()
            ->where('mikrotik_session_id', $sessionId)
            ->orderBy('id')
            ->get()
            ->groupBy('domain')
            ->map(fn ($rows) => $rows->map(fn (DnsFilterWindow $row) => [
                'start' => $row->starts_at,
                'end' => $row->ends_at,
                'blocked' => $row->blocked,
                'label' => $row->label,
            ])->values()->all())
            ->all();
    }
}
