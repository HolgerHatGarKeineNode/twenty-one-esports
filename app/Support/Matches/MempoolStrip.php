<?php

namespace App\Support\Matches;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\ScoreRun;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\SeriesPresenter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The mempool strip on /matches: the matches of every game, played on the
 * left and waiting on the right, casual and rated. It is not the chain: the
 * season chain is the one blockchain (/mining), and a finished rated match
 * that mined shows its block there (ChainStamps).
 *
 * Five a side, merged by time across the kinds, because the strip shows
 * what happens in the league now and a quota per game would put a three-day
 * old series next to a game from a minute ago. Finished: the five latest
 * results, oldest left, the newest next to the divider. Waiting: the games
 * running now (latest activity first), then the scheduled series (soonest
 * first). Aborted games never happened and stay out.
 *
 * Board games only while their route is registered: the registry has only
 * the ones switched on, and a route table cached with the switch off has no
 * board page to link. A fixed number of queries for any number of matches
 * (tests/Feature/Matches/MempoolStripTest.php).
 *
 * `chain` narrows the strip to one side of the league (plan
 * "Mempool-Streifen", P4): `season` keeps the rated matches, whose wins
 * mine the season chain; `casual` keeps the casual ones, which never mine.
 * Row 1 of the header links both, next to the whole mempool.
 *
 * `runs` adds the highscore attempts of the score games (ScoreAttempts):
 * a verified run on the finished side, an unconfirmed one on the waiting
 * side, merged by time as every other kind, but at most ATTEMPTS_PER_SIDE
 * of them a side, so matches always keep the rest; never on the `season`
 * chain, they mine nothing. /matches asks for them; the stream's mempool slide
 * (App\Support\TwentyOne\Stream\MempoolSlides) does not.
 */
final class MempoolStrip
{
    public const SIDE = 5;

    /**
     * Highscore attempts take at most this many cubes a side: a busy
     * Blockfill week would otherwise push every match out of the strip.
     */
    public const ATTEMPTS_PER_SIDE = 2;

    /** The `chain` filter values of /matches, with whether they keep rated matches. */
    public const CHAINS = ['season' => true, 'casual' => false];

    /** Series states the waiting side shows: scheduled or playing, reported, disputed. */
    public const WAITING_SERIES = [SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed];

