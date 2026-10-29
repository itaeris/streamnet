<?php

namespace App\Services\Unifi;

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

    public function configured(): bool
    {
        return config('unifi.url') !== '' && config('unifi.api_key') !== '';
    }

    /**
     * Current access points, their live statistics, and connected clients.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'UNIFI_URL dan UNIFI_API_KEY belum diisi di .env.'];
        }

        $cached = Cache::get('unifi.snapshot');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $snapshot = $this->fetch();
            Cache::put('unifi.snapshot', $snapshot, max(1, (int) config('unifi.cache_seconds')));
        } catch (Throwable $exception) {
            $snapshot = ['ok' => false, 'error' => $exception->getMessage()];
            Cache::put('unifi.snapshot', $snapshot, 5);
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

        return [
            'ok' => true,
            'site' => ['id' => $site['id'], 'name' => $site['name'] ?? 'Default'],
            'fetched_at' => now()->toIso8601String(),
            'devices' => $mappedDevices,
            'clients' => array_map(fn (array $client) => [
                'id' => (string) ($client['id'] ?? ''),
                'name' => (string) ($client['name'] ?? ''),
                'type' => strtolower((string) ($client['type'] ?? '')),
                'ip' => (string) ($client['ipAddress'] ?? ''),
                'mac' => (string) ($client['macAddress'] ?? ''),
                'uplink_id' => (string) ($client['uplinkDeviceId'] ?? ''),
                'uplink' => $names[$client['uplinkDeviceId'] ?? ''] ?? '',
                'connected_at' => $client['connectedAt'] ?? null,
            ], $clients),
        ];
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
        $wanted = strtolower(trim((string) config('unifi.site')));
        $sites = Cache::remember('unifi.sites', 3600, fn () => $this->list('/sites'));

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
            ->withHeaders(['X-API-KEY' => (string) config('unifi.api_key')])
            ->acceptJson()
            ->timeout(max(1, (int) config('unifi.timeout')))
            ->withOptions(['verify' => (bool) config('unifi.verify_tls')]);
    }

    private function url(string $path): string
    {
        return config('unifi.url').self::BASE.$path;
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
