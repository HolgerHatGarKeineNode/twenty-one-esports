<?php

namespace App\Support\Matches;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\HyperMatchStatus;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Hyper\HyperNames;
use Carbon\CarbonInterface;

/**
 * The cubes of the mempool strip (components/block-strip) for the games
 * played move by move: chess (blitz and daily) and the board games (nine
 * men's morris, checkers). A cube is a match waiting or played, never a
 * block; a finished rated match that mined carries its block in `chain`
 * (ChainStamps). Series cubes come from SeriesPresenter::block(), in the
 * same shape().
 */
final class MatchBlocks
{
    /**
     * The colour family of a game in the strip (app.css `.g-*`), from its
     * registry icon, so a new edition of a game keeps its colour. `other` is
     * the neutral family for a game the strip has no colour for.
     *
     * @return 'chess'|'rl'|'fc'|'morris'|'checkers'|'aoe'|'other'
     */
    public static function family(string $slug): string
    {
        return match (app(GameRegistry::class)->find($slug)?->assets()->icon) {
            'chess' => 'chess',
            'rocket-league' => 'rl',
            'soccer' => 'fc',
            'morris' => 'morris',
            'checkers' => 'checkers',
            'castle' => 'aoe',
            default => 'other',
        };
    }

    /**
     * @param  list<array{name: string, user: User|null, clan: Clan|null, won: bool}>  $sides
     * @param  'fin'|'live'|'next'  $state
     * @param  array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}|null  $chain
     *                                                                                                                                                                                     `blank`: the cube opens its match in a new tab (a Hyperbitcoinization match is a full-screen page of its own).
     * @return array{key: string, number: string, slug: string, game: string, icon: string, mode: string, score: string, word: bool, who: string, when: string, sides: list<array{name: string, user: User|null, clan: Clan|null, won: bool}>, href: string, aria: string, level: string, casual: bool, state: string, dot: bool, newest: bool, chain: array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}|null, blank: bool}
     */
    public static function shape(
        string $key,
        string $number,
        string $slug,
        string $mode,
        string $score,
        string $who,
        string $when,
        array $sides,
        string $href,
        string $aria,
        string $state = 'fin',
        string $level = '100%',
        bool $casual = false,
        bool $dot = false,
        bool $word = false,
        bool $newest = false,
        ?array $chain = null,
        bool $blank = false,
    ): array {
        return [
            'key' => $key,
            'number' => $number,
            'slug' => $slug,
            'game' => self::family($slug),
            'icon' => app(GameRegistry::class)->find($slug)?->assets()->icon ?? 'trophy',
            'mode' => $mode,
            'score' => $score,
            'word' => $word,
            'who' => $who,
            'when' => $when,
            'sides' => $sides,
            'href' => $href,
            'aria' => $aria,
            'level' => $level,
            'casual' => $casual,
            'state' => $state,
            'dot' => $dot,
            'newest' => $newest,
            'chain' => $chain,
            'blank' => $blank,
        ];
    }

    /**
     * A chess game, blitz or daily. Aborted games never reach the strip.
     *
     * @param  array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}|null  $chain
     * @return array<string, mixed>
     */
    public static function chess(ChessGame $game, bool $newest = false, ?array $chain = null): array
    {
        return self::oneVsOne(
            key: 'chess-'.$game->id,
            number: $game->number === null ? '' : $game->number(),
            slug: 'chess',
            mode: GameNames::mode('chess', $game->mode),
            finished: $game->status === ChessGameStatus::Finished,
            result: $game->result,
            daily: $game->isCorrespondence(),
            ply: $game->ply,
            turn: $game->turn(),
            white: $game->white,
            black: $game->black,
            endedAt: $game->ended_at,
            rated: $game->rated,
            href: route('games.show', $game),
            newest: $newest,
            chain: $chain,
            expectedPly: 80,
        );
    }

    /**
     * A board game other than chess. Only called while its route is there
     * (MempoolStrip checks Route::has('board.show')).
     *
     * @param  array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}|null  $chain
     * @return array<string, mixed>
     */
    public static function board(BoardGame $game, bool $newest = false, ?array $chain = null): array
    {
        return self::oneVsOne(
            key: 'board-'.$game->id,
            number: $game->number === null ? '' : '#'.$game->number,
            slug: $game->game,
            // "Correspondence" does not fit a cube; "Daily" is chess's word ("Fernschach").
            mode: $game->isCorrespondence() ? __('Daily game') : GameNames::mode($game->game, $game->mode),
            finished: $game->status === BoardGameStatus::Finished,
            result: $game->result,
            daily: $game->isCorrespondence(),
            ply: $game->ply,
            turn: $game->turn,
            white: $game->white,
            black: $game->black,
            endedAt: $game->ended_at,
            rated: $game->rated,
            href: route('board.show', $game),
            newest: $newest,
            chain: $chain,
            expectedPly: 60,
        );
    }

