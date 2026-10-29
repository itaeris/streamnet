<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('octane:start --watch --host=127.0.0.1 --port=8000', 'server')->blue();
            DevCommands::artisan('reverb:start', 'reverb')->yellow();
            DevCommands::artisan('mikrotik:watch', 'mikrotik')->green();
            DevCommands::artisan('mikrotik:torch', 'torch')->orange();
        }
    }
}
