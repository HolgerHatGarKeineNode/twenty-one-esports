<?php

namespace App\Support\Dock;

use App\Enums\TournamentStatus;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\GameNames;
use App\Support\PreSeason;
use App\Support\RequestMemo;
use App\Support\Series\SeriesPresenter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A player's upcoming events (2026-10-02): their open match rooms
 * (OpenMatches::rooms()) and the tournaments they are registered for, as
 * one list in urgency order (OpenMatches::order()). Home's "Your next
 * match" card, the top of /matches, /tournaments and a game page, and the
 * count on the account menu read it; the match dock takes the rooms and
 * only the tournaments of the day.
 *
 * A registered tournament counts from the sign-up until it is finished,
 * called off, or the player is out:
 * - its day (Europe/Berlin), or running: on the dock too, counting down to
 *   the start, within the last hour on the player;
 * - earlier: waiting at the end of the list, with the way to pull out
 *   ({@see DockItem::$withdraw}) while sign-up is open and the player may
 *   (solo, or an acting captain of the lineup; TournamentSignups::withdraw()).
 *
 * Out means: no entry in the draw, disqualified, or, in a format with a final
 * (an elimination bracket), no match left to play. Round robin, Swiss and
 * leaderboards keep their players to the end.
 */
final class UpcomingEvents
{
    /** The league's day, for "the same day" (config `app.timezone` is UTC). */
    public const ZONE = 'Europe/Berlin';

    private const ACTIVE = [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running];

    /**
     * Rooms and tournaments in urgency order, once per request.
     *
     * @return Collection<int, DockItem>
     */
    public function for(User $user): Collection
    {
        $key = 'upcoming-events.'.$user->id;
        $cached = request()->attributes->get($key);

        if ($cached instanceof Collection) {
            return $cached;
        }

        $items = OpenMatches::order(app(OpenMatches::class)->rooms($user)->concat($this->tournaments($user)));
        request()->attributes->set($key, $items);

        return $items;
    }

    /**
     * The tournaments this player is registered for and still in; with
     * `todayOnly`, those of today (or running) alone.
     *
     * @return Collection<int, DockItem>
     */
    public function tournaments(User $user, bool $todayOnly = false): Collection
    {
        $nowMs = (int) now()->getTimestampMs();
        $signups = $this->signups($user)->filter(fn (TournamentSignup $signup): bool => ! $todayOnly || self::isToday($signup->tournament));
        $rooms = $this->roomsIn($signups->pluck('tournament')->filter(fn (Tournament $tournament): bool => self::isLive($tournament, $nowMs)), $user);

        return $signups
            ->map(fn (TournamentSignup $signup): DockItem => $this->item($signup, $user, $nowMs, $rooms))
            ->values();
    }

    /** Running, or past its start: the item leads to the player's room, else to the tournament. */
    private static function isLive(Tournament $tournament, int $nowMs): bool
    {
        return $tournament->status === TournamentStatus::Running || (int) $tournament->starts_at->getTimestampMs() <= $nowMs;
    }

    /**
     * The player's active sign-ups they are still in, once per request: the
     * dock asks for today's, the account menu and home for all (P2).
     *
     * @return Collection<int, TournamentSignup>
     */
    private function signups(User $user): Collection
    {
        return RequestMemo::remember('upcoming-signups.'.$user->id, function () use ($user): Collection {
            $signups = TournamentSignup::query()->active()
                ->where(fn ($query) => $query->whereJsonContains('members', $user->id)->orWhereIn('lineup_id', OpenMatches::lineupsOf($user)))
                ->whereHas('tournament', fn ($query) => $query->whereIn('status', self::ACTIVE)->exceptLeagueWeeks())
                ->with(['tournament', 'lineup.clan', 'lineup.seats'])
                ->latest('id')
                ->limit(OpenMatches::KIND_LIMIT)
                ->get()
                ->unique('tournament_id');
            $out = $this->outOf($signups);

            return $signups->reject(fn (TournamentSignup $signup): bool => isset($out[$signup->id]))->values();
        });
    }

    /** Running, past its start, or starting later today in Berlin. */
    public static function isToday(Tournament $tournament): bool
    {
        return $tournament->status === TournamentStatus::Running
            || ! $tournament->starts_at->isFuture()
            || $tournament->starts_at->copy()->setTimezone(self::ZONE)->isSameDay(now(self::ZONE));
    }

