<?php

namespace App\Services\Unifi;

use App\Models\UnifiConnection;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class UnifiClient
{
    private const BASE = '/proxy/network/integration/v1';

    private ?UnifiConnection $connection = null;

    private ?int $forcedSessionId = null;

    public function useSession(int $sessionId): void
    {
        $this->forcedSessionId = $sessionId;
        $this->connection = null;
    }

    public function configured(): bool
    {
        return $this->connection() !== null;
    }

    public function forget(): void
    {
        $sessionId = $this->sessionId();
        if ($sessionId) {
            Cache::forget($this->cacheKey('snapshot', $sessionId));
            Cache::forget($this->cacheKey('sites', $sessionId));
        }
        $this->connection = null;
    }

    private function connection(): ?UnifiConnection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $sessionId = $this->sessionId();

        return $this->connection = $sessionId ? UnifiConnection::forSession($sessionId) : null;
    }

    private function sessionId(): ?int
    {
        $id = $this->forcedSessionId ?? app(CurrentMikrotikSession::class)->resolve()?->id;

        return $id ? (int) $id : null;
    }

    private function cacheKey(string $name, ?int $sessionId = null): string
    {
        return 'unifi.'.$name.'.'.($sessionId ?? $this->sessionId() ?? 0);
    }

    /**
     * Current access points, their live statistics, and connected clients.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'configured' => false, 'error' => null];
        }

        $cached = Cache::get($this->cacheKey('snapshot'));
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $snapshot = $this->fetch();
            Cache::put($this->cacheKey('snapshot'), $snapshot, max(1, (int) config('unifi.cache_seconds')));
        } catch (Throwable $exception) {
            $snapshot = ['ok' => false, 'configured' => true, 'error' => $exception->getMessage()];
            Cache::put($this->cacheKey('snapshot'), $snapshot, 5);
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $site = $this->site();
        $devices = $this->list("/sites/{$site['id']}/devices");
        $clients = $this->list("/sites/{$site['id']}/clients");
        $ids = array_values(array_filter(array_map(fn (array $device) => $device['id'] ?? null, $devices)));

        $responses = $ids === [] ? [] : Http::pool(fn (Pool $pool) => array_map(
            fn (string $id) => $this->prepare($pool->as($id))->get($this->url("/sites/{$site['id']}/devices/{$id}/statistics/latest")),
            $ids,
        ));
        $details = $ids === [] ? [] : Http::pool(fn (Pool $pool) => array_map(
            fn (string $id) => $this->prepare($pool->as($id))->get($this->url("/sites/{$site['id']}/devices/{$id}")),
            $ids,
        ));

        $perDevice = [];
        foreach ($clients as $client) {
            $uplink = (string) ($client['uplinkDeviceId'] ?? '');
            if ($uplink !== '') {
                $perDevice[$uplink] = ($perDevice[$uplink] ?? 0) + 1;
            }
        }

        $mappedDevices = array_map(function (array $device) use ($responses, $details, $perDevice) {
            $id = (string) ($device['id'] ?? '');
            $stats = $this->json($responses[$id] ?? null);
            $detail = $this->json($details[$id] ?? null);

            return $this->mapDevice(array_merge($device, $detail), $stats, $perDevice[$id] ?? 0);
        }, $devices);

        $names = [];
        foreach ($mappedDevices as $device) {
            $names[$device['id']] = $device['name'];
        }

        $wifi = $this->activeClients((string) ($site['internalReference'] ?? 'default'));

        return [
            'ok' => true,
            'configured' => true,
            'site' => ['id' => $site['id'], 'name' => $site['name'] ?? 'Default'],
            'fetched_at' => now()->toIso8601String(),
            'devices' => $mappedDevices,
            'clients' => array_map(function (array $client) use ($names, $wifi) {
                $mac = strtolower((string) ($client['macAddress'] ?? ''));

                return [
                    'id' => (string) ($client['id'] ?? ''),
                    'name' => (string) ($client['name'] ?? ''),
                    'type' => strtolower((string) ($client['type'] ?? '')),
                    'ip' => (string) ($client['ipAddress'] ?? ''),
                    'mac' => (string) ($client['macAddress'] ?? ''),
                    'uplink_id' => (string) ($client['uplinkDeviceId'] ?? ''),
                    'uplink' => $names[$client['uplinkDeviceId'] ?? ''] ?? '',
                    'connected_at' => $client['connectedAt'] ?? null,
                    ...($wifi[$mac] ?? []),
                ];
            }, $clients),
        ];
    }

    /**
     * Radio-level detail per wireless client (SSID, experience, channel, live rates) from the
     * Network application's v2 API, keyed by MAC. Missing on older consoles, so it is optional.
     *
     * @return array<string, array<string, mixed>>
     */
    private function activeClients(string $site): array
    {
        try {
            $response = $this->prepare(Http::acceptJson())
                ->get(rtrim((string) $this->connection()?->url, '/')."/proxy/network/v2/api/site/{$site}/clients/active");
        } catch (Throwable) {
            return [];
        }

        $rows = $response->successful() ? $response->json() : null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }

        $mapped = [];
        foreach ($rows as $row) {
            $mac = strtolower((string) ($row['mac'] ?? ''));
            if ($mac === '' || ($row['is_wired'] ?? false)) {
                continue;
            }

            $mapped[$mac] = [
                'display_name' => (string) ($row['display_name'] ?? ''),
                'hostname' => (string) ($row['hostname'] ?? ''),
                'vendor' => (string) ($row['oui'] ?? ''),
                'ssid' => (string) ($row['essid'] ?? ''),
                'experience' => isset($row['wifi_experience_score']) ? (int) $row['wifi_experience_score'] : null,
                'standard' => $this->wifiStandard((string) ($row['radio_proto'] ?? '')),
                'streams' => preg_match('/(\d+)/', (string) ($row['mimo'] ?? ''), $match) ? "{$match[1]}x{$match[1]}" : null,
                'channel' => $row['channel'] ?? null,
                'band' => match ((string) ($row['radio'] ?? '')) {
                    'ng' => '2.4 GHz',
                    'na' => '5 GHz',
                    '6e' => '6 GHz',
                    default => null,
                },
                'channel_width' => $row['channel_width'] ?? null,
                'signal' => isset($row['signal']) ? (int) $row['signal'] : null,
                'noise' => isset($row['noise']) ? (int) $row['noise'] : null,
                'retries' => isset($row['wifi_tx_retries_percentage']) ? (float) $row['wifi_tx_retries_percentage'] : null,
                'link_down' => isset($row['tx_rate']) ? (int) $row['tx_rate'] * 1000 : null,
                'link_up' => isset($row['rx_rate']) ? (int) $row['rx_rate'] * 1000 : null,
                'down_bps' => (int) round(((float) ($row['tx_bytes-r'] ?? 0)) * 8),
                'up_bps' => (int) round(((float) ($row['rx_bytes-r'] ?? 0)) * 8),
                'total_bytes' => (int) ($row['tx_bytes'] ?? 0) + (int) ($row['rx_bytes'] ?? 0),
                'uptime' => isset($row['uptime']) ? (int) $row['uptime'] : null,
                'uplink_name' => (string) ($row['last_uplink_name'] ?? ''),
            ];
        }

        return $mapped;
    }

    private function wifiStandard(string $proto): ?string
    {
        return match ($proto) {
            'be' => 'WiFi 7',
            'ax', '6e' => 'WiFi 6',
            'ac' => 'WiFi 5',
            'ng', 'na', 'n' => 'WiFi 4',
            'a', 'g', 'b' => 'Legacy',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $device
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function mapDevice(array $device, array $stats, int $clients): array
    {
        $features = $device['features'] ?? [];
        $features = array_is_list($features) ? $features : array_keys($features);
        $retries = [];
        foreach ($stats['interfaces']['radios'] ?? [] as $radio) {
            $retries[(string) ($radio['frequencyGHz'] ?? '')] = $radio['txRetriesPct'] ?? null;
        }

        return [
            'id' => (string) ($device['id'] ?? ''),
            'name' => (string) ($device['name'] ?? ''),
            'model' => (string) ($device['model'] ?? ''),
            'mac' => (string) ($device['macAddress'] ?? ''),
            'ip' => (string) ($device['ipAddress'] ?? ''),
            'state' => strtolower((string) ($device['state'] ?? 'unknown')),
            'firmware' => (string) ($device['firmwareVersion'] ?? ''),
            'firmware_updatable' => (bool) ($device['firmwareUpdatable'] ?? false),
            'adopted_at' => $device['adoptedAt'] ?? null,
            'access_point' => in_array('accessPoint', $features, true),
            'features' => array_values($features),
            'clients' => $clients,
            'uptime' => isset($stats['uptimeSec']) ? (int) $stats['uptimeSec'] : null,
            'cpu' => isset($stats['cpuUtilizationPct']) ? (float) $stats['cpuUtilizationPct'] : null,
            'memory' => isset($stats['memoryUtilizationPct']) ? (float) $stats['memoryUtilizationPct'] : null,
            'load' => isset($stats['loadAverage1Min']) ? (float) $stats['loadAverage1Min'] : null,
            'rx_bps' => (int) ($stats['uplink']['rxRateBps'] ?? 0),
            'tx_bps' => (int) ($stats['uplink']['txRateBps'] ?? 0),
            'heartbeat_at' => $stats['lastHeartbeatAt'] ?? null,
            'radios' => array_map(fn (array $radio) => [
                'band' => (float) ($radio['frequencyGHz'] ?? 0),
                'standard' => (string) ($radio['wlanStandard'] ?? ''),
                'channel' => $radio['channel'] ?? null,
                'width' => $radio['channelWidthMHz'] ?? null,
                'retries' => $retries[(string) ($radio['frequencyGHz'] ?? '')] ?? null,
            ], $device['interfaces']['radios'] ?? []),
        ];
    }

    /**
     * @return array{id: string, name?: string}
     */
    private function site(): array
    {
        $wanted = strtolower(trim((string) $this->connection()?->site));
        $sites = Cache::remember($this->cacheKey('sites'), 3600, fn () => $this->list('/sites'));

        foreach ($sites as $site) {
            $keys = array_map('strtolower', [(string) ($site['id'] ?? ''), (string) ($site['internalReference'] ?? ''), (string) ($site['name'] ?? '')]);
            if ($wanted === '' || in_array($wanted, $keys, true)) {
                return $site;
            }
        }

        throw new RuntimeException($wanted === '' ? 'UniFi tidak punya site.' : "Site UniFi [{$wanted}] tidak ditemukan.");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(string $path): array
    {
        $rows = [];
        $offset = 0;

        do {
            $response = $this->prepare(Http::acceptJson())->get($this->url($path), ['offset' => $offset, 'limit' => 200]);
            $body = $this->json($response, true);
            $page = $body['data'] ?? [];
            $rows = array_merge($rows, $page);
            $offset += count($page);
        } while ($page !== [] && $offset < (int) ($body['totalCount'] ?? 0) && $offset < 5000);

        return $rows;
    }

    private function prepare(PendingRequest $request): PendingRequest
    {
        return $request
            ->withHeaders(['X-API-KEY' => (string) $this->connection()?->api_key])
            ->acceptJson()
            ->timeout(max(1, (int) config('unifi.timeout')))
            ->withOptions(['verify' => (bool) ($this->connection()?->verify_tls ?? true)]);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->connection()?->url, '/').self::BASE.$path;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $response, bool $strict = false): array
    {
        if (! $response instanceof Response) {
            if ($strict) {
                throw new RuntimeException('UniFi tidak bisa dihubungi.');
            }

            return [];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('API key UniFi ditolak.');
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            if ($strict) {
                throw new RuntimeException("UniFi menjawab HTTP {$response->status()}.");
            }

            return [];
        }

        return $body;
    }
}
