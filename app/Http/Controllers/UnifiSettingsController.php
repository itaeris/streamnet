<?php

namespace App\Http\Controllers;

use App\Models\UnifiConnection;
use App\Services\AnalyticsQuery;
use App\Services\Mikrotik\CurrentMikrotikSession;
use App\Services\Unifi\UnifiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnifiSettingsController extends Controller
{
    public function show(CurrentMikrotikSession $current, AnalyticsQuery $analytics, UnifiClient $unifi): Response
    {
        $connection = UnifiConnection::current();
        $active = $current->resolve();

        return Inertia::render('Settings', [
            'section' => 'unifi',
            'pulse' => $active ? $analytics->pulse($active->id) : null,
            'unifi' => [
                'url' => $connection?->url,
                'site' => $connection?->site,
                'verify_tls' => $connection?->verify_tls ?? true,
                'has_key' => $connection !== null,
            ],
            'unifiStatus' => fn () => $this->status($unifi),
        ]);
    }

    public function update(Request $request, UnifiClient $unifi): RedirectResponse
    {
        $connection = UnifiConnection::query()->first();
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

        $connection ? $connection->update($data) : UnifiConnection::query()->create($data);
        $unifi->forget();

        return back();
    }

    public function destroy(UnifiClient $unifi): RedirectResponse
    {
        UnifiConnection::query()->delete();
        $unifi->forget();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function status(UnifiClient $unifi): array
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
