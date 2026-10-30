<?php

namespace Tests\Feature;

use App\Models\DhcpLease;
use App\Models\DnsQuery;
use App\Models\InterfaceSample;
use App\Models\MikrotikSession;
use App\Models\RouterLog;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AnalyticsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_cookie_redirects_before_the_board_renders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('board_live', '1')
            ->get('/');

        $response->assertRedirect();
        $this->assertStringEndsWith('/?live=1', (string) $response->headers->get('Location'));
    }

    public function test_live_reload_only_sends_the_requested_props(): void
    {
        $user = User::factory()->create();

        $version = $this->actingAs($user)->get('/?live=1')->viewData('page')['version'] ?? '';

        $props = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) $version,
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'logs,events',
            ])
            ->get('/?live=1')
            ->assertOk()
            ->json('props');

        $this->assertArrayHasKey('logs', $props);
        $this->assertArrayHasKey('events', $props);
        $this->assertArrayNotHasKey('series', $props);
        $this->assertArrayNotHasKey('bridge', $props);
        $this->assertArrayNotHasKey('pulse', $props);
    }

    public function test_unknown_range_falls_back_to_thirty_minutes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/?minutes=9999')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('minutes', 30)
                ->where('from', null)
                ->where('to', null));
    }

    public function test_known_ranges_and_absolute_windows_are_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/bandwidth?minutes=1440')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('minutes', 1440)->where('from', null));

        $from = now()->subHour()->getTimestamp();
        $to = now()->getTimestamp();

        $this->actingAs($user)
            ->get('/?from='.$from.'&to='.$to)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('from', $from)->where('to', $to));
    }

    public function test_logs_events_and_leases_follow_the_selected_window(): void
    {
        $user = User::factory()->create();
        $session = MikrotikSession::query()->create([
            'name' => 'Utama',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        $this->session(['mikrotik_session_id' => $session->id]);

        RouterLog::query()->create([
            'mikrotik_session_id' => $session->id,
            'router_id' => 'inside-log',
            'logged_at' => now()->subMinute(),
            'topics' => 'system',
            'message' => 'inside-window',
            'severity' => 'info',
        ]);
        RouterLog::query()->create([
            'mikrotik_session_id' => $session->id,
            'router_id' => 'outside-log',
            'logged_at' => now()->subHours(3),
            'topics' => 'system',
            'message' => 'outside-window',
            'severity' => 'info',
        ]);
        SecurityEvent::query()->create([
            'mikrotik_session_id' => $session->id,
            'occurred_at' => now()->subMinute(),
            'severity' => 'warning',
            'category' => 'service',
            'title' => 'inside-event',
            'detail' => 'recent',
        ]);
        SecurityEvent::query()->create([
            'mikrotik_session_id' => $session->id,
            'occurred_at' => now()->subHours(3),
            'severity' => 'warning',
            'category' => 'service',
            'title' => 'outside-event',
            'detail' => 'old',
        ]);
        DhcpLease::query()->create([
            'mikrotik_session_id' => $session->id,
            'router_id' => 'recent-lease',
            'address' => '10.0.0.8',
            'status' => 'bound',
            'last_seen' => '30s',
        ]);
        DhcpLease::query()->create([
            'mikrotik_session_id' => $session->id,
            'router_id' => 'stale-lease',
            'address' => '10.0.0.2',
            'status' => 'bound',
            'last_seen' => '2d',
        ]);

        $this->actingAs($user)
            ->get('/utama/logs?minutes=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('minutes', 5)
                ->has('logs', 1)
                ->where('logs.0.message', 'inside-window'));

        $this->actingAs($user)
            ->get('/utama/security?minutes=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('minutes', 5)
                ->has('events', 1)
                ->where('events.0.title', 'inside-event'));

        $this->actingAs($user)
            ->get('/utama/dhcp?minutes=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('minutes', 5)
                ->has('leases', 1)
                ->where('leases.0.address', '10.0.0.8'));

        $this->actingAs($user)
            ->get('/utama?minutes=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs', 1)
                ->where('logs.0.message', 'inside-window')
                ->has('events', 1)
                ->where('events.0.title', 'inside-event'));

        $this->actingAs($user)
            ->get('/utama/logs?live=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('live', true)
                ->has('logs', 2));

        $this->actingAs($user)
            ->get('/utama/security?live=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('live', true)
                ->has('events', 2));

        $this->actingAs($user)
            ->get('/utama/dhcp?live=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('live', true)
                ->has('leases', 2));
    }

    public function test_live_chart_span_is_a_day_a_week_or_a_month(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/?live=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('live', true)->where('minutes', 1440));

        $this->actingAs($user)
            ->get('/bandwidth?live=1&span=10080')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('live', true)->where('minutes', 10080));

        $this->actingAs($user)
            ->get('/?live=1&span=30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('live', true)->where('minutes', 1440));
    }

    public function test_a_long_chart_averages_samples_without_loading_every_row(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'Utama',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        InterfaceSample::query()->create([
            'mikrotik_session_id' => $session->id,
            'name' => 'ether1',
            'sampled_at' => now()->subHours(5),
            'rx_bps' => 1000,
            'tx_bps' => 500,
        ]);
        InterfaceSample::query()->create([
            'mikrotik_session_id' => $session->id,
            'name' => 'ether1',
            'sampled_at' => now()->subMinutes(5),
            'rx_bps' => 3000,
            'tx_bps' => 1500,
        ]);

        $series = app(AnalyticsQuery::class)->interfaceSeries($session->id, 'ether1', now()->subDay(), now());

        $this->assertCount(2, $series);
        $this->assertSame(1000, $series[0]['rx']);
        $this->assertSame(3000, $series[1]['rx']);
    }

    public function test_overview_charts_follow_the_selected_span(): void
    {
        $user = User::factory()->create();
        $session = MikrotikSession::query()->create([
            'name' => 'Utama',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        $this->session(['mikrotik_session_id' => $session->id]);

        foreach ([
            ['*1', '10.10.10.10', 'youtube.com', 'YouTube', now()->subHour()],
            ['*2', '10.10.10.11', 'youtube.com', 'YouTube', now()->subHour()],
            ['*3', '10.10.10.10', 'google.com', 'Google', now()->subMinutes(30)],
            ['*4', '10.10.10.12', 'netflix.com', 'Netflix', now()->subDays(3)],
        ] as [$id, $address, $domain, $app, $at]) {
            DnsQuery::query()->create([
                'mikrotik_session_id' => $session->id,
                'router_id' => $id,
                'logged_at' => $at,
                'address' => $address,
                'domain' => $domain,
                'qtype' => 'A',
                'app' => $app,
            ]);
        }

        SecurityEvent::query()->create([
            'mikrotik_session_id' => $session->id,
            'occurred_at' => now()->subMinutes(20),
            'severity' => 'info',
            'category' => 'bandwidth',
            'title' => 'Klien menyentuh batas bandwidth',
            'detail' => 'Nita (10.10.10.139) download 50.1 Mbps · 17% kapasitas',
            'context' => ['client' => ['name' => 'Nita', 'address' => '10.10.10.139', 'direction' => 'down', 'down' => 50_100_000]],
        ]);
        SecurityEvent::query()->create([
            'mikrotik_session_id' => $session->id,
            'occurred_at' => now()->subMinutes(40),
            'severity' => 'info',
            'category' => 'bandwidth',
            'title' => 'Klien menyentuh batas bandwidth',
            'detail' => 'Nita (10.10.10.139) download 40.0 Mbps · 13% kapasitas',
            'context' => ['client' => ['name' => 'Nita', 'address' => '10.10.10.139', 'direction' => 'down', 'down' => 40_000_000]],
        ]);
        SecurityEvent::query()->create([
            'mikrotik_session_id' => $session->id,
            'occurred_at' => now()->subDays(10),
            'severity' => 'info',
            'category' => 'bandwidth',
            'title' => 'Klien menyentuh batas bandwidth',
            'detail' => 'Lama (10.10.10.50) download 20.0 Mbps',
            'context' => ['client' => ['name' => 'Lama', 'address' => '10.10.10.50', 'direction' => 'down', 'down' => 20_000_000]],
        ]);

        $this->actingAs($user)
            ->get('/utama?live=1&span=1440')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('apps.0.name', 'YouTube')
                ->where('apps.0.users', 2)
                ->where('apps.0.queries', 2)
                ->where('apps.0.site', 'youtube.com')
                ->has('apps', 2)
                ->where('policy.0.name', 'Nita')
                ->where('policy.0.count', 2)
                ->where('policy.0.peak', 50100000)
                ->has('policy', 1));

        $this->actingAs($user)
            ->get('/utama?live=1&span=10080')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('apps', 3)
                ->has('policy', 1));

        $this->actingAs($user)
            ->get('/utama?live=1&span=43200')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('policy', 2));
    }
}
