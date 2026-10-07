<?php

use App\Enums\BoardGameStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\GameNames;
use App\Support\Tournaments\TournamentGameEnd;
use Illuminate\Support\Facades\Blade;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * One board game next to chess (plan "Mühle und Dame", P2): nine men's
 * morris, checkers, live or finished, for guests too (watching).
 *
 * The board is Alpine (resources/js/boardGame.js) inside `wire:ignore`: an
 * SVG drawn from what the game's rules describe, with no rules of its own. It
 * proposes a move; the actions below are #[Json], never re-render, and
 * return the server's state, which the board shows. Every change also
 * reaches both players (private `board.{id}`) and spectators (public
 * `board.{id}.watch`) over Reverb; without a websocket the page polls
 * fetchState().
 *
 * Correspondence (P8): the same page with a day per move; the deadline is
 * written out and the lobby link leads to the correspondence page. Every
 * game can be stepped back through, move by move (boardGame.js, history).
 *
 * The route exists only while `esports.board_games.enabled` is on
 * (routes/board.php); a game whose own board game is switched off is a 404.
 */
new #[Layout('layouts::app', ['realtime' => true, 'scripts' => ['resources/js/boardGame.js']])] class extends Component {
    #[Locked]
    public BoardGame $boardGame;

    public function mount(BoardGame $boardGame): void
    {
        abort_unless(app(GameRegistry::class)->isBoard($boardGame->game), 404);

        // A flag that fell while nobody was looking is settled before the clock is shown.
        $this->boardGame = $boardGame->isActive() ? app(BoardGameService::class)->checkClock($boardGame) : $boardGame;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $game = $this->boardGame;
        $title = __(':game: :white vs :black', [
            'game' => GameNames::game($game->game),
            'white' => $this->name('w'),
            'black' => $this->name('b'),
        ]);
        $view->title($title);

        // Search and link preview, as a chess game's page has them: the players, the state, the board as a card.
        $kind = ($game->rated ? __('Rated') : __('Casual')).' · '.GameNames::full($game->game, $game->mode);
        $description = __(':kind: :white (white) vs :black (black) in the TWENTY ONE esports league.', ['kind' => $kind, 'white' => $this->name('w'), 'black' => $this->name('b')]).' '.match ($game->status) {
            BoardGameStatus::Active => __('Live now: watch the board move by move.'),
            BoardGameStatus::Aborted => __('Aborted before both first moves'),
            BoardGameStatus::Finished => __('Result: :result.', ['result' => $game->result ?? '?']),
        };
        app(\App\Support\PageMeta::class)->describe($title, $description)->card(fn () => \App\Support\Cards\PageCard::boardGame($game));
    }

    /**
     * Full state for a reconnect or a client that fell behind.
     *
     * @return array<string, mixed>
     */
    #[Json]
    public function fetchState(): array
    {
        return app(BoardGameService::class)->snapshot($this->boardGame->refresh());
    }

    /**
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    #[Json]
    public function move(string $move, int $ply): array
    {
        return $this->act(fn (BoardGameService $games, User $user) => $games->move($this->boardGame, $user, $move, $ply));
    }

    #[Json]
    public function resign(): array
    {
        return $this->act(fn (BoardGameService $games, User $user) => $games->resign($this->boardGame, $user));
    }

    #[Json]
    public function offerDraw(): array
    {
        return $this->act(fn (BoardGameService $games, User $user) => $games->offerDraw($this->boardGame, $user));
    }

    #[Json]
    public function acceptDraw(): array
    {
        return $this->act(fn (BoardGameService $games, User $user) => $games->acceptDraw($this->boardGame, $user));
    }

    #[Json]
    public function declineDraw(): array
    {
        return $this->act(fn (BoardGameService $games, User $user) => $games->declineDraw($this->boardGame, $user));
    }

    #[Json]
    public function abort(): array
    {
        return $this->act(fn (BoardGameService $games, User $user) => $games->abort($this->boardGame, $user));
    }

    /**
     * A client whose clock shows zero asks; the server's clock decides.
     *
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    #[Json]
    public function checkClock(): array
    {
        $games = app(BoardGameService::class);
        $games->checkClock($this->boardGame);

        return ['ok' => true, 'error' => null, 'state' => $games->snapshot($this->boardGame->refresh())];
    }

    /**
     * A tournament game's "what comes next" panel (TournamentGameEnd), once
     * the game is over; null while it runs or outside a tournament.
     */
    #[Json]
    public function tournamentPanel(): ?string
    {
        $game = $this->boardGame->refresh();
        $panel = $game->isActive() ? null : TournamentGameEnd::of($game, auth()->user());

        return $panel === null ? null : Blade::render('<x-tournaments.game-end :panel="$panel" :framed="false" />', ['panel' => $panel]);
    }

    /**
     * @param  Closure(BoardGameService, User): mixed  $action
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    private function act(Closure $action): array
    {
        $games = app(BoardGameService::class);
        $user = auth()->user();
        $error = null;

        try {
            if (! $user instanceof User) {
                throw new BoardRuleViolation('not_a_player');
            }

            $action($games, $user);
        } catch (BoardRuleViolation $violation) {
            $error = $violation->reason;
        }

        return ['ok' => $error === null, 'error' => $error, 'state' => $games->snapshot($this->boardGame->refresh())];
    }

    /**
     * @param  'w'|'b'  $side
     */
    private function name(string $side): string
    {
        return $this->boardGame->player($side)?->displayName() ?? __('Deleted player');
    }

    /**
     * Everything the board script needs, translated once here.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $games = app(BoardGameService::class);
        $rules = $games->rulesOf($this->boardGame);
        $reasons = $rules?->reasons() ?? [];

        return [
            'state' => $games->snapshot($this->boardGame),
            'layout' => $games->layout($this->boardGame),
            // The position before the first move, for browsing back to it (P8).
            'startPieces' => $rules === null ? [] : $rules->view($rules->start())['pieces'],
            'color' => $this->boardGame->colorOf(auth()->user()),
            'labels' => [
                'white' => __('White'),
                'black' => __('Black'),
                'names' => ['w' => $this->name('w'), 'b' => $this->name('b')],
                'firstMove' => __(':side: first move within :s s'),
                // A tournament game's first-move deadline, in the player's words (user, 2026-10-03).
                'cupFirstMove' => $this->boardGame->tournament_match_id === null ? null : ['mine' => __('Make your first move within :time or you lose this cup game'), 'theirs' => __(':side must move within :time or loses this cup game')],
                'drawOffer' => __(':name offers a draw', ['name' => $this->name($this->boardGame->colorOf(auth()->user()) === 'w' ? 'b' : 'w')]),
                'status' => ['live' => __('Live · move :move · :side to move'), 'daily' => __('Correspondence · move :move · :side to move'), 'over' => __('Game over'), 'aborted' => __('Aborted')],
                'deadline' => __(':side moves by :time, or loses on time.'),
                'connection' => ['connected' => __('Connected'), 'connecting' => __('Connecting …'), 'polling' => __('Live via server')],
                'outcome' => ['wins' => __(':name wins'), 'draw' => __('Draw'), 'aborted' => __('Game aborted')],
                'reasons' => [
                    'resignation' => __('Resignation'), 'timeout' => __('Out of time'), 'agreement' => __('Draw by agreement'), 'aborted' => __('Aborted'),
                    'forfeit' => __('No first move'), 'voided' => __('Voided by the league'),
                    ...array_map(fn (string $label): string => __($label), $reasons),
                ],
                'errors' => ['illegal_move' => __('That move is not legal here.'), 'not_your_turn' => __('It is not your turn.'),
                    'out_of_sync' => __('The board was behind. It shows the latest position now.'), 'game_over' => __('The game is already over.'),
                    'not_a_player' => __('Only the two players can do that.'), 'too_late_to_abort' => __('Both sides have moved, the game can no longer be aborted.'),
                    'tournament_game' => __('A tournament game cannot be aborted.'),
                    'default' => __('That did not work. The board shows the server\'s state.')],
                // The block input (Blockli).
                'blocks' => ['tap' => __('Tap a groove: the block shows where it lands.'), 'point' => __('Point at a groove: the block follows.'),
                    'confirm' => __('Tap the block again or press Confirm.'), 'click' => __('Click to set the block.'), 'illegal' => __('No block fits here.'),
                    'keys' => __('Arrow keys move the block, R turns it, Enter sets it, Escape cancels.'),
                    'at' => __('Block at :crossing, :direction.'), 'horizontal' => __('horizontal'), 'vertical' => __('vertical'),
                    'enter' => __('Enter sets it, R turns it.')],
            ],
        ];
    }
}; ?>

@php
    $config = $this->config();
    // A guest has the "New here?" strip above the page (P5, from the P2 review): the board gives up what the first
    // viewport lacks, so the lower player card stays in view (1440 x 900, measured: board top 335, then 12 px, the
    // 48 px card and 16 px air = 411; the card ended at 955 with 560 px). From ~970 px of height it is 560 again;
    // players and logged-in spectators keep 560 px (their card ends at 886).
    $boardColumn = auth()->guest() ? 'lg:grid-cols-[minmax(0,min(560px,calc(100dvh-411px)))_minmax(0,1fr)]' : 'lg:grid-cols-[minmax(0,560px)_minmax(0,1fr)]';
@endphp

<div class="flex grow flex-col px-4 pb-8 lg:px-12 lg:pb-10">
    {{-- A tournament game says so first, above everything else (user, 2026-10-03). --}}
    <x-tournaments.game-banner :banner="$boardGame->tournament_match_id !== null ? TournamentGameEnd::banner($boardGame) : null" class="mx-auto mb-4 w-full max-w-[1000px] lg:mb-5" />

    <div wire:ignore x-data="boardGame(@js($config))" x-on:keydown.window="browseKey($event)" class="mx-auto flex w-full max-w-[1000px] flex-col gap-4 lg:gap-5" data-test="board-game" data-mode="{{ $boardGame->mode }}">

        {{-- Title row --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="min-w-0">
                <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ GameNames::game($boardGame->game) }}</h1>
                <x-game-credit :game="$boardGame->game" />
            </div>
            {{-- The lobby of this board game (P5): the next opponent, the ladder. --}}
            <a href="{{ route('board.lobby', $boardGame->game) }}" class="text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc" data-test="board-lobby-link">{{ __('Lobby') }}</a>
            {{-- No separate "Correspondence games" link: board games are correspondence only, the lobby is that page (2026-10-08; it pushed the board below the fold at 1440). --}}
            <span class="grow"></span>
            <span role="status" class="flex h-[34px] items-center gap-2 rounded-md px-3 text-[13px]"
                  :class="connection === 'connected' ? 'bg-[#122016] text-win' : 'bg-[#241D10] text-btc-hi'">
                <span class="size-2 rounded-full" :class="connection === 'connected' ? 'bg-win' : 'bg-btc-hi'"></span>
                <span x-text="connectionLabel" data-test="connection"></span>
            </span>
            <span class="flex h-[34px] items-center rounded-md bg-btc-press px-3.5 text-[13px] font-bold text-btc-hi" x-text="statusLine" data-test="status-line"></span>
        </div>

        <div @class(['grid grid-cols-1 gap-4 lg:gap-7', $boardColumn])>
            {{-- The board: the rules describe it, the script draws it --}}
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex items-center justify-between gap-3 rounded-lg bg-card px-3 py-2" data-test="player-top">
                    <span class="min-w-0 truncate text-sm" x-text="sideName(topSide)"></span>
                    <span role="timer" class="shrink-0 font-display text-2xl font-bold whitespace-nowrap tabular-nums" :class="state.clock.running === topSide ? 'text-btc-hi' : 'text-ink-2'" x-text="clock(topSide)" data-test="clock-top"></span>
                </div>
                {{-- Black sees the board from its own side: its name and clock sit below it. --}}
                <svg x-ref="board" viewBox="0 0 {{ $config['layout']['width'] ?? 100 }} {{ $config['layout']['height'] ?? 100 }}"
                     x-on:click="pick($event)" x-on:keydown="pickKey($event)"
                     role="group" aria-label="{{ __('Game board') }}"
                     x-bind:class="color === 'b' ? 'rotate-180' : ''"
                     style="aspect-ratio: {{ $config['layout']['width'] ?? 1 }} / {{ $config['layout']['height'] ?? 1 }}"
                     class="block w-full touch-manipulation rounded-lg bg-well select-none" data-test="board"></svg>
                <div class="flex items-center justify-between gap-3 rounded-lg bg-card px-3 py-2" data-test="player-bottom">
                    <span class="min-w-0 truncate text-sm" x-text="sideName(bottomSide)"></span>
                    <span role="timer" class="shrink-0 font-display text-2xl font-bold whitespace-nowrap tabular-nums" :class="state.clock.running === bottomSide ? 'text-btc-hi' : 'text-ink-2'" x-text="clock(bottomSide)" data-test="clock-bottom"></span>
                </div>
                {{-- The block input (Blockli): move, or set a block shown first, as in the Blockli prototype. --}}
                <template x-if="layout.input === 'blocks' && color && state.status === 'active'">
                    <div class="flex flex-col gap-2" data-test="block-input">
                        <div class="grid grid-cols-2 gap-2" role="group" aria-label="{{ __('Move or set a block') }}">
                            <button type="button" class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md px-[18px] text-[13px]"
                                    :class="blockMode ? 'btn-w border border-line bg-well text-ink' : 'border border-btc bg-btc-press font-bold text-btc-hi'"
                                    :aria-pressed="blockMode ? 'false' : 'true'" x-on:click="setBlockMode(false)" data-test="mode-move">{{ __('Move the pawn') }}</button>
                            <button type="button" class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md px-[18px] text-[13px] disabled:cursor-default disabled:opacity-50"
                                    :class="blockMode ? 'border border-btc bg-btc-press font-bold text-btc-hi' : 'btn-w border border-line bg-well text-ink'"
                                    :aria-pressed="blockMode ? 'true' : 'false'" :disabled="!myTurn || !canSetBlocks" x-on:click="setBlockMode(true, $event)" aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight R Enter Escape" data-test="mode-block">
                                {{ __('Set a block') }} <span class="tabular-nums" x-text="blocksLeft"></span>
                            </button>
                        </div>
                        <div class="grid grid-cols-2 gap-2" x-show="blockMode">
                            <x-button variant="quiet" x-bind:disabled="!preview" x-on:click="rotateBlock()" data-test="rotate-block">{{ __('Rotate') }}</x-button>
                            <x-button x-bind:disabled="!preview || !preview.move" x-on:click="setBlock()" data-test="set-block">{{ __('Confirm') }}</x-button>
                        </div>
                        {{-- Always in the page, so a screen reader hears each new hint (the keys say where the block is). --}}
                        <p class="m-0 text-[13px] text-ink-2" role="status" aria-live="polite" x-text="blockHint" data-test="block-hint"></p>
                    </div>
                </template>
            </div>

            {{-- Status, actions, moves --}}
            <div class="flex min-w-0 flex-col gap-3">
                @if ($boardGame->tournament_match_id !== null)
                    {{-- A tournament game ends at the first-move deadline by the league's decision: the countdown leads, the rule stays under it. --}}
                    <div class="flex flex-col gap-1 rounded-lg bg-btc-tint px-4 py-3 shadow-[inset_0_0_0_1px_var(--color-btc)]" role="status" x-show="firstMoveLine" data-test="first-move">
                        <b class="font-display text-base leading-snug text-btc-hi lg:text-lg" data-test="first-move-cup"
                           x-text="firstMoveLeft === null ? '' : (color === state.turn ? t.cupFirstMove.mine : t.cupFirstMove.theirs.replace(':side', t.names[state.turn])).replace(':time', Math.floor(firstMoveLeft / 60) + ':' + String(firstMoveLeft % 60).padStart(2, '0'))"></b>
                        <span class="text-xs text-ink-2" x-text="firstMoveLine"></span>
                    </div>
                @else
                    <p class="m-0 text-[13px] text-ink-2" x-show="firstMoveLine" x-text="firstMoveLine" data-test="first-move"></p>
                @endif
                <p class="m-0 text-[13px] text-ink-2" x-show="deadlineLine" x-text="deadlineLine" data-test="deadline"></p>
                <p role="alert" class="m-0 text-[13px] text-loss" x-show="error" x-text="error" data-test="board-error"></p>

                <template x-if="state.status !== 'active'">
                    <div class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="board-result">
                        <span class="text-base font-bold" data-test="result" x-text="outcome"></span>
                        @if ($boardGame->tournament_match_id === null)
                            <x-button :href="route('board.lobby', $boardGame->game)" icon="bolt" data-test="next-opponent">{{ __('Find next opponent') }}</x-button>
                        @else
                            {{-- A tournament game: what comes next in the tournament (user, 2026-10-03), rendered now for a game already over. --}}
                            <x-tournaments.game-end-slot :url="route('tournaments.show', $boardGame->tournamentMatch?->tournament_id ?? 0)" :panel="$boardGame->isActive() ? null : TournamentGameEnd::of($boardGame, auth()->user())" />
                        @endif
                    </div>
                </template>

                <template x-if="color && state.status === 'active' && state.drawOffer && state.drawOffer !== color">
                    <div class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="draw-offer">
                        <span class="text-sm font-bold" x-text="t.drawOffer"></span>
                        <div class="grid grid-cols-2 gap-2">
                            <x-button variant="quiet" x-on:click="call('declineDraw')" data-test="decline-draw">{{ __('Decline') }}</x-button>
                            <x-button x-on:click="call('acceptDraw')" data-test="accept-draw">{{ __('Accept draw') }}</x-button>
                        </div>
                    </div>
                </template>

                <template x-if="color && state.status === 'active'">
                    <div class="grid grid-cols-2 gap-2">
                        {{-- A tournament game cannot be aborted: a missed first move is the league's to decide (P5). --}}
                        @if ($boardGame->tournament_match_id === null)
                            <template x-if="state.ply < 2">
                                <x-button variant="quiet" x-on:click="call('abort')" data-test="abort">{{ __('Abort game') }}</x-button>
                            </template>
                        @endif
                        <template x-if="state.ply >= 2">
                            <x-button variant="quiet" x-on:click="call('offerDraw')" x-bind:disabled="state.drawOffer === color" data-test="offer-draw">
                                <span x-text="state.drawOffer === color ? @js(__('Draw offered')) : @js(__('Offer draw'))"></span>
                            </x-button>
                        </template>
                        <x-button variant="secondary" x-on:click="resign()" data-test="resign">
                            <span x-text="confirmResign ? @js(__('Resign this game?')) : @js(__('Resign'))"></span>
                        </x-button>
                    </div>
                </template>

                <div class="flex flex-col gap-2 rounded-lg bg-card p-4">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="m-0 text-sm font-bold">{{ __('Moves') }}</h2>
                        {{-- Browse the game (P8): start, back, forward, newest; the arrow keys do the same. --}}
                        <div class="flex items-center gap-1" data-test="history-nav">
                            @foreach ([['0', 'first', '«', __('First position')], ['shownPly - 1', 'back', '‹', __('Previous move')], ['shownPly + 1', 'forward', '›', __('Next move')], ['state.moves.length', 'last', '»', __('Latest move')]] as [$target, $key, $glyph, $label])
                                <button type="button" x-on:click="browse({{ $target }})" aria-label="{{ $label }}" title="{{ $label }}" data-test="history-{{ $key }}"
                                        class="inline-flex size-11 cursor-pointer items-center justify-center rounded-md bg-well text-base text-ink hover:bg-row-hover">{{ $glyph }}</button>
                            @endforeach
                        </div>
                    </div>
                    <p class="m-0 flex flex-wrap items-center gap-2 text-xs text-btc-hi" x-show="viewIndex !== null" data-test="history-pinned">
                        <span x-text="@js(__('Showing move :n of :total')).replace(':n', shownPly).replace(':total', state.moves.length)"></span>
                        <button type="button" class="cursor-pointer text-ink underline decoration-edge underline-offset-4" x-on:click="browse(state.moves.length)" data-test="history-back-to-game">{{ __('Back to the game') }}</button>
                    </p>
                    <ol class="m-0 flex list-none flex-wrap gap-x-1 gap-y-1 p-0 text-[13px] tabular-nums" data-test="moves">
                        <template x-for="m in state.moves" :key="m.ply">
                            <li><button type="button" x-on:click="browse(m.ply)" :aria-current="shownPly === m.ply ? 'step' : null" data-test="move"
                                        class="cursor-pointer rounded-sm px-1.5 py-0.5 text-ink" :class="shownPly === m.ply ? 'bg-btc-press text-btc-hi' : 'hover:bg-row-hover'"><span class="text-ink-3" x-text="m.ply + '.'"></span> <span x-text="m.notation"></span></button></li>
                        </template>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>
