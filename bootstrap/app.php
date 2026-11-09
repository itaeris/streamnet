<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->trustHosts(at: function () {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return array_values(array_unique(array_filter([
                '^localhost$',
                '^127\.0\.0\.1$',
                '^\[::1\]$',
                is_string($host) && $host !== '' ? '^'.preg_quote($host, '/').'$' : null,
            ])));
        }, subdomains: false);
        $middleware->encryptCookies(except: ['board_live']);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        $middleware->web(append: [
            \App\Http\Middleware\ChooseMikrotikSession::class,
            \App\Http\Middleware\BindSiteSession::class,
            \App\Http\Middleware\PreferLiveWindow::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
