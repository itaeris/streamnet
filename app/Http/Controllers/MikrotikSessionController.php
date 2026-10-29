<?php

namespace App\Http\Controllers;

use App\Models\DhcpLease;
use App\Models\InterfaceSample;
use App\Models\LiveSnapshot;
use App\Models\MikrotikSession;
use App\Models\RouterLog;
use App\Models\SecurityEvent;
use App\Models\SystemSample;
use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MikrotikSessionController extends Controller
{
    public function index(CurrentMikrotikSession $current, AnalyticsQuery $analytics): Response
    {
        $active = $current->resolve();
        $sessions = MikrotikSession::query()->orderBy('name')->orderBy('id')->get();
        $pulses = LiveSnapshot::query()
            ->where('key', 'pulse')
            ->whereIn('mikrotik_session_id', $sessions->pluck('id'))
            ->get()
            ->keyBy('mikrotik_session_id');

        return Inertia::render('Settings', [
            'pulse' => $active ? $analytics->pulse($active->id) : null,
            'currentSessionId' => $active?->id,
            'sessions' => $sessions->map(function (MikrotikSession $session) use ($pulses) {
                $payload = $pulses->get($session->id)?->payload ?? [];

                return [
                    'id' => $session->id,
                    'name' => $session->name,
                    'host' => $session->host,
                    'scheme' => $session->scheme,
                    'transport' => $session->transport(),
                    'port' => $session->port,
                    'username' => $session->username,
                    'wan_interface' => $session->wan_interface,
                    'enabled' => $session->enabled,
                    'online' => (bool) ($payload['online'] ?? false),
                    'identity' => $payload['system']['identity'] ?? null,
                    'error' => $payload['error'] ?? null,
                ];
            })->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MikrotikSession::query()->create($this->validated($request, true));

        return redirect()->route('settings');
    }

    public function update(Request $request, MikrotikSession $mikrotikSession): RedirectResponse
    {
        $mikrotikSession->update($this->validated($request, false));

        return redirect()->route('settings');
    }

    public function destroy(Request $request, MikrotikSession $mikrotikSession): RedirectResponse
    {
        DB::transaction(function () use ($mikrotikSession) {
            LiveSnapshot::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            SystemSample::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            InterfaceSample::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            DhcpLease::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            RouterLog::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            SecurityEvent::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            $mikrotikSession->delete();
        });

        if ((int) $request->session()->get('mikrotik_session_id') === $mikrotikSession->id) {
            $request->session()->forget('mikrotik_session_id');
        }

        return redirect()->route('settings');
    }

    public function activate(Request $request, MikrotikSession $mikrotikSession): RedirectResponse
    {
        $request->session()->put('mikrotik_session_id', $mikrotikSession->id);

        return redirect()->route('dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $request->merge([
            'port' => $request->filled('port') ? $request->integer('port') : null,
            'wan_interface' => $request->filled('wan_interface') ? trim((string) $request->input('wan_interface')) : null,
            'enabled' => $request->boolean('enabled'),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'host' => ['required', 'string', 'max:255', 'not_regex:/[\s\/]/'],
            'scheme' => ['required', 'in:http,https,api,api-ssl'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:64'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'wan_interface' => ['nullable', 'string', 'max:64'],
            'enabled' => ['boolean'],
        ], [
            'name.required' => 'Nama sesi wajib diisi.',
            'host.required' => 'Host wajib diisi.',
            'host.not_regex' => 'Host tidak boleh berisi spasi atau garis miring.',
            'username.required' => 'Username wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        if (! $creating && blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }
}
