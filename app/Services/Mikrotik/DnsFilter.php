<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikSession;

/**
 * Blocks sites with one Layer 7 regexp. Every blocked hostname is an alternative in that pattern.
 */
class DnsFilter
{
    public const LIST = 'streamnet-block';

    public const RULE = 'StreamNet';

    public function __construct(private MikrotikClient $client) {}

    /**
     * @return array{online: bool, rule: bool, layer7_id: string|null, regexp: string, rule_ids: array<int, string>, legacy_filter_ids: array<int, string>, nat_ids: array<int, string>, sites: array<int, array{domain: string, layer7: bool, firewall: bool, dns_ids: array<int, string>, list_ids: array<int, string>, filter_ids: array<int, string>}>}
     */
    public function read(MikrotikSession $session): array
    {
        $result = $this->client->gather([
            'layer7' => ['path' => 'ip/firewall/layer7-protocol'],
            'filter' => ['path' => 'ip/firewall/filter'],
            'static' => ['path' => 'ip/dns/static'],
            'lists' => ['path' => 'ip/firewall/address-list'],
            'nat' => ['path' => 'ip/firewall/nat'],
        ], $session->connection());

        if ($result['layer7'] === null && $result['filter'] === null && $result['static'] === null && $result['lists'] === null && $result['nat'] === null) {
            return $this->blank();
        }

        $layer7Id = null;
        $regexp = '';
        foreach ($this->rows($result['layer7']) as $row) {
            if (($row['name'] ?? '') !== self::LIST) {
                continue;
            }

            $layer7Id = (string) ($row['.id'] ?? '');
            $regexp = (string) ($row['regexp'] ?? '');
        }

        $inRegexp = $this->domainsIn($regexp);
        $sites = [];
        foreach ($inRegexp as $domain) {
            $sites[$domain]['layer7'] = true;
        }

        $ruleIds = [];
        $legacyFilterIds = [];
        foreach ($this->rows($result['filter']) as $row) {
            if (! $this->enabled($row)) {
                continue;
            }

            $id = (string) ($row['.id'] ?? '');
            if ($this->isLayer7Rule($row)) {
                $ruleIds[] = $id;

                continue;
            }

            $comment = (string) ($row['comment'] ?? '');
            $domain = $this->owned($comment);
            if ($domain !== null) {
                $sites[$domain]['filter_ids'][] = $id;

                continue;
            }

            if ($comment === self::RULE) {
                $legacyFilterIds[] = $id;
            }
        }

        foreach ($this->rows($result['static']) as $row) {
            $domain = $this->owned($row['comment'] ?? '');
            if ($domain === null || $this->truthy($row['dynamic'] ?? false)) {
                continue;
            }

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

            $sites[$domain]['list_ids'][] = (string) ($row['.id'] ?? '');
        }

        $natIds = [];
        foreach ($this->rows($result['nat']) as $row) {
            $comment = (string) ($row['comment'] ?? '');
            if ($comment !== self::RULE && $this->owned($comment) === null) {
                continue;
            }

            $natIds[] = (string) ($row['.id'] ?? '');
        }

        $listed = [];
        foreach ($sites as $domain => $site) {
            $listed[] = [
                'domain' => $domain,
                'layer7' => (bool) ($site['layer7'] ?? false),
                'firewall' => $ruleIds !== [] && (bool) ($site['layer7'] ?? false),
                'dns_ids' => array_values(array_filter($site['dns_ids'] ?? [])),
                'list_ids' => array_values(array_filter($site['list_ids'] ?? [])),
                'filter_ids' => array_values(array_filter($site['filter_ids'] ?? [])),
            ];
        }

        usort($listed, fn (array $left, array $right) => strcmp($left['domain'], $right['domain']));

        return [
            'online' => true,
            'rule' => $layer7Id !== null && $ruleIds !== [],
            'layer7_id' => $layer7Id !== '' ? $layer7Id : null,
            'regexp' => $regexp,
            'rule_ids' => array_values(array_filter($ruleIds)),
            'legacy_filter_ids' => array_values(array_filter($legacyFilterIds)),
            'nat_ids' => array_values(array_filter($natIds)),
            'sites' => $listed,
        ];
    }

