<?php

namespace App\Livewire\Actions;

use App\Enums\ChessGameStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\ClanDeparture;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    public function __construct(private ChessGameService $games) {}

    /**
     * Delete everything this site stores about the user and log them out.
     *
     * Signed Nostr events (challenges, results) are not ours to delete: they
     * live on relays under the user's key. The flash message says so. Chess
     * games and ratings stay too, with the player shown as "Deleted player".
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

        // Casual games still running end first (aborted before the clocks run, else
        // resigned): the game rows stay with the side anonymised (security re-check item 4).
        $running = ChessGame::query()->where('status', ChessGameStatus::Active)
            ->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))
            ->get();

        foreach ($running as $game) {
            try {
                $game->clocksRunning() ? $this->games->resign($game, $user) : $this->games->abort($game, $user);
            } catch (ChessRuleViolation) {
                // ended in between
            }
        }

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        DB::transaction(function () use ($user): void {
            Admin::query()->where('pubkey', $user->pubkey)->delete();

            // The clan membership goes with the account; its departure stays with the pubkey,
            // so the trust admin's own-clan guard still sees this key (P7e).
            $membership = $user->clanMember()->with('clan')->first();

            if ($membership !== null) {
                ClanDeparture::query()->create([
                    'clan_id' => $membership->clan_id, 'clan_address' => $membership->clan->address(), 'clan_name' => $membership->clan->name,
                    'user_id' => $user->id, 'pubkey' => $user->pubkey, 'reason' => 'deleted', 'left_at' => now(),
                ]);
            }

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
