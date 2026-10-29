<?php

namespace App\Http\Controllers;

use App\Models\DnsQuery;
use App\Models\InterfaceSample;
use App\Services\AnalyticsQuery;
use App\Services\DnsActivity;
use App\Services\IncidentReport;
use App\Services\Mikrotik\CurrentMikrotikSession;
use App\Services\Mikrotik\MikrotikClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BandwidthPolicyController extends Controller
{
    public function index(AnalyticsQuery $analytics, CurrentMikrotikSession $current, IncidentReport $report): Response
    {
        $session = $current->resolve();
        $pulse = $analytics->pulse($session?->id);
        $wan = $pulse['wan']['name'] ?? $session?->wan_interface;

        return Inertia::render('BandwidthPolicy', [
            'pulse' => $pulse,
            'wanPeak' => fn () => [
                'rx' => (int) InterfaceSample::query()
                    ->where('mikrotik_session_id', $session?->id)
                    ->where('name', $wan)
                    ->where('sampled_at', '>=', now()->subDay())
                    ->max('rx_bps'),
                'tx' => (int) InterfaceSample::query()
                    ->where('mikrotik_session_id', $session?->id)
                    ->where('name', $wan)
                    ->where('sampled_at', '>=', now()->subDay())
                    ->max('tx_bps'),
            ],
            'incidents' => fn () => $report->rows($session?->id, now()->subDays(30), now()),
        ]);
    }

    public function export(Request $request, CurrentMikrotikSession $current, IncidentReport $report): BinaryFileResponse
    {
        $range = $request->validate(['range' => ['nullable', 'in:'.implode(',', array_keys(IncidentReport::RANGES))]])['range'] ?? '7d';
        $window = $report->window($range);
        $rows = $report->rows($current->resolve()?->id, $window['start'], $window['end']);
        $path = $report->export($rows, $window['label']);

        return response()
            ->download($path, sprintf('insiden-bandwidth-%s-%s.xlsx', $range, now()->format('Ymd-His')))
            ->deleteFileAfterSend();
    }

    public function context(Request $request, CurrentMikrotikSession $current, DnsActivity $dns): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'ip'],
            'time' => ['required', 'date'],
        ]);
        $at = Carbon::parse($data['time'], config('app.timezone'));

        $rows = DnsQuery::query()
            ->where('mikrotik_session_id', $current->resolve()?->id)
            ->where('address', $data['address'])
            ->whereBetween('logged_at', [$at->copy()->subMinutes(3), $at->copy()->addMinute()])
            ->selectRaw('domain, app, count(*) as total, max(logged_at) as last_at')
            ->groupBy('domain', 'app')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $kinds = [];
        foreach ($rows as $row) {
            $kind = $dns->kind($row->domain);
            $kinds[$kind] = ($kinds[$kind] ?? 0) + (int) $row->total;
        }
        arsort($kinds);

        return response()->json([
            'kinds' => $kinds,
            'domains' => $rows->map(fn ($row) => [
                'domain' => $row->domain,
                'app' => $row->app,
                'kind' => $dns->kind($row->domain),
                'total' => (int) $row->total,
            ])->values(),
        ]);
    }

    public function update(Request $request, AnalyticsQuery $analytics, CurrentMikrotikSession $current, MikrotikClient $client): RedirectResponse
    {
        $data = $request->validate([
            'capacity_down' => ['required', 'numeric', 'min:1', 'max:100000'],
            'capacity_up' => ['required', 'numeric', 'min:1', 'max:100000'],
            'client_down' => ['required', 'numeric', 'min:0', 'lte:capacity_down'],
            'client_up' => ['required', 'numeric', 'min:0', 'lte:capacity_up'],
            'burst' => ['boolean'],
            'burst_down' => ['exclude_unless:burst,true', 'required', 'numeric', 'gt:client_down', 'lte:capacity_down'],
            'burst_up' => ['exclude_unless:burst,true', 'required', 'numeric', 'gt:client_up', 'lte:capacity_up'],
            'burst_time' => ['exclude_unless:burst,true', 'required', 'integer', 'min:1', 'max:120'],
            'target' => ['nullable', 'string', 'max:64', 'regex:/^[\d.\/,]+$/'],
        ], [
            'client_down.lte' => 'Batas per klien tidak boleh melebihi kapasitas.',
            'client_up.lte' => 'Batas per klien tidak boleh melebihi kapasitas.',
            'burst_down.gt' => 'Burst harus lebih besar dari batas per klien.',
            'burst_up.gt' => 'Burst harus lebih besar dari batas per klien.',
            'target.regex' => 'Target berisi subnet, misalnya 10.10.10.0/24.',
        ]);

        $session = $current->resolve();
        abort_unless($session, 404);

        $shaping = $analytics->pulse($session->id)['shaping'] ?? null;
        $mbps = fn (float|int|string $value) => (string) (int) round((float) $value * 1_000_000);
        $burst = (bool) ($data['burst'] ?? false);
        $pcq = fn (string $rate, ?string $burstRate) => [
            'pcq-rate' => $rate,
            'pcq-burst-rate' => $burst && $burstRate !== null ? $burstRate : '0',
            'pcq-burst-threshold' => $burst ? (string) (int) round((float) $rate * 0.75) : '0',
            'pcq-burst-time' => $burst ? ($data['burst_time'] ?? 10).'s' : '10s',
        ];
        $down = $pcq($mbps($data['client_down']), $burst ? $mbps($data['burst_down']) : null);
        $up = $pcq($mbps($data['client_up']), $burst ? $mbps($data['burst_up']) : null);
        $maxLimit = $mbps($data['capacity_up']).'/'.$mbps($data['capacity_down']);

        if (is_array($shaping['main'] ?? null)) {
            $calls = [
                'down' => ['method' => 'post', 'path' => 'queue/type/set', 'body' => ['.id' => $shaping['down_type']['id'], ...$down]],
                'up' => ['method' => 'post', 'path' => 'queue/type/set', 'body' => ['.id' => $shaping['up_type']['id'], ...$up]],
                'queue' => ['method' => 'post', 'path' => 'queue/simple/set', 'body' => ['.id' => $shaping['main']['id'], 'max-limit' => $maxLimit]],
            ];
        } else {
            if (blank($data['target'] ?? null)) {
                return back()->withErrors(['target' => 'Isi subnet LAN untuk membuat queue baru.']);
            }

            $calls = [
                'down' => ['method' => 'post', 'path' => 'queue/type/add', 'body' => ['name' => 'streamnet-pcq-down', 'kind' => 'pcq', 'pcq-classifier' => 'dst-address', ...$down]],
                'up' => ['method' => 'post', 'path' => 'queue/type/add', 'body' => ['name' => 'streamnet-pcq-up', 'kind' => 'pcq', 'pcq-classifier' => 'src-address', ...$up]],
            ];
        }

        $result = $client->gather($calls, $session->connection());
        if (collect($calls)->keys()->contains(fn (string $key) => ($result[$key] ?? null) === null)) {
            return back()->withErrors(['policy' => 'Router menolak perubahan. Pastikan user API punya izin write.']);
        }

        if (! is_array($shaping['main'] ?? null)) {
            $queue = $client->gather([
                'queue' => ['method' => 'post', 'path' => 'queue/simple/add', 'body' => [
                    'name' => 'streamnet-fair-share',
                    'target' => $data['target'],
                    'queue' => 'streamnet-pcq-up/streamnet-pcq-down',
                    'max-limit' => $maxLimit,
                    'comment' => 'StreamNet fair share',
                ]],
            ], $session->connection());

            if (($queue['queue'] ?? null) === null) {
                return back()->withErrors(['policy' => 'Tipe PCQ dibuat, tapi simple queue gagal ditambahkan.']);
            }
        }

        return back();
    }
}
