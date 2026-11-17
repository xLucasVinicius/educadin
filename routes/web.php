<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('accounts', 'pages::accounts.index')->name('accounts.index');
    Route::livewire('cards', 'pages::cards.index')->name('cards.index');
});

require __DIR__.'/settings.php';
