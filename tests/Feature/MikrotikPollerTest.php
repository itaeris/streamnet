<?php

namespace Tests\Feature;

use App\Models\DhcpLease;
use App\Models\DnsQuery;
use App\Models\InterfaceSample;
use App\Models\LiveSnapshot;
use App\Models\MikrotikSession;
use App\Models\SecurityEvent;
use App\Services\Mikrotik\MikrotikPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MikrotikPollerTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_stores_router_samples_and_security_events(): void
    {
        config([
            'mikrotik.base_url' => 'http://10.10.10.1/rest',
            'mikrotik.username' => 'wiki',
            'mikrotik.password' => 'secret',
            'mikrotik.wan_interface' => 'ether1-ISP',
        ]);

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                str_ends_with($path, '/system/resource') => [
                    'board-name' => 'RB450Gx4',
                    'version' => '7.21.5',
                    'uptime' => '1d',
                    'cpu-load' => '10',
                    'cpu-count' => '4',
                    'cpu-frequency' => '768',
                    'free-memory' => '500',
                    'total-memory' => '1000',
                    'free-hdd-space' => '100',
                    'total-hdd-space' => '200',
                ],
                str_ends_with($path, '/system/identity') => ['name' => 'AERIS'],
                str_ends_with($path, '/system/health') => [
                    ['name' => 'temperature', 'value' => '40'],
                    ['name' => 'voltage', 'value' => '24.1'],
                ],
                str_ends_with($path, '/system/routerboard') => [
                    'model' => 'RB450Gx4',
                    'current-firmware' => '7.21.5',
                    'upgrade-firmware' => '7.21.5',
                    'serial-number' => 'ABC',
                ],
                str_ends_with($path, '/system/clock') => [
                    'date' => '2026-10-28',
                    'time' => '13:00:00',
                    'time-zone-name' => 'Asia/Jakarta',
                ],
                str_ends_with($path, '/interface/monitor-traffic') => [[
                    'name' => 'ether1-ISP',
                    'rx-bits-per-second' => '8000000',
                    'tx-bits-per-second' => '1000000',
                    'rx-packets-per-second' => '10',
                    'tx-packets-per-second' => '4',
                ]],
                str_ends_with($path, '/interface') => [[
                    'name' => 'ether1-ISP',
                    'type' => 'ether',
                    'running' => 'true',
                    'disabled' => 'false',
                    'rx-byte' => '1000',
                    'tx-byte' => '2000',
                    'rx-error' => '0',
                    'tx-error' => '0',
                    'rx-drop' => '0',
                    'tx-drop' => '0',
                    'link-downs' => '1',
                ]],
                str_ends_with($path, '/ip/dhcp-server/lease') => [[
                    '.id' => '*1',
                    'address' => '10.10.10.10',
                    'mac-address' => 'AA:BB:CC:DD:EE:FF',
                    'host-name' => 'laptop',
                    'server' => 'ho-local',
                    'status' => 'bound',
                    'blocked' => 'false',
                    'dynamic' => 'true',
                    'last-seen' => '1m',
                    'expires-after' => '9m',
                ]],
                str_ends_with($path, '/log') => [[
                    '.id' => '*10',
                    'time' => '2026-10-28 13:00:00',
                    'topics' => 'account,error',
                    'message' => 'login failure for user admin from 1.2.3.4 via winbox',
                ]],
                str_ends_with($path, '/ip/firewall/filter') => [[
                    'chain' => 'input',
                    'action' => 'drop',
                    'comment' => 'drop wan',
                    'bytes' => '10',
                    'packets' => '2',
                ]],
                str_ends_with($path, '/ip/firewall/address-list') => [[
                    'list' => 'blocked',
                    'address' => '1.2.3.4',
                    'comment' => 'test',
                    'dynamic' => 'false',
                    'disabled' => 'false',
                ]],
                str_ends_with($path, '/user/active') => [[
                    'name' => 'wiki',
                    'address' => '10.10.10.5',
                    'via' => 'winbox',
                    'group' => 'full',
                    'when' => '2026-10-28 13:00:00',
                ]],
                str_ends_with($path, '/ip/service') => [[
                    'name' => 'www',
                    'disabled' => 'false',
                    'port' => '80',
                    'proto' => 'tcp',
                    'address' => '',
                    'invalid' => 'false',
                ]],
                str_contains($request->url(), '.proplist') => [
                    ['src-address' => '10.10.10.10:1111', 'dst-address' => '1.1.1.1:443', 'orig-rate' => '8000', 'repl-rate' => '24000'],
                    ['src-address' => '9.9.9.9', 'dst-address' => '10.10.10.10', 'orig-rate' => '1000', 'repl-rate' => '500'],
                ],
                str_ends_with($path, '/ip/firewall/connection/print') => ['ret' => '12'],
                default => [],
            };

            return Http::response($body);
        });

        $payload = app(MikrotikPoller::class)->poll();

        $this->assertTrue($payload['online']);
        $this->assertSame('AERIS', $payload['system']['identity']);
        $this->assertSame(8000000, $payload['wan']['rx_bps']);
        $this->assertSame('laptop', $payload['leases'][0]['host']);
        $this->assertSame(25000, $payload['leases'][0]['rx_bps']);
        $this->assertSame(8500, $payload['leases'][0]['tx_bps']);
        $this->assertSame(2, $payload['leases'][0]['flows']);
        $this->assertSame(12, $payload['security']['connections']);
        $this->assertTrue(collect($payload['security']['findings'])->contains(
            fn (array $finding) => $finding['category'] === 'service'
        ));
        $this->assertDatabaseHas('dhcp_leases', ['host_name' => 'laptop']);
        $this->assertDatabaseHas('security_events', ['category' => 'login']);
        $this->assertSame(8000000, (int) InterfaceSample::query()->first()->rx_bps);
        $this->assertSame(1, DhcpLease::query()->count());
        $this->assertGreaterThanOrEqual(1, SecurityEvent::query()->count());
    }

    public function test_rate_refresh_updates_bandwidth_without_storing_a_sample(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'wan_interface' => 'ether1-ISP',
            'enabled' => true,
        ]);
        $this->travelTo(now()->startOfSecond());
        $sampledAt = now()->subSeconds(2);

        LiveSnapshot::query()->create([
            'mikrotik_session_id' => $session->id,
            'key' => 'pulse',
            'sampled_at' => $sampledAt,
            'payload' => [
                'online' => true,
                'sampled_at' => $sampledAt->toIso8601String(),
                'system' => ['identity' => 'AERIS', 'cpu_load' => 1],
                'wan' => ['name' => 'ether1-ISP', 'rx_bps' => 100, 'tx_bps' => 100],
                'interfaces' => [[
                    'name' => 'ether1-ISP',
                    'rx_bytes' => 1000,
                    'tx_bytes' => 2000,
                    'rx_bps' => 100,
                    'tx_bps' => 100,
                    'rx_pps' => 1,
                    'tx_pps' => 1,
                ]],
                'leases' => [[
                    'host' => 'laptop',
                    'address' => '10.10.10.10',
                    'rx_bps' => 5,
                    'tx_bps' => 5,
                    'flows' => 1,
                ]],
            ],
        ]);

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                str_ends_with($path, '/system/resource') => [
                    'cpu-load' => '22',
                    'uptime' => '2d',
                    'free-memory' => '400',
                    'total-memory' => '1000',
                ],
                str_ends_with($path, '/system/health') => [
                    ['name' => 'temperature', 'value' => '41'],
                ],
                str_ends_with($path, '/interface') => [[
                    'name' => 'ether1-ISP',
                    'type' => 'ether',
                    'running' => 'true',
                    'disabled' => 'false',
                    'rx-byte' => '3000',
                    'tx-byte' => '4000',
                ]],
                str_contains($request->url(), '.proplist') => [
                    ['src-address' => '10.10.10.10:1111', 'dst-address' => '1.1.1.1:443', 'orig-rate' => '1000', 'repl-rate' => '3000'],
                ],
                default => [],
            };

            return Http::response($body);
        });

        $payload = app(MikrotikPoller::class)->refreshRates($session);

        $this->assertTrue($payload['online']);
        $this->assertSame(8000, $payload['wan']['rx_bps']);
        $this->assertSame(8000, $payload['wan']['tx_bps']);
        $this->assertSame(22, $payload['system']['cpu_load']);
        $this->assertSame('AERIS', $payload['system']['identity']);
        $this->assertSame('laptop', $payload['leases'][0]['host']);
        $this->assertSame(3000, $payload['leases'][0]['rx_bps']);
        $this->assertSame(1000, $payload['leases'][0]['tx_bps']);
        $this->assertSame(0, InterfaceSample::query()->count());
    }

    public function test_each_router_keeps_its_own_samples(): void
    {
        $first = MikrotikSession::query()->create([
            'name' => 'Satu',
            'host' => '10.1.1.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'wan_interface' => 'ether1',
            'enabled' => true,
        ]);
        $second = MikrotikSession::query()->create([
            'name' => 'Dua',
            'host' => '10.2.2.2',
            'scheme' => 'https',
            'port' => 443,
            'username' => 'ops',
            'password' => 'secret',
            'enabled' => true,
        ]);

        Http::fake(function ($request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if (str_ends_with($path, '/system/resource')) {
                return Http::response([
                    'board-name' => $host,
                    'version' => '7',
                    'uptime' => '1d',
                    'cpu-load' => '1',
                    'cpu-count' => '1',
                    'cpu-frequency' => '1',
                    'free-memory' => '1',
                    'total-memory' => '2',
                    'free-hdd-space' => '1',
                    'total-hdd-space' => '2',
                ]);
            }

            if (str_ends_with($path, '/system/identity')) {
                return Http::response(['name' => $host]);
            }

            if (str_ends_with($path, '/ip/dhcp-server/lease')) {
                return Http::response([[
                    '.id' => '*1',
                    'address' => $host === '10.1.1.1' ? '10.1.1.10' : '10.2.2.10',
                    'mac-address' => 'AA:BB:CC:DD:EE:FF',
                    'host-name' => $host,
                    'status' => 'bound',
                ]]);
            }

            if (str_ends_with($path, '/interface')) {
                return Http::response([[
                    'name' => 'ether1',
                    'type' => 'ether',
                    'running' => 'false',
                    'disabled' => 'false',
                    'rx-byte' => '10',
                    'tx-byte' => '10',
                ]]);
            }

            return Http::response([]);
        });

        $poller = app(MikrotikPoller::class);
        $left = $poller->poll($first);
        $right = $poller->poll($second);

        $this->assertSame('10.1.1.1', $left['system']['identity']);
        $this->assertSame('10.2.2.2', $right['system']['identity']);
        $this->assertSame(2, DhcpLease::query()->count());
        $this->assertSame('10.1.1.10', DhcpLease::query()->where('mikrotik_session_id', $first->id)->value('address'));
        $this->assertSame('10.2.2.10', DhcpLease::query()->where('mikrotik_session_id', $second->id)->value('address'));
        $this->assertSame('https://10.2.2.2:443/rest', $second->baseUrl());
    }

    public function test_poll_stores_client_dns_queries(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'wan_interface' => 'ether1-ISP',
            'enabled' => true,
        ]);

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                str_ends_with($path, '/system/resource') => [
                    'board-name' => 'RB450Gx4',
                    'version' => '7.21.5',
                    'uptime' => '1d',
                    'cpu-load' => '1',
                    'cpu-count' => '1',
                    'cpu-frequency' => '1',
                    'free-memory' => '1',
                    'total-memory' => '2',
                    'free-hdd-space' => '1',
                    'total-hdd-space' => '2',
                ],
                str_ends_with($path, '/system/logging') => [[
                    '.id' => '*1',
                    'topics' => 'info',
                    'action' => 'memory',
                ]],
                str_ends_with($path, '/log') => [
                    [
                        '.id' => '*21',
                        'time' => '2026-10-29 11:00:00',
                        'topics' => 'dns',
                        'message' => 'query from 10.10.10.10: #12 www.youtube.com. A',
                    ],
                    [
                        '.id' => '*22',
                        'time' => '2026-10-29 11:00:01',
                        'topics' => 'dns',
                        'message' => 'query from 10.10.10.10: #13 clients2.google.com. A',
                    ],
                    [
                        '.id' => '*23',
                        'time' => '2026-10-29 11:00:02',
                        'topics' => 'dns',
                        'message' => 'query from 10.10.10.10: #14 10.10.10.1.in-addr.arpa. PTR',
                    ],
                ],
                default => [],
            };

            return Http::response($body);
        });

        $payload = app(MikrotikPoller::class)->poll($session);

        $this->assertTrue($payload['online']);
        $this->assertSame(2, DnsQuery::query()->count());
        $this->assertDatabaseHas('dns_queries', [
            'address' => '10.10.10.10',
            'domain' => 'www.youtube.com',
            'app' => 'YouTube',
            'browser' => null,
        ]);
        $this->assertDatabaseHas('dns_queries', [
            'address' => '10.10.10.10',
            'domain' => 'clients2.google.com',
            'app' => 'Google',
            'browser' => 'Chrome',
        ]);
        Http::assertSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/system/logging')
            && $request->method() === 'POST');
    }

    public function test_poll_reports_offline_when_password_is_missing(): void
    {
        config(['mikrotik.password' => '']);

        $payload = app(MikrotikPoller::class)->poll();

        $this->assertFalse($payload['online']);
        $this->assertNotEmpty($payload['error']);
    }
}
