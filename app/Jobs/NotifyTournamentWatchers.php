<?php

namespace App\Jobs;

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentWatch;
use App\Support\GameNames;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Tells every player who asked for "Notify me of new <game> tournaments"
 * (TournamentWatch, the prize band of a game page) that a tournament of that
 * game opened for sign-up (TournamentPublisher::openSignup), through the
 * Notifier (NotificationKind::NewTournament: the bell, push, and a Nostr DM
 * by the player's settings, where the kind can be switched off).
 *
 * Once per tournament: the tournament is claimed with a conditional update
 * (`watchers_notified_at`) before anyone is told, so a second or concurrent
 * run tells no one twice (at most once: a delivery that throws after the
 * claim is reported, not retried). Never for a casual cup or a league week:
 * those open on their own every week and carry no prize. One player whose
 * delivery fails does not stop the others. Dispatched after the commit, so
 * it reads the tournament as published.
 */
class NotifyTournamentWatchers implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 200;

    public function __construct(public int $tournamentId)
    {
        $this->afterCommit();
    }

    public function handle(Notifier $notifier): void
    {
        $tournament = Tournament::query()->find($this->tournamentId);

        if ($tournament === null || $tournament->status !== TournamentStatus::Signup || $tournament->isCasualCup() || $tournament->isLeagueWeek()) {
            return;
        }

        $claimed = Tournament::query()->whereKey($tournament->id)->whereNull('watchers_notified_at')->update(['watchers_notified_at' => now()]) === 1;

        if (! $claimed) {
            return;
        }

        TournamentWatch::query()->where('game', $tournament->game)->with('user')
            ->chunkById(self::CHUNK, function (Collection $watches) use ($notifier, $tournament): void {
                foreach ($watches as $watch) {
                    /** @var TournamentWatch $watch */
                    $user = $watch->user;
                    $locale = $user->locale ?? (string) config('app.locale');

                    try {
                        $notifier->send($user, NotificationKind::NewTournament, new Notice(
                            __('New :game tournament: :name', ['game' => GameNames::game($tournament->game), 'name' => $tournament->name], $locale),
                            __('Sign-up is open. You asked to hear of new :game tournaments.', ['game' => GameNames::game($tournament->game)], $locale),
                            route('tournaments.show', $tournament),
                            null,
                            __('View', [], $locale),
                        ));
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }
}
