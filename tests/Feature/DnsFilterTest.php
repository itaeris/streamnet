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

    public function test_blocking_puts_the_site_in_the_layer7_regexp(): void
    {
        $session = $this->router();
        Http::fake(fn () => Http::response([]));

        $error = app(DnsFilter::class)->block($session, 'https://www.facebook.com/watch');

        $this->assertNull($error);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/layer7-protocol/add')
            && ($request['name'] ?? null) === 'streamnet-block'
            && ($request['regexp'] ?? null) === '^.+(facebook\\.com).*$'
            && ($request['comment'] ?? null) === 'StreamNet');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/filter/add')
            && ($request['layer7-protocol'] ?? null) === 'streamnet-block'
            && ($request['action'] ?? null) === 'drop'
            && ($request['protocol'] ?? null) === 'tcp'
            && ($request['dst-port'] ?? null) === '80,443'
            && ($request['place-before'] ?? null) === '0');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ip/dns/static/add')
            || ($request['tls-host'] ?? null) !== null);
    }

    public function test_a_second_site_is_added_to_the_same_regexp(): void
    {
        $session = $this->router();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/layer7-protocol') => [
                    ['.id' => '*L1', 'name' => 'streamnet-block', 'regexp' => '^.+(amazon\\.com).*$', 'comment' => 'StreamNet'],
                ],
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/filter') => [
                    ['.id' => '*R1', 'chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp', 'dst-port' => '80,443', 'layer7-protocol' => 'streamnet-block', 'comment' => 'StreamNet'],
                ],
                default => [],
            };

            return Http::response($body);
        });

        $this->assertNull(app(DnsFilter::class)->block($session, 'facebook.com'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/layer7-protocol/set')
            && ($request['.id'] ?? null) === '*L1'
            && ($request['regexp'] ?? null) === '^.+(amazon\\.com|facebook\\.com).*$');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/filter/add')
            || str_ends_with($request->url(), '/ip/firewall/layer7-protocol/add'));
    }

    public function test_unblocking_removes_the_protocol_when_it_was_the_last_site(): void
    {
        $session = $this->router();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/layer7-protocol') => [
                    ['.id' => '*L1', 'name' => 'streamnet-block', 'regexp' => '^.+(facebook\\.com).*$', 'comment' => 'StreamNet'],
                ],
                $request->method() === 'GET' && str_ends_with($path, '/ip/dns/static') => [
                    ['.id' => '*D1', 'name' => 'facebook.com', 'type' => 'NXDOMAIN', 'comment' => 'StreamNet:facebook.com'],
                ],
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/filter') => [
                    ['.id' => '*R1', 'chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp', 'dst-port' => '80,443', 'layer7-protocol' => 'streamnet-block', 'comment' => 'StreamNet'],
                    ['.id' => '*F1', 'chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp', 'dst-port' => '443', 'tls-host' => 'facebook.com', 'comment' => 'StreamNet:facebook.com'],
                    ['.id' => '*F3', 'chain' => 'forward', 'action' => 'drop', 'protocol' => 'udp', 'dst-port' => '443', 'comment' => 'StreamNet'],
                ],
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/nat') => [
                    ['.id' => '*N1', 'chain' => 'dstnat', 'action' => 'redirect', 'protocol' => 'udp', 'dst-port' => '53', 'comment' => 'StreamNet'],
                ],
                default => [],
            };

            return Http::response($body);
        });

        $filter = app(DnsFilter::class);
        $snapshot = $filter->present($filter->read($session));
        $this->assertTrue($snapshot['rule']);
        $this->assertSame('^.+(facebook\\.com).*$', $snapshot['regexp']);
        $this->assertSame('facebook.com', $snapshot['sites'][0]['domain']);
        $this->assertTrue($snapshot['sites'][0]['layer7']);
        $this->assertTrue($snapshot['sites'][0]['firewall']);

        $this->assertNull($filter->unblock($session, 'facebook.com'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/layer7-protocol/remove') && ($request['.id'] ?? null) === '*L1');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/filter/remove') && ($request['.id'] ?? null) === '*R1');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/filter/remove') && ($request['.id'] ?? null) === '*F1');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/filter/remove') && ($request['.id'] ?? null) === '*F3');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/dns/static/remove') && ($request['.id'] ?? null) === '*D1');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/nat/remove') && ($request['.id'] ?? null) === '*N1');
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
