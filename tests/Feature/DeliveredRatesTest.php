<?php

namespace Tests\Feature;

use App\Models\LiveSnapshot;
use App\Models\MikrotikSession;
use App\Services\Mikrotik\DeliveredRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveredRatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_torch_rows_become_per_client_rates_after_the_queue(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        LiveSnapshot::query()->create([
            'mikrotik_session_id' => $session->id,
            'key' => 'pulse',
            'payload' => ['online' => true, 'leases' => [['address' => '10.10.10.26']]],
            'sampled_at' => now(),
        ]);

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            return Http::response(match (true) {
                str_ends_with($path, '/ip/address') => [
                    ['address' => '203.0.113.2/30', 'interface' => 'ether1-ISP'],
                    ['address' => '10.10.10.1/24', 'interface' => 'bridge-ho'],
                ],
                str_ends_with($path, '/tool/torch') => [
                    ['.section' => '0', 'src-address' => '10.10.10.26', 'dst-address' => '17.0.0.1', 'tx' => '0', 'rx' => '0'],
                    ['.section' => '1', 'src-address' => '10.10.10.26', 'dst-address' => '17.0.0.1', 'tx' => '40000000', 'rx' => '1000000'],
                    ['.section' => '1', 'src-address' => '23.0.0.1', 'dst-address' => '10.10.10.26', 'tx' => '10000000', 'rx' => '0'],
                    ['.section' => '1', 'src-address' => '10.10.10.1', 'dst-address' => '10.10.10.50', 'tx' => '500', 'rx' => '0'],
                ],
                default => [],
            });
        });

        $rates = app(DeliveredRates::class);
        $delivered = $rates->measure($session);

        $this->assertSame('bridge-ho', $delivered['interface']);
        $this->assertSame(['down' => 50000000, 'up' => 1000000], $delivered['clients']['10.10.10.26']);
        $this->assertSame(500, $delivered['clients']['10.10.10.50']['down']);

        $rates->store($session, $delivered);
        $leases = $rates->attach([
            ['address' => '10.10.10.26', 'rx_bps' => 99000000, 'tx_bps' => 2000000],
            ['address' => '10.10.10.99', 'rx_bps' => 300, 'tx_bps' => 100],
        ], $rates->latest($session->id));

        $this->assertSame(50000000, $leases[0]['rx_bps']);
        $this->assertSame(99000000, $leases[0]['wan_rx_bps']);
        $this->assertSame('queue', $leases[0]['measured']);
        $this->assertSame(0, $leases[1]['rx_bps']);
    }

    public function test_without_a_fresh_measurement_conntrack_rates_stay(): void
    {
        $rates = app(DeliveredRates::class);
        $leases = $rates->attach([['address' => '10.10.10.26', 'rx_bps' => 99000000, 'tx_bps' => 2000000]], null);

        $this->assertSame(99000000, $leases[0]['rx_bps']);
        $this->assertSame('conntrack', $leases[0]['measured']);
    }
}
