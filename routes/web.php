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
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
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
    Route::get('/logs', LogController::class)->name('logs');
    Route::get('/security', SecurityController::class)->name('security');
    Route::get('/pengaturan', [MikrotikSessionController::class, 'account'])->name('settings');
    Route::get('/pengaturan/sesi', [MikrotikSessionController::class, 'index'])->name('settings.sessions');
    Route::put('/pengaturan/password', [LoginController::class, 'updatePassword'])->name('settings.password');
    Route::post('/pengaturan/sesi', [MikrotikSessionController::class, 'store'])->name('settings.sessions.store');
    Route::put('/pengaturan/sesi/{mikrotikSession}', [MikrotikSessionController::class, 'update'])->name('settings.sessions.update');
    Route::delete('/pengaturan/sesi/{mikrotikSession}', [MikrotikSessionController::class, 'destroy'])->name('settings.sessions.destroy');
    Route::post('/pengaturan/sesi/{mikrotikSession}/aktif', [MikrotikSessionController::class, 'activate'])->name('settings.sessions.activate');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
