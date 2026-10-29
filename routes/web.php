<?php

use App\Http\Controllers\AccessPointController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BandwidthController;
use App\Http\Controllers\BandwidthPolicyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DhcpController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MikrotikSessionController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\UnifiSettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    Route::get('/sesi', [MikrotikSessionController::class, 'sites'])->name('sites');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/bandwidth', BandwidthController::class)->name('bandwidth');
    Route::get('/dhcp', DhcpController::class)->name('dhcp');
    Route::get('/dhcp/dns', [DhcpController::class, 'dns'])->name('dhcp.dns');
    Route::put('/dhcp/alias', [DhcpController::class, 'updateComment'])->name('dhcp.alias');
    Route::get('/dns', DnsController::class)->name('dns');
    Route::get('/access-points', AccessPointController::class)->name('access-points');
    Route::get('/bandwidth-policy', [BandwidthPolicyController::class, 'index'])->name('bandwidth-policy');
    Route::put('/bandwidth-policy', [BandwidthPolicyController::class, 'update'])->name('bandwidth-policy.update');
    Route::get('/bandwidth-policy/context', [BandwidthPolicyController::class, 'context'])->name('bandwidth-policy.context');
    Route::get('/bandwidth-policy/incidents/export', [BandwidthPolicyController::class, 'export'])->name('bandwidth-policy.export');
    Route::get('/logs', LogController::class)->name('logs');
    Route::get('/security', SecurityController::class)->name('security');
    Route::get('/pengaturan', [MikrotikSessionController::class, 'account'])->name('settings');
    Route::get('/pengaturan/sesi', [MikrotikSessionController::class, 'index'])->name('settings.sessions');
    Route::put('/pengaturan/password', [LoginController::class, 'updatePassword'])->name('settings.password');
    Route::get('/pengaturan/pengguna', [UserController::class, 'index'])->name('settings.users');
    Route::post('/pengaturan/pengguna', [UserController::class, 'store'])->name('settings.users.store');
    Route::put('/pengaturan/pengguna/{user}', [UserController::class, 'update'])->name('settings.users.update');
    Route::delete('/pengaturan/pengguna/{user}', [UserController::class, 'destroy'])->name('settings.users.destroy');
    Route::get('/pengaturan/unifi', [UnifiSettingsController::class, 'show'])->name('settings.unifi');
    Route::put('/pengaturan/unifi', [UnifiSettingsController::class, 'update'])->name('settings.unifi.update');
    Route::delete('/pengaturan/unifi', [UnifiSettingsController::class, 'destroy'])->name('settings.unifi.destroy');
    Route::post('/pengaturan/sesi', [MikrotikSessionController::class, 'store'])->name('settings.sessions.store');
    Route::put('/pengaturan/sesi/{mikrotikSession}', [MikrotikSessionController::class, 'update'])->name('settings.sessions.update');
    Route::delete('/pengaturan/sesi/{mikrotikSession}', [MikrotikSessionController::class, 'destroy'])->name('settings.sessions.destroy');
    Route::post('/pengaturan/sesi/{mikrotikSession}/aktif', [MikrotikSessionController::class, 'activate'])->name('settings.sessions.activate');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
