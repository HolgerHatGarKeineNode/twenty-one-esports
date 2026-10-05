<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Models\ChessGame;
use App\Models\LineupSeat;
use App\Models\SeriesMatch;
use App\Models\SeriesMatchBoard;
use App\Models\User;
use App\Support\Chess\ChessTeamMatches;
use Illuminate\Support\Collection;

/**
 * Chess team match notifications (plan "Schach Rapid und Clan", P6), all of
 * kind `team_match` (bell and push, never a DM: everything here is minutes
 * away). The challenge itself is `challenge` (SeriesService::notifyChallenged()).
 *
 * - Lineup lock reached: every named player hears their board, the opponent
 *   and the whole board order; the captains of both sides hear the order.
 * - Lock missed: both sides hear that the team match is lost by forfeit (or
 *   void when neither named a lineup).
 * - Board started: its two players, with the first-move window.
 * - Result: the named players and the captains of both sides.
 */
final class TeamMatchNotifications
{
    public function __construct(private Notifier $notifier) {}

    public function locked(SeriesMatch $match): void
    {
        $seats = $this->seats($match);
        $order = $this->order($match, $seats);

        foreach ($this->recipients($match, $seats) as ['user' => $user, 'side' => $side]) {
            $locale = $user->locale ?? (string) config('app.locale');
            $own = $seats->first(fn (SeriesMatchBoard $seat): bool => $seat->user_id === $user->id);
            $opponent = $own === null ? null : $seats->first(fn (SeriesMatchBoard $seat): bool => $seat->board === $own->board && $seat->side !== $own->side)?->user;
            $time = $this->time($match, $user);

            $body = $own !== null
                ? __('You play board :board against :opponent at :time. Board order: :order.', ['board' => $own->board, 'opponent' => $opponent?->displayName() ?? '?', 'time' => $time, 'order' => $order], $locale)
                : __('The boards start at :time. Board order: :order.', ['time' => $time, 'order' => $order], $locale);

            $this->notifier->send($user, NotificationKind::TeamMatch, new Notice(
                __('Team match :number: lineups locked', ['number' => $match->label()], $locale),
                $body,
                route('matches.show', $match),
                $match->number,
                __('View', [], $locale),
            ));
        }
    }

    /**
     * @param  'forfeit'|'void'  $outcome
     */
    public function lockMissed(SeriesMatch $match, string $outcome): void
    {
        $seats = $this->seats($match);

        foreach ($this->recipients($match, $seats) as ['user' => $user, 'side' => $side]) {
            $locale = $user->locale ?? (string) config('app.locale');
            $body = match (true) {
                $outcome === 'void' => __('Neither clan named its players by the lineup lock. The team match is void.', [], $locale),
                $match->winner === $side => __(':clan named no players by the lineup lock. Your clan wins the team match by forfeit.', ['clan' => $match->sideName(SeriesMatch::otherSide($side))], $locale),
                default => __('Your clan named no players by the lineup lock, :minutes minutes before the start. The team match is lost by forfeit.', ['minutes' => ChessTeamMatches::lockMinutes()], $locale),
            };

            $this->notifier->send($user, NotificationKind::TeamMatch, new Notice(
                __('Team match :number: lineup lock missed', ['number' => $match->label()], $locale),
                $body,
                route('matches.show', $match),
                $match->number,
                __('View', [], $locale),
            ));
        }
    }

    public function boardStarted(ChessGame $game): void
    {
        foreach ([$game->white, $game->black] as $user) {
            if (! $user->exists) {
                continue;
            }

            $locale = $user->locale ?? (string) config('app.locale');

            $this->notifier->send($user, NotificationKind::TeamMatch, new Notice(
                __('Your board :board started', ['board' => $game->board], $locale),
                __('Team match :number, rapid 10+5. Make your first move within :seconds seconds, or the board is lost by forfeit.', ['number' => '#'.$game->matchNumber(), 'seconds' => (int) ($game->first_move_seconds ?? ChessTeamMatches::firstMoveSeconds())], $locale),
                route('games.show', $game),
                $game->matchNumber(),
                __('Play', [], $locale),
            ), $game);
        }
    }

    /**
     * @param  'won'|'draw'|'forfeit'|'void'  $outcome
     */
    public function result(SeriesMatch $match, string $outcome): void
    {
        $seats = $this->seats($match);
        $score = ChessTeamMatches::score(ChessTeamMatches::boardResults($match));

        foreach ($this->recipients($match, $seats) as ['user' => $user, 'side' => $side]) {
            $locale = $user->locale ?? (string) config('app.locale');
            $other = SeriesMatch::otherSide($side);
            $text = ChessTeamMatches::points($score[$side]).' : '.ChessTeamMatches::points($score[$other]);
            $body = match (true) {
                $outcome === 'void' => __('No board was played. The team match is void.', [], $locale),
                $outcome === 'draw' => __('Team draw :score against :clan. Each clan keeps the hashrate of its boards, no bonus.', ['score' => $text, 'clan' => $match->sideName($other)], $locale),
                $match->winner === $side => __('Your clan won :score against :clan.', ['score' => $text, 'clan' => $match->sideName($other)], $locale),
                default => __('Your clan lost :score against :clan.', ['score' => $text, 'clan' => $match->sideName($other)], $locale),
            };

            $this->notifier->send($user, NotificationKind::TeamMatch, new Notice(
                __('Team match :number ended', ['number' => $match->label()], $locale),
                $body,
                route('matches.show', $match),
                $match->number,
                __('View', [], $locale),
            ));
        }
    }

    /**
     * @return Collection<int, SeriesMatchBoard>
     */
    private function seats(SeriesMatch $match): Collection
    {
        return SeriesMatchBoard::query()->with('user')->where('series_match_id', $match->id)->orderBy('board')->orderBy('side')->get();
    }

    /**
     * "1 alice – bob, 2 carol – dave": the challenger's player first.
     *
     * @param  Collection<int, SeriesMatchBoard>  $seats
     */
    private function order(SeriesMatch $match, Collection $seats): string
    {
        $rows = [];

        for ($board = 1; $board <= (int) $match->boards; $board++) {
            $name = fn (string $side): string => $seats->first(fn (SeriesMatchBoard $seat): bool => $seat->board === $board && $seat->side === $side)?->user?->displayName() ?? '?';
            $rows[] = $board.' '.$name('challenger').' – '.$name('challenged');
        }

        return implode(', ', $rows);
    }

    /**
     * The named players and the acting captains of both sides, each once.
     *
     * @param  Collection<int, SeriesMatchBoard>  $seats
     * @return list<array{user: User, side: string}>
     */
    private function recipients(SeriesMatch $match, Collection $seats): array
    {
        $out = [];

        foreach ($seats as $seat) {
            if ($seat->user instanceof User) {
                $out[$seat->user->id] = ['user' => $seat->user, 'side' => $seat->side];
            }
        }

        foreach (SeriesMatch::SIDES as $side) {
            $lineup = $match->lineup($side);
            $lineup?->loadMissing(['clan', 'seats.user.clanMember']);

            foreach ($lineup?->seats->filter(fn (LineupSeat $seat): bool => $lineup->isActingCaptain($seat->user)) ?? [] as $seat) {
                $out[$seat->user->id] ??= ['user' => $seat->user, 'side' => $side];
            }
        }

        return array_values($out);
    }

    private function time(SeriesMatch $match, User $user): string
    {
        return ($match->start_at ?? now())->copy()->timezone($user->timezone ?? config('esports.preseason.display_timezone'))->format('H:i');
    }
}
