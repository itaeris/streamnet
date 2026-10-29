<?php

namespace Tests\Feature;

use App\Models\DhcpLease;
use App\Models\DnsQuery;
use App\Models\MikrotikSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DhcpAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_alias_is_stored_as_the_mikrotik_lease_comment(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        DhcpLease::query()->create([
            'mikrotik_session_id' => $session->id,
            'router_id' => '*A',
            'address' => '10.10.10.10',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'host_name' => 'laptop',
        ]);
        Http::fake(['*' => Http::response([])]);

        $this->actingAs(User::factory()->create())
            ->put('/dhcp/alias', ['router_id' => '*A', 'comment' => 'Ruang meeting'])
            ->assertRedirect();

        $this->assertDatabaseHas('dhcp_leases', [
            'router_id' => '*A',
            'comment' => 'Ruang meeting',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'dhcp-server/lease/set')
            && $request['.id'] === '*A'
            && $request['comment'] === 'Ruang meeting');
    }

    public function test_dns_for_one_client_is_loaded_on_demand(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        foreach (['10.10.10.10' => 'youtube.com', '10.10.10.11' => 'instagram.com'] as $address => $domain) {
            DnsQuery::query()->create([
                'mikrotik_session_id' => $session->id,
                'router_id' => $address,
                'logged_at' => now()->subMinute(),
                'address' => $address,
                'domain' => $domain,
                'qtype' => 'A',
                'app' => '',
                'browser' => '',
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->getJson('/dhcp/dns?live=1&address=10.10.10.10')
            ->assertOk()
            ->assertJsonCount(1, 'queries')
            ->assertJsonPath('queries.0.domain', 'youtube.com');

        $this->actingAs(User::factory()->create())
            ->get('/dhcp?live=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('dns'));
    }

    public function test_clearing_an_alias_removes_the_comment(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        DhcpLease::query()->create([
            'mikrotik_session_id' => $session->id,
            'router_id' => '*A',
            'address' => '10.10.10.10',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'comment' => 'Lama',
        ]);
        Http::fake(['*' => Http::response([])]);

        $this->actingAs(User::factory()->create())
            ->put('/dhcp/alias', ['router_id' => '*A', 'comment' => ''])
            ->assertRedirect();

        $this->assertDatabaseHas('dhcp_leases', [
            'router_id' => '*A',
            'comment' => null,
        ]);
    }
}
