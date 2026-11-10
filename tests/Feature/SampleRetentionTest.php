<?php

namespace Tests\Feature;

use App\Models\DnsQuery;
use App\Models\InterfaceSample;
use App\Models\MikrotikSession;
use App\Models\SecurityEvent;
use App\Services\SampleRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_older_than_thirty_days_is_deleted(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'AERIS',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);

        InterfaceSample::query()->create($this->sample($session->id, now()->subDays(31), 'ether1'));
        InterfaceSample::query()->create($this->sample($session->id, now()->subDays(2), 'ether1'));
        DnsQuery::query()->create([
            'mikrotik_session_id' => $session->id,
            'router_id' => '*old',
            'logged_at' => now()->subDays(31),
            'address' => '10.10.10.10',
            'domain' => 'old.example',
            'qtype' => 'A',
            'app' => 'Example',
        ]);
        DnsQuery::query()->create([
            'mikrotik_session_id' => $session->id,
            'router_id' => '*new',
            'logged_at' => now()->subDay(),
            'address' => '10.10.10.10',
            'domain' => 'new.example',
            'qtype' => 'A',
            'app' => 'Example',
        ]);
        SecurityEvent::query()->create([
            'mikrotik_session_id' => $session->id,
            'occurred_at' => now()->subDays(40),
            'severity' => 'warning',
            'category' => 'firewall',
            'title' => 'Lama',
        ]);

        app(SampleRetention::class)->prune();

        $this->assertSame(['ether1'], InterfaceSample::query()->pluck('name')->all());
        $this->assertSame(['*new'], DnsQuery::query()->pluck('router_id')->all());
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(int $sessionId, mixed $at, string $name): array
    {
        return [
            'mikrotik_session_id' => $sessionId,
            'sampled_at' => $at,
            'name' => $name,
            'type' => 'ether',
            'running' => true,
            'disabled' => false,
            'rx_bytes' => 1,
            'tx_bytes' => 1,
            'rx_bps' => 1,
            'tx_bps' => 1,
        ];
    }
}
