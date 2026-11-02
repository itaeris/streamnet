<?php

namespace Tests\Feature;

use App\Models\MikrotikSession;
use App\Models\User;
use App\Services\Mikrotik\CloudflareDns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_servers_count_as_enabled(): void
    {
        $session = $this->router();
        Http::fake(fn () => Http::response(['servers' => '1.0.0.3,1.1.1.3']));

        $status = app(CloudflareDns::class)->status($session);

        $this->assertTrue($status['online']);
        $this->assertTrue($status['enabled']);
    }

    public function test_turning_it_on_saves_the_previous_servers_and_points_dns_at_cloudflare(): void
    {
        $user = User::factory()->create();
        $session = $this->router();
        Http::fake(fn () => Http::response(['servers' => '8.8.8.8,1.1.1.1']));

        $this->actingAs($user)
            ->withSession(['mikrotik_session_id' => $session->id])
            ->put('/dns-filter/cloudflare', ['enabled' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('8.8.8.8,1.1.1.1', $session->fresh()->dns_servers_backup);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/dns/set')
            && ($request['servers'] ?? null) === '1.1.1.3,1.0.0.3');
    }

    public function test_turning_it_off_restores_the_previous_servers(): void
    {
        $session = $this->router();
        $session->forceFill(['dns_servers_backup' => '9.9.9.9'])->save();
        Http::fake(fn () => Http::response(['servers' => '1.1.1.3,1.0.0.3']));

        $this->assertNull(app(CloudflareDns::class)->apply($session, false));

        $this->assertNull($session->fresh()->dns_servers_backup);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/dns/set')
            && ($request['servers'] ?? null) === '9.9.9.9');
    }

    private function router(): MikrotikSession
    {
        return MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
    }
}
