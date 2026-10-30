<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikSession;

/**
 * Blocks a site by name, not by whichever IP it happens to resolve to.
 * DNS answers NXDOMAIN, port 53 is forced to the router, and HTTPS is dropped on the TLS name.
 */
class DnsFilter
{
    public const LIST = 'streamnet-block';

    public const RULE = 'StreamNet';

    public function __construct(private MikrotikClient $client) {}

    /**
     * @return array{online: bool, rule: bool, quic_id: string|null, legacy_rule_id: string|null, nat_ids: array<int, string>, sites: array<int, array{domain: string, dns: bool, firewall: bool, tls: bool, dns_ids: array<int, string>, list_ids: array<int, string>, filter_ids: array<int, string>}>}
     */
    public function read(MikrotikSession $session): array
    {
        $result = $this->client->gather([
            'static' => ['path' => 'ip/dns/static'],
            'lists' => ['path' => 'ip/firewall/address-list'],
            'filter' => ['path' => 'ip/firewall/filter'],
            'nat' => ['path' => 'ip/firewall/nat'],
        ], $session->connection());

        if ($result['static'] === null && $result['lists'] === null && $result['filter'] === null && $result['nat'] === null) {
            return ['online' => false, 'rule' => false, 'quic_id' => null, 'legacy_rule_id' => null, 'nat_ids' => [], 'sites' => []];
        }

        $sites = [];
        foreach ($this->rows($result['static']) as $row) {
            $domain = $this->owned($row['comment'] ?? '');
            if ($domain === null || $this->truthy($row['dynamic'] ?? false)) {
                continue;
            }

            $sites[$domain]['dns'] = ($sites[$domain]['dns'] ?? false) || $this->enabled($row);
            $sites[$domain]['dns_ids'][] = (string) ($row['.id'] ?? '');
        }

        foreach ($this->rows($result['lists']) as $row) {
            if (($row['list'] ?? '') !== self::LIST || $this->truthy($row['dynamic'] ?? false)) {
                continue;
            }

            $domain = $this->owned($row['comment'] ?? '') ?? $this->hostname($row['address'] ?? '');
            if ($domain === null) {
                continue;
            }

            $sites[$domain]['list'] = true;
            $sites[$domain]['list_ids'][] = (string) ($row['.id'] ?? '');
        }

        $quicId = null;
        $legacyRuleId = null;
        foreach ($this->rows($result['filter']) as $row) {
            if (! $this->enabled($row)) {
                continue;
            }

            $domain = $this->owned($row['comment'] ?? '');
            if ($domain !== null && ($row['tls-host'] ?? '') !== '') {
                $sites[$domain]['tls'] = true;
                $sites[$domain]['filter_ids'][] = (string) ($row['.id'] ?? '');

                continue;
            }

            if (($row['comment'] ?? '') !== self::RULE || ($row['chain'] ?? '') !== 'forward' || ($row['action'] ?? '') !== 'drop') {
                continue;
            }

            if ((string) ($row['protocol'] ?? '') === 'udp' && (string) ($row['dst-port'] ?? '') === '443') {
                $quicId = (string) ($row['.id'] ?? '');
            } elseif (($row['dst-address-list'] ?? '') === self::LIST) {
                $legacyRuleId = (string) ($row['.id'] ?? '');
            }
        }

        $natIds = [];
        foreach ($this->rows($result['nat']) as $row) {
            if (($row['comment'] ?? '') === self::RULE && ($row['chain'] ?? '') === 'dstnat' && (string) ($row['dst-port'] ?? '') === '53' && $this->enabled($row)) {
                $natIds[] = (string) ($row['.id'] ?? '');
            }
        }

        $listed = [];
        foreach ($sites as $domain => $site) {
            $tls = (bool) ($site['tls'] ?? false);
            $listed[] = [
                'domain' => $domain,
                'dns' => (bool) ($site['dns'] ?? false),
                'tls' => $tls,
                'firewall' => $tls || (bool) ($site['list'] ?? false),
                'dns_ids' => array_values(array_filter($site['dns_ids'] ?? [])),
                'list_ids' => array_values(array_filter($site['list_ids'] ?? [])),
                'filter_ids' => array_values(array_filter($site['filter_ids'] ?? [])),
            ];
        }

        usort($listed, fn (array $left, array $right) => strcmp($left['domain'], $right['domain']));

        return [
            'online' => true,
            'rule' => $quicId !== null || $legacyRuleId !== null || collect($listed)->contains(fn (array $site) => $site['tls']),
            'quic_id' => $quicId,
            'legacy_rule_id' => $legacyRuleId,
            'nat_ids' => array_values(array_filter($natIds)),
            'sites' => $listed,
        ];
    }

    /**
     * @param  array{online: bool, rule: bool, sites: array<int, array<string, mixed>>}  $snapshot
     * @return array{online: bool, rule: bool, sites: array<int, array{domain: string, dns: bool, firewall: bool, tls: bool}>}
     */
    public function present(array $snapshot): array
    {
        return [
            'online' => $snapshot['online'],
            'rule' => $snapshot['rule'],
            'sites' => array_map(fn (array $site) => [
                'domain' => $site['domain'],
                'dns' => $site['dns'],
                'firewall' => $site['firewall'],
                'tls' => (bool) ($site['tls'] ?? false),
            ], $snapshot['sites']),
        ];
    }