    /**
     * The sign-ups that are out, after the draw: the entry was not drawn in,
     * was disqualified, or has no match left in a bracket that ends with a
     * final. Two queries for all of them, not two per sign-up (P2, S7).
     *
     * @param  Collection<int, TournamentSignup>  $signups
     * @return array<int, true> sign-up id => out
     */
    private function outOf(Collection $signups): array
    {
        $running = $signups->filter(fn (TournamentSignup $signup): bool => $signup->tournament->status === TournamentStatus::Running);

        if ($running->isEmpty()) {
            return [];
        }

        $participants = TournamentParticipant::query()->whereIn('tournament_signup_id', $running->pluck('id')->all())->get()
            // A sign-up is drawn into its own tournament only.
            ->filter(fn (TournamentParticipant $participant): bool => $running->firstWhere('id', $participant->tournament_signup_id)?->tournament_id === $participant->tournament_id)
            ->keyBy('tournament_signup_id');
        $finals = $running->filter(fn (TournamentSignup $signup): bool => $signup->tournament->format->hasFinal());
        // Eloquent's only() filters by model key: the participants are keyed by their sign-up here.
        $finalists = $participants->toBase()->only($finals->pluck('id')->all())->map(fn (TournamentParticipant $participant): int => $participant->id)->values();
        $playing = $finalists->isEmpty() ? [] : array_flip(TournamentMatchSlot::query()->whereIn('tournament_participant_id', $finalists)
            ->whereIn('tournament_match_id', TournamentMatch::query()->whereIn('tournament_id', $finals->pluck('tournament_id')->unique()->values())->whereIn('status', ['waiting', 'ready'])->select('id'))
            ->distinct()->pluck('tournament_participant_id')->map(fn (mixed $id): int => (int) $id)->all());
        $out = [];

        foreach ($running as $signup) {
            $participant = $participants->get($signup->id);

            if ($participant === null || $participant->isDisqualified()
                || ($signup->tournament->format->hasFinal() && ! isset($playing[$participant->id]))) {
                $out[$signup->id] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $rooms  {@see roomsIn()}
     */
    private function item(TournamentSignup $signup, User $user, int $nowMs, array $rooms): DockItem
    {
        $tournament = $signup->tournament;
        $startMs = (int) $tournament->starts_at->getTimestampMs();
        $running = self::isLive($tournament, $nowMs);
        $today = self::isToday($tournament);
        $soon = ! $running && $startMs - $nowMs <= OpenMatches::STARTS_SOON_MS;
        $name = $tournament->title();
        $title = $tournament->isCasualCup() ? self::text('Casual cup') : self::text('Tournament');
        $game = GameNames::game($tournament->game);
        $left = OpenMatches::format($startMs - $nowMs, 'hm');
        $mayWithdraw = $tournament->isSignupOpen() && ($signup->isSolo() || ($signup->lineup?->isActingCaptain($user) ?? false));

        return new DockItem(
            key: 'tournament-'.$tournament->id,
            kind: 'tournament',
            group: $today ? 'need' : 'wait',
            phase: $running ? 'live' : 'scheduled',
            needsYou: $soon,
            name: $name,
            face: null,
            tag: null,
            number: '',
            href: $running ? ($rooms[$tournament->id] ?? route('tournaments.show', $tournament)) : route('tournaments.show', $tournament),
            title: $title,
            state: $running ? self::text('Live') : self::text('Starts'),
            trailing: $running ? $game : $left,
            line: $running
                ? self::text(':title, live', ['title' => $title])
                : self::text(':title, starts :time', ['title' => $title, 'time' => self::when($tournament->starts_at, $user)]),
            sentence: $running
                ? self::text(':name is running', ['name' => $name])
                : self::text(':name starts in :left', ['name' => $name, 'left' => $left]),
            action: $running ? self::text('Open tournament') : null,
            // Running: after everything with a deadline, its own room carries the urgency.
            deadlineMs: $running ? null : $startMs,
            tick: $running ? null : ['endsAt' => $startMs, 'format' => 'hm', 'total' => max(1, $startMs - $nowMs), 'redUnder' => 0],
            model: $tournament,
            withdraw: $mayWithdraw ? route('tournaments.signup', $tournament).'#withdraw' : null,
        );
    }

    /**
     * The player's open room in each of these tournaments, once drawn: one
     * query for all of them, not one per tournament (P2, S7).
     *
     * @param  Collection<int, Tournament>  $tournaments
     * @return array<int, string> tournament id => room URL
     */
    private function roomsIn(Collection $tournaments, User $user): array
    {
        if ($tournaments->isEmpty()) {
            return [];
        }

        $rooms = [];
        $matches = OpenMatches::involving(SeriesMatch::query(), $user, OpenMatches::lineupsOf($user))
            ->whereIn('tournament_match_id', TournamentMatch::query()->whereIn('tournament_id', $tournaments->pluck('id')->unique()->values())->select('id'))
            ->with('tournamentMatch:id,tournament_id')
            ->orderBy('id')
            ->get();

        foreach ($matches as $match) {
            $tournamentId = (int) $match->tournamentMatch?->tournament_id;

            if (! isset($rooms[$tournamentId]) && $match->status->isRunning()) {
                $rooms[$tournamentId] = route('matches.room', $match);
            }
        }

        return $rooms;
    }

    /** "20:00" today, else "Sat, Oct 4 · 20:00", in the player's zone (SeriesPresenter::time()). */
    private static function when(CarbonInterface $at, User $user): string
    {
        $zone = PreSeason::timezoneFor($user);

        return SeriesPresenter::time($at, $user, $at->copy()->setTimezone($zone)->isSameDay(now($zone)) ? 'H:i' : 'D, M j · H:i');
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
