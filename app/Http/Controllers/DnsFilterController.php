<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsQuery;
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
            'filter' => $session ? fn () => $filter->present($filter->read($session)) : [
                'online' => false,
                'rule' => false,
                'active' => false,
                'packets' => 0,
                'bytes' => 0,
                'sampled_at' => null,
                'regexp' => '',
                'sites' => [],
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

        return back();
    }
}
