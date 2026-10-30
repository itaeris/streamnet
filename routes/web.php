<?php

use App\Http\Controllers\AccessPointController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BandwidthController;
use App\Http\Controllers\BandwidthPolicyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DhcpController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DnsFilterController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MikrotikSessionController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SiteLogoController;
use App\Http\Controllers\TopologyController;
use App\Http\Controllers\UnifiSettingsController;
use App\Http\Controllers\UserController;
use App\Services\Mikrotik\CurrentMikrotikSession;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    Route::get('/sesi', [MikrotikSessionController::class, 'sites'])->name('sites');
    Route::get('/sesi/baru', [MikrotikSessionController::class, 'create'])->name('sites.create');
    Route::post('/sesi', [MikrotikSessionController::class, 'store'])->name('sites.store');
    Route::put('/sesi/{session}/unifi', [UnifiSettingsController::class, 'saveNamed'])->where('session', '.+')->name('sites.unifi.update');
    Route::delete('/sesi/{session}/unifi', [UnifiSettingsController::class, 'disconnectNamed'])->where('session', '.+')->name('sites.unifi.destroy');
    Route::put('/sesi/{session}', [MikrotikSessionController::class, 'updateNamed'])->where('session', '.+')->name('sites.update');
    Route::delete('/sesi/{session}', [MikrotikSessionController::class, 'destroyNamed'])->where('session', '.+')->name('sites.destroy');
    Route::get('/sesi/{session}', [MikrotikSessionController::class, 'edit'])->where('session', '.+')->name('sites.show');
    Route::get('/', DashboardController::class);
    Route::get('/bandwidth', BandwidthController::class);
    Route::get('/dhcp', DhcpController::class);
    Route::get('/dhcp/dns', [DhcpController::class, 'dns'])->name('dhcp.dns');
    Route::put('/dhcp/alias', [DhcpController::class, 'updateComment'])->name('dhcp.alias');
    Route::get('/dns', DnsController::class);
    Route::get('/dns/logo', SiteLogoController::class)->middleware('throttle:120,1');
    Route::get('/dns-filter', [DnsFilterController::class, 'index']);
    Route::post('/dns-filter', [DnsFilterController::class, 'store'])->name('dns-filter.store');
    Route::put('/dns-filter', [DnsFilterController::class, 'update'])->name('dns-filter.update');
    Route::put('/dns-filter/schedule', [DnsFilterController::class, 'schedule'])->name('dns-filter.schedule');
    Route::delete('/dns-filter', [DnsFilterController::class, 'destroy'])->name('dns-filter.destroy');
    Route::get('/access-points', AccessPointController::class);
    Route::get('/topology', TopologyController::class);
    Route::get('/bandwidth-policy', [BandwidthPolicyController::class, 'index']);
    Route::put('/bandwidth-policy', [BandwidthPolicyController::class, 'update'])->name('bandwidth-policy.update');
    Route::get('/bandwidth-policy/context', [BandwidthPolicyController::class, 'context'])->name('bandwidth-policy.context');
    Route::get('/bandwidth-policy/incidents/export', [BandwidthPolicyController::class, 'export'])->name('bandwidth-policy.export');
    Route::get('/logs', LogController::class);
    Route::get('/security', SecurityController::class);
    Route::get('/pengaturan', [MikrotikSessionController::class, 'account'])->name('settings');
    Route::get('/pengaturan/sesi', fn () => redirect()->route('sites'))->name('settings.sessions');
    Route::put('/pengaturan/password', [LoginController::class, 'updatePassword'])->name('settings.password');
    Route::get('/pengaturan/pengguna', [UserController::class, 'index'])->name('settings.users');
    Route::post('/pengaturan/pengguna', [UserController::class, 'store'])->name('settings.users.store');
    Route::put('/pengaturan/pengguna/{user}', [UserController::class, 'update'])->name('settings.users.update');
    Route::delete('/pengaturan/pengguna/{user}', [UserController::class, 'destroy'])->name('settings.users.destroy');
    Route::get('/pengaturan/unifi', function (CurrentMikrotikSession $current) {
        $active = $current->resolve();

        return $active
            ? redirect()->route('sites.show', ['session' => $active->slug])
            : redirect()->route('sites');
    })->name('settings.unifi');
    Route::put('/pengaturan/unifi', [UnifiSettingsController::class, 'update'])->name('settings.unifi.update');
    Route::delete('/pengaturan/unifi', [UnifiSettingsController::class, 'destroy'])->name('settings.unifi.destroy');
    Route::post('/pengaturan/sesi', [MikrotikSessionController::class, 'store'])->name('settings.sessions.store');
    Route::put('/pengaturan/sesi/{mikrotikSession}', [MikrotikSessionController::class, 'update'])->name('settings.sessions.update');
    Route::delete('/pengaturan/sesi/{mikrotikSession}', [MikrotikSessionController::class, 'destroy'])->name('settings.sessions.destroy');
    Route::post('/pengaturan/sesi/{mikrotikSession}/aktif', [MikrotikSessionController::class, 'activate'])->name('settings.sessions.activate');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::prefix('{site}')->where(['site' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/bandwidth', BandwidthController::class)->name('bandwidth');
        Route::get('/dhcp', DhcpController::class)->name('dhcp');
        Route::get('/dns', DnsController::class)->name('dns');
        Route::get('/dns-filter', [DnsFilterController::class, 'index'])->name('dns-filter');
        Route::get('/access-points', AccessPointController::class)->name('access-points');
        Route::get('/topology', TopologyController::class)->name('topology');
        Route::get('/bandwidth-policy', [BandwidthPolicyController::class, 'index'])->name('bandwidth-policy');
        Route::get('/logs', LogController::class)->name('logs');
        Route::get('/security', SecurityController::class)->name('security');
    });
});
