<?php

namespace App\Support\Caster\GameMoments;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\PongMatch;
use App\Models\SeriesMatch;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\SceneTopic;
use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\HyperScene;
use App\Support\TwentyOne\Stream\PongScene;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use Closure;
use Throwable;

/**
 * The game moments of a commentary topic (SceneFacts::forTopic() puts them
 * under `moments`): what the games its slides show are doing right now, as
 * structured facts with a mood (Mood) for the caster's emotion. Live games
 * (chess as the live-games facts name them, the board game, the
 * Hyperbitcoinization matches and series the stream shows), the Pong
 * results, a tournament under way, the score games' week.
 *
 * Only what the games' own state says: every moment class documents its
 * rule. A source that fails leaves its moments out (no moment rather than
 * a wrong one); at night none (the night topics name no player). At most
 * MAX moments, taken in turn from each game so one long game does not
 * crowd out the others.
 */
final class GameMoments
{
    /** Moments per topic. */
    public const MAX = 12;

    /** Chess games said, as SceneFacts names them (rankedChess()). */
    public const CHESS_GAMES = 3;

    /** Running chess games ranked, newest first (the snapshot of 2026-10-11 had 12). */
    public const CHESS_CANDIDATES = 30;

    /**
     * What a chess moment is worth to the commentary (rankedChess()): a check, a comeback, a low clock and a
     * material swing first, an endgame next; a game's worth is the sum of its moments'. Our weighting, no source.
     */
    public const CHESS_WEIGHTS = ['check' => 5, 'comeback' => 5, 'clock' => 4, 'exchange' => 4, 'capture' => 3, 'level' => 1];

    /** A material moment's worth by its mood (dominance is the bigger swing). */
    public const MATERIAL_WEIGHTS = [Mood::DOMINANCE => 4, Mood::TENSION => 3, Mood::CLOSE => 2];

    /** An endgame (near the end) is worth this; the opening and the middlegame nothing. */
    public const ENDGAME_WEIGHT = 3;

    /**
     * Moment slots whose value is a player (or a team): PatternScripts treats a line with one as a name line,
     * said only where names are allowed. `lead` and every `mood_*` slot name the player the moment is about.
     */
    public const PERSON_SLOTS = [
        'lead', 'mood_tension', 'mood_comeback', 'mood_dominance', 'mood_close', 'mood_upset',
        'check_by', 'check_to', 'capture_by', 'comeback_by', 'ahead', 'behind', 'clock_low',
        'race_leader', 'race_trailer', 'one_step', 'men_leader', 'men_trailer', 'mill_owner', 'flying', 'pieces_leader', 'pieces_trailer', 'king_owner', 'captor', 'count_leader',
        'hyper_leader', 'duel_a', 'duel_b', 'bank_by', 'conqueror', 'series_leader', 'series_point', 'pong_winner', 'pong_loser', 'saver',
        'finalist_a', 'finalist_b', 'upset_winner', 'upset_loser', 'score_leader', 'score_second', 'tied_a', 'tied_b', 'new_top', 'dethroned', 'best_by',
    ];

    /** Hyperbitcoinization matches, Pong results, series and tournaments read per topic. */
    public const OTHERS = 2;

    public function __construct(
        private ChessMoments $chess,
        private BoardMoments $board,
        private PongMoments $pong,
        private HyperMatchMoments $hyper,
        private ScoreMoments $scores,
    ) {}

    /**
     * @param  list<array{game: ChessGame, white: string, black: string, moments: list<array<string, string|int>>}>|null  $chess  rankedChess(), when the caller has it already
     * @return list<array<string, string|int>>
     */
    public function forTopic(string $topic, bool $night = false, ?array $chess = null): array
    {
        if ($night) {
            return [];
        }

        $nowMs = (int) (microtime(true) * 1000);
        $sources = match ($topic) {
            SceneTopic::LIVE_GAMES, SceneTopic::MEMPOOL => [fn (): array => array_map(fn (array $entry): array => $entry['moments'], $chess ?? $this->rankedChess($nowMs)), fn (): array => [$this->boardGame()], fn (): array => $this->hyperMatches($nowMs), fn (): array => $this->series()],
            SceneTopic::RESULTS => [fn (): array => $this->pongResults()],
            SceneTopic::LIVE_TOURNAMENT => [fn (): array => $this->tournaments($nowMs)],
            SceneTopic::BLOCKFILL => [fn (): array => [$this->scores->blockfill()]],
            SceneTopic::TMNF => [fn (): array => [$this->scores->tmnf()]],
            default => [],
        };

        $games = [];
        foreach ($sources as $source) {
            foreach (self::safelyAll($source) as $moments) {
                if ($moments !== []) {
                    $games[] = $moments;
                }
            }
        }

        return self::interleave($games, self::MAX);
    }

    /**
     * The moments of several games taken in turn (first of each, then second of each …), at most `$max`.
     *
     * @param  list<list<array<string, string|int>>>  $games
     * @return list<array<string, string|int>>
     */
    public static function interleave(array $games, int $max): array
    {
        $out = [];

        for ($round = 0; count($out) < $max; $round++) {
            $added = false;

            foreach ($games as $moments) {
                if (isset($moments[$round]) && count($out) < $max) {
                    $out[] = $moments[$round];
                    $added = true;
                }
            }

            if (! $added) {
                break;
            }
        }

        return $out;
    }

