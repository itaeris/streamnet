<?php

namespace Tests\Feature;

use App\Models\MikrotikSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_topology_map_uses_the_open_session_slug(): void
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

        $this->actingAs($user)
            ->get('/topology')
            ->assertRedirect('/sesi');

        $this->actingAs($user)
            ->withSession(['mikrotik_session_id' => MikrotikSession::query()->value('id')])
            ->get('/aeris-fti/topology')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Topology', false));
    }
}
