<?php

namespace App\Support\Clans;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Support\Payouts\TournamentPlacements;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The proud moments of every clan on /clans, from the league's own records
 * only; a clan without one has none, and the page says nothing for it.
 *
 * - `tournament`: a place 1 to 3 in a tournament finished in the last
 *   {@see self::TOURNAMENT_DAYS} days, of the clan's lineup (`team`) or of
 *   a player who is in the clan now (`player`, the entry's name). Read with
 *   TournamentPlacements from the newest {@see self::TOURNAMENTS} of them.
 * - `series`: the clan's latest won series (confirmed or decided by an
 *   admin) of the last {@see self::DAYS} days: the other side's tag, name
 *   and the score. Casual and rated alike.
 * - `streak`: a player of the clan whose last {@see self::STREAK} or more
 *   finished chess games (last {@see self::DAYS} days) were all wins.
 * - `wins`: games its players won since they joined, in the last 7 days:
 *   chess wins plus won series.
 * - `joined`: players who joined in the last 7 days; `founded` instead when
 *   the clan itself is that new (with its player count).
 *
 * Each clan's moments come best first by weight, then the newest. Cached
 * for a minute like the hashrate, so a search keystroke never reads them
 * again.
 */
final class ClanPride
{
    public const CACHE_KEY = 'clans.pride';

    public const CACHE_SECONDS = 60;

    /** Window for won series and streaks, in days. */
    public const DAYS = 30;

    /** Window for finished tournaments, in days, and how many of them are read at most. */
    public const TOURNAMENT_DAYS = 90;

    public const TOURNAMENTS = 6;

    /** Wins in a row that make a streak. */
    public const STREAK = 3;

    /** The weight from which a moment lights its card and may take the spotlight: a tournament place, a won series, a streak. */
    public const LIT = 50;

    /** Chess games read at most (newest first) for streaks and weekly wins. */
    private const GAMES = 2000;

    /** @var array<string, int> */
    private const WEIGHT = ['tournament' => 100, 'series' => 60, 'streak' => 50, 'wins' => 30, 'joined' => 25, 'founded' => 20];

    public function __construct(private TournamentPlacements $placements) {}

