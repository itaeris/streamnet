<?php

namespace Tests\Feature;

use App\Models\MikrotikSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikrotikSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/pengaturan')->assertRedirect('/login');
    }

    public function test_operator_can_add_a_router_without_the_password_coming_back(): void
    {
        $user = User::factory()->create();
        $password = 'session-secret-value';

        $this->actingAs($user)
            ->post('/pengaturan/sesi', [
                'name' => 'Cabang',
                'host' => '10.20.30.1',
                'scheme' => 'http',
                'username' => 'admin',
                'password' => $password,
                'wan_interface' => 'ether1',
                'enabled' => true,
            ])
            ->assertRedirect('/sesi/cabang');

        $session = MikrotikSession::query()->where('host', '10.20.30.1')->first();
        $this->assertNotNull($session);
        $this->assertSame($password, $session->password);
        $this->assertNotSame($password, $session->getRawOriginal('password'));

        $this->actingAs($user)
            ->get('/sesi/cabang')
            ->assertOk()
            ->assertDontSee($password, false);
    }

    public function test_blank_password_keeps_the_stored_one(): void
    {
        $user = User::factory()->create();
        $session = MikrotikSession::query()->create([
            'name' => 'Pusat',
            'host' => '10.10.10.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'kept-secret',
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->put('/pengaturan/sesi/'.$session->id, [
                'name' => 'Pusat HO',
                'host' => '10.10.10.1',
                'scheme' => 'https',
                'username' => 'wiki',
                'password' => '',
                'enabled' => true,
            ])
            ->assertRedirect('/sesi/pusat-ho');

        $session->refresh();
        $this->assertSame('Pusat HO', $session->name);
        $this->assertSame('https', $session->scheme);
        $this->assertSame('kept-secret', $session->password);
    }

    public function test_the_console_waits_until_a_session_is_chosen(): void
    {
        $user = User::factory()->create();
        MikrotikSession::query()->create([
            'name' => 'AERIS | FTI',
            'host' => '10.10.10.1',
            'scheme' => 'api',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect('/sesi');

        $this->actingAs($user)
            ->get('/sesi')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sites', false)
                ->where('sessions.0.name', 'AERIS | FTI')
                ->where('sessions.0.unifi', false));

        $this->actingAs($user)
            ->get('/sesi/aeris-fti')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SessionSettings', false)
                ->where('session.name', 'AERIS | FTI')
                ->where('session.slug', 'aeris-fti')
                ->where('unifi.has_key', false));
    }

    public function test_activating_a_session_selects_it_on_the_dashboard(): void
    {
        $user = User::factory()->create();
        $first = MikrotikSession::query()->create([
            'name' => 'Satu',
            'host' => '10.1.1.1',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);
        $second = MikrotikSession::query()->create([
            'name' => 'Dua',
            'host' => '10.2.2.2',
            'scheme' => 'http',
            'username' => 'wiki',
            'password' => 'secret',
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->post('/pengaturan/sesi/'.$second->id.'/aktif')
            ->assertRedirect('/dua')
            ->assertSessionHas('mikrotik_session_id', $second->id);

        $this->actingAs($user)
            ->withSession(['mikrotik_session_id' => $second->id])
            ->get('/dua')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentSessionId', $second->id));

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_port_8728_uses_the_binary_api_even_when_scheme_says_http(): void
    {
        $session = MikrotikSession::query()->create([
            'name' => 'Rumah',
            'host' => '20.20.20.1',
            'scheme' => 'http',
            'port' => 8728,
            'username' => 'wikirumah',
            'password' => 'secret',
            'enabled' => true,
        ]);

        $connection = $session->connection();

        $this->assertSame('api', $session->transport());
        $this->assertSame('api', $connection['transport']);
        $this->assertSame('20.20.20.1', $connection['host']);
        $this->assertSame(8728, $connection['port']);
        $this->assertFalse($connection['tls']);
    }

    public function test_operator_can_save_an_api_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/pengaturan/sesi', [
                'name' => 'Rumah',
                'host' => '20.20.20.1',
                'scheme' => 'api',
                'port' => 8728,
                'username' => 'wikirumah',
                'password' => 'secret',
                'wan_interface' => 'ether1',
                'enabled' => true,
            ])
            ->assertRedirect('/sesi/rumah');

        $session = MikrotikSession::query()->where('host', '20.20.20.1')->first();
        $this->assertNotNull($session);
        $this->assertSame('api', $session->scheme);
        $this->assertSame('api', $session->transport());
    }
}
