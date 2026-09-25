<?php

use App\Http\Controllers\Auth\NostrLoginController;
use Illuminate\Support\Facades\Route;

/*
| Nostr login. The only way in: no password, email or registration routes.
| The client module is resources/js/nostrLogin.js.
*/

Route::middleware('throttle:30,1')->prefix('auth/nostr')->name('auth.nostr.')->group(function () {
    Route::post('challenge', [NostrLoginController::class, 'challenge'])->name('challenge');
    Route::post('login', [NostrLoginController::class, 'login'])->name('login');
});
