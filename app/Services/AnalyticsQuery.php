<?php

namespace App\Services;

use App\Models\DnsQuery;
use App\Models\InterfaceSample;
use App\Models\QueueSample;
use App\Models\LiveSnapshot;
use App\Models\RouterLog;
use App\Models\SecurityEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsQuery
{
    /**
     * @return array<string, mixed>|null
     */
    public function pulse(?int $sessionId): ?array
    {
        if ($sessionId === null) {
            return null;
        }

        return LiveSnapshot::query()
            ->where('mikrotik_session_id', $sessionId)
            ->where('key', 'pulse')
            ->first()
            ?->payload;
    }

    /**
     * @return array{minutes: int, from: int|null, to: int|null, start: Carbon, end: Carbon}
     */
    public function window(Request $request): array
    {
        $allowed = [5, 15, 30, 60, 180, 360, 720, 1440, 2880];
        $from = $request->integer('from');
        $to = $request->integer('to');
        $now = now()->getTimestamp();

        if ($request->boolean('live')) {
            $span = (int) $request->integer('span', 1440);
            if (! in_array($span, [1440, 10080, 43200], true)) {
                $span = 1440;
            }
            $end = now();

            return [
                'minutes' => $span,
                'from' => null,
                'to' => null,
                'start' => $end->copy()->subDays(31),
                'chart_start' => $end->copy()->subMinutes($span),
                'end' => $end,
                'live' => true,
            ];
        }

        if ($from > 0 && $to > $from) {
            $to = min($to, $now);
            $span = $to - $from;

            if ($span >= 60 && $span <= 2880 * 60) {
            return [
                'minutes' => (int) max(1, round($span / 60)),
                'from' => $from,
                'to' => $to,
                'start' => Carbon::createFromTimestamp($from),
                'chart_start' => Carbon::createFromTimestamp($from),
                'end' => Carbon::createFromTimestamp($to),
                'live' => false,
            ];
            }
        }

        $minutes = (int) $request->integer('minutes', 30);
        if (! in_array($minutes, $allowed, true)) {
            $minutes = 30;
        }

        $end = now();

        return [
            'minutes' => $minutes,
            'from' => null,
            'to' => null,
            'start' => $end->copy()->subMinutes($minutes),
            'chart_start' => $end->copy()->subMinutes($minutes),
            'end' => $end,
            'live' => false,
        ];
    }

    /**
     * @return array<int, array{t: string, ts: int, rx: int, tx: int}>
     */
    public function interfaceSeries(?int $sessionId, ?string $name, Carbon $start, Carbon $end, int $maxPoints = 240): array
    {
        if ($sessionId === null || $name === null || $name === '') {
            return [];
        }

        $query = InterfaceSample::query()
            ->where('mikrotik_session_id', $sessionId)
            ->where('name', $name)
            ->whereBetween('sampled_at', [$start, $end]);

        $bucket = $this->bucketSeconds($start, $end, $maxPoints);
        if ($bucket > 0) {
            return $this->bucketed($query, $start, $bucket, false);
        }

        $rows = $query->orderBy('sampled_at')->toBase()->get(['sampled_at', 'rx_bps', 'tx_bps']);

        return $this->downsample($rows, $maxPoints, $start, $end);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, array<int, array{t: string, ts: int, rx: int, tx: int}>>
     */
    public function interfaceSeriesMap(?int $sessionId, array $names, Carbon $start, Carbon $end, int $maxPoints = 240): array
    {
        if ($sessionId === null || $names === []) {
            return [];
        }

        $query = InterfaceSample::query()
            ->where('mikrotik_session_id', $sessionId)
            ->whereIn('name', $names)
            ->whereBetween('sampled_at', [$start, $end]);

        $bucket = $this->bucketSeconds($start, $end, $maxPoints);
        if ($bucket > 0) {
            return $this->bucketed($query, $start, $bucket, true);
        }

        $rows = $query->orderBy('sampled_at')->toBase()->get(['name', 'sampled_at', 'rx_bps', 'tx_bps']);

        return $rows
            ->groupBy('name')
            ->map(fn (Collection $group) => $this->downsample($group, $maxPoints, $start, $end))
            ->all();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, array<int, array{t: string, ts: int, rx: int, tx: int}>>
     */
    public function queueSeriesMap(?int $sessionId, array $names, Carbon $start, Carbon $end, int $maxPoints = 240): array
    {
        if ($sessionId === null || $names === []) {
            return [];
        }

        $query = QueueSample::query()
            ->where('mikrotik_session_id', $sessionId)
            ->whereIn('name', $names)
            ->whereBetween('sampled_at', [$start, $end]);

        $bucket = $this->bucketSeconds($start, $end, $maxPoints);
        if ($bucket > 0) {
            return $this->bucketed($query, $start, $bucket, true);
        }

        $rows = $query->orderBy('sampled_at')->toBase()->get(['name', 'sampled_at', 'rx_bps', 'tx_bps']);

        return $rows
            ->groupBy('name')
            ->map(fn (Collection $group) => $this->downsample($group, $maxPoints, $start, $end))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentLogs(?int $sessionId, Carbon $start, Carbon $end, int $limit = 200): array
    {
        if ($sessionId === null) {
            return [];
        }

        return RouterLog::query()
            ->where('mikrotik_session_id', $sessionId)
            ->whereBetween('logged_at', [$start, $end])
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map->toPayload()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dnsQueries(?int $sessionId, Carbon $start, Carbon $end, int $limit = 500): array
    {
        if ($sessionId === null) {
            return [];
        }

        return DnsQuery::query()
            ->where('mikrotik_session_id', $sessionId)
            ->whereBetween('logged_at', [$start, $end])
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map->toPayload()
            ->all();
    }

    /**
     * Apps opened most often in the chart window.
     *
     * @return array<int, array{name: string, site: string|null, users: int, queries: int}>
     */
    public function dnsApps(?int $sessionId, Carbon $start, Carbon $end, int $limit = 8): array
    {
        if ($sessionId === null) {
            return [];
        }

        $sites = app(DnsActivity::class);
        $rows = DnsQuery::query()
            ->where('mikrotik_session_id', $sessionId)
            ->whereBetween('logged_at', [$start, $end])
            ->where('app', '!=', '')
            ->select('app')
            ->selectRaw('COUNT(*) as queries')
            ->selectRaw('COUNT(DISTINCT address) as users')
            ->groupBy('app')
            ->orderByDesc('users')
            ->orderByDesc('queries')
            ->limit($limit)
            ->get();

        $names = $rows->pluck('app')->filter()->values();
        $counts = $names->isEmpty() ? collect() : DnsQuery::query()
            ->where('mikrotik_session_id', $sessionId)
            ->whereBetween('logged_at', [$start, $end])
            ->whereIn('app', $names)
            ->select('app', 'domain')
            ->selectRaw('COUNT(*) as queries')
            ->groupBy('app', 'domain')
            ->get()
            ->groupBy('app');

        return $rows->map(function (object $row) use ($sites, $counts) {
            $name = (string) $row->app;
            $seen = [];
            foreach ($counts->get($name, []) as $domain) {
                $seen[(string) $domain->domain] = (int) $domain->queries;
            }
            $hosts = $sites->logoHosts($seen, $sites->website($name));

            return [
                'name' => $name,
                'site' => $hosts[0] ?? null,
                'hosts' => $hosts,
                'users' => (int) $row->users,
                'queries' => (int) $row->queries,
            ];
        })->all();
    }

    /**
     * Clients that hit the bandwidth policy, busiest first.
     *
     * @return array<int, array{name: string, address: string|null, count: int, peak: int|null}>
     */
    public function bandwidthClients(?int $sessionId, Carbon $start, Carbon $end, int $limit = 8): array
    {
        if ($sessionId === null) {
            return [];
        }

        $groups = [];
        $events = SecurityEvent::query()
            ->where('mikrotik_session_id', $sessionId)
            ->where('category', 'bandwidth')
            ->whereBetween('occurred_at', [$start, $end])
            ->orderByDesc('occurred_at')
            ->limit(2000)
            ->get(['detail', 'context']);

        foreach ($events as $event) {
            $client = is_array($event->context['client'] ?? null) ? $event->context['client'] : [];
            $detail = (string) $event->detail;
            preg_match('/\(([\d.]+)\)/', $detail, $address);
            preg_match('/(?:download|upload)\s+([\d.]+)\s*Mbps/i', $detail, $rate);
            $key = (string) ($client['address'] ?? ($address[1] ?? ''));
            if ($key === '') {
                $key = (string) ($client['name'] ?? $detail);
            }
            $direction = ($client['direction'] ?? null) === 'up' || str_contains(strtolower($detail), 'upload') ? 'up' : 'down';
            $peak = isset($client[$direction]) ? (int) $client[$direction] : (isset($rate[1]) ? (int) round((float) $rate[1] * 1_000_000) : null);
            $group = $groups[$key] ?? [
                'name' => (string) ($client['name'] ?? (trim(strstr($detail, ' (', true) ?: '') ?: $key)),
                'address' => $client['address'] ?? ($address[1] ?? null),
                'count' => 0,
                'peak' => null,
            ];
            $group['count']++;
            if ($peak !== null && ($group['peak'] === null || $peak > $group['peak'])) {
                $group['peak'] = $peak;
            }
            $groups[$key] = $group;
        }

        return collect($groups)->sortByDesc('count')->take($limit)->values()->all();
    }

    /**
     * Latest queries from one client.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dnsForAddress(?int $sessionId, string $address, Carbon $start, Carbon $end, int $limit = 15): array
    {
        if ($sessionId === null) {
            return [];
        }

        return DnsQuery::query()
            ->where('mikrotik_session_id', $sessionId)
            ->where('address', $address)
            ->whereBetween('logged_at', [$start, $end])
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DnsQuery $row) => $row->toPayload())
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentEvents(?int $sessionId, Carbon $start, Carbon $end, int $limit = 40): array
    {
        if ($sessionId === null) {
            return [];
        }

        return SecurityEvent::query()
            ->where('mikrotik_session_id', $sessionId)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map->toPayload()
            ->all();
    }

    public function seenAt(?string $lastSeen, ?Carbon $now = null): Carbon
    {
        $now ??= now();
        $seconds = $this->durationSeconds($lastSeen);

        return $seconds === null ? $now->copy() : $now->copy()->subSeconds($seconds);
    }

    private function durationSeconds(?string $value): ?int
    {
        $raw = trim((string) $value);

        if ($raw === '' || ! preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $raw, $match)) {
            return null;
        }

        $weeks = (int) ($match[1] ?? 0);
        $days = (int) ($match[2] ?? 0);
        $hours = (int) ($match[3] ?? 0);
        $minutes = (int) ($match[4] ?? 0);
        $seconds = (int) ($match[5] ?? 0);

        return ((($weeks * 7 + $days) * 24 + $hours) * 60 + $minutes) * 60 + $seconds;
    }

    private function bucketSeconds(Carbon $start, Carbon $end, int $maxPoints): int
    {
        $span = max(1, $end->getTimestamp() - $start->getTimestamp());
        if ($span <= 2 * 3600) {
            return 0;
        }

        return (int) max(60, (int) ceil($span / max(1, $maxPoints)));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<InterfaceSample>  $query
     * @return array<int, array{t: string, ts: int, rx: int, tx: int}>|array<string, array<int, array{t: string, ts: int, rx: int, tx: int}>>
     */
    private function bucketed($query, Carbon $start, int $bucket, bool $byName): array
    {
        $origin = $start->getTimestamp();
        $driver = $query->getConnection()->getDriverName();
        $stamp = $driver === 'sqlite'
            ? "CAST(strftime('%s', sampled_at) AS INTEGER)"
            : 'UNIX_TIMESTAMP(sampled_at)';
        $bucketExpr = "FLOOR(($stamp - $origin) / $bucket)";
        $timezone = config('app.timezone');
        $format = $bucket >= 300 ? 'd M H:i' : 'H:i:s';
        $select = [
            DB::raw("$bucketExpr as bucket"),
            DB::raw("MIN($stamp) as ts"),
            DB::raw('ROUND(AVG(rx_bps)) as rx'),
            DB::raw('ROUND(AVG(tx_bps)) as tx'),
        ];
        if ($byName) {
            $select[] = 'name';
        }

        $builder = $query->toBase()->select($select)->groupBy(DB::raw($bucketExpr))->orderBy('bucket');
        if ($byName) {
            $builder->groupBy('name')->orderBy('name');
        }

        $points = [];
        foreach ($builder->get() as $row) {
            $point = [
                't' => Carbon::createFromTimestamp((int) $row->ts, $timezone)->format($format),
                'ts' => (int) $row->ts,
                'rx' => (int) $row->rx,
                'tx' => (int) $row->tx,
            ];
            if ($byName) {
                $points[(string) $row->name][] = $point;
            } else {
                $points[] = $point;
            }
        }

        return $points;
    }

    /**
     * Average samples into at most $maxPoints time buckets.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array{t: string, ts: int, rx: int, tx: int}>
     */
    private function downsample(Collection $rows, int $maxPoints, Carbon $start, Carbon $end): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $stamps = $rows->map(fn (object $row) => strtotime((string) $row->sampled_at) ?: 0);
        $first = (int) $stamps->first();
        $span = max(1, (int) $stamps->last() - $first);
        $bucket = $rows->count() <= $maxPoints ? 0 : (int) ceil($span / max(1, $maxPoints));
        $format = $start->diffInHours($end) >= 24 ? 'd M H:i' : 'H:i:s';
        $timezone = config('app.timezone');
        $groups = [];

        foreach ($rows->values() as $index => $row) {
            $ts = (int) $stamps[$index];
            $key = $bucket > 0 ? intdiv($ts - $first, $bucket) : $index;
            $group = $groups[$key] ?? ['ts' => $ts, 'rx' => 0, 'tx' => 0, 'n' => 0];
            $group['rx'] += (int) $row->rx_bps;
            $group['tx'] += (int) $row->tx_bps;
            $group['n']++;
            $groups[$key] = $group;
        }

        return array_values(array_map(fn (array $group) => [
            't' => Carbon::createFromTimestamp($group['ts'], $timezone)->format($format),
            'ts' => $group['ts'],
            'rx' => intdiv($group['rx'], $group['n']),
            'tx' => intdiv($group['tx'], $group['n']),
        ], $groups));
    }
}
