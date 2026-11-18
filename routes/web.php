<?php

use App\Http\Middleware\GenerateRecurringTransactions;
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

Route::middleware(['auth', 'verified', GenerateRecurringTransactions::class])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('accounts', 'pages::accounts.index')->name('accounts.index');
    Route::livewire('cards', 'pages::cards.index')->name('cards.index');
});

require __DIR__.'/settings.php';
