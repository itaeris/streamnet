<?php

namespace App\Http\Controllers;

use App\Models\DhcpLease;
use App\Models\LiveSnapshot;
use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use App\Services\Mikrotik\MikrotikClient;
use Illuminate\Http\RedirectResponse;
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

    public function updateComment(Request $request, CurrentMikrotikSession $current, MikrotikClient $client): RedirectResponse
    {
        $data = $request->validate([
            'router_id' => ['required', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:128'],
        ]);
        $session = $current->resolve();
        abort_unless($session, 404);

        $comment = trim((string) ($data['comment'] ?? ''));
        $result = $client->gather([
            'set' => ['method' => 'post', 'path' => 'ip/dhcp-server/lease/set', 'body' => [
                '.id' => $data['router_id'],
                'comment' => $comment,
            ]],
        ], $session->connection());

        if (($result['set'] ?? null) === null) {
            return back()->withErrors(['comment' => 'Router menolak perubahan alias.']);
        }

        $stored = $comment !== '' ? $comment : null;
        $lease = DhcpLease::query()
            ->where('mikrotik_session_id', $session->id)
            ->where('router_id', $data['router_id'])
            ->first();
        $lease?->update(['comment' => $stored]);

        $snapshot = LiveSnapshot::query()
            ->where('mikrotik_session_id', $session->id)
            ->where('key', 'pulse')
            ->first();
        $payload = $snapshot?->payload;
        if (is_array($payload) && is_array($payload['leases'] ?? null)) {
            foreach ($payload['leases'] as $index => $row) {
                $sameId = ($row['router_id'] ?? '') === $data['router_id'];
                $sameAddress = $lease && ($row['address'] ?? '') === $lease->address;
                if (! $sameId && ! $sameAddress) {
                    continue;
                }

                $payload['leases'][$index]['comment'] = $stored;
                $payload['leases'][$index]['router_id'] = $data['router_id'];
            }
            $snapshot->update(['payload' => $payload]);
        }

        return back();
    }
}
