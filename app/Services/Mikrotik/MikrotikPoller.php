<?php

namespace App\Services\Mikrotik;

use App\Models\DhcpLease;
use App\Models\DnsQuery;
use App\Models\InterfaceSample;
use App\Models\LiveSnapshot;
use App\Models\MikrotikSession;
use App\Models\RouterLog;
use App\Models\SecurityEvent;
use App\Models\SystemSample;
use App\Services\DnsActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MikrotikPoller
{
    private ?MikrotikSession $router = null;

    public function __construct(
        private MikrotikClient $client,
        private MikrotikSessionRegistry $sessions,
        private DnsActivity $dns,
        private DeliveredRates $delivered,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pollEnabled(): array
    {
        $this->sessions->ensureDefault();
        $rows = MikrotikSession::query()->where('enabled', true)->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->router = null;

            return [$this->offline('Belum ada sesi MikroTik. Tambahkan di Pengaturan.')];
        }

        $payloads = [];

        foreach ($rows as $session) {
            try {
                $payloads[] = $this->poll($session);
            } catch (Throwable $exception) {
                $payloads[] = [
                    'online' => false,
                    'error' => $exception->getMessage(),
                    'session_id' => $session->id,
                    'session_name' => $session->name,
                    'session_host' => $session->host,
                    'sampled_at' => now()->toIso8601String(),
                    'sampled_label' => now()->format('H:i:s'),
                ];
            }
        }

        return $payloads;
    }

    /**
     * Refresh throughput from interface counters without the slow connection scan.
     *
     * @return array<string, mixed>
     */
    public function refreshRates(MikrotikSession $session): array
    {
        $this->router = $session;
        $previous = LiveSnapshot::query()
            ->where('mikrotik_session_id', $session->id)
            ->where('key', 'pulse')
            ->first();
        $payload = $previous?->payload;

        if (! is_array($payload) || ! ($payload['online'] ?? false)) {
            return $this->poll($session);
        }

        $connection = $session->connection();
        $connection['timeout'] = 2;

        try {
            $batch = $this->client->gather([
                'resource' => ['path' => 'system/resource'],
                'health' => ['path' => 'system/health'],
                'interfaces' => ['path' => 'interface'],
                'host_traffic' => ['path' => 'ip/firewall/connection', 'body' => ['.proplist' => 'src-address,dst-address,orig-rate,repl-rate']],
            ], $connection);
        } catch (Throwable $exception) {
            return $this->offline($exception->getMessage());
        }

        if (! is_array($batch['resource'] ?? null)) {
            return $this->offline('Router tidak menjawab system/resource.');
        }

        $sampledAt = now();
        $prior = [];
        foreach ($payload['interfaces'] ?? [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                $prior[(string) $row['name']] = $row;
            }
        }

        $interfaces = $this->applySnapshotRates(
            $this->mapInterfaces($this->rows($batch['interfaces'] ?? null), [], $sampledAt),
            $prior,
            isset($payload['sampled_at']) ? (string) $payload['sampled_at'] : null,
            $sampledAt,
        );
        $resource = $batch['resource'];
        $health = $this->healthMap($batch['health'] ?? null);
        $memoryTotal = $this->integer($resource['total-memory'] ?? 0);
        $memoryFree = $this->integer($resource['free-memory'] ?? 0);
        $system = is_array($payload['system'] ?? null) ? $payload['system'] : [];
        $system['cpu_load'] = $this->integer($resource['cpu-load'] ?? 0);
        $system['uptime'] = $resource['uptime'] ?? ($system['uptime'] ?? null);
        $system['memory_used'] = max(0, $memoryTotal - $memoryFree);
        $system['memory_total'] = $memoryTotal;
        if (isset($health['temperature'])) {
            $system['temperature'] = (float) $health['temperature'];
        }
        if (isset($health['voltage'])) {
            $system['voltage'] = (float) $health['voltage'];
        }

        $payload['online'] = true;
        $payload['error'] = null;
        $payload['sampled_at'] = $sampledAt->toIso8601String();
        $payload['sampled_label'] = $sampledAt->format('H:i:s');
        $payload['system'] = $system;
        $payload['wan'] = $this->wan($interfaces);
        if (is_array($batch['host_traffic'] ?? null) && is_array($payload['leases'] ?? null)) {
            $payload['leases'] = $this->delivered->attach(
                $this->attachHostRates($payload['leases'], $batch['host_traffic']),
                $this->delivered->latest($session->id),
            );
        }
        $payload['interfaces'] = array_map(fn (array $row) => [
            'name' => $row['name'],
            'type' => $row['type'],
            'running' => $row['running'],
            'disabled' => $row['disabled'],
            'rx_bps' => $row['rx_bps'],
            'tx_bps' => $row['tx_bps'],
            'rx_pps' => $row['rx_pps'],
            'tx_pps' => $row['tx_pps'],
            'rx_bytes' => $row['rx_bytes'],
            'tx_bytes' => $row['tx_bytes'],
            'rx_error' => $row['rx_error'],
            'tx_error' => $row['tx_error'],
            'rx_drop' => $row['rx_drop'],
            'tx_drop' => $row['tx_drop'],
            'link_downs' => $row['link_downs'],
        ], $interfaces);

        LiveSnapshot::query()->updateOrCreate(
            ['mikrotik_session_id' => $session->id, 'key' => 'pulse'],
            ['payload' => $payload, 'sampled_at' => $sampledAt],
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function poll(?MikrotikSession $session = null): array
    {
        if (! $session instanceof MikrotikSession) {
            $this->sessions->ensureDefault();
            $session = MikrotikSession::query()->where('enabled', true)->orderBy('id')->first();
        }

        $this->router = $session;

        if ($session === null || blank($session->password)) {
            return $this->offline($session === null
                ? 'Belum ada sesi MikroTik. Tambahkan di Pengaturan.'
                : 'Password sesi MikroTik kosong.');
        }

        $this->ensureDnsLogging($session);

        try {
            $batch = $this->client->gather([
                'resource' => ['path' => 'system/resource'],
                'identity' => ['path' => 'system/identity'],
                'health' => ['path' => 'system/health'],
                'routerboard' => ['path' => 'system/routerboard'],
                'clock' => ['path' => 'system/clock'],
                'interfaces' => ['path' => 'interface'],
                'leases' => ['path' => 'ip/dhcp-server/lease'],
                'logs' => ['path' => 'log'],
                'filter' => ['path' => 'ip/firewall/filter'],
                'addresses' => ['path' => 'ip/firewall/address-list'],
                'sessions' => ['path' => 'user/active'],
                'services' => ['path' => 'ip/service'],
                'connections' => ['method' => 'post', 'path' => 'ip/firewall/connection/print', 'body' => ['count-only' => '']],
                'host_traffic' => ['path' => 'ip/firewall/connection', 'body' => ['.proplist' => 'src-address,dst-address,orig-rate,repl-rate']],
                'queues' => ['path' => 'queue/simple', 'body' => ['.proplist' => '.id,name,target,max-limit,queue,rate,pcq-queues,dropped,disabled,dynamic,comment']],
                'queue_types' => ['path' => 'queue/type'],
            ], $session->connection());
        } catch (Throwable $exception) {
            return $this->offline($exception->getMessage());
        }

        if (! is_array($batch['resource'] ?? null)) {
            return $this->offline('Router tidak menjawab system/resource.');
        }

        $interfaces = $this->rows($batch['interfaces'] ?? null);
        $rates = $this->trafficRates($interfaces);

        $sampledAt = now();
        $resource = $batch['resource'];
        $identity = is_array($batch['identity'] ?? null) ? $batch['identity'] : [];
        $routerboard = is_array($batch['routerboard'] ?? null) ? $batch['routerboard'] : [];
        $clock = is_array($batch['clock'] ?? null) ? $batch['clock'] : [];
        $health = $this->healthMap($batch['health'] ?? null);
        $memoryTotal = $this->integer($resource['total-memory'] ?? 0);
        $memoryFree = $this->integer($resource['free-memory'] ?? 0);
        $diskTotal = $this->integer($resource['total-hdd-space'] ?? 0);
        $diskFree = $this->integer($resource['free-hdd-space'] ?? 0);
        $connections = $this->countFrom($batch['connections'] ?? null);

        $system = [
            'identity' => trim((string) ($identity['name'] ?? 'MikroTik')),
            'board' => $resource['board-name'] ?? ($routerboard['model'] ?? null),
            'version' => $resource['version'] ?? null,
            'uptime' => $resource['uptime'] ?? null,
            'cpu_load' => $this->integer($resource['cpu-load'] ?? 0),
            'cpu_count' => $this->integer($resource['cpu-count'] ?? 0),
            'cpu_frequency' => $this->integer($resource['cpu-frequency'] ?? 0),
            'memory_used' => max(0, $memoryTotal - $memoryFree),
            'memory_total' => $memoryTotal,
            'disk_used' => max(0, $diskTotal - $diskFree),
            'disk_total' => $diskTotal,
            'temperature' => isset($health['temperature']) ? (float) $health['temperature'] : null,
            'voltage' => isset($health['voltage']) ? (float) $health['voltage'] : null,
            'firmware' => $routerboard['current-firmware'] ?? null,
            'upgrade_firmware' => $routerboard['upgrade-firmware'] ?? null,
            'serial' => $routerboard['serial-number'] ?? null,
            'router_time' => trim(((string) ($clock['date'] ?? '')).' '.((string) ($clock['time'] ?? ''))),
            'timezone' => $clock['time-zone-name'] ?? null,
        ];

        $identityName = mb_substr($system['identity'], 0, 80);
        if ($this->router->name === $this->router->host && $identityName !== '' && $identityName !== 'MikroTik') {
            $this->router->update(['name' => $identityName]);
        }

        $interfaceRows = $this->mapInterfaces($interfaces, $rates, $sampledAt);

        $payload = DB::transaction(function () use ($batch, $sampledAt, $system, $interfaceRows, $connections) {
            $previousSnapshot = LiveSnapshot::query()
                ->where('mikrotik_session_id', $this->router->id)
                ->where('key', 'pulse')
                ->first();
            $previousPayload = $previousSnapshot?->payload ?? [];
            $previousInterfaces = $this->previousInterfaces(array_column($interfaceRows, 'name'));
            $interfaceRows = $this->applyCounterRates($interfaceRows, $previousInterfaces, $sampledAt);
            $wan = $this->wan($interfaceRows);

            SystemSample::query()->create([
                'mikrotik_session_id' => $this->router->id,
                'sampled_at' => $sampledAt,
                'identity' => $system['identity'],
                'board' => $system['board'],
                'version' => $system['version'],
                'uptime' => $system['uptime'],
                'cpu_load' => $system['cpu_load'],
                'memory_used' => $system['memory_used'],
                'memory_total' => $system['memory_total'],
                'disk_used' => $system['disk_used'],
                'disk_total' => $system['disk_total'],
                'temperature' => $system['temperature'],
                'voltage' => $system['voltage'],
                'connections' => $connections ?? 0,
            ]);

            if ($interfaceRows !== []) {
                InterfaceSample::query()->insert(array_map(fn (array $row) => [
                    'mikrotik_session_id' => $this->router->id,
                    'sampled_at' => $sampledAt,
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'running' => $row['running'],
                    'disabled' => $row['disabled'],
                    'rx_bytes' => $row['rx_bytes'],
                    'tx_bytes' => $row['tx_bytes'],
                    'rx_bps' => $row['rx_bps'],
                    'tx_bps' => $row['tx_bps'],
                    'rx_error' => $row['rx_error'],
                    'tx_error' => $row['tx_error'],
                    'rx_drop' => $row['rx_drop'],
                    'tx_drop' => $row['tx_drop'],
                    'link_downs' => $row['link_downs'],
                    'created_at' => $sampledAt,
                    'updated_at' => $sampledAt,
                ], $interfaceRows));
            }

            $leases = $this->delivered->attach(
                $this->attachHostRates($this->syncLeases($batch['leases'] ?? null), $batch['host_traffic'] ?? null),
                $this->delivered->latest($this->router->id),
            );
            $logs = $this->syncLogs($batch['logs'] ?? null, $sampledAt);
            $services = $batch['services'] === null
                ? ($previousPayload['security']['services'] ?? [])
                : $this->mapServices($batch['services']);
            $sessions = $batch['sessions'] === null
                ? ($previousPayload['security']['sessions'] ?? [])
                : $this->mapSessions($batch['sessions']);
            $rules = $batch['filter'] === null
                ? ($previousPayload['security']['rules'] ?? [])
                : $this->mapRules($batch['filter']);
            $addressLists = $batch['addresses'] === null
                ? ($previousPayload['security']['address_lists'] ?? [])
                : $this->mapAddressLists($batch['addresses']);

            $findings = $this->findings(
                $system,
                $connections,
                $services,
                $sessions,
                $leases,
                $interfaceRows,
                $previousInterfaces,
            );

            $this->storeNewFindings($findings, $previousPayload['security']['findings'] ?? [], $sampledAt);

            $shaping = $batch['queues'] === null || $batch['queue_types'] === null
                ? ($previousPayload['shaping'] ?? null)
                : $this->mapShaping($batch['queues'], $batch['queue_types'], $batch['filter'] ?? null);
            $this->trackBandwidthHogs($leases, is_array($shaping) ? $shaping : null, $sampledAt, $batch['host_traffic'] ?? null);

            $leaseSummary = [
                'total' => count($leases),
                'bound' => count(array_filter($leases, fn (array $lease) => $lease['status'] === 'bound')),
                'waiting' => count(array_filter($leases, fn (array $lease) => $lease['status'] === 'waiting')),
                'blocked' => count(array_filter($leases, fn (array $lease) => $lease['blocked'])),
            ];

            $payload = [
                'online' => true,
                'error' => null,
                'session_id' => $this->router->id,
                'session_name' => $this->router->name,
                'session_host' => $this->router->host,
                'sampled_at' => $sampledAt->toIso8601String(),
                'sampled_label' => $sampledAt->format('H:i:s'),
                'system' => $system,
                'wan' => $wan,
                'interfaces' => array_map(fn (array $row) => [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'running' => $row['running'],
                    'disabled' => $row['disabled'],
                    'rx_bps' => $row['rx_bps'],
                    'tx_bps' => $row['tx_bps'],
                    'rx_pps' => $row['rx_pps'],
                    'tx_pps' => $row['tx_pps'],
                    'rx_bytes' => $row['rx_bytes'],
                    'tx_bytes' => $row['tx_bytes'],
                    'rx_error' => $row['rx_error'],
                    'tx_error' => $row['tx_error'],
                    'rx_drop' => $row['rx_drop'],
                    'tx_drop' => $row['tx_drop'],
                    'link_downs' => $row['link_downs'],
                ], $interfaceRows),
                'leases' => $leases,
                'lease_summary' => $leaseSummary,
                'logs' => array_slice($logs, 0, 40),
                'shaping' => $shaping,
                'security' => [
                    'connections' => $connections,
                    'sessions' => $sessions,
                    'services' => $services,
                    'rules' => $rules,
                    'address_lists' => $addressLists,
                    'findings' => $findings,
                ],
            ];

            LiveSnapshot::query()->updateOrCreate(
                ['mikrotik_session_id' => $this->router->id, 'key' => 'pulse'],
                ['payload' => $payload, 'sampled_at' => $sampledAt],
            );

            return $payload;
        });

        if (Cache::add('mikrotik.prune', true, now()->addMinutes(10))) {
            $this->prune();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function offline(string $message): array
    {
        $base = [
            'online' => false,
            'error' => $message,
            'session_id' => $this->router?->id,
            'session_name' => $this->router?->name,
            'session_host' => $this->router?->host,
            'sampled_at' => now()->toIso8601String(),
            'sampled_label' => now()->format('H:i:s'),
        ];

        if ($this->router === null) {
            return $base;
        }

        $previous = LiveSnapshot::query()
            ->where('mikrotik_session_id', $this->router->id)
            ->where('key', 'pulse')
            ->first();
        $payload = is_array($previous?->payload) ? $previous->payload : [];
        $payload = array_merge($payload, $base);

        LiveSnapshot::query()->updateOrCreate(
            ['mikrotik_session_id' => $this->router->id, 'key' => 'pulse'],
            ['payload' => $payload, 'sampled_at' => now()],
        );

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $interfaces
     * @return array<string, array<string, int>>
     */
    private function trafficRates(array $interfaces): array
    {
        $calls = [];

        foreach ($interfaces as $interface) {
            $name = (string) ($interface['name'] ?? '');
            if ($name === '' || ! $this->flag($interface['running'] ?? false) || $this->flag($interface['disabled'] ?? false)) {
                continue;
            }

            $calls[$name] = [
                'method' => 'post',
                'path' => 'interface/monitor-traffic',
                'body' => ['interface' => $name, 'once' => ''],
            ];
        }

        if ($calls === []) {
            return [];
        }

        try {
            $batch = $this->client->gather($calls, $this->router?->connection());
        } catch (Throwable) {
            return [];
        }

        $rates = [];

        foreach ($batch as $name => $json) {
            $row = $this->rows($json)[0] ?? null;
            if (! is_array($row)) {
                continue;
            }

            $rates[$name] = [
                'rx_bps' => $this->integer($row['rx-bits-per-second'] ?? 0),
                'tx_bps' => $this->integer($row['tx-bits-per-second'] ?? 0),
                'rx_pps' => $this->integer($row['rx-packets-per-second'] ?? 0),
                'tx_pps' => $this->integer($row['tx-packets-per-second'] ?? 0),
            ];
        }

        return $rates;
    }

    /**
     * Average the byte counters across the poll interval. A one-shot
     * monitor-traffic sample is only one second wide and draws as spikes.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, InterfaceSample>  $previous
     * @return array<int, array<string, mixed>>
     */
    private function applyCounterRates(array $rows, array $previous, Carbon $sampledAt): array
    {
        return array_map(function (array $row) use ($previous, $sampledAt) {
            $prior = $previous[$row['name']] ?? null;
            if ($prior === null || $prior->sampled_at === null) {
                return $row;
            }

            $seconds = abs($sampledAt->diffInSeconds($prior->sampled_at));
            if ($seconds < 1 || $seconds > 30) {
                return $row;
            }

            $rxBytes = $row['rx_bytes'] - (int) $prior->rx_bytes;
            $txBytes = $row['tx_bytes'] - (int) $prior->tx_bytes;
            if ($rxBytes < 0 || $txBytes < 0) {
                return $row;
            }

            $row['rx_bps'] = (int) round(($rxBytes * 8) / $seconds);
            $row['tx_bps'] = (int) round(($txBytes * 8) / $seconds);

            return $row;
        }, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $interfaces
     * @param  array<string, array<string, int>>  $rates
     * @return array<int, array<string, mixed>>
     */
    private function mapInterfaces(array $interfaces, array $rates, Carbon $sampledAt): array
    {
        return array_map(function (array $interface) use ($rates) {
            $name = (string) ($interface['name'] ?? 'unknown');
            $rate = $rates[$name] ?? ['rx_bps' => 0, 'tx_bps' => 0, 'rx_pps' => 0, 'tx_pps' => 0];

            return [
                'name' => $name,
                'type' => $interface['type'] ?? null,
                'running' => $this->flag($interface['running'] ?? false),
                'disabled' => $this->flag($interface['disabled'] ?? false),
                'rx_bytes' => $this->integer($interface['rx-byte'] ?? 0),
                'tx_bytes' => $this->integer($interface['tx-byte'] ?? 0),
                'rx_bps' => $rate['rx_bps'],
                'tx_bps' => $rate['tx_bps'],
                'rx_pps' => $rate['rx_pps'],
                'tx_pps' => $rate['tx_pps'],
                'rx_error' => $this->integer($interface['rx-error'] ?? 0),
                'tx_error' => $this->integer($interface['tx-error'] ?? 0),
                'rx_drop' => $this->integer($interface['rx-drop'] ?? 0),
                'tx_drop' => $this->integer($interface['tx-drop'] ?? 0),
                'link_downs' => $this->integer($interface['link-downs'] ?? 0),
            ];
        }, $interfaces);
    }

    /**
     * @param  array<int, array<string, mixed>>  $interfaces
     * @return array{name: string, rx_bps: int, tx_bps: int}
     */
    private function wan(array $interfaces): array
    {
        $preferred = (string) ($this->router?->wan_interface ?: config('mikrotik.wan_interface'));
        $match = collect($interfaces)->firstWhere('name', $preferred)
            ?? collect($interfaces)->first(fn (array $row) => $row['running'] && str_contains(strtolower($row['name']), 'isp'))
            ?? collect($interfaces)->first(fn (array $row) => $row['running'])
            ?? ['name' => $preferred, 'rx_bps' => 0, 'tx_bps' => 0];

        return [
            'name' => $match['name'],
            'rx_bps' => (int) $match['rx_bps'],
            'tx_bps' => (int) $match['tx_bps'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $prior
     * @return array<int, array<string, mixed>>
     */
    private function applySnapshotRates(array $rows, array $prior, ?string $sampledAt, Carbon $now): array
    {
        $previousAt = $sampledAt ? Carbon::parse($sampledAt) : null;

        return array_map(function (array $row) use ($prior, $previousAt, $now) {
            $old = $prior[$row['name']] ?? null;
            if (! is_array($old) || $previousAt === null) {
                return $row;
            }

            $seconds = abs($now->diffInSeconds($previousAt));
            if ($seconds < 1 || $seconds > 30) {
                $row['rx_bps'] = (int) ($old['rx_bps'] ?? 0);
                $row['tx_bps'] = (int) ($old['tx_bps'] ?? 0);
                $row['rx_pps'] = (int) ($old['rx_pps'] ?? 0);
                $row['tx_pps'] = (int) ($old['tx_pps'] ?? 0);

                return $row;
            }

            $rxBytes = $row['rx_bytes'] - (int) ($old['rx_bytes'] ?? 0);
            $txBytes = $row['tx_bytes'] - (int) ($old['tx_bytes'] ?? 0);
            if ($rxBytes < 0 || $txBytes < 0) {
                $row['rx_bps'] = (int) ($old['rx_bps'] ?? 0);
                $row['tx_bps'] = (int) ($old['tx_bps'] ?? 0);

                return $row;
            }

            $row['rx_bps'] = (int) round(($rxBytes * 8) / $seconds);
            $row['tx_bps'] = (int) round(($txBytes * 8) / $seconds);
            $row['rx_pps'] = (int) ($old['rx_pps'] ?? 0);
            $row['tx_pps'] = (int) ($old['tx_pps'] ?? 0);

            return $row;
        }, $rows);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, InterfaceSample>
     */
    private function previousInterfaces(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $ids = InterfaceSample::query()
            ->selectRaw('max(id) as id')
            ->where('mikrotik_session_id', $this->router?->id)
            ->whereIn('name', $names)
            ->groupBy('name')
            ->pluck('id');

        return InterfaceSample::query()->whereIn('id', $ids)->get()->keyBy('name')->all();
    }

    /**
     * Download is traffic toward the host. Upload is traffic leaving it.
     * Connection tracking reports orig-rate from src to dst and repl-rate back.
     *
     * @param  array<int, array<string, mixed>>  $leases
     * @return array<int, array<string, mixed>>
     */
    private function attachHostRates(array $leases, mixed $json): array
    {
        $totals = [];

        foreach ($this->rows($json) as $row) {
            $src = $this->hostAddress($row['src-address'] ?? '');
            $dst = $this->hostAddress($row['dst-address'] ?? '');
            $orig = $this->integer($row['orig-rate'] ?? 0);
            $repl = $this->integer($row['repl-rate'] ?? 0);

            if ($src !== '') {
                $totals[$src]['tx_bps'] = ($totals[$src]['tx_bps'] ?? 0) + $orig;
                $totals[$src]['rx_bps'] = ($totals[$src]['rx_bps'] ?? 0) + $repl;
                $totals[$src]['flows'] = ($totals[$src]['flows'] ?? 0) + 1;
            }

            if ($dst !== '' && $dst !== $src) {
                $totals[$dst]['rx_bps'] = ($totals[$dst]['rx_bps'] ?? 0) + $orig;
                $totals[$dst]['tx_bps'] = ($totals[$dst]['tx_bps'] ?? 0) + $repl;
                $totals[$dst]['flows'] = ($totals[$dst]['flows'] ?? 0) + 1;
            }
        }

        foreach ($leases as $index => $lease) {
            $rate = $totals[$this->hostAddress($lease['address'] ?? '')] ?? ['rx_bps' => 0, 'tx_bps' => 0, 'flows' => 0];
            $leases[$index]['rx_bps'] = $rate['rx_bps'];
            $leases[$index]['tx_bps'] = $rate['tx_bps'];
            $leases[$index]['flows'] = $rate['flows'];
        }

        return $leases;
    }

    private function hostAddress(mixed $value): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return '';
        }

        if (str_starts_with($text, '[')) {
            $end = strpos($text, ']');

            return $end === false ? $text : substr($text, 1, $end - 1);
        }

        if (substr_count($text, ':') === 1 && str_contains($text, '.')) {
            return strstr($text, ':', true) ?: $text;
        }

        return $text;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function syncLeases(mixed $json): array
    {
        if ($json === null) {
            return DhcpLease::query()
                ->where('mikrotik_session_id', $this->router?->id)
                ->orderBy('address')
                ->get()
                ->map->toPayload()
                ->all();
        }

        $rows = $this->rows($json);
        $ids = [];
        $payload = [];

        foreach ($rows as $row) {
            $routerId = (string) ($row['.id'] ?? '');
            if ($routerId === '') {
                continue;
            }

            $ids[] = $routerId;
            $record = [
                'address' => $row['address'] ?? ($row['active-address'] ?? null),
                'mac_address' => $row['mac-address'] ?? ($row['active-mac-address'] ?? null),
                'host_name' => $row['host-name'] ?? null,
                'server' => $row['server'] ?? ($row['active-server'] ?? null),
                'status' => $row['status'] ?? null,
                'blocked' => $this->flag($row['blocked'] ?? false),
                'dynamic' => $this->flag($row['dynamic'] ?? false),
                'last_seen' => $row['last-seen'] ?? null,
                'expires_after' => $row['expires-after'] ?? null,
                'comment' => $this->text($row['comment'] ?? '', 1000) ?: null,
            ];

            DhcpLease::query()->updateOrCreate(
                ['mikrotik_session_id' => $this->router->id, 'router_id' => $routerId],
                $record,
            );
            $payload[] = [
                'router_id' => $routerId,
                'address' => $record['address'],
                'mac' => $record['mac_address'],
                'host' => $record['host_name'],
                'server' => $record['server'],
                'status' => $record['status'],
                'blocked' => $record['blocked'],
                'dynamic' => $record['dynamic'],
                'last_seen' => $record['last_seen'],
                'expires_after' => $record['expires_after'],
                'comment' => $record['comment'],
            ];
        }

        if ($ids !== []) {
            DhcpLease::query()
                ->where('mikrotik_session_id', $this->router->id)
                ->whereNotIn('router_id', $ids)
                ->delete();
        }

        usort($payload, function (array $left, array $right) {
            return (ip2long((string) $left['address']) ?: 0) <=> (ip2long((string) $right['address']) ?: 0);
        });

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function syncLogs(mixed $json, Carbon $sampledAt): array
    {
        if ($json === null) {
            return [];
        }

        $entries = [];

        foreach ($this->rows($json) as $row) {
            $routerId = (string) ($row['.id'] ?? '');
            $message = $this->text($row['message'] ?? '', 4000);
            if ($routerId === '' || $message === '') {
                continue;
            }

            $topics = $this->text($row['topics'] ?? '', 255);
            $class = $this->classifyLog($topics, $message);
            $entries[] = [
                'id' => $routerId,
                'time' => $this->parseTime((string) ($row['time'] ?? ''))->format('Y-m-d H:i:s'),
                'topics' => $topics,
                'message' => $message,
                'severity' => $class['severity'] ?? 'info',
                'class' => $class,
            ];
        }

        $entries = array_values(array_filter($entries, fn (array $entry) => ! str_contains(strtolower((string) $entry['topics']), 'packet')));
        $queries = array_values(array_filter($entries, fn (array $entry) => $this->dns->isResolverLine((string) $entry['topics'], (string) $entry['message'])));
        $entries = array_values(array_filter($entries, fn (array $entry) => ! $this->dns->isResolverLine((string) $entry['topics'], (string) $entry['message'])));
        $this->storeDns($queries, $sampledAt);

        if ($entries === []) {
            return [];
        }

        $known = RouterLog::query()
            ->where('mikrotik_session_id', $this->router->id)
            ->whereIn('router_id', array_column($entries, 'id'))
            ->pluck('router_id')
            ->flip();

        $fresh = array_values(array_filter($entries, fn (array $entry) => ! isset($known[$entry['id']])));

        if ($fresh !== []) {
            RouterLog::query()->insert(array_map(fn (array $entry) => [
                'mikrotik_session_id' => $this->router->id,
                'router_id' => $entry['id'],
                'logged_at' => $entry['time'],
                'topics' => $entry['topics'],
                'message' => $entry['message'],
                'severity' => $entry['severity'],
                'created_at' => $sampledAt,
                'updated_at' => $sampledAt,
            ], $fresh));

            usort($fresh, fn (array $left, array $right) => strcmp($right['time'], $left['time']));
            $created = 0;

            foreach ($fresh as $entry) {
                if ($entry['class'] === null || $created >= 30) {
                    continue;
                }

                SecurityEvent::query()->create([
                    'mikrotik_session_id' => $this->router->id,
                    'occurred_at' => $entry['time'],
                    'severity' => $entry['class']['severity'],
                    'category' => $entry['class']['category'],
                    'title' => $entry['class']['title'],
                    'detail' => $entry['message'],
                ]);
                $created++;
            }
        }

        usort($fresh, fn (array $left, array $right) => strcmp($right['time'], $left['time']));

        return array_map(fn (array $entry) => [
            'id' => $entry['id'],
            'time' => $entry['time'],
            'topics' => $entry['topics'],
            'message' => $entry['message'],
            'severity' => $entry['severity'],
        ], $fresh);
    }

    /**
     * @return array{severity: string, category: string, title: string}|null
     */
    private function classifyLog(string $topics, string $message): ?array
    {
        $topic = strtolower($topics);
        $haystack = strtolower($message.' '.$topics);

        if (str_contains($haystack, 'login failure') || str_contains($haystack, 'login failed') || str_contains($haystack, 'authentication failed') || str_contains($haystack, 'invalid user')) {
            return ['severity' => 'critical', 'category' => 'login', 'title' => 'Login gagal'];
        }

        if (str_contains($topic, 'critical')) {
            return ['severity' => 'critical', 'category' => 'system', 'title' => 'Log critical'];
        }

        if (str_contains($topic, 'firewall') && preg_match('/\b(drop|reject)\b/', $haystack)) {
            return ['severity' => 'warning', 'category' => 'firewall', 'title' => 'Firewall menolak trafik'];
        }

        if ((str_contains($topic, 'error') || str_contains($topic, 'warning')) && ! str_contains($topic, 'dhcp')) {
            return ['severity' => 'warning', 'category' => 'system', 'title' => 'Peringatan router'];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapServices(mixed $json): array
    {
        return array_map(fn (array $row) => [
            'name' => $row['name'] ?? '',
            'port' => $row['port'] ?? '',
            'proto' => $row['proto'] ?? '',
            'disabled' => $this->flag($row['disabled'] ?? true),
            'address' => $row['address'] ?? '',
            'invalid' => $this->flag($row['invalid'] ?? false),
        ], $this->rows($json));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapSessions(mixed $json): array
    {
        return array_map(fn (array $row) => [
            'name' => $row['name'] ?? '',
            'address' => $row['address'] ?? '',
            'via' => $row['via'] ?? '',
            'group' => $row['group'] ?? '',
            'when' => $row['when'] ?? '',
        ], $this->rows($json));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapRules(mixed $json): array
    {
        $rules = array_map(fn (array $row) => [
            'chain' => $row['chain'] ?? '',
            'action' => $row['action'] ?? '',
            'comment' => $row['comment'] ?? '',
            'bytes' => $this->integer($row['bytes'] ?? 0),
            'packets' => $this->integer($row['packets'] ?? 0),
            'in_interface' => $row['in-interface'] ?? '',
            'out_interface' => $row['out-interface'] ?? '',
            'disabled' => $this->flag($row['disabled'] ?? false),
        ], $this->rows($json));

        usort($rules, fn (array $left, array $right) => $right['packets'] <=> $left['packets']);

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapAddressLists(mixed $json): array
    {
        return array_map(fn (array $row) => [
            'list' => $row['list'] ?? '',
            'address' => $row['address'] ?? '',
            'comment' => $row['comment'] ?? '',
            'dynamic' => $this->flag($row['dynamic'] ?? false),
            'disabled' => $this->flag($row['disabled'] ?? false),
        ], $this->rows($json));
    }

    /**
     * Simple queues, PCQ types, and whether FastTrack would bypass them.
     *
     * @return array<string, mixed>
     */
    private function mapShaping(mixed $queues, mixed $types, mixed $filter): array
    {
        $pcq = [];
        foreach ($this->rows($types) as $row) {
            if (($row['kind'] ?? '') !== 'pcq') {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $pcq[$name] = [
                'id' => (string) ($row['.id'] ?? ''),
                'name' => $name,
                'classifier' => (string) ($row['pcq-classifier'] ?? ''),
                'rate' => $this->bits($row['pcq-rate'] ?? 0),
                'burst_rate' => $this->bits($row['pcq-burst-rate'] ?? 0),
                'burst_threshold' => $this->bits($row['pcq-burst-threshold'] ?? 0),
                'burst_time' => (string) ($row['pcq-burst-time'] ?? ''),
                'default' => $this->flag($row['default'] ?? false),
            ];
        }

        $mapped = [];
        foreach ($this->rows($queues) as $row) {
            if ($this->flag($row['dynamic'] ?? false)) {
                continue;
            }

            [$upType, $downType] = array_pad(explode('/', (string) ($row['queue'] ?? ''), 2), 2, '');
            [$maxUp, $maxDown] = $this->pair($row['max-limit'] ?? '');
            [$rateUp, $rateDown] = $this->pair($row['rate'] ?? '');
            [$usersUp, $usersDown] = $this->pair($row['pcq-queues'] ?? '');
            [$droppedUp, $droppedDown] = $this->pair($row['dropped'] ?? '');

            $mapped[] = [
                'id' => (string) ($row['.id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'target' => (string) ($row['target'] ?? ''),
                'comment' => (string) ($row['comment'] ?? ''),
                'disabled' => $this->flag($row['disabled'] ?? false),
                'up_type' => $upType,
                'down_type' => $downType,
                'pcq' => isset($pcq[$upType], $pcq[$downType]),
                'max_up' => $maxUp,
                'max_down' => $maxDown,
                'rate_up' => $rateUp,
                'rate_down' => $rateDown,
                'users_up' => $usersUp,
                'users_down' => $usersDown,
                'dropped_up' => $droppedUp,
                'dropped_down' => $droppedDown,
            ];
        }

        $main = collect($mapped)->first(fn (array $queue) => $queue['pcq'] && ! $queue['disabled']);
        $fasttrack = collect($this->rows($filter))->contains(fn (array $rule) => ($rule['action'] ?? '') === 'fasttrack-connection'
            && ! $this->flag($rule['disabled'] ?? false));

        return [
            'main' => $main,
            'down_type' => $main ? $pcq[$main['down_type']] : null,
            'up_type' => $main ? $pcq[$main['up_type']] : null,
            'queues' => $mapped,
            'pcq_types' => array_values($pcq),
            'fasttrack' => $fasttrack,
        ];
    }

    /**
     * Log an incident when one client keeps taking most of the shaped capacity.
     *
     * @param  array<int, array<string, mixed>>  $leases
     * @param  array<string, mixed>|null  $shaping
     */
    private function trackBandwidthHogs(array $leases, ?array $shaping, Carbon $sampledAt, mixed $hostTraffic = null): void
    {
        $directions = [
            'down' => [
                'field' => 'rx_bps',
                'capacity' => (int) ($shaping['main']['max_down'] ?? 0),
                'cap' => (int) ($shaping['down_type']['rate'] ?? 0),
                'word' => 'download',
            ],
            'up' => [
                'field' => 'tx_bps',
                'capacity' => (int) ($shaping['main']['max_up'] ?? 0),
                'cap' => (int) ($shaping['up_type']['rate'] ?? 0),
                'word' => 'upload',
            ],
        ];

        foreach ($leases as $lease) {
            $address = (string) ($lease['address'] ?? '');
            if ($address === '') {
                continue;
            }

            foreach ($directions as $direction => $check) {
                $threshold = $check['cap'] > 0 ? (int) ($check['cap'] * 0.9) : (int) ($check['capacity'] * 0.5);
                $rate = (int) ($lease[$check['field']] ?? 0);
                $streak = "mikrotik.{$this->router->id}.hog.{$direction}.{$address}";

                if ($threshold <= 0 || $rate < $threshold) {
                    Cache::forget($streak);

                    continue;
                }

                $count = (int) Cache::get($streak, 0) + 1;
                Cache::put($streak, $count, 60);
                $cooldown = "{$streak}.logged";
                if ($count < 3 || Cache::has($cooldown)) {
                    continue;
                }

                Cache::put($cooldown, true, 600);
                $who = trim(($lease['comment'] ?? '') ?: ($lease['host'] ?? '')) ?: $address;
                $share = $check['capacity'] > 0 ? round($rate / $check['capacity'] * 100) : null;

                SecurityEvent::query()->create([
                    'mikrotik_session_id' => $this->router->id,
                    'occurred_at' => $sampledAt,
                    'severity' => $check['cap'] > 0 ? 'info' : 'warning',
                    'category' => 'bandwidth',
                    'title' => $check['cap'] > 0 ? 'Klien menyentuh batas bandwidth' : 'Klien memakai bandwidth besar',
                    'detail' => sprintf(
                        '%s (%s) %s %.1f Mbps%s',
                        $who,
                        $address,
                        $check['word'],
                        $rate / 1_000_000,
                        $share !== null ? " · {$share}% kapasitas" : '',
                    ),
                    'context' => [
                        'client' => [
                            'name' => $who,
                            'address' => $address,
                            'mac' => $lease['mac'] ?? null,
                            'direction' => $direction,
                            'down' => (int) ($lease['rx_bps'] ?? 0),
                            'up' => (int) ($lease['tx_bps'] ?? 0),
                            'share' => $share,
                        ],
                        ...$this->hogContext($address, $hostTraffic, $sampledAt, $direction),
                    ],
                ]);
            }
        }
    }

    /**
     * What the client was doing when it hit the limit: its heaviest connections, named via the
     * router's DNS cache, plus the domains it looked up in the last few minutes.
     *
     * @return array<string, mixed>
     */
    private function hogContext(string $address, mixed $hostTraffic, Carbon $sampledAt, string $direction = 'down'): array
    {
        $flows = [];
        foreach ($this->rows($hostTraffic) as $row) {
            $src = (string) ($row['src-address'] ?? '');
            $dst = (string) ($row['dst-address'] ?? '');
            if ($this->hostAddress($src) === $address) {
                $remote = $dst;
                $down = $this->integer($row['repl-rate'] ?? 0);
                $up = $this->integer($row['orig-rate'] ?? 0);
            } elseif ($this->hostAddress($dst) === $address) {
                $remote = $src;
                $down = $this->integer($row['orig-rate'] ?? 0);
                $up = $this->integer($row['repl-rate'] ?? 0);
            } else {
                continue;
            }

            $flows[$remote]['down'] = ($flows[$remote]['down'] ?? 0) + $down;
            $flows[$remote]['up'] = ($flows[$remote]['up'] ?? 0) + $up;
        }

        $heavy = $direction === 'up' ? 'up' : 'down';
        uasort($flows, fn (array $left, array $right) => $right[$heavy] <=> $left[$heavy]);
        $flows = array_slice($flows, 0, 8, true);
        $names = $flows === [] ? [] : $this->dnsNames();
        $connections = [];
        $byKind = [];

        foreach ($flows as $remote => $rate) {
            $ip = $this->hostAddress($remote);
            $port = str_contains($remote, ':') ? (int) substr(strrchr($remote, ':'), 1) : null;
            $name = $names[$ip] ?? null;
            $kind = $name !== null ? $this->dns->kind($name) : 'Tidak diketahui';
            $byKind[$kind] = ($byKind[$kind] ?? 0) + $rate[$heavy];
            $connections[] = [
                'remote' => $ip,
                'port' => $port,
                'name' => $name,
                'app' => $name !== null ? $this->dns->app($name) : null,
                'kind' => $kind,
                'down' => $rate['down'],
                'up' => $rate['up'],
            ];
        }

        arsort($byKind);
        $recent = DnsQuery::query()
            ->where('mikrotik_session_id', $this->router->id)
            ->where('address', $address)
            ->where('logged_at', '>=', $sampledAt->copy()->subMinutes(3))
            ->selectRaw('app, count(*) as total')
            ->groupBy('app')
            ->orderByDesc('total')
            ->limit(6)
            ->pluck('total', 'app')
            ->all();

        return [
            'kind' => array_key_first($byKind),
            'connections' => $connections,
            'recent_apps' => $recent,
        ];
    }

    /**
     * IPv4 address to the name the client originally asked for, following CNAME chains.
     *
     * @return array<string, string>
     */
    private function dnsNames(): array
    {
        try {
            $rows = $this->rows($this->client->gather([
                'cache' => ['path' => 'ip/dns/cache', 'body' => ['.proplist' => 'name,type,data']],
            ], $this->router->connection())['cache'] ?? null);
        } catch (Throwable) {
            return [];
        }

        $aliasOf = [];
        $names = [];
        foreach ($rows as $row) {
            $name = rtrim(strtolower((string) ($row['name'] ?? '')), '.');
            $data = rtrim(strtolower((string) ($row['data'] ?? '')), '.');
            if (($row['type'] ?? '') === 'CNAME' && $name !== '' && $data !== '') {
                $aliasOf[$data] = $name;
            } elseif (($row['type'] ?? '') === 'A' && $name !== '' && filter_var($data, FILTER_VALIDATE_IP)) {
                $names[$data] = $name;
            }
        }

        foreach ($names as $ip => $name) {
            $seen = 0;
            while (isset($aliasOf[$name]) && $seen++ < 8) {
                $name = $aliasOf[$name];
            }
            $names[$ip] = $name;
        }

        return $names;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pair(mixed $value): array
    {
        [$up, $down] = array_pad(explode('/', (string) $value, 2), 2, '0');

        return [$this->bits($up), $this->bits($down)];
    }

    private function bits(mixed $value): int
    {
        $text = strtolower(trim((string) $value));
        if (! preg_match('/^(\d+(?:\.\d+)?)([kmg]?)/', $text, $match)) {
            return 0;
        }

        $scale = ['' => 1, 'k' => 1_000, 'm' => 1_000_000, 'g' => 1_000_000_000][$match[2]];

        return (int) round((float) $match[1] * $scale);
    }

    /**
     * @param  array<string, mixed>  $system
     * @param  array<int, array<string, mixed>>  $services
     * @param  array<int, array<string, mixed>>  $sessions
     * @param  array<int, array<string, mixed>>  $leases
     * @param  array<int, array<string, mixed>>  $interfaces
     * @param  array<string, InterfaceSample>  $previousInterfaces
     * @return array<int, array<string, string>>
     */
    private function findings(array $system, ?int $connections, array $services, array $sessions, array $leases, array $interfaces, array $previousInterfaces): array
    {
        $findings = [];
        $exposed = ['ftp', 'telnet', 'ssh', 'www', 'www-ssl', 'winbox', 'api', 'api-ssl'];

        foreach ($services as $service) {
            if ($service['disabled'] || $service['invalid'] || ! in_array($service['name'], $exposed, true)) {
                continue;
            }

            if (trim((string) $service['address']) !== '') {
                continue;
            }

            $critical = in_array($service['name'], ['ftp', 'telnet'], true);
            $findings[] = [
                'severity' => $critical ? 'critical' : 'warning',
                'category' => 'service',
                'title' => strtoupper((string) $service['name']).' '.$service['port'].'/'.$service['proto'].' terbuka tanpa batas IP',
                'detail' => 'Service ini menerima koneksi dari alamat mana pun. Batasi ke jaringan manajemen.',
            ];
        }

        if ($system['firmware'] && $system['upgrade_firmware'] && $system['firmware'] !== $system['upgrade_firmware']) {
            $findings[] = [
                'severity' => 'warning',
                'category' => 'firmware',
                'title' => 'Firmware RouterBOARD tertinggal',
                'detail' => 'Terpasang '.$system['firmware'].', paket tersedia '.$system['upgrade_firmware'].'.',
            ];
        }

        $blocked = count(array_filter($leases, fn (array $lease) => $lease['blocked']));
        if ($blocked > 0) {
            $findings[] = [
                'severity' => 'warning',
                'category' => 'dhcp',
                'title' => $blocked.' lease DHCP diblokir',
                'detail' => 'Ada klien yang statusnya blocked di DHCP server.',
            ];
        }

        if ($system['cpu_load'] >= 95) {
            $findings[] = ['severity' => 'critical', 'category' => 'health', 'title' => 'CPU sangat tinggi', 'detail' => 'Beban CPU '.$system['cpu_load'].'%.'];
        } elseif ($system['cpu_load'] >= 85) {
            $findings[] = ['severity' => 'warning', 'category' => 'health', 'title' => 'CPU tinggi', 'detail' => 'Beban CPU '.$system['cpu_load'].'%.'];
        }

        if ($system['temperature'] !== null && $system['temperature'] >= 85) {
            $findings[] = ['severity' => 'critical', 'category' => 'health', 'title' => 'Suhu router kritis', 'detail' => $system['temperature'].'°C'];
        } elseif ($system['temperature'] !== null && $system['temperature'] >= 75) {
            $findings[] = ['severity' => 'warning', 'category' => 'health', 'title' => 'Suhu router tinggi', 'detail' => $system['temperature'].'°C'];
        }

        if ($connections !== null && $connections >= (int) config('mikrotik.connection_critical')) {
            $findings[] = ['severity' => 'critical', 'category' => 'firewall', 'title' => 'Connection tracking sangat penuh', 'detail' => number_format($connections).' koneksi aktif.'];
        } elseif ($connections !== null && $connections >= (int) config('mikrotik.connection_warning')) {
            $findings[] = ['severity' => 'warning', 'category' => 'firewall', 'title' => 'Connection tracking tinggi', 'detail' => number_format($connections).' koneksi aktif.'];
        }

        foreach ($sessions as $session) {
            if ($this->isPublicAddress((string) $session['address'])) {
                $findings[] = [
                    'severity' => 'critical',
                    'category' => 'login',
                    'title' => 'Sesi admin dari IP publik',
                    'detail' => $session['name'].' via '.$session['via'].' dari '.$session['address'].'.',
                ];
            }
        }

        foreach ($interfaces as $interface) {
            $previous = $previousInterfaces[$interface['name']] ?? null;
            if (! $previous) {
                continue;
            }

            $errorGrew = $interface['rx_error'] > $previous->rx_error || $interface['tx_error'] > $previous->tx_error;
            if ($errorGrew) {
                $findings[] = [
                    'severity' => 'warning',
                    'category' => 'interface',
                    'title' => 'Error naik di '.$interface['name'],
                    'detail' => 'RX error '.$interface['rx_error'].', TX error '.$interface['tx_error'].'.',
                ];
            }
        }

        return collect($findings)
            ->unique(fn (array $finding) => $finding['title'].'|'.$finding['detail'])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, string>>  $findings
     * @param  array<int, array<string, mixed>>  $previous
     */
    private function storeNewFindings(array $findings, array $previous, Carbon $sampledAt): void
    {
        $seen = collect($previous)->pluck('title')->all();

        foreach ($findings as $finding) {
            if (in_array($finding['title'], $seen, true)) {
                continue;
            }

            $seen[] = $finding['title'];

            SecurityEvent::query()->create([
                'mikrotik_session_id' => $this->router->id,
                'occurred_at' => $sampledAt,
                'severity' => $finding['severity'],
                'category' => $finding['category'],
                'title' => $finding['title'],
                'detail' => $finding['detail'],
            ]);
        }
    }

    private function ensureDnsLogging(MikrotikSession $session): void
    {
        $key = 'mikrotik.'.$session->id.'.dns-logging';
        if (Cache::has($key)) {
            return;
        }

        try {
            $batch = $this->client->gather([
                'logging' => ['path' => 'system/logging'],
            ], $session->connection());
        } catch (Throwable) {
            return;
        }

        foreach ($this->rows($batch['logging'] ?? null) as $rule) {
            $topics = strtolower((string) ($rule['topics'] ?? ''));
            if (! str_contains($topics, 'dns')) {
                continue;
            }

            if (str_contains($topics, '!packet')) {
                Cache::put($key, true, now()->addHours(6));

                return;
            }

            $id = (string) ($rule['.id'] ?? '');
            if ($id === '') {
                continue;
            }

            try {
                $updated = $this->client->gather([
                    'set' => ['method' => 'post', 'path' => 'system/logging/set', 'body' => [
                        '.id' => $id,
                        'topics' => 'dns,!packet',
                    ]],
                ], $session->connection());
            } catch (Throwable) {
                return;
            }

            if ($updated['set'] !== null) {
                Cache::put($key, true, now()->addHours(6));
            }

            return;
        }

        $path = $session->transport() === 'api' ? 'system/logging/add' : 'system/logging';

        try {
            $created = $this->client->gather([
                'create' => ['method' => 'post', 'path' => $path, 'body' => [
                    'topics' => 'dns,!packet',
                    'action' => 'memory',
                ]],
            ], $session->connection());
        } catch (Throwable) {
            return;
        }

        if ($created['create'] !== null) {
            Cache::put($key, true, now()->addHours(6));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function storeDns(array $entries, Carbon $sampledAt): void
    {
        $parsed = [];

        foreach ($entries as $entry) {
            $query = $this->dns->parse((string) ($entry['topics'] ?? ''), (string) ($entry['message'] ?? ''));
            if ($query !== null && ($entry['id'] ?? '') !== '') {
                $parsed[] = [$entry, $query];
            }
        }

        if ($parsed === []) {
            return;
        }

        usort($parsed, fn (array $left, array $right) => strcmp($left[0]['time'], $right[0]['time']));
        $window = 60;
        $since = Carbon::parse($parsed[0][0]['time'])->subSeconds($window);
        $latest = [];

        DnsQuery::query()
            ->where('mikrotik_session_id', $this->router->id)
            ->whereIn('address', array_unique(array_map(fn (array $pair) => $pair[1]['address'], $parsed)))
            ->where('logged_at', '>=', $since)
            ->toBase()
            ->get(['address', 'domain', 'logged_at'])
            ->each(function (object $row) use (&$latest) {
                $key = $row->address.'|'.$row->domain;
                $latest[$key] = max($latest[$key] ?? 0, strtotime((string) $row->logged_at));
            });

        $rows = [];

        foreach ($parsed as [$entry, $query]) {
            $key = $query['address'].'|'.$query['domain'];
            $at = strtotime((string) $entry['time']);
            if (isset($latest[$key]) && $at - $latest[$key] < $window) {
                continue;
            }
            $latest[$key] = $at;

            $rows[] = [
                'mikrotik_session_id' => $this->router->id,
                'router_id' => $entry['id'],
                'logged_at' => $entry['time'],
                'address' => $query['address'],
                'domain' => $query['domain'],
                'qtype' => $query['qtype'],
                'app' => $query['app'],
                'browser' => $query['browser'],
                'created_at' => $sampledAt,
                'updated_at' => $sampledAt,
            ];
        }

        if ($rows !== []) {
            DnsQuery::query()->insertOrIgnore($rows);
        }
    }

    private function prune(): void
    {
        SystemSample::query()->where('sampled_at', '<', now()->subHours(48))->delete();
        InterfaceSample::query()->where('sampled_at', '<', now()->subDays(30))->delete();
        RouterLog::query()->where('logged_at', '<', now()->subDays(14))->delete();
        DnsQuery::query()->where('logged_at', '<', now()->subDays(7))->delete();
        SecurityEvent::query()->where('occurred_at', '<', now()->subDays(30))->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        if (array_is_list($json)) {
            return array_values(array_filter($json, 'is_array'));
        }

        if (isset($json['ret']) && count($json) === 1) {
            return [];
        }

        return [$json];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthMap(mixed $json): array
    {
        $out = [];

        foreach ($this->rows($json) as $row) {
            $name = $row['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $out[$name] = $row['value'] ?? null;
            }
        }

        return $out;
    }

    private function countFrom(mixed $json): ?int
    {
        if (! is_array($json)) {
            return null;
        }

        if (isset($json['ret'])) {
            return $this->integer($json['ret']);
        }

        return array_is_list($json) ? count($json) : null;
    }

    private function flag(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        $digits = preg_replace('/[^\d\-]/', '', (string) $value) ?? '0';

        return (int) $digits;
    }

    private function text(mixed $value, int $limit): string
    {
        $text = iconv('UTF-8', 'UTF-8//IGNORE', (string) $value) ?: '';

        return mb_substr(trim($text), 0, $limit);
    }

    private function parseTime(string $value): Carbon
    {
        if (trim($value) === '') {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return now();
        }
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
