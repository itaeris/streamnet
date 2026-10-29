<?php

namespace Tests\Feature;

use App\Models\LiveSnapshot;
use App\Models\MikrotikSession;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Mikrotik\MikrotikPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BandwidthPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function router(): MikrotikSession
    {
        return MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'wan_interface' => 'ether1',
            'enabled' => true,
        ]);
    }

    private function fakeRouter(): void
    {
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                str_ends_with($path, '/system/resource') => ['board-name' => 'RB', 'version' => '7', 'uptime' => '1d', 'cpu-load' => '1', 'free-memory' => '1', 'total-memory' => '2'],
                str_ends_with($path, '/queue/simple') => [[
                    '.id' => '*3BE0',
                    'name' => 'TOTAL-INTERNET',
                    'target' => '10.10.10.0/24',
                    'queue' => 'pcq_upload/pcq_download',
                    'max-limit' => '290000000/290000000',
                    'rate' => '1000000/150000000',
                    'pcq-queues' => '69/68',
                    'dropped' => '1/2',
                    'disabled' => 'false',
                    'dynamic' => 'false',
                ]],
                str_ends_with($path, '/queue/type') => [
                    ['.id' => '*6', 'name' => 'pcq_download', 'kind' => 'pcq', 'pcq-rate' => '0', 'pcq-classifier' => 'dst-address', 'pcq-burst-time' => '10s'],
                    ['.id' => '*7', 'name' => 'pcq_upload', 'kind' => 'pcq', 'pcq-rate' => '0', 'pcq-classifier' => 'src-address', 'pcq-burst-time' => '10s'],
                    ['.id' => '*1', 'name' => 'default', 'kind' => 'pfifo'],
                ],
                str_ends_with($path, '/ip/firewall/filter') => [['chain' => 'forward', 'action' => 'accept']],
                str_ends_with($path, '/ip/dhcp-server/lease') => [[
                    '.id' => '*1',
                    'address' => '10.10.10.19',
                    'mac-address' => 'AA:BB:CC:DD:EE:FF',
                    'host-name' => 'V2309',
                    'status' => 'bound',
                ]],
                str_ends_with($path, '/ip/firewall/connection') => [
                    ['src-address' => '10.10.10.19:5000', 'dst-address' => '1.1.1.1:443', 'orig-rate' => '1000', 'repl-rate' => '200000000'],
                ],
                default => [],
            };

            return Http::response($body);
        });
    }

    public function test_poll_maps_pcq_queue_and_logs_a_sustained_hog_once(): void
    {
        $session = $this->router();
        $this->fakeRouter();
        $poller = app(MikrotikPoller::class);

        $payload = $poller->poll($session);
        $poller->poll($session);
        $poller->poll($session);
        $poller->poll($session);

        $this->assertSame('TOTAL-INTERNET', $payload['shaping']['main']['name']);
        $this->assertSame(290000000, $payload['shaping']['main']['max_down']);
        $this->assertSame(150000000, $payload['shaping']['main']['rate_down']);
        $this->assertSame(68, $payload['shaping']['main']['users_down']);
        $this->assertSame('*6', $payload['shaping']['down_type']['id']);
        $this->assertFalse($payload['shaping']['fasttrack']);

        $events = SecurityEvent::query()->where('category', 'bandwidth')->get();
        $this->assertCount(1, $events);
        $this->assertStringContainsString('10.10.10.19', $events->first()->detail);
    }

    public function test_apply_sets_pcq_rates_and_queue_capacity(): void
    {
        $session = $this->router();
        $this->fakeRouter();
        app(MikrotikPoller::class)->poll($session);
        Http::fake(['*' => Http::response([])]);

        $this->actingAs(User::factory()->create())
            ->put('/bandwidth-policy', [
                'capacity_down' => 270,
                'capacity_up' => 270,
                'client_down' => 20,
                'client_up' => 5,
                'burst' => true,
                'burst_down' => 40,
                'burst_up' => 10,
                'burst_time' => 8,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/queue/type/set')
            && $request['.id'] === '*6'
            && $request['pcq-rate'] === '20000000'
            && $request['pcq-burst-rate'] === '40000000'
            && $request['pcq-burst-threshold'] === '15000000'
            && $request['pcq-burst-time'] === '8s');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/queue/simple/set')
            && $request['.id'] === '*3BE0'
            && $request['max-limit'] === '270000000/270000000');
    }

    public function test_client_limit_cannot_exceed_capacity(): void
    {
        $this->router();
        LiveSnapshot::query()->delete();

        $this->actingAs(User::factory()->create())
            ->from('/bandwidth-policy')
            ->put('/bandwidth-policy', [
                'capacity_down' => 100,
                'capacity_up' => 50,
                'client_down' => 200,
                'client_up' => 5,
            ])
            ->assertRedirect('/bandwidth-policy')
            ->assertSessionHasErrors('client_down');
    }
}
