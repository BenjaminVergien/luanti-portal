<?php

use App\Http\Controllers\InvitationController;
use App\Http\Middleware\EnsurePortalAccount;
use App\Livewire\AccessPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('access'));
Route::get('/admin/hetzner', fn () => redirect()->route('filament.admin.pages.einstellungen'));
Route::get('/einladungen/{token}', InvitationController::class)->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:30,1')->name('invitations.open');
Route::get('/email-bestaetigen', fn () => redirect()->route('filament.portal.auth.email-verification.prompt'))->middleware('auth')->name('verification.notice');
Route::get('/zugang', AccessPage::class)->middleware(['auth', EnsurePortalAccount::class, 'verified'])->name('access');
Route::post('/abmelden', function (Request $request) {
    auth()->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('filament.portal.auth.login');
})->middleware('auth')->name('logout');