    /**
     * `live`: whether a season runs now, so the strip's copy promises mining
     * only then (before Block 0 and between seasons no win mines).
     *
     * @return array{finished: list<array<string, mixed>>, running: list<array<string, mixed>>, live: bool}
     */
    public static function build(?User $viewer = null, ?string $chain = null, bool $runs = false): array
    {
        $runs = $runs && $chain !== 'season';
        $boards = self::boardSlugs();
        $sides = ['challengerLineup.clan', 'challengedLineup.clan'];
        $players = ['white', 'black'];

        /** @var list<array{kind: string, model: SeriesMatch|ChessGame|BoardGame|StackerRun|ScoreRun, at: int}> $finished */
        $finished = [
            ...self::onChain(SeriesMatch::query(), $chain)->with($sides)->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])
                ->orderByDesc('finished_at')->limit(self::SIDE)->get()
                ->map(fn (SeriesMatch $match): array => self::item('series', $match, $match->finished_at))->all(),
            ...self::onChain(ChessGame::query(), $chain)->with($players)->where('status', ChessGameStatus::Finished)
                ->orderByDesc('ended_at')->limit(self::SIDE)->get()
                ->map(fn (ChessGame $game): array => self::item('chess', $game, $game->ended_at))->all(),
            ...($boards === [] ? [] : self::onChain(BoardGame::query(), $chain)->with($players)->whereIn('game', $boards)->where('status', BoardGameStatus::Finished)
                ->orderByDesc('ended_at')->limit(self::SIDE)->get()
                ->map(fn (BoardGame $game): array => self::item('board', $game, $game->ended_at))->all()),
            ...($runs ? array_map(fn (StackerRun|ScoreRun $run): array => self::item('run', $run, ScoreAttempts::at($run)), ScoreAttempts::latest('done', self::ATTEMPTS_PER_SIDE)) : []),
        ];

        $running = [
            ...self::onChain(SeriesMatch::query(), $chain)->with(['latestReport', ...$sides])->whereIn('status', self::WAITING_SERIES)
                ->orderBy('start_at')->limit(self::SIDE)->get()
                ->map(fn (SeriesMatch $match): array => self::item('series', $match, $match->start_at ?? $match->created_at))->all(),
            ...self::onChain(ChessGame::query(), $chain)->with($players)->where('status', ChessGameStatus::Active)
                ->orderByDesc('updated_at')->limit(self::SIDE)->get()
                ->map(fn (ChessGame $game): array => self::item('chess', $game, $game->updated_at))->all(),
            ...($boards === [] ? [] : self::onChain(BoardGame::query(), $chain)->with($players)->whereIn('game', $boards)->where('status', BoardGameStatus::Active)
                ->orderByDesc('updated_at')->limit(self::SIDE)->get()
                ->map(fn (BoardGame $game): array => self::item('board', $game, $game->updated_at))->all()),
            ...($runs ? array_map(fn (StackerRun|ScoreRun $run): array => self::item('run', $run, ScoreAttempts::at($run)), ScoreAttempts::latest('waiting', self::ATTEMPTS_PER_SIDE)) : []),
        ];

        usort($finished, fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $finished = array_reverse(array_slice($finished, 0, self::SIDE));

        // Running now first, latest activity first; then the scheduled series, soonest first.
        $next = fn (array $item): bool => $item['model'] instanceof SeriesMatch && SeriesPresenter::blockState($item['model']) === 'next';
        usort($running, fn (array $a, array $b): int => [$next($a), $next($a) ? $a['at'] : -$a['at']] <=> [$next($b), $next($b) ? $b['at'] : -$b['at']]);
        $running = array_slice($running, 0, self::SIDE);

        $stamps = ChainStamps::for(self::ratedIds($finished));
        $last = count($finished) - 1;
        $attempts = array_values(array_filter(array_column([...$finished, ...$running], 'model'), fn (Model $model): bool => $model instanceof StackerRun || $model instanceof ScoreRun));
        $links = $attempts === [] ? [] : ScoreAttempts::links($attempts);

        return [
            'finished' => array_map(fn (array $item, int $index): array => self::present($item, $index === $last, $viewer, $stamps, $links), $finished, array_keys($finished)),
            'running' => array_map(fn (array $item): array => self::present($item, false, $viewer, [], $links), $running),
            'live' => Seasons::isLive(),
        ];
    }

    /**
     * How many matches and highscore attempts wait in the mempool: the
     * waiting side of the strip uncut, every game, casual and rated
     * (scheduled and playing series, reported and disputed ones, running
     * chess and board games, attempts waiting for the verifier or an admin,
     * ScoreAttempts). One query for any number of them, a count per kind as
     * its columns; row 1 of the header caches it
     * (App\Support\Navigation\ShellNavigation::chain()).
     */
    public static function waiting(): int
    {
        $boards = self::boardSlugs();
        $scores = ScoreAttempts::scoreSlugs(ScoreAttempts::slugs());
        $counts = DB::query()
            ->selectSub(SeriesMatch::query()->whereIn('status', self::WAITING_SERIES)->selectRaw('count(*)'), 'series')
            ->selectSub(ChessGame::query()->where('status', ChessGameStatus::Active)->selectRaw('count(*)'), 'chess')
            ->when($boards !== [], fn ($query) => $query->selectSub(BoardGame::query()->whereIn('game', $boards)->where('status', BoardGameStatus::Active)->selectRaw('count(*)'), 'boards'))
            ->when(ScoreAttempts::blockfill(), fn ($query) => $query->selectSub(ScoreAttempts::stacker('waiting')->selectRaw('count(*)'), 'stacker'))
            ->when($scores !== [], fn ($query) => $query->selectSub(ScoreAttempts::scores($scores, 'waiting')->selectRaw('count(*)'), 'scores'))
            ->first();

        return array_sum(array_map('intval', (array) $counts));
    }

    /**
     * A query of any kind narrowed to a `chain`: the rated matches for
     * `season`, the casual ones for `casual`, untouched for anything else.
     * The strip and the table of /matches share it.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function onChain(Builder $query, ?string $chain): Builder
    {
        $rated = self::CHAINS[$chain ?? ''] ?? null;

        return $rated === null ? $query : $query->where('rated', $rated);
    }

    /**
     * The board games whose matches the strip and the table may show: the
     * ones switched on, and only while their page is routed.
     *
     * @return list<string>
     */
    public static function boardSlugs(): array
    {
        return Route::has('board.show') ? array_keys(app(GameRegistry::class)->boards()) : [];
    }

    /**
     * @return array{kind: string, model: SeriesMatch|ChessGame|BoardGame|StackerRun|ScoreRun, at: int}
     */
    private static function item(string $kind, SeriesMatch|ChessGame|BoardGame|StackerRun|ScoreRun $model, ?CarbonInterface $at): array
    {
        return ['kind' => $kind, 'model' => $model, 'at' => $at?->getTimestamp() ?? 0];
    }

    /**
     * The finished rated matches, by attestation source.
     *
     * @param  list<array{kind: string, model: SeriesMatch|ChessGame|BoardGame|StackerRun|ScoreRun, at: int}>  $items
     * @return array<string, list<int>>
     */
    private static function ratedIds(array $items): array
    {
        $ids = [SeasonAttestation::SERIES => [], SeasonAttestation::CHESS => [], SeasonAttestation::BOARD => []];

        foreach ($items as $item) {
            // A highscore attempt is never rated: it mines no block.
            if (! $item['model'] instanceof StackerRun && ! $item['model'] instanceof ScoreRun && $item['model']->rated) {
                $ids[$item['kind']][] = $item['model']->id;
            }
        }

        return $ids;
    }

    /**
     * @param  array{kind: string, model: SeriesMatch|ChessGame|BoardGame|StackerRun|ScoreRun, at: int}  $item
     * @param  array<string, array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}>  $stamps
     * @param  array<string, string>  $links  where each highscore attempt links (ScoreAttempts::links())
     * @return array<string, mixed>
     */
    private static function present(array $item, bool $newest, ?User $viewer, array $stamps, array $links): array
    {
        $model = $item['model'];

        if ($model instanceof StackerRun || $model instanceof ScoreRun) {
            return ScoreAttempts::block($model, $links[ScoreAttempts::key($model)], $newest);
        }

        $chain = $stamps[$item['kind'].':'.$model->id] ?? null;

        return match (true) {
            $model instanceof SeriesMatch => SeriesPresenter::block($model, $newest, $viewer, $chain),
            $model instanceof ChessGame => MatchBlocks::chess($model, $newest, $chain),
            default => MatchBlocks::board($model, $newest, $chain),
        };
    }
}
