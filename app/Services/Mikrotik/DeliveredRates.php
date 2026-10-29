<?php

namespace App\Services\Mikrotik;

use App\Models\LiveSnapshot;
use App\Models\MikrotikSession;
use Illuminate\Support\Facades\Cache;

/**
 * Per-client rates as delivered on the LAN side, i.e. after queues drop the excess.
 * Connection tracking counts packets as they arrive from the WAN, before shaping.
 */
class DeliveredRates
{
    public function __construct(private MikrotikClient $client) {}

    /**
     * @return array{interface: string, sampled_at: string, clients: array<string, array{down: int, up: int}>}|null
     */
    public function measure(MikrotikSession $session): ?array
    {
        $lan = $this->lan($session);
        if ($lan === null) {
            return null;
        }

        $result = $this->client->gather([
            'torch' => ['method' => 'post', 'path' => 'tool/torch', 'body' => [
                'interface' => $lan['interface'],
                'src-address' => '0.0.0.0/0',
                'dst-address' => '0.0.0.0/0',
                'duration' => '2s',
            ]],
        ], $session->connection());

        $rows = $result['torch'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return null;
        }

        $last = max(array_map(fn (array $row) => (int) ($row['.section'] ?? 0), $rows));
        $clients = [];

        foreach ($rows as $row) {
            if ((int) ($row['.section'] ?? 0) !== $last) {
                continue;
            }

            $address = $this->client($lan, (string) ($row['src-address'] ?? ''))
                ?? $this->client($lan, (string) ($row['dst-address'] ?? ''));
            if ($address === null) {
                continue;
            }

            $clients[$address]['down'] = ($clients[$address]['down'] ?? 0) + (int) ($row['tx'] ?? 0);
            $clients[$address]['up'] = ($clients[$address]['up'] ?? 0) + (int) ($row['rx'] ?? 0);
        }

        return [
            'interface' => $lan['interface'],
            'sampled_at' => now()->toIso8601String(),
            'clients' => $clients,
        ];
    }

    /**
     * @param  array<string, mixed>  $delivered
     */
    public function store(MikrotikSession $session, array $delivered): void
    {
        LiveSnapshot::query()->updateOrCreate(
            ['mikrotik_session_id' => $session->id, 'key' => 'delivered'],
            ['payload' => $delivered, 'sampled_at' => now()],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latest(int $sessionId, int $maxAge = 15): ?array
    {
        $snapshot = LiveSnapshot::query()
            ->where('mikrotik_session_id', $sessionId)
            ->where('key', 'delivered')
            ->first();

        if ($snapshot === null || $snapshot->sampled_at === null || $snapshot->sampled_at->lt(now()->subSeconds($maxAge))) {
            return null;
        }

        return is_array($snapshot->payload) ? $snapshot->payload : null;
    }

    /**
     * Swap conntrack rates for delivered ones, keeping the pre-queue numbers alongside.
     *
     * @param  array<int, array<string, mixed>>  $leases
     * @param  array<string, mixed>|null  $delivered
     * @return array<int, array<string, mixed>>
     */
    public function attach(array $leases, ?array $delivered): array
    {
        foreach ($leases as $index => $lease) {
            $leases[$index]['wan_rx_bps'] = (int) ($lease['rx_bps'] ?? 0);
            $leases[$index]['wan_tx_bps'] = (int) ($lease['tx_bps'] ?? 0);

            if ($delivered === null) {
                $leases[$index]['measured'] = 'conntrack';

                continue;
            }

            $rate = $delivered['clients'][$lease['address'] ?? ''] ?? ['down' => 0, 'up' => 0];
            $leases[$index]['rx_bps'] = (int) $rate['down'];
            $leases[$index]['tx_bps'] = (int) $rate['up'];
            $leases[$index]['measured'] = 'queue';
            $leases[$index]['measured_on'] = $delivered['interface'] ?? null;
        }

        return $leases;
    }

    /**
     * @return array{interface: string, network: int, mask: int, router: int}|null
     */
    private function lan(MikrotikSession $session): ?array
    {
        $key = "mikrotik.{$session->id}.lan";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $sample = collect(LiveSnapshot::query()
            ->where('mikrotik_session_id', $session->id)
            ->where('key', 'pulse')
            ->first()?->payload['leases'] ?? [])
            ->pluck('address')
            ->filter()
            ->first();
        $forced = trim((string) config('mikrotik.lan_interface'));
        $addresses = $this->client->gather(['addresses' => ['path' => 'ip/address']], $session->connection())['addresses'] ?? [];

        foreach (is_array($addresses) ? $addresses : [] as $row) {
            [$ip, $prefix] = array_pad(explode('/', (string) ($row['address'] ?? ''), 2), 2, '32');
            $router = ip2long($ip);
            if ($router === false) {
                continue;
            }

            $mask = $prefix === '0' ? 0 : (-1 << (32 - (int) $prefix)) & 0xFFFFFFFF;
            $lan = [
                'interface' => (string) ($row['interface'] ?? ''),
                'network' => $router & $mask,
                'mask' => $mask,
                'router' => $router,
            ];
            $matches = $forced !== ''
                ? $lan['interface'] === $forced
                : $sample !== null && (ip2long((string) $sample) & $mask) === $lan['network'];

            if ($matches && $lan['interface'] !== '') {
                Cache::put($key, $lan, 600);

                return $lan;
            }
        }

        return null;
    }

    /**
     * @param  array{network: int, mask: int, router: int}  $lan
     */
    private function client(array $lan, string $address): ?string
    {
        $long = ip2long($address);
        if ($long === false || $long === $lan['router'] || ($long & $lan['mask']) !== $lan['network']) {
            return null;
        }

        return $address;
    }
}
