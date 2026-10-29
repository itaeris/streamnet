<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the dashboard admin once. Later deploys leave the password alone.
     */
    public function run(): void
    {
        $this->seedAdmin(
            (string) env('DASHBOARD_EMAIL', 'it@aerisbeaute.com'),
            (string) env('DASHBOARD_NAME', 'IT'),
            env('DASHBOARD_PASSWORD') ?: 'aerisbeaute',
        );
    }

    public function seedAdmin(string $email, string $name, mixed $password): void
    {
        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('DASHBOARD_PASSWORD wajib diisi untuk membuat admin pertama.');
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
    }
}
