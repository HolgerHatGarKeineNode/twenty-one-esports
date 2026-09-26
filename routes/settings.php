<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/gaming')->name('settings');

    Route::livewire('settings/gaming', 'pages::settings.gaming')->name('gaming.edit');
    Route::livewire('settings/chess', 'pages::settings.chess')->name('settings.chess');
    Route::livewire('settings/opponents', 'pages::settings.opponents')->name('settings.opponents');
    Route::livewire('settings/badges', 'pages::settings.badges')->name('settings.badges');
});
