<?php

namespace Tests\Feature;

use App\Models\DhcpLease;
use App\Models\MikrotikSession;
use App\Models\RouterLog;
use App\Models\SecurityEvent;
use App\Models\User;
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
            ->get('/logs?minutes=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('minutes', 5)
                ->has('logs', 1)
                ->where('logs.0.message', 'inside-window'));

        $this->actingAs($user)
            ->get('/security?minutes=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('minutes', 5)
                ->has('events', 1)
                ->where('events.0.title', 'inside-event'));

        $this->actingAs($user)
            ->get('/dhcp?minutes=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('minutes', 5)
                ->has('leases', 1)
                ->where('leases.0.address', '10.0.0.8'));

        $this->actingAs($user)
            ->get('/?minutes=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs', 1)
                ->where('logs.0.message', 'inside-window')
                ->has('events', 1)
                ->where('events.0.title', 'inside-event'));

        $this->actingAs($user)
            ->get('/logs?live=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('live', true)
                ->has('logs', 2));

        $this->actingAs($user)
            ->get('/security?live=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('live', true)
                ->has('events', 2));

        $this->actingAs($user)
            ->get('/dhcp?live=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('live', true)
                ->has('leases', 2));
    }
}
