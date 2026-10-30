<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikSession;

/**
 * Blocks a site twice: NXDOMAIN on the router DNS, and a forward drop for the resolved address.
 */
class DnsFilter
{
    public const LIST = 'streamnet-block';

    public const RULE = 'StreamNet';

    public function __construct(private MikrotikClient $client) {}

    /**
     * @return array{online: bool, rule: bool, sites: array<int, array{domain: string, dns: bool, firewall: bool, dns_ids: array<int, string>, list_ids: array<int, string>, rule_id: string|null}>}
     */
    public function read(MikrotikSession $session): array
    {
        $result = $this->client->gather([
            'static' => ['path' => 'ip/dns/static'],
            'lists' => ['path' => 'ip/firewall/address-list'],
            'filter' => ['path' => 'ip/firewall/filter'],
        ], $session->connection());

        if ($result['static'] === null && $result['lists'] === null && $result['filter'] === null) {
            return ['online' => false, 'rule' => false, 'sites' => []];
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

            $sites[$domain]['firewall'] = ($sites[$domain]['firewall'] ?? false) || $this->enabled($row);
            $sites[$domain]['list_ids'][] = (string) ($row['.id'] ?? '');
        }

        $ruleId = null;
        foreach ($this->rows($result['filter']) as $row) {
            if (($row['chain'] ?? '') === 'forward'
                && ($row['action'] ?? '') === 'drop'
                && ($row['dst-address-list'] ?? '') === self::LIST
                && $this->enabled($row)) {
                $ruleId = (string) ($row['.id'] ?? '');
                break;
            }
        }

        $listed = [];
        foreach ($sites as $domain => $site) {
            $listed[] = [
                'domain' => $domain,
                'dns' => (bool) ($site['dns'] ?? false),
                'firewall' => (bool) ($site['firewall'] ?? false),
                'dns_ids' => array_values(array_filter($site['dns_ids'] ?? [])),
                'list_ids' => array_values(array_filter($site['list_ids'] ?? [])),
                'rule_id' => $ruleId,
            ];
        }

        usort($listed, fn (array $left, array $right) => strcmp($left['domain'], $right['domain']));

        return [
            'online' => true,
            'rule' => $ruleId !== null,
            'sites' => $listed,
        ];
    }

    /**
     * @param  array{online: bool, rule: bool, sites: array<int, array<string, mixed>>}  $snapshot
     * @return array{online: bool, rule: bool, sites: array<int, array{domain: string, dns: bool, firewall: bool}>}
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
        if (! ($site['firewall'] ?? false)) {
            $calls['list'] = ['method' => 'post', 'path' => 'ip/firewall/address-list/add', 'body' => [
                'list' => self::LIST,
                'address' => $domain,
                'comment' => $this->comment($domain),
            ]];
        }
        if (! $current['rule']) {
            $calls['rule'] = ['method' => 'post', 'path' => 'ip/firewall/filter/add', 'body' => [
                'chain' => 'forward',
                'action' => 'drop',
                'dst-address-list' => self::LIST,
                'comment' => self::RULE,
                'place-before' => '0',
            ]];
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

        $others = collect($current['sites'])->contains(fn (array $row) => $row['domain'] !== $domain && $row['firewall']);
        if (! $others && $current['rule'] && $site['rule_id']) {
            $calls['rule'] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $site['rule_id']]];
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