    public function block(MikrotikSession $session, string $domain): ?string
    {
        $domain = $this->hostname($domain);
        if ($domain === null) {
            return 'Domain tidak valid. Contoh: facebook.com';
        }

        $current = $this->read($session);
        if (! $current['online']) {
            return 'Router tidak menjawab. Pastikan sesi ini sedang terhubung.';
        }

        $site = collect($current['sites'])->firstWhere('domain', $domain);
        $calls = [];
        if (! ($site['dns'] ?? false)) {
            $calls['name'] = ['method' => 'post', 'path' => 'ip/dns/static/add', 'body' => [
                'name' => $domain,
                'type' => 'NXDOMAIN',
                'comment' => $this->comment($domain),
            ]];
            $calls['regexp'] = ['method' => 'post', 'path' => 'ip/dns/static/add', 'body' => [
                'regexp' => '.*\\.'.str_replace('.', '\\.', $domain).'$',
                'type' => 'NXDOMAIN',
                'comment' => $this->comment($domain),
            ]];
        }
        if (! ($site['tls'] ?? false)) {
            foreach ([$domain, '*.'.$domain] as $index => $host) {
                $calls['tls'.$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/add', 'body' => [
                    'chain' => 'forward',
                    'action' => 'drop',
                    'protocol' => 'tcp',
                    'dst-port' => '443',
                    'tls-host' => $host,
                    'comment' => $this->comment($domain),
                    'place-before' => '0',
                ]];
            }
        }
        if (($current['quic_id'] ?? null) === null) {
            $calls['quic'] = ['method' => 'post', 'path' => 'ip/firewall/filter/add', 'body' => [
                'chain' => 'forward',
                'action' => 'drop',
                'protocol' => 'udp',
                'dst-port' => '443',
                'comment' => self::RULE,
                'place-before' => '0',
            ]];
        }
        if (($current['nat_ids'] ?? []) === []) {
            foreach (['udp', 'tcp'] as $protocol) {
                $calls['nat'.$protocol] = ['method' => 'post', 'path' => 'ip/firewall/nat/add', 'body' => [
                    'chain' => 'dstnat',
                    'action' => 'redirect',
                    'protocol' => $protocol,
                    'dst-port' => '53',
                    'to-ports' => '53',
                    'comment' => self::RULE,
                ]];
            }
        }

        return $this->send($session, $calls);
    }

    public function unblock(MikrotikSession $session, string $domain): ?string
    {
        $domain = $this->hostname($domain);
        if ($domain === null) {
            return 'Domain tidak valid.';
        }

        $current = $this->read($session);
        if (! $current['online']) {
            return 'Router tidak menjawab. Pastikan sesi ini sedang terhubung.';
        }

        $site = collect($current['sites'])->firstWhere('domain', $domain);
        if ($site === null) {
            return null;
        }

        $calls = [];
        foreach ($site['dns_ids'] as $index => $id) {
            $calls['dns'.$index] = ['method' => 'post', 'path' => 'ip/dns/static/remove', 'body' => ['.id' => $id]];
        }
        foreach ($site['list_ids'] as $index => $id) {
            $calls['list'.$index] = ['method' => 'post', 'path' => 'ip/firewall/address-list/remove', 'body' => ['.id' => $id]];
        }
        foreach ($site['filter_ids'] as $index => $id) {
            $calls['filter'.$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $id]];
        }

        $others = collect($current['sites'])->contains(fn (array $row) => $row['domain'] !== $domain);
        if (! $others) {
            if ($current['quic_id']) {
                $calls['quic'] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $current['quic_id']]];
            }
            if ($current['legacy_rule_id']) {
                $calls['legacy'] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $current['legacy_rule_id']]];
            }
            foreach ($current['nat_ids'] as $index => $id) {
                $calls['nat'.$index] = ['method' => 'post', 'path' => 'ip/firewall/nat/remove', 'body' => ['.id' => $id]];
            }
        }

        return $this->send($session, $calls);
    }

    /**
     * @param  array<string, array{method?: string, path: string, body?: array<string, mixed>}>  $calls
     */
    private function send(MikrotikSession $session, array $calls): ?string
    {
        if ($calls === []) {
            return null;
        }

        $result = $this->client->gather($calls, $session->connection());
        $errors = array_values(array_filter($result['_errors'] ?? []));
        if ($errors !== []) {
            return 'Router menolak: '.$errors[0];
        }

        $failed = collect($calls)->keys()->contains(fn (string $key) => ($result[$key] ?? null) === null);

        return $failed ? 'Router menolak perubahan. Pastikan user API punya izin write.' : null;
    }

    private function comment(string $domain): string
    {
        return 'StreamNet:'.$domain;
    }

    private function owned(mixed $comment): ?string
    {
        $comment = (string) $comment;
        if (! str_starts_with($comment, 'StreamNet:')) {
            return null;
        }

        return $this->hostname(substr($comment, strlen('StreamNet:')));
    }

    private function hostname(mixed $value): ?string
    {
        $domain = strtolower(trim((string) $value));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        $domain = rtrim($domain, '.');
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return null;
        }

        return $domain;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(mixed $json): array
    {
        if (! is_array($json) || $json === []) {
            return [];
        }

        return array_is_list($json) ? $json : [$json];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function enabled(array $row): bool
    {
        return ! $this->truthy($row['disabled'] ?? false);
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 'true', 'yes', '1'], true);
    }
}
