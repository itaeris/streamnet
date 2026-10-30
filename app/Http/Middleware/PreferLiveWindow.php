<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreferLiveWindow
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $this->tracksLive($request)) {
            return $next($request);
        }

        if ($request->hasAny(['live', 'minutes', 'from', 'to']) || $request->cookie('board_live') !== '1') {
            return $next($request);
        }

        return redirect($request->fullUrlWithQuery(['live' => 1]));
    }

    private function tracksLive(Request $request): bool
    {
        $known = [
            '/',
            'bandwidth',
            'dhcp',
            'dns',
            'dns-filter',
            'logs',
            'security',
            'topology',
            'pengaturan',
            'pengaturan/sesi',
            'pengaturan/unifi',
            'pengaturan/pengguna',
        ];
        $path = $request->path();
        if (in_array($path, $known, true)) {
            return true;
        }

        $site = $request->route('site');
        if (! is_string($site) || $site === '' || ! str_starts_with($path, $site)) {
            return false;
        }

        $rest = trim(substr($path, strlen($site)), '/');

        return $rest === '' || in_array($rest, ['bandwidth', 'dhcp', 'dns', 'dns-filter', 'logs', 'security', 'topology'], true);
    }
}
