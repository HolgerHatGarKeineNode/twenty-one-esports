<?php

namespace App\Support\Players;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Scores\ScorePoints;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

/**
 * A player's record in every registered score game (Blockfill and any later
 * ScoreGame) on their page: per game and mode they have a verified value in,
 * the personal best, the leaderboard running now (a Blockfill week) with
 * their best and place in it, their place on the game's points ladder
 * (ScorePoints, as the game's page sums it) and their latest verified attempts.
 *
 * Read from score_runs only: a verified Blockfill run is mirrored there for
 * its week (BlockfillWeeks::record()), every other score game writes there
 * directly. Counted are the runs a leaderboard counts: verified, not
 * rejected, with a value, never a director's entry (a correction, not an
 * attempt). Values only: no proof link (it may name a game account) and no
 * account id is read.
 *
 * Driven by the registry: a score game registered later shows here with no
 * change. A game or mode without a verified run has no card. Two queries for
 * every game together (bests, latest attempts) and one for the running
 * leaderboards; per card the standings of its leaderboard and its points ladder.
 *
 * @phpstan-type Attempt array{value: string, at: CarbonInterface|null}
 * @phpstan-type Board array{title: string, href: string, value: string|null, place: int|null, of: int}
 * @phpstan-type Card array{key: string, game: string, mode: string, name: string, href: string, replays: string|null, best: string, runs: int, board: Board|null, points: array{place: int, points: int, of: int}|null, attempts: list<Attempt>}
 */
final class PlayerScores
{
    /** Latest attempts per card. */
    public const ATTEMPTS = 3;

    public function __construct(private readonly User $user) {}

    /**
     * One card per score game and mode with a verified value, in the
     * registry's order of games and modes.
     *
     * @return list<Card>
     */
    public function cards(): array
    {
        $games = app(GameRegistry::class)->scores();

        if ($games === []) {
            return [];
        }

        $counted = fn () => ScoreRun::query()->where('user_id', $this->user->id)->whereIn('game', array_keys($games))
            ->where('source', '!=', ScoreRun::DIRECTOR)->whereNotNull('verified_at')->whereNull('rejected_at')->whereNotNull('value');

        $totals = $counted()->select(['game', 'mode'])
            ->selectRaw('min(value) as low, max(value) as high, count(*) as runs')->groupBy('game', 'mode')
            ->toBase()->get()->keyBy(fn (object $row): string => $row->game.'|'.$row->mode);

        if ($totals->isEmpty()) {
            return [];
        }

        $recent = ScoreRun::query()->withoutGlobalScopes()->fromSub(
            $counted()->select(['id', 'game', 'mode', 'value', 'achieved_at'])
                ->selectRaw('row_number() over (partition by game, mode order by achieved_at desc, id desc) as recent'),
            'score_runs',
        )->where('recent', '<=', self::ATTEMPTS)->orderBy('recent')->get()->groupBy(fn (ScoreRun $run): string => $run->game.'|'.$run->mode);

        $boards = $this->boards(array_keys($games));
        $runs = app(ScoreRuns::class);
        $points = app(ScorePoints::class);
        $cards = [];

        foreach ($games as $slug => $game) {
            foreach ($game->modes() as $mode) {
                $total = $totals->get($slug.'|'.$mode->slug);

                if ($total === null) {
                    continue;
                }

                $metric = $game->metric($mode);
                $board = $boards[$slug.'|'.$mode->slug] ?? null;

                $cards[] = [
                    'key' => $slug.'-'.$mode->slug,
                    'game' => $slug,
                    'mode' => $mode->slug,
                    'name' => GameNames::game($slug).' · '.GameNames::mode($slug, $mode->slug),
                    'href' => Route::has('scores.show') ? route('scores.show', $slug) : GameNames::page($slug),
                    // Blockfill keeps replays: the replays page narrowed to this player (StackerReplays::ofPlayer()).
                    'replays' => $slug === Blockfill::SLUG && Route::has('stacker.replays') ? route('stacker.replays', ['player' => $this->user->npub]) : null,
                    'best' => $metric->format((int) ($metric->lowerIsBetter() ? $total->low : $total->high)),
                    'runs' => (int) $total->runs,
                    'board' => $board === null ? null : $this->standing($board, $runs->standings($board), $metric->format(...)),
                    'points' => $this->ladderPlace($points->ladder($game, $mode->slug)),
                    'attempts' => array_values(array_map(fn (ScoreRun $run): array => ['value' => $metric->format((int) $run->value), 'at' => $run->achieved_at],
                        ($recent->get($slug.'|'.$mode->slug) ?? collect())->all())),
                ];
            }
        }

        return $cards;
    }

    /**
     * The leaderboard of each game and mode whose window is open now, the
     * earliest when there are more (as home picks it: a Blockfill week is this week's).
     *
     * @param  list<string>  $slugs
     * @return array<string, Tournament>
     */
    private function boards(array $slugs): array
    {
        $now = now();

        return Tournament::query()
            ->whereIn('game', $slugs)
            ->where(['format' => TournamentFormat::Leaderboard, 'status' => TournamentStatus::Running])
            ->whereNotNull('published_at')->where('starts_at', '<=', $now)
            ->orderBy('starts_at')->orderBy('id')->get()
            ->filter(fn (Tournament $board): bool => ScoreWindow::of($board)->contains($now))
            ->unique(fn (Tournament $board): string => $board->game.'|'.$board->mode)
            ->keyBy(fn (Tournament $board): string => $board->game.'|'.$board->mode)
            ->all();
    }

    /**
     * The player's best and place on a running leaderboard; value and place
     * null while they have none in it.
     *
     * @param  list<ScoreStanding>  $standings
     * @param  callable(int): string  $format
     * @return array{title: string, href: string, value: string|null, place: int|null, of: int}
     */
    private function standing(Tournament $board, array $standings, callable $format): array
    {
        $placed = array_values(array_filter($standings, fn (ScoreStanding $standing): bool => $standing->place !== null));
        $mine = null;

        foreach ($placed as $standing) {
            if ($standing->participant->user_id === $this->user->id) {
                $mine = $standing;
                break;
            }
        }

        return [
            'title' => $board->title(),
            'href' => Route::has('tournaments.scores') ? route('tournaments.scores', $board) : route('tournaments.show', $board),
            'value' => $mine?->value === null ? null : $format($mine->value),
            'place' => $mine?->place,
            'of' => count($placed),
        ];
    }

    /**
     * The player's place on a points ladder (points per user id, most first);
     * null while they have no points on it.
     *
     * @param  array<int, int>  $ladder
     * @return array{place: int, points: int, of: int}|null
     */
    private function ladderPlace(array $ladder): ?array
    {
        $index = array_search($this->user->id, array_keys($ladder), true);

        return $index === false ? null : ['place' => $index + 1, 'points' => $ladder[$this->user->id], 'of' => count($ladder)];
    }
}
