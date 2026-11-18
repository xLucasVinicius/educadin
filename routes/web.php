<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\GenerateRecurringTransactions;
use App\Http\Controllers\LinkedAccountController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// A foto de perfil fica em disco privado; cada usuário só recebe a própria.
Route::get('profile/photo', function () {
    $path = Auth::user()->avatar_path;

    abort_unless($path && Storage::disk('local')->exists($path), 404);

    return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=31536000, immutable']);
})->middleware('auth')->name('profile.photo');

// Troca de perfil: só entre contas que já entraram neste navegador.
Route::post('linked-accounts/{user}/switch', [LinkedAccountController::class, 'switch'])->name('linked-accounts.switch');
Route::post('linked-accounts/{user}/forget', [LinkedAccountController::class, 'forget'])->name('linked-accounts.forget');
Route::middleware('auth')->group(function () {
    Route::post('linked-accounts/add', [LinkedAccountController::class, 'add'])->name('linked-accounts.add');
    Route::post('linked-accounts/leave', [LinkedAccountController::class, 'leave'])->name('linked-accounts.leave');
});

Route::middleware(['auth', 'verified', EnsureUserIsNotSuspended::class, GenerateRecurringTransactions::class])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('accounts', 'pages::accounts.index')->name('accounts.index');
    Route::livewire('cards', 'pages::cards.index')->name('cards.index');
    Route::livewire('performance', 'pages::performance.index')->name('performance.index');
    Route::livewire('plans', 'pages::plans.index')->name('plans.index');
});

// Painel de admin: só admins (os demais recebem 404).
Route::middleware(['auth', 'verified', EnsureUserIsNotSuspended::class, EnsureUserIsAdmin::class])->group(function () {
    Route::livewire('admin', 'pages::admin.index')->name('admin.index');

    // Foto de perfil de qualquer usuário, só para quem administra.
    Route::get('admin/users/{user}/photo', function (\App\Models\User $user) {
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, ['Cache-Control' => 'private, max-age=31536000, immutable']);
    })->name('admin.users.photo');
});

require __DIR__.'/settings.php';