    /**
     * @param  array{online: bool, rule: bool, regexp?: string, sites: array<int, array<string, mixed>>}  $snapshot
     * @return array{online: bool, rule: bool, regexp: string, sites: array<int, array{domain: string, layer7: bool, firewall: bool}>}
     */
    public function present(array $snapshot): array
    {
        return [
            'online' => $snapshot['online'],
            'rule' => $snapshot['rule'],
            'regexp' => (string) ($snapshot['regexp'] ?? ''),
            'sites' => array_map(fn (array $site) => [
                'domain' => $site['domain'],
                'layer7' => (bool) ($site['layer7'] ?? false),
                'firewall' => (bool) ($site['firewall'] ?? false),
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

        $domains = collect($current['sites'])->pluck('domain')->push($domain)->unique()->sort()->values()->all();
        $regexp = $this->compile($domains);
        $calls = [];
        if ($current['layer7_id'] === null) {
            $calls['layer7'] = ['method' => 'post', 'path' => 'ip/firewall/layer7-protocol/add', 'body' => [
                'name' => self::LIST,
                'regexp' => $regexp,
                'comment' => self::RULE,
            ]];
        } elseif ($current['regexp'] !== $regexp) {
            $calls['layer7'] = ['method' => 'post', 'path' => 'ip/firewall/layer7-protocol/set', 'body' => [
                '.id' => $current['layer7_id'],
                'regexp' => $regexp,
            ]];
        }
        if ($current['rule_ids'] === []) {
            $calls['rule'] = ['method' => 'post', 'path' => 'ip/firewall/filter/add', 'body' => [
                'chain' => 'forward',
                'action' => 'drop',
                'protocol' => 'tcp',
                'dst-port' => '80,443',
                'layer7-protocol' => self::LIST,
                'comment' => self::RULE,
                'place-before' => '0',
            ]];
        }

        $error = $this->send($session, $calls);
        if ($error !== null) {
            return $error;
        }

        return $this->send($session, $this->legacyCalls($current));
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

        $domains = collect($current['sites'])->pluck('domain')->reject(fn (string $listed) => $listed === $domain)->sort()->values()->all();
        $calls = [];
        if ($domains === []) {
            if ($current['layer7_id'] !== null) {
                $calls['layer7'] = ['method' => 'post', 'path' => 'ip/firewall/layer7-protocol/remove', 'body' => ['.id' => $current['layer7_id']]];
            }
            foreach ($current['rule_ids'] as $index => $id) {
                $calls['rule'.$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $id]];
            }
        } elseif ($current['layer7_id'] !== null) {
            $calls['layer7'] = ['method' => 'post', 'path' => 'ip/firewall/layer7-protocol/set', 'body' => [
                '.id' => $current['layer7_id'],
                'regexp' => $this->compile($domains),
            ]];
        }

        foreach ($site['dns_ids'] as $index => $id) {
            $calls['dns'.$index] = ['method' => 'post', 'path' => 'ip/dns/static/remove', 'body' => ['.id' => $id]];
        }
        foreach ($site['list_ids'] as $index => $id) {
            $calls['list'.$index] = ['method' => 'post', 'path' => 'ip/firewall/address-list/remove', 'body' => ['.id' => $id]];
        }
        foreach ($site['filter_ids'] as $index => $id) {
            $calls['filter'.$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $id]];
        }
        if ($domains === []) {
            foreach ($current['legacy_filter_ids'] as $index => $id) {
                $calls['legacy'.$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $id]];
            }
            foreach ($current['nat_ids'] as $index => $id) {
                $calls['nat'.$index] = ['method' => 'post', 'path' => 'ip/firewall/nat/remove', 'body' => ['.id' => $id]];
            }
        }

        return $this->send($session, $calls);
    }

    /**
     * @param  array{sites: array<int, array<string, mixed>>, legacy_filter_ids: array<int, string>, nat_ids: array<int, string>}  $current
     * @return array<string, array{method: string, path: string, body: array<string, string>}>
     */
    private function legacyCalls(array $current): array
    {
        $calls = [];
        foreach ($current['sites'] as $site) {
            foreach ($site['dns_ids'] as $index => $id) {
                $calls['dns'.$site['domain'].$index] = ['method' => 'post', 'path' => 'ip/dns/static/remove', 'body' => ['.id' => $id]];
            }
            foreach ($site['list_ids'] as $index => $id) {
                $calls['list'.$site['domain'].$index] = ['method' => 'post', 'path' => 'ip/firewall/address-list/remove', 'body' => ['.id' => $id]];
            }
            foreach ($site['filter_ids'] as $index => $id) {
                $calls['filter'.$site['domain'].$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $id]];
            }
        }
        foreach ($current['legacy_filter_ids'] as $index => $id) {
            $calls['legacy'.$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $id]];
        }
        foreach ($current['nat_ids'] as $index => $id) {
            $calls['nat'.$index] = ['method' => 'post', 'path' => 'ip/firewall/nat/remove', 'body' => ['.id' => $id]];
        }

        return $calls;
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function compile(array $domains): string
    {
        $parts = array_map(fn (string $domain) => str_replace('.', '\\.', $domain), $domains);

        return '^.+('.implode('|', $parts).').*$';
    }

    /**
     * @return array<int, string>
     */
    private function domainsIn(string $regexp): array
    {
        if (! preg_match('/\((.*)\)/', $regexp, $matches)) {
            return [];
        }

        $domains = [];
        foreach (explode('|', $matches[1]) as $part) {
            $domain = $this->hostname(str_replace('\\.', '.', $part));
            if ($domain !== null) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isLayer7Rule(array $row): bool
    {
        return ($row['comment'] ?? '') === self::RULE
            && ($row['layer7-protocol'] ?? '') === self::LIST
            && ($row['chain'] ?? '') === 'forward'
            && ($row['action'] ?? '') === 'drop';
    }

    /**
     * @return array{online: bool, rule: bool, layer7_id: null, regexp: string, rule_ids: array<int, string>, legacy_filter_ids: array<int, string>, nat_ids: array<int, string>, sites: array<int, array<string, mixed>>}
     */
    private function blank(): array
    {
        return [
            'online' => false,
            'rule' => false,
            'layer7_id' => null,
            'regexp' => '',
            'rule_ids' => [],
            'legacy_filter_ids' => [],
            'nat_ids' => [],
            'sites' => [],
        ];
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
