<?php

namespace App\Http\Middleware;

use App\Models\MikrotikSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChooseMikrotikSession
{
    /**
     * Send the operator to the session picker before the console.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || $request->session()->has('mikrotik_session_id') || ! $request->isMethod('GET')) {
            return $next($request);
        }

        if ($request->is('sesi', 'pengaturan', 'pengaturan/*', 'logout', 'up')) {
            return $next($request);
        }

        if (! MikrotikSession::query()->exists()) {
            return $next($request);
        }

        return redirect()->route('sites');
    }
}
