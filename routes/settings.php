<?php

use App\Http\Middleware\EnsureUserIsNotSuspended;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureUserIsNotSuspended::class])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified', EnsureUserIsNotSuspended::class])->group(function () {
    // Abre direto, como o Perfil. A confirmação de senha para 2FA e chaves de
    // acesso é pedida dentro da própria página (veja a tela de Segurança).
    Route::livewire('settings/security', 'pages::settings.security')
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