    /**
     * Clan id => its moments, best first; clans without a moment are absent.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->read());
    }

    /**
     * The clan with the strongest lit moment of all (weight, then the newest), or null when no clan has one:
     * a week's wins or new players never take the top of the page.
     *
     * @param  array<int, list<array<string, mixed>>>  $moments
     */
    public static function spotlight(array $moments): ?int
    {
        $best = null;

        foreach ($moments as $clanId => $list) {
            $top = $list[0] ?? null;

            if ($top === null || $top['weight'] < self::LIT) {
                continue;
            }

            if ($best === null || [$top['weight'], $top['at']] > [$best[1]['weight'], $best[1]['at']]) {
                $best = [$clanId, $top];
            }
        }

        return $best[0] ?? null;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    public function read(): array
    {
        $members = ClanMember::query()->with('user')->get(['id', 'clan_id', 'user_id', 'joined_at']);

        if ($members->isEmpty()) {
            return [];
        }

        /** @var array<int, ClanMember> $byUser */
        $byUser = $members->keyBy('user_id')->all();
        /** @var array<int, int> $lineupClan */
        $lineupClan = Lineup::query()->pluck('clan_id', 'id')->map(fn ($id): int => (int) $id)->all();
        $moments = [];
        $add = function (int $clanId, string $type, CarbonInterface|int $at, array $values = []) use (&$moments): void {
            $moments[$clanId][] = ['type' => $type, 'weight' => self::WEIGHT[$type], 'at' => $at instanceof CarbonInterface ? $at->getTimestamp() : $at, ...$values];
        };

        $this->tournaments($byUser, $lineupClan, $add);
        $weekly = $this->series($lineupClan, $byUser, $add);
        $this->chess($byUser, $weekly, $add);

        foreach ($weekly as $clanId => $won) {
            $add($clanId, 'wins', $won['at'], ['count' => $won['count']]);
        }

        $week = now()->subDays(7);
        $clans = Clan::query()->whereIn('id', $members->pluck('clan_id')->unique())->pluck('created_at', 'id');

        foreach ($members->groupBy('clan_id') as $clanId => $group) {
            $founded = $clans[$clanId] ?? null;
            $new = $group->filter(fn (ClanMember $member): bool => $member->joined_at->gte($week));

            if ($founded !== null && Carbon::parse($founded)->gte($week)) {
                $add((int) $clanId, 'founded', Carbon::parse($founded), ['count' => $group->count()]);
            } elseif ($new->isNotEmpty()) {
                $add((int) $clanId, 'joined', $new->max('joined_at'), ['count' => $new->count()]);
            }
        }

        foreach ($moments as $clanId => $list) {
            usort($list, fn (array $a, array $b): int => [$b['weight'], $b['at']] <=> [$a['weight'], $a['at']]);
            $moments[$clanId] = $list;
        }

        return $moments;
    }

    /**
     * Places 1 to 3 of the newest finished tournaments.
     *
     * @param  array<int, ClanMember>  $byUser
     * @param  array<int, int>  $lineupClan
     */
    private function tournaments(array $byUser, array $lineupClan, \Closure $add): void
    {
        $tournaments = Tournament::query()->where('status', TournamentStatus::Finished)
            ->where('starts_at', '>=', now()->subDays(self::TOURNAMENT_DAYS))
            ->latest('starts_at')->latest('id')->limit(self::TOURNAMENTS)->get();
        $podium = [];

        foreach ($tournaments as $tournament) {
            foreach ($this->placements->of($tournament) ?? [] as $row) {
                if ($row['place'] > 3) {
                    break;
                }

                foreach ($row['participants'] as $participantId) {
                    $podium[$participantId] = [$tournament, $row['place']];
                }
            }
        }

        if ($podium === []) {
            return;
        }

        $participants = TournamentParticipant::query()->whereKey(array_keys($podium))->get(['id', 'user_id', 'lineup_id', 'name']);

        foreach ($participants as $participant) {
            [$tournament, $place] = $podium[$participant->id];
            $values = ['place' => $place, 'tournament' => $tournament->name, 'tournament_id' => $tournament->id, 'weight' => self::WEIGHT['tournament'] - 10 * ($place - 1)];

            if ($participant->lineup_id !== null && isset($lineupClan[$participant->lineup_id])) {
                $add($lineupClan[$participant->lineup_id], 'tournament', $tournament->starts_at, [...$values, 'player' => null]);
            } elseif ($participant->user_id !== null && isset($byUser[$participant->user_id])) {
                $add($byUser[$participant->user_id]->clan_id, 'tournament', $tournament->starts_at, [...$values, 'player' => $participant->name]);
            }
        }
    }

    /**
     * The latest won series per clan; returns clan id => series won this week and the newest of them.
     *
     * @param  array<int, int>  $lineupClan
     * @param  array<int, ClanMember>  $byUser
     * @return array<int, array{count: int, at: int}>
     */
    private function series(array $lineupClan, array $byUser, \Closure $add): array
    {
        $weekly = [];

        if ($lineupClan === []) {
            return $weekly;
        }

        $since = now()->subDays(self::DAYS);
        $week = now()->subDays(7);
        $matches = SeriesMatch::query()
            ->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])
            ->whereIn('winner', SeriesMatch::SIDES)
            // A win by forfeit (a no-show) is no moment to be proud of: skipped, never told as "Beat X 0:0".
            ->where(fn ($query) => $query->whereNull('resolution')->orWhere('resolution', '!=', SeriesResolution::Forfeit))
            ->where(fn ($query) => $query->where('finished_at', '>=', $since)->orWhere(fn ($query) => $query->whereNull('finished_at')->where('updated_at', '>=', $since)))
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->get(['id', 'number', 'mode', 'winner', 'challenger_lineup_id', 'challenged_lineup_id', 'challenger_name', 'challenged_name', 'challenger_tag', 'challenged_tag', 'result_games', 'finished_at', 'updated_at']);
        $latest = [];

