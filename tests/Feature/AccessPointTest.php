<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Unifi\UnifiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccessPointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'unifi.url' => 'https://unifi.test',
            'unifi.api_key' => 'test-key',
            'unifi.site' => '',
            'cache.default' => 'array',
        ]);
    }

    public function test_snapshot_maps_access_points_stats_and_clients(): void
    {
        $base = 'https://unifi.test/proxy/network/integration/v1';
        Http::fake([
            "{$base}/sites?*" => Http::response(['totalCount' => 1, 'data' => [['id' => 'site-1', 'internalReference' => 'default', 'name' => 'Default']]]),
            "{$base}/sites/site-1/devices?*" => Http::response(['totalCount' => 1, 'data' => [[
                'id' => 'ap-1',
                'name' => 'Nano HD Lantai 1',
                'model' => 'Nano HD',
                'macAddress' => 'd0:21:f9:00:00:01',
                'ipAddress' => '10.10.10.231',
                'state' => 'ONLINE',
                'features' => ['accessPoint'],
            ]]]),
            "{$base}/sites/site-1/clients?*" => Http::response(['totalCount' => 2, 'data' => [
                ['id' => 'c-1', 'type' => 'WIRELESS', 'name' => 'Laptop', 'ipAddress' => '10.10.10.50', 'macAddress' => 'aa:aa:aa:aa:aa:01', 'uplinkDeviceId' => 'ap-1', 'connectedAt' => '2026-10-29T01:00:00Z'],
                ['id' => 'c-2', 'type' => 'WIRELESS', 'name' => 'Phone', 'ipAddress' => '10.10.10.51', 'macAddress' => 'aa:aa:aa:aa:aa:02', 'uplinkDeviceId' => 'ap-1', 'connectedAt' => '2026-10-29T02:00:00Z'],
            ]]),
            "{$base}/sites/site-1/devices/ap-1/statistics/latest" => Http::response([
                'uptimeSec' => 3600,
                'cpuUtilizationPct' => 7.5,
                'memoryUtilizationPct' => 51.9,
                'uplink' => ['txRateBps' => 1000, 'rxRateBps' => 2000],
                'interfaces' => ['radios' => [['frequencyGHz' => 5, 'txRetriesPct' => 12.5]]],
            ]),
            "{$base}/sites/site-1/devices/ap-1" => Http::response([
                'id' => 'ap-1',
                'firmwareVersion' => '6.7.57',
                'features' => ['accessPoint' => (object) []],
                'interfaces' => ['radios' => [['wlanStandard' => '802.11ac', 'frequencyGHz' => 5, 'channelWidthMHz' => 40, 'channel' => 36]]],
            ]),
        ]);

        $snapshot = app(UnifiClient::class)->snapshot();

        $this->assertTrue($snapshot['ok']);
        $this->assertCount(1, $snapshot['devices']);
        $device = $snapshot['devices'][0];
        $this->assertTrue($device['access_point']);
        $this->assertSame('online', $device['state']);
        $this->assertSame(2, $device['clients']);
        $this->assertSame(2000, $device['rx_bps']);
        $this->assertSame(36, $device['radios'][0]['channel']);
        $this->assertSame(12.5, $device['radios'][0]['retries']);
        $this->assertSame('Nano HD Lantai 1', $snapshot['clients'][0]['uplink']);
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-KEY', 'test-key'));
    }

    public function test_rejected_key_is_reported_without_crashing_the_page(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->actingAs(User::factory()->create())
            ->get('/access-points')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AccessPoints', false)
                ->where('unifi.ok', false)
                ->where('unifi.error', 'API key UniFi ditolak.'));
    }
}
