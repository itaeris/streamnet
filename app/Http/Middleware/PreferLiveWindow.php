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
        if (! $request->isMethod('GET') || ! $request->is('/', 'bandwidth', 'dhcp', 'dns', 'logs', 'security', 'pengaturan', 'pengaturan/sesi')) {
            return $next($request);
        }

        if ($request->hasAny(['live', 'minutes', 'from', 'to']) || $request->cookie('board_live') !== '1') {
            return $next($request);
        }

        return redirect($request->fullUrlWithQuery(['live' => 1]));
    }
}
