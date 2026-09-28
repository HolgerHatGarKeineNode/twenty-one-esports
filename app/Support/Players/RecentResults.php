<?php

namespace App\Support\Players;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\LineupSeat;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\GameNames;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A player's latest results, chess games and series together, newest first,
 * each from the player's side: the opponent's face, the score and what the
 * result did to the player's rating (their own, or their lineup's).
 *
 * This is the "results chain" of a player, kept as its own small read so the
 * public player page (P31) and the personal hub (P30, PlayerHub) can share
 * one helper once both are on master; the Result shape matches PlayerHub's.
 *
 * A lineup's series count for a player from the moment their seat was
 * accepted: someone who joined later does not inherit the lineup's past.
 * Four queries whatever the limit: games, series, roster faces, deltas.
 *
 * @phpstan-type Result array{key: string, href: string, opponent: string, face: User|null, clan: \App\Models\Clan|null, tag: string|null, outcome: 'win'|'loss'|'draw', score: string, game: string, at: CarbonInterface|null, delta: int|null}
 */
final class RecentResults
{
    public const LIMIT = 10;

    /** @var Collection<int, LineupSeat>|null */
    private ?Collection $seats = null;

    public function __construct(private readonly User $user) {}

    /**
     * @return list<Result>
     */
    public function latest(int $limit = self::LIMIT): array
    {
        $me = $this->user->id;

        $games = ChessGame::query()->where('status', ChessGameStatus::Finished)
            ->where(fn (Builder $query) => $query->where('white_id', $me)->orWhere('black_id', $me))
            ->with(['white', 'black'])->latest('updated_at')->latest('id')->limit($limit)->get();

        $series = $this->series()->latest('finished_at')->latest('id')->limit($limit)->get();

        $deltas = $this->deltas(array_values(array_map(intval(...), $games->modelKeys())), array_values(array_map(intval(...), $series->modelKeys())));
        $faces = $this->rosterFaces($series);
        $results = [];

        foreach ($games as $game) {
            $white = $game->white_id === $me;
            $opponent = $white ? $game->black : $game->white;
            $outcome = match ($game->result) {
                '1-0' => $white ? 'win' : 'loss',
                '0-1' => $white ? 'loss' : 'win',
                default => 'draw',
            };

            $results[] = [
                'key' => 'chess-'.$game->id,
                'href' => route('games.show', $game),
                // A deleted player's side is ChessGame::deletedPlayer(), never null.
                'opponent' => $opponent->displayName(),
                'face' => $opponent,
                'clan' => null,
                'tag' => null,
                'outcome' => $outcome,
                'score' => match ($outcome) {
                    'win' => '1–0',
                    'loss' => '0–1',
                    default => '½–½',
                },
                'game' => GameNames::full('chess', $game->mode),
                'at' => $game->updated_at,
                'delta' => $deltas['chess:'.$game->id] ?? null,
            ];
        }

        foreach ($series as $match) {
            $side = $this->sideOf($match);

            if ($side === null) {
                continue;
            }

            $other = SeriesMatch::otherSide($side);
            $score = SeriesMatch::seriesScore($match->result_games);

            $results[] = [
                'key' => 'series-'.$match->id,
                'href' => route('matches.show', $match),
                'opponent' => $match->sideName($other),
                'face' => $faces[$match->rosterSide($other)[0] ?? 0] ?? null,
                'clan' => $match->sideClan($other),
                'tag' => $match->sideTag($other),
                'outcome' => $match->winner === $side ? 'win' : 'loss',
                'score' => $score[$side].' : '.$score[$other],
                'game' => GameNames::full($match->game, $match->mode),
                'at' => $match->finished_at ?? $match->updated_at,
                'delta' => $deltas['series:'.$match->id] ?? null,
            ];
        }

        usort($results, fn (array $a, array $b): int => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        return array_slice($results, 0, $limit);
    }

    /**
     * The rating subjects a player's results move: their own, and every
     * lineup they hold an accepted seat in.
     *
     * @return list<string>
     */
    public function subjects(): array
    {
        return ['user:'.$this->user->id, ...$this->seats()->map(fn (LineupSeat $seat): string => 'lineup:'.$seat->lineup_id)->values()->all()];
    }

    /**
     * @return Collection<int, LineupSeat> accepted seats, keyed by lineup id
     */
    private function seats(): Collection
    {
        return $this->seats ??= LineupSeat::query()->where('user_id', $this->user->id)->whereNotNull('accepted_at')->get()->keyBy('lineup_id');
    }

    /**
     * Decided series this player played: a roster side they are on, or a
     * lineup side they held a seat in when it finished.
     *
     * @return Builder<SeriesMatch>
     */
    private function series(): Builder
    {
        $me = $this->user->id;

        return SeriesMatch::query()
            ->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->whereIn('winner', SeriesMatch::SIDES)
            ->where(function (Builder $query) use ($me): void {
                $query->whereJsonContains('sides->challenger', $me)->orWhereJsonContains('sides->challenged', $me);

                foreach ($this->seats() as $lineupId => $seat) {
                    $query->orWhere(fn (Builder $one) => $one
                        ->where(fn (Builder $either) => $either->where('challenger_lineup_id', $lineupId)->orWhere('challenged_lineup_id', $lineupId))
                        ->where('finished_at', '>=', $seat->accepted_at));
                }
            })
            ->with(['challengerLineup.clan', 'challengedLineup.clan']);
    }

    /**
     * The side this player played in a series: their roster side, else the
     * side of a lineup they sit in.
     */
    private function sideOf(SeriesMatch $match): ?string
    {
        foreach (SeriesMatch::SIDES as $side) {
            if (in_array($this->user->id, $match->rosterSide($side), true)) {
                return $side;
            }
        }

        foreach (SeriesMatch::SIDES as $side) {
            $lineup = $side === 'challenger' ? $match->challenger_lineup_id : $match->challenged_lineup_id;

            if ($lineup !== null && $this->seats()->has((int) $lineup)) {
                return $side;
            }
        }

        return null;
    }

    /**
     * The users of every roster side of these series, loaded once.
     *
     * @param  Collection<int, SeriesMatch>  $matches
     * @return array<int, User>
     */
    private function rosterFaces(Collection $matches): array
    {
        $ids = $matches->flatMap(fn (SeriesMatch $match): array => [...$match->rosterSide('challenger'), ...$match->rosterSide('challenged')])->unique()->values();

        return $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * What these results did to this player's ratings, keyed `chess:<id>` / `series:<id>`.
     *
     * @param  list<int>  $games  chess game ids
     * @param  list<int>  $series  series match ids
     * @return array<string, int>
     */
    private function deltas(array $games, array $series): array
    {
        if ($games === [] && $series === []) {
            return [];
        }

        return RatingChange::query()->whereIn('rating_id', Rating::query()->whereIn('subject', $this->subjects())->select('id'))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('source', RatingChange::CHESS)->whereIn('source_id', $games))
                ->orWhere(fn (Builder $query) => $query->where('source', RatingChange::SERIES)->whereIn('source_id', $series)))
            ->get(['source', 'source_id', 'delta'])
            ->mapWithKeys(fn (RatingChange $change): array => [$change->source.':'.$change->source_id => (int) $change->delta])
            ->all();
    }
}
