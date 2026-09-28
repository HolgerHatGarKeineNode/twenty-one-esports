<?php

namespace App\Http\Controllers;

use App\Support\Notifications\BlockZeroNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Notify me at Block 0" on the pre-launch home: stores when the player asked.
 * Guests are sent to the login by the `auth` middleware. Delivery:
 * BlockZeroNotifications (the planned date, then the release). A player who
 * asks while the planned date is on the page is not told that date again.
 */
class NotifyAtBlockZeroController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->notify_block0_at === null) {
            $planned = BlockZeroNotifications::plannedDate();

            $user->forceFill([
                'notify_block0_at' => now(),
                'block0_heads_up_for' => $planned === null ? null : BlockZeroNotifications::stamp($planned),
            ])->save();
        }

        return redirect()->to(route('home').'#block0');
    }
}