        foreach ($matches as $match) {
            $side = (string) $match->winner;
            $lineupId = $side === 'challenger' ? $match->challenger_lineup_id : $match->challenged_lineup_id;
            $clanId = $lineupId === null ? null : ($lineupClan[$lineupId] ?? null);

            if ($clanId === null) {
                continue;
            }

            $at = $match->finished_at ?? $match->updated_at;

            if ($at !== null && $at->gte($week)) {
                $weekly[$clanId] = ['count' => ($weekly[$clanId]['count'] ?? 0) + 1, 'at' => max($weekly[$clanId]['at'] ?? 0, $at->getTimestamp())];
            }

            if (isset($latest[$clanId])) {
                continue;
            }

            $other = SeriesMatch::otherSide($side);
            $score = SeriesMatch::seriesScore($match->result_games);
            $latest[$clanId] = true;
            $add($clanId, 'series', $at ?? now(), [
                'number' => $match->number,
                'mode' => $match->mode,
                'opponent_tag' => $match->sideTag($other),
                'opponent' => $match->sideName($other),
                'score' => $score[$side].':'.$score[$other],
            ]);
        }

        return $weekly;
    }

    /**
     * Streaks per player and chess wins this week per clan (added to `$weekly`).
     *
     * @param  array<int, ClanMember>  $byUser
     * @param  array<int, array{count: int, at: int}>  $weekly
     */
    private function chess(array $byUser, array &$weekly, \Closure $add): void
    {
        $ids = array_keys($byUser);
        $games = ChessGame::query()
            ->where('status', ChessGameStatus::Finished)
            ->whereIn('result', ['1-0', '0-1', '1/2-1/2'])
            // A game decided by forfeit (a missed first move, a withdrawal) is skipped: no streak link, no week's win.
            ->where(fn ($query) => $query->whereNull('end_reason')->orWhere('end_reason', '!=', ChessEndReason::Forfeit))
            ->where('ended_at', '>=', now()->subDays(self::DAYS))
            ->where(fn ($query) => $query->whereIn('white_id', $ids)->orWhereIn('black_id', $ids))
            ->orderByDesc('ended_at')->orderByDesc('id')
            ->limit(self::GAMES)
            ->toBase()->get(['white_id', 'black_id', 'result', 'ended_at']);

        $week = now()->subDays(7);
        /** @var array<int, array{run: int, open: bool, at: int}> $streaks */
        $streaks = [];

        foreach ($games as $game) {
            $ended = Carbon::parse($game->ended_at);

            foreach (['1-0' => [$game->white_id, $game->black_id], '0-1' => [$game->black_id, $game->white_id], '1/2-1/2' => [$game->white_id, $game->black_id]][$game->result] as $index => $userId) {
                $member = $userId === null ? null : ($byUser[(int) $userId] ?? null);

                if ($member === null) {
                    continue;
                }

                $won = $game->result !== '1/2-1/2' && $index === 0;
                $streak = $streaks[$member->user_id] ?? ['run' => 0, 'open' => true, 'at' => $ended->getTimestamp()];

                if ($streak['open']) {
                    $streak['run'] += $won ? 1 : 0;
                    $streak['open'] = $won;
                }

                $streaks[$member->user_id] = $streak;

                if ($won && $ended->gte($week) && $ended->gte($member->joined_at)) {
                    $weekly[$member->clan_id] = ['count' => ($weekly[$member->clan_id]['count'] ?? 0) + 1, 'at' => max($weekly[$member->clan_id]['at'] ?? 0, $ended->getTimestamp())];
                }
            }
        }

        foreach ($streaks as $userId => $streak) {
            if ($streak['run'] >= self::STREAK) {
                $member = $byUser[$userId];
                $add($member->clan_id, 'streak', $streak['at'], ['player' => $member->user->displayName(), 'count' => $streak['run'], 'weight' => self::WEIGHT['streak'] + min(9, $streak['run'])]);
            }
        }
    }
}
