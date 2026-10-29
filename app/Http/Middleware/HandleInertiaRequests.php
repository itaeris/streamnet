<?php

namespace App\Http\Middleware;

use App\Models\MikrotikSession;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $current = null;
        $sessions = [];

        if ($request->user()) {
            $current = app(CurrentMikrotikSession::class)->resolve();
            $sessions = MikrotikSession::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'slug', 'host', 'enabled'])
                ->map(fn (MikrotikSession $session) => [
                    'id' => $session->id,
                    'name' => $session->name,
                    'slug' => $session->slug,
                    'host' => $session->host,
                    'enabled' => $session->enabled,
                ])
                ->all();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
            ],
            'sessions' => $sessions,
            'currentSessionId' => $current?->id,
            'currentSessionSlug' => $current?->slug,
        ];
    }
}
