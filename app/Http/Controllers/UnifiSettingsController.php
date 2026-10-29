<?php

namespace App\Http\Controllers;

use App\Models\MikrotikSession;
use App\Models\UnifiConnection;
use App\Services\Mikrotik\CurrentMikrotikSession;
use App\Services\Unifi\UnifiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UnifiSettingsController extends Controller
{
    public function update(Request $request, CurrentMikrotikSession $current, UnifiClient $unifi): RedirectResponse
    {
        $active = $current->resolve();
        if (! $active) {
            return redirect()->route('sites');
        }

        return $this->save($request, $active, $unifi);
    }

    public function saveNamed(Request $request, string $session, UnifiClient $unifi): RedirectResponse
    {
        return $this->save($request, $this->named($session), $unifi);
    }

    public function save(Request $request, MikrotikSession $session, UnifiClient $unifi): RedirectResponse
    {
        $unifi->useSession($session->id);
        $connection = UnifiConnection::forSession($session->id);
        $data = $request->validate([
            'url' => ['required', 'url:https,http', 'max:255'],
            'api_key' => [$connection ? 'nullable' : 'required', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:120'],
            'verify_tls' => ['boolean'],
        ], [
            'url.required' => 'Alamat console UniFi wajib diisi.',
            'url.url' => 'Alamat harus diawali https:// atau http://.',
            'api_key.required' => 'API key wajib diisi.',
        ]);

        $data['url'] = rtrim($data['url'], '/');
        $data['site'] = filled($data['site'] ?? null) ? trim($data['site']) : null;
        $data['verify_tls'] = $request->boolean('verify_tls');
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        $data['mikrotik_session_id'] = $session->id;
        $connection ? $connection->update($data) : UnifiConnection::query()->create($data);
        $unifi->forget();

        return redirect()->route('sites.show', ['session' => $session->name]);
    }

    public function destroy(CurrentMikrotikSession $current, UnifiClient $unifi): RedirectResponse
    {
        $active = $current->resolve();
        if (! $active) {
            return redirect()->route('sites');
        }

        return $this->disconnect($active, $unifi);
    }

    public function disconnectNamed(string $session, UnifiClient $unifi): RedirectResponse
    {
        return $this->disconnect($this->named($session), $unifi);
    }

    public function disconnect(MikrotikSession $session, UnifiClient $unifi): RedirectResponse
    {
        $unifi->useSession($session->id);
        UnifiConnection::query()->where('mikrotik_session_id', $session->id)->delete();
        $unifi->forget();

        return redirect()->route('sites.show', ['session' => $session->name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function named(string $session): MikrotikSession
    {
        return MikrotikSession::query()->where('name', $session)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(UnifiClient $unifi): array
    {
        $snapshot = $unifi->snapshot();
        if (! ($snapshot['ok'] ?? false)) {
            return [
                'connected' => false,
                'configured' => (bool) ($snapshot['configured'] ?? false),
                'reason' => $snapshot['error'] ?? null,
            ];
        }

        return [
            'connected' => true,
            'configured' => true,
            'site' => $snapshot['site']['name'] ?? null,
            'access_points' => count(array_filter($snapshot['devices'] ?? [], fn (array $device) => $device['access_point'] ?? false)),
            'clients' => count($snapshot['clients'] ?? []),
        ];
    }
}
