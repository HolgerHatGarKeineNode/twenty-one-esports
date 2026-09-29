<?php

use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\GameNames;
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
        $view->title(__(':game: :white vs :black', [
            'game' => GameNames::game($this->boardGame->game),
            'white' => $this->name('w'),
            'black' => $this->name('b'),
        ]));
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
        $reasons = $games->rulesOf($this->boardGame)?->reasons() ?? [];

        return [
            'state' => $games->snapshot($this->boardGame),
            'layout' => $games->layout($this->boardGame),
            'color' => $this->boardGame->colorOf(auth()->user()),
            'labels' => [
                'white' => __('White'),
                'black' => __('Black'),
                'names' => ['w' => $this->name('w'), 'b' => $this->name('b')],
                'firstMove' => __(':side: first move within :s s'),
                'drawOffer' => __(':name offers a draw', ['name' => $this->name($this->boardGame->colorOf(auth()->user()) === 'w' ? 'b' : 'w')]),
                'status' => ['live' => __('Live · move :move · :side to move'), 'over' => __('Game over'), 'aborted' => __('Aborted')],
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
    <div wire:ignore x-data="boardGame(@js($config))" class="mx-auto flex w-full max-w-[1000px] flex-col gap-4 lg:gap-5" data-test="board-game">

        {{-- Title row --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ GameNames::game($boardGame->game) }}</h1>
            {{-- The lobby of this board game (P5): the next opponent, the ladder. --}}
            <a href="{{ route('board.lobby', $boardGame->game) }}" class="text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc" data-test="board-lobby-link">{{ __('Lobby') }}</a>
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
                    <span role="timer" class="font-display text-2xl font-bold tabular-nums" :class="state.clock.running === topSide ? 'text-btc-hi' : 'text-ink-2'" x-text="clock(topSide)" data-test="clock-top"></span>
                </div>
                {{-- Black sees the board from its own side: its name and clock sit below it. --}}
                <svg x-ref="board" viewBox="0 0 {{ $config['layout']['width'] ?? 100 }} {{ $config['layout']['height'] ?? 100 }}"
                     x-on:click="pick($event)" x-on:keydown="pickKey($event)"
                     role="group" aria-label="{{ __('Game board') }}"
                     x-bind:class="color === 'b' ? 'rotate-180' : ''"
                     class="block aspect-square w-full touch-manipulation rounded-lg bg-well select-none" data-test="board"></svg>
                <div class="flex items-center justify-between gap-3 rounded-lg bg-card px-3 py-2" data-test="player-bottom">
                    <span class="min-w-0 truncate text-sm" x-text="sideName(bottomSide)"></span>
                    <span role="timer" class="font-display text-2xl font-bold tabular-nums" :class="state.clock.running === bottomSide ? 'text-btc-hi' : 'text-ink-2'" x-text="clock(bottomSide)" data-test="clock-bottom"></span>
                </div>
            </div>

            {{-- Status, actions, moves --}}
            <div class="flex min-w-0 flex-col gap-3">
                <p class="m-0 text-[13px] text-ink-2" x-show="firstMoveLine" x-text="firstMoveLine" data-test="first-move"></p>
                <p role="alert" class="m-0 text-[13px] text-loss" x-show="error" x-text="error" data-test="board-error"></p>

                <template x-if="state.status !== 'active'">
                    <div class="flex flex-col gap-3 rounded-lg bg-card p-4">
                        <span class="text-base font-bold" data-test="result" x-text="outcome"></span>
                        @if ($boardGame->tournament_match_id === null)
                            <x-button :href="route('board.lobby', $boardGame->game)" icon="bolt" data-test="next-opponent">{{ __('Find next opponent') }}</x-button>
                        @else
                            <x-button variant="secondary" :href="route('tournaments.show', $boardGame->tournamentMatch?->tournament_id ?? 0)" data-test="back-to-tournament">{{ __('Back to the tournament') }}</x-button>
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
                    <h2 class="m-0 text-sm font-bold">{{ __('Moves') }}</h2>
                    <ol class="m-0 flex list-none flex-wrap gap-x-3 gap-y-1 p-0 text-[13px] tabular-nums" data-test="moves">
                        <template x-for="m in state.moves" :key="m.ply">
                            <li><span class="text-ink-3" x-text="m.ply + '.'"></span> <span x-text="m.notation"></span></li>
                        </template>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>
