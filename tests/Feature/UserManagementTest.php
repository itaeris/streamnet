<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_add_a_user_who_can_then_log_in(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/pengaturan/pengguna', [
                'name' => 'Teknisi',
                'email' => 'teknisi@example.test',
                'password' => 'rahasia-baru',
                'password_confirmation' => 'rahasia-baru',
            ])
            ->assertRedirect('/pengaturan/pengguna')
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'teknisi@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('rahasia-baru', $user->password));

        auth()->logout();
        $this->post('/login', ['email' => 'teknisi@example.test', 'password' => 'rahasia-baru'])->assertRedirect('/sesi');
    }

    public function test_editing_without_a_password_keeps_it(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['password' => 'lama-sekali']);

        $this->actingAs($admin)
            ->put("/pengaturan/pengguna/{$user->id}", ['name' => 'Nama Baru', 'email' => $user->email, 'password' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('Nama Baru', $user->fresh()->name);
        $this->assertTrue(Hash::check('lama-sekali', $user->fresh()->password));
    }

    public function test_email_must_be_unique_and_self_cannot_be_deleted(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($admin)
            ->post('/pengaturan/pengguna', [
                'name' => 'Duplikat',
                'email' => $other->email,
                'password' => 'rahasia-baru',
                'password_confirmation' => 'rahasia-baru',
            ])
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)->delete("/pengaturan/pengguna/{$admin->id}")->assertSessionHasErrors('user');
        $this->assertNotNull($admin->fresh());

        $this->actingAs($admin)->delete("/pengaturan/pengguna/{$other->id}")->assertRedirect('/pengaturan/pengguna');
        $this->assertNull($other->fresh());
    }

    public function test_replacing_a_password_drops_that_users_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->create();
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'stolen-session',
            'user_id' => $user->id,
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)
            ->put("/pengaturan/pengguna/{$user->id}", [
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'rahasia-baru',
                'password_confirmation' => 'rahasia-baru',
            ])
            ->assertRedirect('/pengaturan/pengguna');

        $this->assertDatabaseMissing('sessions', ['id' => 'stolen-session']);
    }
}
