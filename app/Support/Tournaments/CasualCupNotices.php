<?php

namespace App\Support\Tournaments;

use App\Enums\NotificationKind;
use App\Models\BoardGame;
use App\Models\BoardInvite;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRound;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the league tells the players of its casual cups (P25, CasualCups),
 * in their language and on the channels they chose (Notifier). News with
 * hours or days to act (match open, times, called off) is `tournament_news`,
 * which may go out as a DM; what is on now (a play-now invite, a started
 * game with minutes to the first move) is `cup_game_now`: the page and a
 * browser push, never a DM, which would come too late (audit 2026-09-30).
 */
final class CasualCupNotices
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @param  list<int>  $userIds
     */
    public function calledOff(Tournament $cup, array $userIds): void
    {
        foreach (User::query()->whereKey($userIds)->get() as $player) {
            $locale = $this->locale($player);

            $this->send($player, $cup, __(':tournament was called off', ['tournament' => $cup->name], $locale),
                __('Not enough players signed up. The next cup opens in a day.', [], $locale), $locale);
        }
    }

    /**
     * A small cup switched to its live evening (S2): when it starts, the
     * format, and that the league starts every game.
     *
     * @param  array{rounds: int, games_per_player: int, round_minutes: int, play_minutes: int, span_minutes: int}  $plan
     */
    public function eveningAnnounced(Tournament $cup, array $plan): void
    {
        foreach ($this->signedUp($cup) as $player) {
            $locale = $this->locale($player);

            $this->send($player, $cup, __(':tournament: live evening :start', ['tournament' => $cup->name, 'start' => $this->time($cup->starts_at, $player, $cup)], $locale),
                __('Few signed up, so the cup is one evening: :format, :games games for you, about :minutes minutes. The league starts every game on the board; be online.', [
                    'format' => __($cup->format->label(), [], $locale),
                    'games' => $plan['games_per_player'],
                    'minutes' => $plan['span_minutes'],
                ], $locale), $locale);
        }
    }

    /**
     * A cup moved to its region's slot (the EU/US split, user 2026-09-28):
     * every signed-up player hears the new start once, in their zone.
     */
    public function moved(Tournament $cup): void
    {
        foreach ($this->signedUp($cup) as $player) {
            $locale = $this->locale($player);

            $this->send($player, $cup, __(':tournament now starts :start', ['tournament' => $cup->name, 'start' => $this->time($cup->starts_at, $player, $cup)], $locale),
                __('The casual cups now run on a fixed evening per region (EU and US). You stay signed up; sign-up closes at the start.', [], $locale), $locale);
        }
    }

    /**
     * The players signed up to a cup, members of a lineup each.
     *
     * @return Collection<int, User>
     */
    private function signedUp(Tournament $cup): Collection
    {
        $ids = TournamentSignup::query()->where('tournament_id', $cup->id)->active()->get()
            ->flatMap(fn (TournamentSignup $signup): array => $signup->members)->all();

        return User::query()->whereKey(array_values(array_unique(array_map(intval(...), $ids))))->get();
    }

    /**
     * Each player of a match that can be played now: whom, until when, and
     * when the league starts it on its own.
     */
    public function roundOpened(Tournament $cup, TournamentRound $round): void
    {
        $matches = TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')
            ->where('bracket', '!=', 'bye')->with('slots.participant')->get();

        foreach ($matches as $match) {
            foreach ($match->slots as $slot) {
                $opponent = $match->slots->firstWhere('slot', 1 - $slot->slot)?->participant;
                $player = User::query()->find($slot->participant?->memberIds()[0] ?? 0);

                if ($player === null || $opponent === null || $round->window_ends_at === null) {
                    continue;
                }

                $locale = $this->locale($player);
                $body = $cup->profile()->isChess() || $cup->profile()->isBoard()
                    ? __('Play :opponent by :deadline: when you are both online, start it from the cup page. Otherwise the league starts your game at :slot.', [
                        'opponent' => $opponent->name,
                        'deadline' => $this->time($round->window_ends_at, $player, $cup),
                        'slot' => $this->time(CasualCups::autoSlot($round->window_ends_at, CasualCups::timezoneOf($cup)), $player, $cup),
                    ], $locale)
                    : __('Play :opponent by :deadline.', ['opponent' => $opponent->name, 'deadline' => $this->time($round->window_ends_at, $player, $cup)], $locale);

                $this->send($player, $cup, __(':tournament: your match is open', ['tournament' => $cup->name], $locale), $body, $locale);
            }
        }
    }

    /**
     * A series match (S3): the opponent of the proposer hears the suggested times.
     */
    public function timesProposed(TournamentMatch $match, User $proposer): void
    {
        $opponent = $this->opponentOf($match, $proposer);

        if ($opponent === null) {
            return;
        }

        $locale = $this->locale($opponent);
        $times = implode(', ', array_map(fn (int $at): string => $this->time(CarbonImmutable::createFromTimestamp($at), $opponent, $match->tournament), $match->schedule['proposals'] ?? []));

        $this->send($opponent, $match->tournament, __(':name suggests times for your cup match', ['name' => $proposer->displayName()], $locale),
            __(':times · accept one on the cup page.', ['times' => $times], $locale), $locale);
    }

    /**
     * A series match (S3): the proposer hears the time the opponent accepted.
     */
    public function timeAgreed(TournamentMatch $match, User $acceptor): void
    {
        $proposer = $this->opponentOf($match, $acceptor);
        $agreed = CupSchedules::agreedAt($match);

        if ($proposer === null || $agreed === null) {
            return;
        }

        $locale = $this->locale($proposer);

        $this->send($proposer, $match->tournament, __(':name accepted :time for your cup match', ['name' => $acceptor->displayName(), 'time' => $this->time($agreed, $proposer, $match->tournament)], $locale),
            __('Check in in the match room from :minutes minutes before.', ['minutes' => (int) config('esports.casual.checkin_before_minutes', 10)], $locale), $locale);
    }

    private function opponentOf(TournamentMatch $match, User $user): ?User
    {
        $match->loadMissing('slots.participant');
        $ids = $match->slots->map(fn ($slot): ?int => $slot->participant?->memberIds()[0] ?? null)->filter()->all();

        return User::query()->whereKey(array_values(array_diff($ids, [$user->id])))->first();
    }

    /**
     * The opponent asks to play the cup match now.
     */
    public function invited(Tournament $cup, ChessInvite|BoardInvite $invite): void
    {
        $player = $invite->invitee;
        $locale = $this->locale($player);

        $this->send($player, $cup, __(':name wants to play your cup match now', ['name' => $invite->inviter->displayName()], $locale),
            __(':tournament · open the cup page to accept.', ['tournament' => $cup->name], $locale), $locale, NotificationKind::CupGameNow);
    }

    /**
     * A cup game started (accepted invite, the auto slot, a replay): both
     * players, wherever they are; not the one who just accepted the invite
     * and is taken to the board anyway.
     */
    public function gameStarted(Tournament $cup, ChessGame|BoardGame $game, ?User $except = null): void
    {
        foreach ([$game->white, $game->black] as $player) {
            if ($player === null || ($except !== null && $player->is($except))) {
                continue;
            }

            $locale = $this->locale($player);

            $this->notifier->send($player, NotificationKind::CupGameNow, new Notice(
                __(':tournament: your game is on', ['tournament' => $cup->name], $locale),
                __('You play :color against :name. Make your first move in time.', [
                    'color' => $game->colorOf($player) === 'w' ? __('White', [], $locale) : __('Black', [], $locale),
                    'name' => $game->opponentOf($player)?->displayName() ?? '',
                ], $locale),
                $game instanceof BoardGame ? route('board.show', $game) : route('games.show', $game),
                $game instanceof ChessGame ? $game->id : null,
                __('Play now', [], $locale),
            ), $game instanceof ChessGame ? $game : null);
        }
    }

    private function send(User $player, Tournament $cup, string $title, string $body, string $locale, NotificationKind $kind = NotificationKind::TournamentNews): void
    {
        $this->notifier->send($player, $kind, new Notice($title, $body, route('tournaments.show', $cup), null, __('Open tournament', [], $locale)));
    }

    /** In the player's zone, else the cup's (its region's). */
    private function time(CarbonInterface $at, User $player, Tournament $cup): string
    {
        return $at->copy()->setTimezone($player->timezone ?? CasualCups::timezoneOf($cup))->format('D j M, H:i T');
    }

    private function locale(User $user): string
    {
        return $user->locale ?? (string) config('app.locale');
    }
}
