<?php

namespace App\Support\Matches;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\SeriesPresenter;
use Carbon\CarbonInterface;
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
 */
final class MempoolStrip
{
    public const SIDE = 5;

    /**
     * `live`: whether a season runs now, so the strip's copy promises mining
     * only then (before Block 0 and between seasons no win mines).
     *
     * @return array{finished: list<array<string, mixed>>, running: list<array<string, mixed>>, live: bool}
     */
    public static function build(?User $viewer = null): array
    {
        $boards = self::boardSlugs();
        $sides = ['challengerLineup.clan', 'challengedLineup.clan'];
        $players = ['white', 'black'];

        /** @var list<array{kind: string, model: SeriesMatch|ChessGame|BoardGame, at: int}> $finished */
        $finished = [
            ...SeriesMatch::query()->with($sides)->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])
                ->orderByDesc('finished_at')->limit(self::SIDE)->get()
                ->map(fn (SeriesMatch $match): array => self::item('series', $match, $match->finished_at))->all(),
            ...ChessGame::query()->with($players)->where('status', ChessGameStatus::Finished)
                ->orderByDesc('ended_at')->limit(self::SIDE)->get()
                ->map(fn (ChessGame $game): array => self::item('chess', $game, $game->ended_at))->all(),
            ...($boards === [] ? [] : BoardGame::query()->with($players)->whereIn('game', $boards)->where('status', BoardGameStatus::Finished)
                ->orderByDesc('ended_at')->limit(self::SIDE)->get()
                ->map(fn (BoardGame $game): array => self::item('board', $game, $game->ended_at))->all()),
        ];

        $running = [
            ...SeriesMatch::query()->with(['latestReport', ...$sides])->whereIn('status', [SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed])
                ->orderBy('start_at')->limit(self::SIDE)->get()
                ->map(fn (SeriesMatch $match): array => self::item('series', $match, $match->start_at ?? $match->created_at))->all(),
            ...ChessGame::query()->with($players)->where('status', ChessGameStatus::Active)
                ->orderByDesc('updated_at')->limit(self::SIDE)->get()
                ->map(fn (ChessGame $game): array => self::item('chess', $game, $game->updated_at))->all(),
            ...($boards === [] ? [] : BoardGame::query()->with($players)->whereIn('game', $boards)->where('status', BoardGameStatus::Active)
                ->orderByDesc('updated_at')->limit(self::SIDE)->get()
                ->map(fn (BoardGame $game): array => self::item('board', $game, $game->updated_at))->all()),
        ];

        usort($finished, fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $finished = array_reverse(array_slice($finished, 0, self::SIDE));

        // Running now first, latest activity first; then the scheduled series, soonest first.
        $next = fn (array $item): bool => $item['model'] instanceof SeriesMatch && SeriesPresenter::blockState($item['model']) === 'next';
        usort($running, fn (array $a, array $b): int => [$next($a), $next($a) ? $a['at'] : -$a['at']] <=> [$next($b), $next($b) ? $b['at'] : -$b['at']]);
        $running = array_slice($running, 0, self::SIDE);

        $stamps = ChainStamps::for(self::ratedIds($finished));
        $last = count($finished) - 1;

        return [
            'finished' => array_map(fn (array $item, int $index): array => self::present($item, $index === $last, $viewer, $stamps), $finished, array_keys($finished)),
            'running' => array_map(fn (array $item): array => self::present($item, false, $viewer, []), $running),
            'live' => Seasons::isLive(),
        ];
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
     * @return array{kind: string, model: SeriesMatch|ChessGame|BoardGame, at: int}
     */
    private static function item(string $kind, SeriesMatch|ChessGame|BoardGame $model, ?CarbonInterface $at): array
    {
        return ['kind' => $kind, 'model' => $model, 'at' => $at?->getTimestamp() ?? 0];
    }

    /**
     * The finished rated matches, by attestation source.
     *
     * @param  list<array{kind: string, model: SeriesMatch|ChessGame|BoardGame, at: int}>  $items
     * @return array<string, list<int>>
     */
    private static function ratedIds(array $items): array
    {
        $ids = [SeasonAttestation::SERIES => [], SeasonAttestation::CHESS => [], SeasonAttestation::BOARD => []];

        foreach ($items as $item) {
            if ($item['model']->rated) {
                $ids[$item['kind']][] = $item['model']->id;
            }
        }

        return $ids;
    }

    /**
     * @param  array{kind: string, model: SeriesMatch|ChessGame|BoardGame, at: int}  $item
     * @param  array<string, array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}>  $stamps
     * @return array<string, mixed>
     */
    private static function present(array $item, bool $newest, ?User $viewer, array $stamps): array
    {
        $model = $item['model'];
        $chain = $stamps[$item['kind'].':'.$model->id] ?? null;

        return match (true) {
            $model instanceof SeriesMatch => SeriesPresenter::block($model, $newest, $viewer, $chain),
            $model instanceof ChessGame => MatchBlocks::chess($model, $newest, $chain),
            default => MatchBlocks::board($model, $newest, $chain),
        };
    }
}
