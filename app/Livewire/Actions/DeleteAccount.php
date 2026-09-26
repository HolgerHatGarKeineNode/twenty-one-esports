<?php

namespace App\Livewire\Actions;

use App\Enums\ChessGameStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
        // A rated game still being played is pinned league evidence; deleting the account
        // would cascade it away and take the result from the opponent (security gate F3).
        $rated = ChessGame::query()->where('rated', true)->where('status', ChessGameStatus::Active)
            ->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))
            ->exists();

        if ($rated) {
            throw ValidationException::withMessages(['confirmDeletion' => __('Finish your rated game first: deleting your account now would take its result from your opponent.')]);
        }

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
