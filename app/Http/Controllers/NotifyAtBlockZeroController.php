<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Notify me at Block 0" on the pre-launch home: stores when the player asked.
 * Guests are sent to the login by the `auth` middleware. Delivery comes later.
 */
class NotifyAtBlockZeroController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->notify_block0_at === null) {
            $user->forceFill(['notify_block0_at' => now()])->save();
        }

        return redirect()->to(route('home').'#block0');
    }
}
