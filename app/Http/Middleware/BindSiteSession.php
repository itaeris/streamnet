<?php

namespace App\Http\Middleware;

use App\Models\MikrotikSession;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindSiteSession
{
    /**
     * The open session lives in the URL: /aeris-fti, /aeris-fti/bandwidth.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        if (is_string($site) && $site !== '') {
            $session = MikrotikSession::query()->where('slug', $site)->first();
            if (! $session) {
                abort(404);
            }

            if ($request->hasSession()) {
                $request->session()->put('mikrotik_session_id', $session->id);
            }

            return $next($request);
        }

        if ($request->user() && $request->isMethod('GET') && $this->bareConsole($request)) {
            $current = app(CurrentMikrotikSession::class)->resolve();
            if ($current?->slug) {
                $suffix = $request->path() === '/' ? '' : '/'.$request->path();
                $target = '/'.$current->slug.$suffix;
                if ($query = $request->getQueryString()) {
                    $target .= '?'.$query;
                }

                return redirect($target);
            }
        }

        return $next($request);
    }

    private function bareConsole(Request $request): bool
    {
        return $request->is(
            '/',
            'bandwidth',
            'dhcp',
            'dns',
            'dns-filter',
            'logs',
            'security',
            'access-points',
            'bandwidth-policy',
            'topology',
        );
    }
}
