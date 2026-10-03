<?php

namespace App\Livewire\Actions;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Tournaments\TournamentModeration;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    public function __construct(private ChessGameService $games, private BoardGameService $boardGames, private ClanService $clans, private TournamentRunner $runner, private TournamentModeration $moderation) {}

    /**
     * Delete everything this site stores about the user and log them out.
     *
     * Signed Nostr events (challenges, results) are not ours to delete: they
     * live on relays under the user's key. The flash message says so. Chess
     * games, board games and ratings stay too, with the player shown as
     * "Deleted player".
     *
     * A running tournament loses the player (P18): an entry left without any
     * account is withdrawn, and each match it would still play goes to the
     * opponent by forfeit, unrated (TournamentRunner::forfeitWithdrawn()).
     * Results already played stay with their pinned subjects (P7d F3).
     *
     * An entry of a tournament not drawn yet (sign-up or draw pending) is
     * withdrawn, or a lineup drops the player while it still fields a team,
     * with a league line in the moderation log: an entry left behind broke
     * the draw on prod (2026-10-03, TournamentModeration::withdrawOrphaned()).
     */
    public function __invoke(User $user): void
    {
        // A rated game still being played is pinned league evidence; deleting the account
        // would cascade it away and take the result from the opponent (security gate F3).
        // The same for a rated board game (Mühle, Dame): its result is league evidence as well.
        $rated = ChessGame::query()->where('rated', true)->where('status', ChessGameStatus::Active)
            ->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))
            ->exists()
            || BoardGame::query()->where('rated', true)->where('status', BoardGameStatus::Active)
                ->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))
                ->exists();

        if ($rated) {
            throw ValidationException::withMessages(['confirmDeletion' => __('Finish your rated game first: deleting your account now would take its result from your opponent.')]);
        }

        // Casual games still running end first (aborted before the clocks run, else
        // resigned; a tournament game is forfeited, P18): the game rows stay with the side
        // anonymised (security re-check item 4).
        $running = ChessGame::query()->where('status', ChessGameStatus::Active)
            ->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))
            ->get();

        foreach ($running as $game) {
            try {
                match (true) {
                    $game->tournament_match_id !== null => $this->games->forfeit($game, $user),
                    $game->clocksRunning() => $this->games->resign($game, $user),
                    default => $this->games->abort($game, $user),
                };
            } catch (ChessRuleViolation) {
                // ended in between
            }
        }

        // A live board game ends the same way: aborted before both first moves, else resigned;
        // a tournament game is forfeited, so the match goes to the opponent.
        $boards = BoardGame::query()->where('status', BoardGameStatus::Active)
            ->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))
            ->get();

        foreach ($boards as $game) {
            try {
                match (true) {
                    $game->tournament_match_id !== null => $this->boardGames->forfeit($game, $user),
                    $game->clocksRunning() => $this->boardGames->resign($game, $user),
                    default => $this->boardGames->abort($game, $user),
                };
            } catch (BoardRuleViolation) {
                // ended in between
            }
        }

        $tournaments = TournamentParticipant::query()->whereHas('tournament', fn ($query) => $query->where('status', TournamentStatus::Running))->get()
            ->filter(fn (TournamentParticipant $participant): bool => in_array($user->id, $participant->memberIds(), true))
            ->pluck('tournament_id')->unique()->values();

        $undrawn = TournamentSignup::query()->active()
            ->whereHas('tournament', fn ($query) => $query->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing]))
            ->get()->filter(fn (TournamentSignup $signup): bool => in_array($user->id, array_map(intval(...), $signup->members), true))
            ->pluck('tournament_id')->unique()->values();

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        DB::transaction(function () use ($user, $undrawn): void {
            Admin::query()->where('pubkey', $user->pubkey)->delete();

            // The player leaves the clan like anyone else (a departure row with the pubkey, so the
            // trust admin's own-clan guard still sees this key), and a clan left empty ends (P7e).
            $this->clans->leaveForDeletedAccount($user);

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            $user->delete();

            foreach ($undrawn as $tournamentId) {
                $this->moderation->withdrawOrphaned(Tournament::query()->lockForUpdate()->findOrFail((int) $tournamentId), 'The player deleted their account.');
            }
        });

        foreach (Tournament::query()->whereIn('id', $tournaments)->get() as $tournament) {
            $this->runner->sync($tournament);
        }

        Auth::guard('web')->logout();
        Session::invalidate();
        Session::regenerateToken();

        Session::flash('status', __('Your account was deleted. Results you confirmed stay public on Nostr, because you signed them with your key.'));
    }
}
