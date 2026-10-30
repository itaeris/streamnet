<?php

namespace Tests\Feature;

use App\Models\MikrotikSession;
use App\Models\User;
use App\Services\Mikrotik\DnsFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DnsFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_uses_the_open_session_slug(): void
    {
        $user = User::factory()->create();
        MikrotikSession::query()->create([
            'name' => 'AERIS | FTI',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        Http::fake(['*' => Http::response([])]);

        $this->actingAs($user)
            ->get('/dns-filter')
            ->assertRedirect('/sesi');

        $this->actingAs($user)
            ->withSession(['mikrotik_session_id' => MikrotikSession::query()->value('id')])
            ->get('/aeris-fti/dns-filter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('DnsFilter', false));
    }

    public function test_blocking_writes_dns_nxdomain_and_a_firewall_drop(): void
    {
        $session = $this->router();
        Http::fake(fn () => Http::response([]));

        $error = app(DnsFilter::class)->block($session, 'https://www.facebook.com/watch');

        $this->assertNull($error);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/dns/static/add')
            && ($request['name'] ?? null) === 'facebook.com'
            && ($request['type'] ?? null) === 'NXDOMAIN'
            && ($request['comment'] ?? null) === 'StreamNet:facebook.com');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/dns/static/add')
            && ($request['regexp'] ?? null) === '.*\\.facebook\\.com$'
            && ($request['type'] ?? null) === 'NXDOMAIN');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/address-list/add')
            && ($request['list'] ?? null) === 'streamnet-block'
            && ($request['address'] ?? null) === 'facebook.com');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/filter/add')
            && ($request['action'] ?? null) === 'drop'
            && ($request['dst-address-list'] ?? null) === 'streamnet-block'
            && ($request['place-before'] ?? null) === '0');
    }

    public function test_unblocking_removes_the_entries_and_the_rule(): void
    {
        $session = $this->router();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                $request->method() === 'GET' && str_ends_with($path, '/ip/dns/static') => [
                    ['.id' => '*D1', 'name' => 'facebook.com', 'type' => 'NXDOMAIN', 'comment' => 'StreamNet:facebook.com'],
                    ['.id' => '*D2', 'regexp' => '.*\\.facebook\\.com$', 'type' => 'NXDOMAIN', 'comment' => 'StreamNet:facebook.com'],
                ],
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/address-list') => [
                    ['.id' => '*A1', 'list' => 'streamnet-block', 'address' => 'facebook.com', 'comment' => 'StreamNet:facebook.com'],
                ],
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/filter') => [
                    ['.id' => '*F1', 'chain' => 'forward', 'action' => 'drop', 'dst-address-list' => 'streamnet-block', 'comment' => 'StreamNet'],
                ],
                default => ['.id' => '*1'],
            };

            return Http::response($body);
        });

        $filter = app(DnsFilter::class);
        $snapshot = $filter->present($filter->read($session));
        $this->assertTrue($snapshot['rule']);
        $this->assertSame('facebook.com', $snapshot['sites'][0]['domain']);
        $this->assertTrue($snapshot['sites'][0]['dns']);
        $this->assertTrue($snapshot['sites'][0]['firewall']);

        $this->assertNull($filter->unblock($session, 'facebook.com'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/dns/static/remove') && $request['.id'] === '*D1');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/dns/static/remove') && $request['.id'] === '*D2');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/address-list/remove') && $request['.id'] === '*A1');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/filter/remove') && $request['.id'] === '*F1');
    }

    public function test_a_bad_domain_is_rejected(): void
    {
        $user = User::factory()->create();
        $session = $this->router();
        Http::fake();

        $this->actingAs($user)
            ->withSession(['mikrotik_session_id' => $session->id])
            ->post('/dns-filter', ['domain' => 'bukan domain'])
            ->assertRedirect()
            ->assertSessionHasErrors('domain');

        Http::assertNothingSent();
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
