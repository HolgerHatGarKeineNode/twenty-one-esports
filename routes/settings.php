<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/gaming')->name('settings');

    Route::livewire('settings/gaming', 'pages::settings.gaming')->name('gaming.edit');
    Route::livewire('settings/account', 'pages::settings.account')->name('settings.account');
    Route::livewire('settings/notifications', 'pages::settings.notifications')->name('settings.notifications');
    Route::livewire('settings/chess', 'pages::settings.chess')->name('settings.chess');
    Route::livewire('settings/opponents', 'pages::settings.opponents')->name('settings.opponents');
    Route::livewire('settings/badges', 'pages::settings.badges')->name('settings.badges');
    // P47: an optional NIP-05 name on the league's domain.
    Route::livewire('settings/nostr-address', 'pages::settings.nip05')->name('settings.nip05');
});