    /**
     * The running chess games most worth talking about (user 2026-10-11: the
     * caster should call who is winning, not the move number), with their
     * moments: of the CHESS_CANDIDATES newest active games whose players the
     * voice can name, the CHESS_GAMES with the highest worth(), the newer
     * first on a tie (so without any moment it is the newest, as before).
     * A game whose moments cannot be read counts as worth nothing.
     *
     * @return list<array{game: ChessGame, white: string, black: string, moments: list<array<string, string|int>>}>
     */
    public function rankedChess(?int $nowMs = null): array
    {
        $nowMs ??= (int) (microtime(true) * 1000);
        $entries = [];

        foreach (ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Active)->whereNotNull('white_id')->whereNotNull('black_id')
            ->orderByDesc('id')->limit(self::CHESS_CANDIDATES)->get() as $game) {
            $white = SceneFacts::spoken($game->white->displayName());
            $black = SceneFacts::spoken($game->black->displayName());

            if ($white !== null && $black !== null) {
                $entries[] = ['game' => $game, 'white' => $white, 'black' => $black, 'moments' => self::safely(fn (): array => $this->chess->of($game, $white, $black, $nowMs))];
            }
        }

        usort($entries, fn (array $x, array $y): int => [self::worth($y['moments']), $y['game']->id] <=> [self::worth($x['moments']), $x['game']->id]);

        return array_slice($entries, 0, self::CHESS_GAMES);
    }

    /**
     * What a chess game's moments are worth to the commentary (CHESS_WEIGHTS, MATERIAL_WEIGHTS, ENDGAME_WEIGHT).
     *
     * @param  list<array<string, string|int>>  $moments
     */
    public static function worth(array $moments): int
    {
        $worth = 0;

        foreach ($moments as $moment) {
            $worth += match ($moment['kind']) {
                'material' => self::MATERIAL_WEIGHTS[$moment['mood'] ?? ''] ?? 0,
                'phase' => ($moment['phase'] ?? null) === 'Endspiel' ? self::ENDGAME_WEIGHT : 0,
                default => self::CHESS_WEIGHTS[$moment['kind']] ?? 0,
            };
        }

        return $worth;
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function boardGame(): array
    {
        $game = app(BoardScene::class)->liveGame();
        $white = SceneFacts::spoken($game?->white?->displayName());
        $black = SceneFacts::spoken($game?->black?->displayName());

        return $game === null || $white === null || $black === null ? [] : $this->board->of($game, $white, $black);
    }

    /**
     * @return list<list<array<string, string|int>>>
     */
    private function hyperMatches(int $nowMs): array
    {
        $games = [];

        foreach (array_slice(app(HyperScene::class)->entries($nowMs), 0, self::OTHERS) as $entry) {
            $match = HyperMatch::query()->with('seats.user')->find($entry['id']);

            if ($match !== null) {
                $games[] = self::safely(fn (): array => $this->hyper->of($match, $nowMs));
            }
        }

        return $games;
    }

    /**
     * Series under way: accepted, started, at least one game on the sheet.
     *
     * @return list<list<array<string, string|int>>>
     */
    private function series(): array
    {
        $games = [];

        foreach (SeriesMatch::query()->where('status', SeriesStatus::Accepted)->whereNotNull('live_games')->where('start_at', '<=', now())
            ->orderByDesc('start_at')->limit(self::OTHERS)->get() as $match) {
            $games[] = self::safely(fn (): array => SeriesMoments::of($match));
        }

        return $games;
    }

    /**
     * @return list<list<array<string, string|int>>>
     */
    private function pongResults(): array
    {
        $games = [];

        foreach (array_slice(app(PongScene::class)->entries(), 0, self::OTHERS) as $entry) {
            $match = PongMatch::query()->with(['left', 'right'])->find($entry['id']);
            $left = SceneFacts::spoken($match?->left?->displayName());
            $right = SceneFacts::spoken($match?->right?->displayName());

            if ($match !== null && $left !== null && $right !== null) {
                $games[] = self::safely(fn (): array => $this->pong->of($match, $left, $right));
            }
        }

        return $games;
    }

    /**
     * @return list<list<array<string, string|int>>>
     */
    private function tournaments(int $nowMs): array
    {
        $games = [];

        foreach (array_slice(app(TournamentLiveSlides::class)->all($nowMs), 0, self::OTHERS) as $frame) {
            $games[] = self::safely(fn (): array => TournamentMoments::fromFrame($frame));
        }

        return $games;
    }

    /**
     * The moments of one game, or none when reading them throws.
     *
     * @param  Closure(): list<array<string, string|int>>  $source
     * @return list<array<string, string|int>>
     */
    private static function safely(Closure $source): array
    {
        try {
            return $source();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The moments of a source's games, or none when the source throws.
     *
     * @param  Closure(): list<list<array<string, string|int>>>  $source
     * @return list<list<array<string, string|int>>>
     */
    private static function safelyAll(Closure $source): array
    {
        try {
            return $source();
        } catch (Throwable) {
            return [];
        }
    }
}
