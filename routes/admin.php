<?php

use App\Http\Controllers\DisputeEvidenceController;
use Illuminate\Support\Facades\Route;

/*
| Admin area: board npubs from config/esports.php plus admins from the
| `admins` table (Gate `admin`, middleware alias `admin`).
*/

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('admins', 'pages::admin.admins')->name('admins');

    // Series disputes and no-shows (P6a); `{match}` is the league match number.
    Route::livewire('disputes', 'pages::admin.disputes')->name('disputes');
    Route::livewire('disputes/{match}', 'pages::admin.dispute')->name('disputes.show');
    Route::get('disputes/{match}/evidence/{evidence}', DisputeEvidenceController::class)->name('disputes.evidence');
});
