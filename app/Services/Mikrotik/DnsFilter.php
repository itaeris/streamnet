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

    private const HOLD = 'streamnet-hold.invalid';

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
        $layerComment = '';
        foreach ($this->rows($result['layer7']) as $row) {
            if (($row['name'] ?? '') !== self::LIST) {
                continue;
            }

            $layer7Id = (string) ($row['.id'] ?? '');
            $regexp = (string) ($row['regexp'] ?? '');
            $layerComment = (string) ($row['comment'] ?? '');
        }

        $sites = [];
        foreach ($this->domainsIn($regexp) as $domain) {
            $sites[$domain]['layer7'] = true;
            $sites[$domain]['enabled'] = true;
        }
        foreach ($this->paused($layerComment) as $domain) {
            $sites[$domain]['enabled'] = $sites[$domain]['enabled'] ?? false;
        }

        $rules = [];
        $packets = 0;
        $bytes = 0;
        $legacyFilterIds = [];
        foreach ($this->rows($result['filter']) as $row) {
            $id = (string) ($row['.id'] ?? '');
            if ($this->isLayer7Rule($row)) {
                $on = $this->enabled($row);
                $rules[] = ['id' => $id, 'disabled' => ! $on];
                $packets += (int) ($row['packets'] ?? 0);
                $bytes += (int) ($row['bytes'] ?? 0);

                continue;
            }

            if (! $this->enabled($row)) {
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

        $ruleIds = array_values(array_filter(array_column($rules, 'id')));
        $dropping = collect($rules)->contains(fn (array $rule) => ! $rule['disabled']);
        $listed = [];
        foreach ($sites as $domain => $site) {
            $enabled = (bool) ($site['enabled'] ?? false);
            $listed[] = [
                'domain' => $domain,
                'enabled' => $enabled,
                'layer7' => (bool) ($site['layer7'] ?? false),
                'firewall' => $dropping && $enabled,
                'dns_ids' => array_values(array_filter($site['dns_ids'] ?? [])),
                'list_ids' => array_values(array_filter($site['list_ids'] ?? [])),
                'filter_ids' => array_values(array_filter($site['filter_ids'] ?? [])),
            ];
        }

        usort($listed, fn (array $left, array $right) => strcmp($left['domain'], $right['domain']));

        return [
            'online' => true,
            'rule' => $layer7Id !== null && $ruleIds !== [],
            'active' => $dropping && collect($listed)->contains(fn (array $site) => $site['enabled']),
            'packets' => $packets,
            'bytes' => $bytes,
            'layer7_id' => $layer7Id !== '' ? $layer7Id : null,
            'regexp' => $regexp,
            'layer_comment' => $layerComment,
            'rules' => $rules,
            'rule_ids' => $ruleIds,
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
        $enabled = array_values(array_filter(
            $snapshot['sites'],
            fn (array $site) => (bool) ($site['enabled'] ?? false),
        ));

        return [
            'online' => $snapshot['online'],
            'rule' => $snapshot['rule'],
            'active' => (bool) ($snapshot['active'] ?? false),
            'packets' => (int) ($snapshot['packets'] ?? 0),
            'bytes' => (int) ($snapshot['bytes'] ?? 0),
            'sampled_at' => now()->toIso8601String(),
            'regexp' => $enabled === [] ? '' : $this->compile(array_column($enabled, 'domain')),
            'sites' => array_map(fn (array $site) => [
                'domain' => $site['domain'],
                'enabled' => (bool) ($site['enabled'] ?? false),
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

        $active = collect($current['sites'])->where('enabled', true)->pluck('domain')->push($domain)->unique()->sort()->values()->all();
        $paused = collect($current['sites'])->where('enabled', false)->pluck('domain')->reject(fn (string $listed) => $listed === $domain)->sort()->values()->all();
        $error = $this->apply($session, $current, $active, $paused);
        if ($error !== null) {
            return $error;
        }

        return $this->send($session, $this->legacyCalls($current));
    }

    public function toggle(MikrotikSession $session, string $domain, bool $enabled): ?string
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
            return 'Situs ini tidak ada di daftar.';
        }

        $active = collect($current['sites'])->where('enabled', true)->pluck('domain');
        $paused = collect($current['sites'])->where('enabled', false)->pluck('domain');
        if ($enabled) {
            $active = $active->push($domain);
            $paused = $paused->reject(fn (string $listed) => $listed === $domain);
        } else {
            $active = $active->reject(fn (string $listed) => $listed === $domain);
            $paused = $paused->push($domain);
        }

        return $this->apply(
            $session,
            $current,
            $active->unique()->sort()->values()->all(),
            $paused->unique()->sort()->values()->all(),
        );
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

        $active = collect($current['sites'])->where('enabled', true)->pluck('domain')->reject(fn (string $listed) => $listed === $domain)->sort()->values()->all();
        $paused = collect($current['sites'])->where('enabled', false)->pluck('domain')->reject(fn (string $listed) => $listed === $domain)->sort()->values()->all();
        $calls = [];
        if ($active === [] && $paused === []) {
            if ($current['layer7_id'] !== null) {
                $calls['layer7'] = ['method' => 'post', 'path' => 'ip/firewall/layer7-protocol/remove', 'body' => ['.id' => $current['layer7_id']]];
            }
            foreach ($current['rule_ids'] as $index => $id) {
                $calls['rule'.$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/remove', 'body' => ['.id' => $id]];
            }
        } else {
            $error = $this->apply($session, $current, $active, $paused);
            if ($error !== null) {
                return $error;
            }
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
        if ($active === [] && $paused === []) {
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
     * @param  array{layer7_id: string|null, regexp: string, layer_comment?: string, rules?: array<int, array{id: string, disabled: bool}>}  $current
     * @param  array<int, string>  $active
     * @param  array<int, string>  $paused
     */
    private function apply(MikrotikSession $session, array $current, array $active, array $paused): ?string
    {
        $active = array_values(array_filter($active, fn (string $domain) => $domain !== self::HOLD));
        $paused = array_values(array_diff($paused, $active));
        $regexp = $this->compile($active === [] ? [self::HOLD] : $active);
        $comment = $this->note($paused);
        $calls = [];
        if ($current['layer7_id'] === null) {
            $calls['layer7'] = ['method' => 'post', 'path' => 'ip/firewall/layer7-protocol/add', 'body' => [
                'name' => self::LIST,
                'regexp' => $regexp,
                'comment' => $comment,
            ]];
        } elseif ($current['regexp'] !== $regexp || ($current['layer_comment'] ?? '') !== $comment) {
            $calls['layer7'] = ['method' => 'post', 'path' => 'ip/firewall/layer7-protocol/set', 'body' => [
                '.id' => $current['layer7_id'],
                'regexp' => $regexp,
                'comment' => $comment,
            ]];
        }

        $wantOff = $active === [];
        foreach ($current['rules'] ?? [] as $index => $rule) {
            if (($rule['disabled'] ?? false) === $wantOff || ($rule['id'] ?? '') === '') {
                continue;
            }

            $calls['state'.$index] = ['method' => 'post', 'path' => 'ip/firewall/filter/set', 'body' => [
                '.id' => $rule['id'],
                'disabled' => $wantOff ? 'yes' : 'no',
            ]];
        }
        if (($current['rules'] ?? []) === [] && ! $wantOff) {
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

        return $this->send($session, $calls);
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
    private function paused(string $comment): array
    {
        $prefix = self::RULE.' off:';
        if (! str_starts_with($comment, $prefix)) {
            return [];
        }

        $domains = [];
        foreach (explode(',', substr($comment, strlen($prefix))) as $part) {
            $domain = $this->hostname($part);
            if ($domain !== null) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * @param  array<int, string>  $paused
     */
    private function note(array $paused): string
    {
        $paused = array_values(array_unique($paused));
        sort($paused);

        return $paused === [] ? self::RULE : self::RULE.' off:'.implode(',', $paused);
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
            if ($domain !== null && $domain !== self::HOLD) {
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
            'active' => false,
            'packets' => 0,
            'bytes' => 0,
            'layer7_id' => null,
            'regexp' => '',
            'layer_comment' => '',
            'rules' => [],
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
