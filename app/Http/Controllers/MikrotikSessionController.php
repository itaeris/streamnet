<?php

namespace App\Http\Controllers;

use App\Models\DhcpLease;
use App\Models\InterfaceSample;
use App\Models\LiveSnapshot;
use App\Models\QueueSample;
use App\Models\MikrotikSession;
use App\Models\RouterLog;
use App\Models\SecurityEvent;
use App\Models\SystemSample;
use App\Models\UnifiConnection;
use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use App\Services\Unifi\UnifiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MikrotikSessionController extends Controller
{
    public function sites(AnalyticsQuery $analytics): Response
    {
        $sessions = MikrotikSession::query()->orderBy('name')->orderBy('id')->get();
        $pulses = LiveSnapshot::query()
            ->where('key', 'pulse')
            ->whereIn('mikrotik_session_id', $sessions->pluck('id'))
            ->get()
            ->keyBy('mikrotik_session_id');
        $connected = UnifiConnection::query()
            ->whereIn('mikrotik_session_id', $sessions->pluck('id'))
            ->pluck('mikrotik_session_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return Inertia::render('Sites', [
            'sessions' => $sessions->map(function (MikrotikSession $session) use ($pulses, $connected, $analytics) {
                $payload = $pulses->get($session->id)?->payload ?? [];
                $wan = $payload['wan']['name'] ?? $session->wan_interface;

                return [
                    'id' => $session->id,
                    'name' => $session->name,
                    'slug' => $session->slug,
                    'host' => $session->host,
                    'enabled' => $session->enabled,
                    'online' => (bool) ($payload['online'] ?? false),
                    'identity' => $payload['system']['identity'] ?? null,
                    'board' => $payload['system']['board'] ?? null,
                    'sampled_label' => $payload['sampled_label'] ?? null,
                    'rx' => $payload['wan']['rx_bps'] ?? null,
                    'tx' => $payload['wan']['tx_bps'] ?? null,
                    'series' => $analytics->interfaceSeries($session->id, is_string($wan) ? $wan : null, now()->subHours(6), now(), 48),
                    'unifi' => in_array($session->id, $connected, true),
                ];
            })->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SessionSettings', [
            'session' => null,
        ]);
    }

    public function edit(string $session, UnifiClient $unifi, UnifiSettingsController $settings): Response|RedirectResponse
    {
        $model = $this->named($session);
        if ($session !== $model->slug) {
            return redirect()->route('sites.show', ['session' => $model->slug]);
        }
        $connection = UnifiConnection::forSession($model->id);
        $unifi->useSession($model->id);

        return Inertia::render('SessionSettings', [
            'session' => $this->detail($model),
            'unifi' => [
                'url' => $connection?->url,
                'site' => $connection?->site,
                'verify_tls' => $connection?->verify_tls ?? true,
                'has_key' => $connection !== null,
            ],
            'unifiStatus' => fn () => $settings->status($unifi),
        ]);
    }

    public function account(CurrentMikrotikSession $current, AnalyticsQuery $analytics): Response
    {
        $active = $current->resolve();

        return Inertia::render('Settings', [
            'section' => 'app',
            'pulse' => $active ? $analytics->pulse($active->id) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $session = MikrotikSession::query()->create($this->validated($request, true));

        return redirect()->route('sites.show', ['session' => $session->slug]);
    }

    public function updateNamed(Request $request, string $session): RedirectResponse
    {
        return $this->update($request, $this->named($session));
    }

    public function update(Request $request, MikrotikSession $mikrotikSession): RedirectResponse
    {
        $mikrotikSession->update($this->validated($request, false, $mikrotikSession));

        return redirect()->route('sites.show', ['session' => $mikrotikSession->slug]);
    }

    public function destroyNamed(Request $request, string $session): RedirectResponse
    {
        return $this->destroy($request, $this->named($session));
    }

    public function destroy(Request $request, MikrotikSession $mikrotikSession): RedirectResponse
    {
        DB::transaction(function () use ($mikrotikSession) {
            LiveSnapshot::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            SystemSample::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            InterfaceSample::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            QueueSample::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            DhcpLease::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            RouterLog::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            SecurityEvent::query()->where('mikrotik_session_id', $mikrotikSession->id)->delete();
            $mikrotikSession->delete();
        });

        if ((int) $request->session()->get('mikrotik_session_id') === $mikrotikSession->id) {
            $request->session()->forget('mikrotik_session_id');
        }

        return redirect()->route('sites');
    }

    public function activate(Request $request, MikrotikSession $mikrotikSession): RedirectResponse
    {
        $request->session()->put('mikrotik_session_id', $mikrotikSession->id);

        return redirect()->route('dashboard', ['site' => $mikrotikSession->slug]);
    }

    /**
     * @return array<string, mixed>
     */
    private function named(string $session): MikrotikSession
    {
        return MikrotikSession::query()->where('slug', $session)->first()
            ?? MikrotikSession::query()->where('name', $session)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(MikrotikSession $session): array
    {
        $payload = LiveSnapshot::query()
            ->where('key', 'pulse')
            ->where('mikrotik_session_id', $session->id)
            ->first()
            ?->payload ?? [];

        return [
            'id' => $session->id,
            'name' => $session->name,
            'slug' => $session->slug,
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
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating, ?MikrotikSession $current = null): array
    {
        $request->merge([
            'port' => $request->filled('port') ? $request->integer('port') : null,
            'wan_interface' => $request->filled('wan_interface') ? trim((string) $request->input('wan_interface')) : null,
            'enabled' => $request->boolean('enabled'),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'not_regex:/[\/?#]/', Rule::notIn(['baru']), Rule::unique('mikrotik_sessions', 'name')->ignore($current?->id)],
            'host' => ['required', 'string', 'max:255', 'not_regex:/[\s\/]/'],
            'scheme' => ['required', 'in:http,https,api,api-ssl'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:64'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'wan_interface' => ['nullable', 'string', 'max:64'],
            'enabled' => ['boolean'],
        ], [
            'name.required' => 'Nama sesi wajib diisi.',
            'name.unique' => 'Nama sesi sudah dipakai.',
            'name.not_in' => 'Nama "baru" dipakai untuk halaman tambah sesi.',
            'name.not_regex' => 'Nama tidak boleh berisi garis miring.',
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
