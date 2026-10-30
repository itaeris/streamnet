<?php

namespace Tests\Feature;

use App\Models\DnsFilterWindow;
use App\Models\MikrotikSession;
use App\Models\User;
use App\Services\DnsFilterSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DnsFilterScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shortest_window_wins_and_overnight_wraps(): void
    {
        $session = $this->router();
        $this->workday($session);

        $schedule = app(DnsFilterSchedule::class);
        $windows = DnsFilterWindow::query()->where('mikrotik_session_id', $session->id)->get();

        $this->assertSame('Jam kerja', $schedule->current($windows, $this->at('09:00'))['label']);
        $this->assertTrue($schedule->current($windows, $this->at('10:00'))['blocked']);
        $this->assertFalse($schedule->current($windows, $this->at('12:30'))['blocked']);
        $this->assertSame('Jam istirahat', $schedule->current($windows, $this->at('12:30'))['label']);
        $this->assertTrue($schedule->current($windows, $this->at('17:30'))['blocked']);
        $this->assertNull($schedule->current($windows, $this->at('17:45')));
        $this->assertSame('Jam kosong', $schedule->current($windows, $this->at('20:00'))['label']);
        $this->assertTrue($schedule->current($windows, $this->at('08:00'))['blocked']);
    }

    public function test_lunch_opens_a_blocked_site(): void
    {
        $session = $this->router();
        $this->workday($session);
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/layer7-protocol') => [
                    ['.id' => '*L1', 'name' => 'streamnet-block', 'regexp' => '^.+(netflix\\.com).*$', 'comment' => 'StreamNet'],
                ],
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/filter') => [
                    ['.id' => '*R1', 'chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp', 'dst-port' => '80,443', 'layer7-protocol' => 'streamnet-block', 'comment' => 'StreamNet'],
                ],
                default => [],
            };

            return Http::response($body);
        });

        $this->travelTo($this->at('12:30'));
        app(DnsFilterSchedule::class)->enforce($session);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/layer7-protocol/set')
            && ($request['regexp'] ?? null) === '^.+(streamnet-hold\\.invalid).*$'
            && ($request['comment'] ?? null) === 'StreamNet off:netflix.com');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ip/firewall/filter/set')
            && ($request['.id'] ?? null) === '*R1'
            && ($request['disabled'] ?? null) === 'yes');
    }

    public function test_a_matching_block_leaves_an_already_blocked_site_alone(): void
    {
        $session = $this->router();
        $this->workday($session);
        Http::fake(fn () => Http::response([
            ['.id' => '*L1', 'name' => 'streamnet-block', 'regexp' => '^.+(netflix\\.com).*$', 'comment' => 'StreamNet'],
        ]));

        $this->travelTo($this->at('10:00'));
        app(DnsFilterSchedule::class)->enforce($session);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/set'));
    }

    public function test_saving_a_schedule_stores_the_windows_and_removing_the_site_clears_them(): void
    {
        $user = User::factory()->create();
        $session = $this->router();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            $body = match (true) {
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/layer7-protocol') => [
                    ['.id' => '*L1', 'name' => 'streamnet-block', 'regexp' => '^.+(netflix\\.com).*$', 'comment' => 'StreamNet'],
                ],
                $request->method() === 'GET' && str_ends_with($path, '/ip/firewall/filter') => [
                    ['.id' => '*R1', 'chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp', 'dst-port' => '80,443', 'layer7-protocol' => 'streamnet-block', 'comment' => 'StreamNet'],
                ],
                default => [],
            };

            return Http::response($body);
        });

        $this->actingAs($user)
            ->withSession(['mikrotik_session_id' => $session->id])
            ->put('/dns-filter/schedule', [
                'domain' => 'https://www.netflix.com/browse',
                'windows' => [
                    ['start' => '09:00', 'end' => '11:59', 'blocked' => true, 'label' => 'Jam kerja'],
                    ['start' => '12:00', 'end' => '12:59', 'blocked' => false, 'label' => 'Jam istirahat'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_filter_windows', [
            'mikrotik_session_id' => $session->id,
            'domain' => 'netflix.com',
            'starts_at' => '12:00',
            'blocked' => false,
            'label' => 'Jam istirahat',
        ]);

        $this->actingAs($user)
            ->withSession(['mikrotik_session_id' => $session->id])
            ->delete('/dns-filter', ['domain' => 'netflix.com'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('dns_filter_windows', [
            'mikrotik_session_id' => $session->id,
            'domain' => 'netflix.com',
        ]);
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse('2026-10-30 '.$time.':00', 'Asia/Jakarta');
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

    private function workday(MikrotikSession $session): void
    {
        foreach ([
            ['09:00', '11:59', true, 'Jam kerja'],
            ['12:00', '12:59', false, 'Jam istirahat'],
            ['13:00', '17:30', true, 'Jam kerja'],
            ['18:00', '09:00', true, 'Jam kosong'],
        ] as [$start, $end, $blocked, $label]) {
            DnsFilterWindow::query()->create([
                'mikrotik_session_id' => $session->id,
                'domain' => 'netflix.com',
                'starts_at' => $start,
                'ends_at' => $end,
                'blocked' => $blocked,
                'label' => $label,
            ]);
        }
    }
}
