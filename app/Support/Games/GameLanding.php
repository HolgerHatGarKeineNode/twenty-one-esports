<?php

namespace App\Support\Games;

use App\Enums\ChessGameStatus;
use App\Enums\PayoutStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Dock\OpenMatches;
use App\Support\Rating\Ratings;
use App\Support\Series\Ladders;
use App\Support\Tournaments\TournamentChampionMoment;
use Illuminate\Support\Facades\Cache;

/**
 * What a game's landing page (P56, `games/{slug}` and the chess lobby) shows
 * beside its match cards, from the league's own records only:
 *
 * - `pulse`: how many people are at it right now, as counts the hero links
 *   to its modules (series live, challenges waiting for an answer, players
 *   searching a casual 1v1, clans with a lineup, ladder entries with a
 *   result). One grouped query per table.
 * - `openMatch`: the viewer's own series of this game that is live, next or
 *   waiting for an answer, soonest first (the hero's primary action).
 * - `standing`: the viewer's best ladder entry in this game (their own, or a
 *   lineup they play for) with its rank, from the ladder each mode's page
 *   opens on (rated once open, casual before).
 * - `steps`: which of the three first steps the viewer has done.
 * - `nextTournament`: the game's special tournament whose sign-up closes
 *   next (never a casual cup; those stay a side mention).
 * - `lastFinishedTournament`, `podium`, `paidSats`: the game's prizes as
 *   they were won (plan "RL-Startseite", P2): its last finished special
 *   tournament with a prize pot and that tournament's places 1 to 3, and
 *   the prizes paid out over its finished tournaments (PrizePool::paidSats
 *   summed; a prize passed on to the next pot is not paid).
 *
 * Every number is a count or a sum of rows; nothing is estimated.
 */
final class GameLanding
{
    /**
     * @return array{live: int, open: int, searching: int, clans: int, ranked: int}
     */
    public function pulse(string $game): array
    {
        $series = SeriesMatch::query()->where('game', $game)
            ->selectRaw('sum(case when status = ? and start_at <= ? then 1 else 0 end) as live', [SeriesStatus::Accepted->value, now()])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as open', [SeriesStatus::Open->value])
            ->toBase()->first();

        return [
            'live' => (int) ($series->live ?? 0),
            'open' => (int) ($series->open ?? 0),
            'searching' => SeriesQueueEntry::query()->where('game', $game)->count(),
            'clans' => Lineup::query()->where('game', $game)->distinct()->count('clan_id'),
            'ranked' => $this->ranked($game),
        ];
    }

    /**
     * The chess lobby's counts: live boards, players in the blitz queue,
     * daily games running and players with a result on a chess ladder.
     *
     * @return array{live: int, searching: int, daily: int, ranked: int}
     */
    public function chessPulse(): array
    {
        $games = ChessGame::query()->where('status', ChessGameStatus::Active)
            ->selectRaw('sum(case when mode = ? then 0 else 1 end) as live', [ChessGame::CORRESPONDENCE])
            ->selectRaw('sum(case when mode = ? then 1 else 0 end) as daily', [ChessGame::CORRESPONDENCE])
            ->toBase()->first();

        return [
            'live' => (int) ($games->live ?? 0),
            'searching' => ChessQueueEntry::query()->count(),
            'daily' => (int) ($games->daily ?? 0),
            'ranked' => $this->ranked('chess'),
        ];
    }

    /**
     * The viewer's series of this game that is live, next or waiting for an
     * answer: live first, then by start (an open challenge by its answer
     * deadline).
     */
    public function openMatch(User $user, string $game): ?SeriesMatch
    {
        return OpenMatches::involving(SeriesMatch::query(), $user, OpenMatches::lineupsOf($user))
            ->where('game', $game)
            ->where(fn ($query) => $query->where('status', SeriesStatus::Open)
                ->orWhere(fn ($query) => $query->where('status', SeriesStatus::Accepted)->whereNotNull('start_at')))
            ->orderByRaw('case when status = ? and start_at <= ? then 0 else 1 end', [SeriesStatus::Accepted->value, now()])
            ->orderByRaw('coalesce(start_at, respond_by)')
            ->with(['challengerLineup.clan', 'challengedLineup.clan'])
            ->first();
    }

