<?php

namespace App\Livewire\Actions;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

class DeleteAccount
{
    /**
     * Delete everything this site stores about the user and log them out.
     *
     * Signed Nostr events (challenges, results) are not ours to delete: they
     * live on relays under the user's key. The flash message says so.
     */
    public function __invoke(User $user): void
    {
        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        DB::transaction(function () use ($user): void {
            Admin::query()->where('pubkey', $user->pubkey)->delete();

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            $user->delete();
        });

        Auth::guard('web')->logout();
        Session::invalidate();
        Session::regenerateToken();

        Session::flash('status', __('Your account was deleted. Results you confirmed stay public on Nostr, because you signed them with your key.'));
    }
}
