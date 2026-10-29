<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_is_created_once_and_a_later_seed_keeps_the_password(): void
    {
        $seeder = new AdminUserSeeder;

        $seeder->seedAdmin('admin@stream.test', 'Wiki', 'secret-pass');

        $user = User::query()->where('email', 'admin@stream.test')->firstOrFail();
        $this->assertSame('Wiki', $user->name);
        $this->assertTrue(Hash::check('secret-pass', $user->password));

        $seeder->seedAdmin('admin@stream.test', 'Wiki', 'other-pass');

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('secret-pass', $user->fresh()->password));
    }

    public function test_a_missing_password_does_not_create_the_admin(): void
    {
        $this->expectException(RuntimeException::class);

        (new AdminUserSeeder)->seedAdmin('new@stream.test', 'Wiki', null);
    }
}
