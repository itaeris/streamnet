<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikSession;
use Throwable;

class CloudflareDns
{
    /** @var array<int, string> */
    public const SERVERS = ['1.1.1.3', '1.0.0.3'];

    public function __construct(private MikrotikClient $client) {}

    /**
     * @return array{online: bool, enabled: bool, servers: string}
     */
    public function status(MikrotikSession $session): array
    {
        $row = $this->record($this->read($session));
        if ($row === null) {
            return ['online' => false, 'enabled' => false, 'servers' => ''];
        }

        $servers = $this->servers((string) ($row['servers'] ?? ''));

        return [
            'online' => true,
            'enabled' => $this->enabled($servers),
            'servers' => implode(', ', $servers),
        ];
    }

    public function apply(MikrotikSession $session, bool $enabled): ?string
    {
        $current = $this->status($session);
        if (! $current['online']) {
            return 'Router tidak menjawab pengaturan DNS.';
        }

        if ($current['enabled'] === $enabled) {
            return null;
        }

        $servers = $enabled
            ? implode(',', self::SERVERS)
            : $this->restore($session);

        if ($enabled) {
            $session->forceFill(['dns_servers_backup' => str_replace(' ', '', $current['servers'])])->save();
        }

        $result = $this->client->gather([
            'set' => ['method' => 'post', 'path' => 'ip/dns/set', 'body' => ['servers' => $servers]],
        ], $session->connection());

        if ($result['set'] === null) {
            return 'Router menolak perubahan DNS.';
        }

        if (! $enabled) {
            $session->forceFill(['dns_servers_backup' => null])->save();
        }

        return null;
    }

    private function read(MikrotikSession $session): mixed
    {
        try {
            return $this->client->gather([
                'dns' => ['path' => 'ip/dns'],
            ], $session->connection())['dns'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function record(mixed $json): ?array
    {
        if (! is_array($json)) {
            return null;
        }

        if (function_exists('array_is_list') && array_is_list($json)) {
            $first = $json[0] ?? null;

            return is_array($first) ? $first : null;
        }

        return $json;
    }

    /**
     * @return array<int, string>
     */
    private function servers(string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $server) => trim($server),
            explode(',', $value),
        )));
    }

    /**
     * @param  array<int, string>  $servers
     */
    private function enabled(array $servers): bool
    {
        if ($servers === []) {
            return false;
        }

        foreach ($servers as $server) {
            if (! in_array($server, self::SERVERS, true)) {
                return false;
            }
        }

        return true;
    }

    private function restore(MikrotikSession $session): string
    {
        $backup = trim((string) $session->dns_servers_backup);

        return $backup !== '' ? $backup : '1.1.1.1,1.0.0.1';
    }
}