    /**
     * The viewer's best entry with a result on this game's ladders, and its
     * place there; null without one.
     *
     * @return array{mode: string, pool: string, rating: int, results: int, rank: int, lineup: bool}|null
     */
    public function standing(User $user, string $game): ?array
    {
        $lineups = OpenMatches::lineupsOf($user);
        $rows = Rating::query()->where('game', $game)->where('results', '>', 0)
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhereIn('lineup_id', $lineups))
            ->get()
            ->filter(fn (Rating $row): bool => $row->pool === Ratings::pool(Ladders::isOpen($game, $row->mode))
                && $row->season === Ratings::season($row->pool, $game, $row->mode))
            ->sortByDesc('rating');

        $best = $rows->first();

        if (! $best instanceof Rating) {
            return null;
        }

        $above = Rating::query()->where(['pool' => $best->pool, 'season' => $best->season, 'game' => $game, 'mode' => $best->mode])
            ->where('results', '>', 0)->where('rating', '>', $best->rating)->count();

        return [
            'mode' => (string) $best->mode,
            'pool' => (string) $best->pool,
            'rating' => (int) $best->rating,
            'results' => (int) $best->results,
            'rank' => $above + 1,
            'lineup' => $best->lineup_id !== null,
        ];
    }

    /**
     * The three first steps on a series game: logged in, a side to play for
     * (a clan, or a casual 1v1 without one) and a first finished series.
     *
     * @return array{login: bool, side: bool, played: bool}
     */
    public function steps(?User $user, string $game): array
    {
        if ($user === null) {
            return ['login' => false, 'side' => false, 'played' => false];
        }

        $played = OpenMatches::involving(SeriesMatch::query(), $user, OpenMatches::lineupsOf($user))
            ->where('game', $game)->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->exists();

        return [
            'login' => true,
            'side' => $played || ClanMember::query()->where('user_id', $user->id)->exists(),
            'played' => $played,
        ];
    }

    public function nextTournament(string $game): ?Tournament
    {
        return Tournament::query()->special()->where('game', $game)->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
            ->orderBy('signup_closes_at')->first();
    }

    /** The game's last finished special tournament with a prize pot (never a casual cup, which has none). */
    public function lastFinishedTournament(string $game): ?Tournament
    {
        return Tournament::query()->special()->exceptLeagueWeeks()->where('game', $game)->where('status', TournamentStatus::Finished)
            ->whereIn('pot_source', [Tournament::POT_LEAGUE, Tournament::POT_WALLET])
            ->orderByDesc('starts_at')->orderByDesc('id')->first();
    }

    /**
     * Places 1 to 3 of a finished tournament by name, ties kept
     * (TournamentChampionMoment); empty when no place 1 can be read. A
     * finished tournament's places do not change, so they are kept for a day
     * per version of the tournament instead of reading its bracket on every
     * visit of the game page.
     *
     * @return list<array{place: int, names: list<string>}>
     */
    public function podium(Tournament $tournament): array
    {
        $key = 'game-landing:podium:'.$tournament->id.':'.$tournament->updated_at?->getTimestamp();

        /** @var list<array{place: int, names: list<string>}> */
        return Cache::remember($key, now()->addDay(), function () use ($tournament): array {
            $moment = TournamentChampionMoment::of($tournament, null);

            return array_map(fn (array $row): array => ['place' => $row['place'], 'names' => array_column($row['entries'], 'name')], $moment['podium'] ?? []);
        });
    }

    /** The prizes paid out over the game's finished tournaments, in sats. */
    public function paidSats(string $game): int
    {
        return (int) TournamentPayout::query()->where('status', PayoutStatus::Paid)
            ->whereHas('tournament', fn ($query) => $query->where('game', $game)->where('status', TournamentStatus::Finished))
            ->sum('amount_sats');
    }

    /** Ladder entries (players or lineups) with at least one result in this game, any mode or pool. */
    private function ranked(string $game): int
    {
        return Rating::query()->where('game', $game)->where('results', '>', 0)->distinct()->count('subject');
    }
}