    /**
     * A Hyperbitcoinization match (plan "Hyperbitcoinization", P6), 2 to 6 seats: the round as its score, the
     * winner (a team's name in a team match) or whose turn it is, and under the cube the first two seats by place
     * with "+N" for the rest. Opens the full-screen match in a new tab. It mines no block (LadderEvents), so it
     * carries no chain stamp. Only called while its route is there (MempoolStrip checks Route::has('hyper.match')).
     *
     * @return array<string, mixed>
     */
    public static function hyper(HyperMatch $match, bool $newest = false): array
    {
        $finished = $match->status === HyperMatchStatus::Finished;
        $round = (int) ($match->state['round'] ?? 1);
        // The players' seats lead the cube, the bots that fill a table follow (user 2026-10-09: no bots in the mempool).
        $seats = HyperNames::ordered($match);
        usort($seats, fn (HyperSeat $a, HyperSeat $b): int => ($a->user_id === null) <=> ($b->user_id === null));
        $daily = $match->isCorrespondence();
        $mode = $daily ? __('Daily game') : GameNames::mode(Hyperbitcoinization::SLUG, $match->mode);
        $toMove = collect($seats)->firstWhere('seat', $match->current_seat);
        $winner = $finished ? HyperNames::winner($match) : null;
        $who = match (true) {
            $finished => $winner ?? '–',
            $toMove !== null => __(":name's turn", ['name' => HyperNames::seat($toMove)]),
            default => '',
        };
        $score = $finished ? __('Winner') : __('round :n', ['n' => $round]);
        $more = max(0, count($seats) - 2);
        $sides = array_map(fn (HyperSeat $seat, int $index): array => [
            'name' => HyperNames::seat($seat).($index === 1 && $more > 0 ? ' +'.$more : ''),
            'user' => $seat->user,
            'clan' => null,
            'won' => $finished && $seat->place === 1,
        ], array_slice($seats, 0, 2), [0, 1]);
        // A won match is one block with its winner alone (user 2026-10-09): no second or third place, no bots.
        $first = collect($seats)->firstWhere('place', 1);
        if ($finished) {
            $sides = $first === null ? [] : [['name' => $winner ?? HyperNames::seat($first), 'user' => $match->isTeamMatch() ? null : $first->user, 'clan' => null, 'won' => true]];
        }
        $game = GameNames::game(Hyperbitcoinization::SLUG);

        return self::shape(
            key: 'hyper-'.$match->id,
            number: '',
            slug: Hyperbitcoinization::SLUG,
            mode: $mode,
            score: $score,
            who: $who,
            when: match (true) {
                $finished => $match->ended_at?->diffForHumans(['short' => true]) ?? '',
                $daily => __('running'),
                default => __('live'),
            },
            sides: array_slice($sides, 0, 2),
            href: route('hyper.match', $match),
            aria: implode(', ', array_filter([
                $game.' '.$mode,
                $finished ? $score.' '.$who : $who,
                $finished ? '' : implode(', ', array_map(HyperNames::seat(...), $seats)),
            ], fn (string $part): bool => $part !== '')),
            state: $finished ? 'fin' : 'live',
            level: $finished ? '100%' : (int) round(min(1, $round / 20) * 100).'%',
            casual: ! $match->rated,
            dot: ! $finished && ! $daily,
            word: true,
            newest: $newest,
            blank: true,
        );
    }

    /**
     * The cube of a game between two players. `expectedPly` only sets how full
     * a running cube looks (a typical game's length, an estimate); no number
     * on the page comes from it.
     *
     * @param  'w'|'b'  $turn
     * @param  array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}|null  $chain
     * @return array<string, mixed>
     */
    private static function oneVsOne(
        string $key,
        string $number,
        string $slug,
        string $mode,
        bool $finished,
        ?string $result,
        bool $daily,
        int $ply,
        string $turn,
        ?User $white,
        ?User $black,
        ?CarbonInterface $endedAt,
        bool $rated,
        string $href,
        bool $newest,
        ?array $chain,
        int $expectedPly,
    ): array {
        $winner = match ($result) {
            '1-0' => 'w',
            '0-1' => 'b',
            default => null,
        };
        $name = fn (?User $user): string => $user?->displayName() ?? __('Deleted player');
        $toMove = $turn === 'b' ? $black : $white;
        // Finished without a winner is a draw: chess and the board games know no other result without one.
        $who = match (true) {
            ! $finished => __(":name's turn", ['name' => $name($toMove)]),
            $winner === 'w' => $name($white),
            $winner === 'b' => $name($black),
            default => __('Draw'),
        };
        $when = match (true) {
            $finished => $endedAt?->diffForHumans(['short' => true]) ?? '',
            $daily => __('running'),
            default => __('live'),
        };
        $score = $finished ? str_replace(['1/2', '-'], ['½', '–'], (string) $result) : __('move :n', ['n' => intdiv($ply, 2) + 1]);
        $game = GameNames::game($slug);

        return self::shape(
            key: $key,
            number: $number,
            slug: $slug,
            mode: $mode,
            score: $score,
            who: $who,
            when: $when,
            sides: [
                ['name' => $name($white), 'user' => $white, 'clan' => null, 'won' => $winner === 'w'],
                ['name' => $name($black), 'user' => $black, 'clan' => null, 'won' => $winner === 'b'],
            ],
            href: $href,
            aria: implode(', ', array_filter([
                $number,
                $game.' '.$mode,
                $finished ? $score.' '.$who : $who,
                $name($white).' '.__('vs :name', ['name' => $name($black)]),
                $chain['spoken'] ?? null,
            ], fn (mixed $part): bool => is_string($part) && $part !== '')),
            state: $finished ? 'fin' : 'live',
            level: $finished ? '100%' : (int) round(min(1, $ply / $expectedPly) * 100).'%',
            casual: ! $rated,
            dot: ! $finished && ! $daily,
            word: ! $finished,
            newest: $newest,
            chain: $chain,
        );
    }
}
