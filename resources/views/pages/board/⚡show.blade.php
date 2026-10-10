<?php

use App\Enums\BoardGameStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChatMute;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use App\Support\Notifications\DmRelays;
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
 * The two players chat privately as on a chess game's page (plan
 * "Blockli-Optimierung", P1): NIP-17 straight from the browser
 * (resources/js/gameChat.js, partials/chat), tagged `board:<id>` so a board
 * game never shares a chat with the chess game of the same number. On a phone
 * the whole board and Blockli's bar ("Set a block") fit the first screen above
 * the chat sheet (P2, boardGame.js fitBoard); Rotate, Confirm and Move the
 * pawn stand in a pop-up over the board.
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
     * Mute or unmute a pubkey for this player (the browser keeps its own copy), as on a chess game's page.
     */
    #[Json]
    public function setMuted(string $pubkey, bool $muted): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->boardGame->colorOf($user) === null || ! NostrKeys::isHexPubkey($pubkey) || $pubkey === $user->pubkey) {
            return false;
        }

        if ($muted) {
            ChatMute::query()->firstOrCreate(['user_id' => $user->id, 'muted_pubkey' => $pubkey]);
        } else {
            ChatMute::query()->where('user_id', $user->id)->where('muted_pubkey', $pubkey)->delete();
        }

        return true;
    }

    /**
     * Config for the chat of the two players (resources/js/gameChat.js); null for anyone else.
     *
     * @return array<string, mixed>|null
     */
    public function chatConfig(): ?array
    {
        $viewer = auth()->user();
        $opponent = $viewer instanceof User ? $this->boardGame->opponentOf($viewer) : null;

        if ($opponent === null || $viewer->pubkey === null || $opponent->pubkey === null) {
            return null;
        }

        return [
            'me' => $viewer->pubkey,
            'meName' => $viewer->displayName(),
            'opponent' => ['pubkey' => $opponent->pubkey, 'name' => $opponent->displayName()],
            // Chess games and board games are numbered apart: the prefix keeps their chats apart.
            'match' => 'board:'.$this->boardGame->id,
            // Gift wraps are backdated up to two days (NIP-59); gameChat.js reads that far before this.
            'since' => $this->boardGame->created_at?->getTimestamp(),
            'settled' => $this->boardGame->ended_at?->getTimestamp(),
            'relays' => array_values(config('esports.chat.relays', [])),
            'lookupRelays' => DmRelays::lookupRelays(),
            'muted' => $viewer->mutedPubkeys(),
            'labels' => [
                'mute' => __('Mute :name', ['name' => $opponent->displayName()]),
                'muted' => __(':name muted', ['name' => $opponent->displayName()]),
                'mutedPeek' => __(':name is muted', ['name' => $opponent->displayName()]),
                'server' => __('Server'),
                'unread' => __(':count new'),
                'open' => __('open'),
                'close' => __('close'),
                'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
                'notSent' => __('The message did not reach any relay. Please try again.'),
                'failed' => __('That did not work. Please try again.'),
                'viaDm' => __('via Nostr DM'),
            ],
        ];
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
                // Blockli: one time before the repetition draw (:count is that time).
                'repetition' => __('The same position :count times. Once more and the game is drawn.'),
                // The race standing (Blockli): :white and :black are `side` each, :lead is `lead` or `level`; the forms are for 1 and more.
                'standing' => ['line' => __('Race: :white, :black. A block counts :rate steps: :lead'), 'side' => __(':name needs :steps and has :blocks'),
                    'steps' => [trans_choice(':count step|:count steps', 1, ['count' => ':count']), trans_choice(':count step|:count steps', 2, ['count' => ':count'])],
                    'blocks' => [trans_choice(':count block|:count blocks', 1, ['count' => ':count']), trans_choice(':count block|:count blocks', 2, ['count' => ':count'])],
                    'lead' => __(':name leads by :steps.'), 'level' => __('Both are level.')],
            ],
        ];
    }
}; ?>

@php
    $config = $this->config();
    $chat = $this->chatConfig();
    // A guest has the "New here?" strip above the page (P5, from the P2 review): the board gives up what the first
    // viewport lacks, so the lower player card stays in view (1440 x 900, measured: board top 335, then 12 px, the
    // 48 px card and 16 px air = 411; the card ended at 955 with 560 px). From ~970 px of height it is 560 again;
    // players and logged-in spectators keep 560 px (their card ends at 886).
    $boardColumn = auth()->guest() ? 'lg:grid-cols-[minmax(0,min(560px,calc(100dvh-411px)))_minmax(0,1fr)]' : 'lg:grid-cols-[minmax(0,560px)_minmax(0,1fr)]';
@endphp

<div class="flex grow flex-col px-4 pb-8 lg:px-12 lg:pb-10" data-test="board-page">
    {{-- A tournament game says so first, above everything else (user, 2026-10-03). --}}
    <x-tournaments.game-banner :banner="$boardGame->tournament_match_id !== null ? TournamentGameEnd::banner($boardGame) : null" class="mx-auto mb-4 w-full max-w-[1000px] lg:mb-5" />

    <div wire:ignore x-data="boardGame(@js($config))" x-on:keydown.window="browseKey($event)" @class(["mx-auto flex w-full max-w-[1000px] flex-col gap-2 lg:gap-5", "min-[87.5rem]:max-w-[1360px]" => $chat]) data-test="board-game" data-mode="{{ $boardGame->mode }}">

        {{-- Title row --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            {{-- On a phone the credit stands beside the name: the board gets the height (P2). --}}
            <div class="flex min-w-0 items-baseline gap-2 lg:block">
                <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ GameNames::game($boardGame->game) }}</h1>
                <x-game-credit :game="$boardGame->game" />
            </div>
            {{-- The lobby of this board game (P5): the next opponent, the ladder. --}}
            <a href="{{ route('board.lobby', $boardGame->game) }}" class="text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc" data-test="board-lobby-link">{{ __('Lobby') }}</a>
            {{-- No separate "Correspondence games" link: board games are correspondence only, the lobby is that page (2026-10-08; it pushed the board below the fold at 1440). --}}
            <span class="grow"></span>
            <span role="status" class="flex h-[34px] items-center gap-2 rounded-md px-2.5 text-[13px] lg:px-3"
                  :class="connection === 'connected' ? 'bg-[#122016] text-win' : 'bg-[#241D10] text-btc-hi'">
                <span class="size-2 rounded-full" :class="connection === 'connected' ? 'bg-win' : 'bg-btc-hi'"></span>
                <span x-text="connectionLabel" class="max-lg:sr-only" data-test="connection"></span>
            </span>
            <span class="flex h-7 items-center rounded-md bg-btc-press px-3 text-xs font-bold text-btc-hi max-lg:w-full lg:h-[34px] lg:px-3.5 lg:text-[13px]" :class="hasDock ? 'max-lg:hidden' : ''" x-text="statusLine" data-test="status-line"></span>
        </div>

        {{-- As daily chess (P2, user: "bei Schach hat das geklappt"): from lg the side column and the chat end with the
             board, its moves scroll inside; from 87.5rem the chat is a third column as tall as the board. --}}
        <div @class(['grid grid-cols-1 gap-3 lg:gap-x-7 lg:gap-y-5', $boardColumn, 'min-[87.5rem]:grid-cols-[minmax(0,560px)_380px_minmax(0,1fr)]' => $chat])>
            {{-- The board: the rules describe it, the script draws it --}}
            {{-- On a phone both players share one row above the board (P2): the board gets the height. --}}
            <div class="grid min-w-0 grid-cols-2 gap-2 lg:flex lg:flex-col lg:gap-3">
                <div class="flex min-w-0 flex-col items-start rounded-lg bg-card px-3 py-1 lg:flex-row lg:items-center lg:justify-between lg:gap-3 lg:py-2" data-test="player-top">
                    <span class="max-w-full min-w-0 truncate text-xs leading-4 lg:text-sm lg:leading-normal"><span class="lg:hidden" x-text="t.names[topSide]"></span><span class="max-lg:hidden" x-text="sideName(topSide)"></span></span>
                    <span class="flex shrink-0 items-baseline gap-1.5"><span class="text-[11px] text-ink-3 lg:hidden" x-text="topSide === 'w' ? t.white : t.black"></span><span role="timer" class="shrink-0 font-display text-lg font-bold max-lg:leading-6 lg:text-2xl whitespace-nowrap tabular-nums" :class="state.clock.running === topSide ? 'text-btc-hi' : 'text-ink-2'" x-text="clock(topSide)" data-test="clock-top"></span></span>
                </div>
                {{-- Black sees the board from its own side: its name and clock sit below it. --}}
                {{-- Blockli's pop-ups stand over the board, not under it (DerCaddy, 2026-10-09), inside the part of it in view (below
                     the sticky header, above the bars fixed to a phone's bottom). In set-a-block mode the hint, at the top; with a block
                     shown, Rotate, Confirm and Move the pawn (a mouse keeps the hint) in the middle of the board on the side the block
                     is not on. Only the buttons take taps: a tap anywhere else, the hint and the warning included, still reaches the
                     board. The warning comes one time before the repetition draw, for a few seconds after each such move, like a push
                     message; beside the board it stays. fitBoard sizes this box (data-board-box), so the pop-ups keep to the board. --}}
                <div class="relative col-span-2 mx-auto w-full" data-board-box>
                    <svg x-ref="board" viewBox="0 0 {{ $config['layout']['width'] ?? 100 }} {{ $config['layout']['height'] ?? 100 }}"
                         x-on:click="pick($event)" x-on:keydown="pickKey($event)"
                         role="group" aria-label="{{ __('Game board') }}"
                         x-bind:class="color === 'b' ? 'rotate-180' : ''"
                         style="aspect-ratio: {{ $config['layout']['width'] ?? 1 }} / {{ $config['layout']['height'] ?? 1 }}"
                         class="block w-full touch-manipulation rounded-lg bg-well select-none" data-test="board"></svg>
                    <template x-if="layout.input === 'blocks'">
                        <div>
                            <div x-show="blockPopup" class="pointer-events-none absolute inset-x-2 z-10 flex items-center justify-center" :style="blockPopupStyle" data-test="block-popup">
                                <p x-show="!blockButtons" class="m-0 w-full rounded-lg bg-card px-3 py-2 text-[13px] text-ink shadow-lg ring-1 ring-edge" aria-hidden="true" x-text="blockHint"></p>
                                <div x-show="blockButtons" class="grid w-full max-w-xs grid-cols-2 gap-2 rounded-lg bg-card p-2 shadow-lg ring-1 ring-edge" data-block-card>
                                    {{-- The keys' hint says where the block is; a red place says why Confirm is off. --}}
                                    <p x-show="pointerType === 'keyboard' || (preview && !preview.move)" class="col-span-2 m-0 px-1 text-[13px]" :class="preview && !preview.move ? 'text-loss' : 'text-ink'" aria-hidden="true" x-text="blockHint"></p>
                                    <x-button variant="quiet" class="pointer-events-auto disabled:cursor-default disabled:opacity-50" x-bind:disabled="!preview" x-on:click="rotateBlock()" data-test="rotate-block">{{ __('Rotate') }}</x-button>
                                    <x-button class="pointer-events-auto disabled:cursor-default disabled:opacity-50" x-bind:disabled="!preview || !preview.move" x-on:click="setBlock()" data-test="set-block">{{ __('Confirm') }}</x-button>
                                    {{-- Back to the pawn: only needed while a block is shown, so it stands here and not under the board. --}}
                                    <x-button variant="secondary" class="pointer-events-auto col-span-2" x-on:click="setBlockMode(false)" data-test="mode-move">{{ __('Move the pawn') }}</x-button>
                                </div>
                            </div>
                            <p x-show="warningShown" x-transition.opacity.duration.300ms class="pointer-events-none absolute inset-x-2 z-10 m-0 rounded-lg bg-btc-tint px-3 py-2 text-[13px] font-bold text-btc-hi shadow-lg ring-1 ring-btc"
                               :style="popupAt(true)" role="alert" x-text="repetitionWarning" data-test="repetition-warning"></p>
                        </div>
                    </template>
                </div>
                <div class="flex min-w-0 flex-col items-start rounded-lg bg-card px-3 py-1 max-lg:order-first max-lg:col-start-2 max-lg:row-start-1 lg:flex-row lg:items-center lg:justify-between lg:gap-3 lg:py-2" data-test="player-bottom">
                    <span class="max-w-full min-w-0 truncate text-xs leading-4 lg:text-sm lg:leading-normal"><span class="lg:hidden" x-text="t.names[bottomSide]"></span><span class="max-lg:hidden" x-text="sideName(bottomSide)"></span></span>
                    <span class="flex shrink-0 items-baseline gap-1.5"><span class="text-[11px] text-ink-3 lg:hidden" x-text="bottomSide === 'w' ? t.white : t.black"></span><span role="timer" class="shrink-0 font-display text-lg font-bold max-lg:leading-6 lg:text-2xl whitespace-nowrap tabular-nums" :class="state.clock.running === bottomSide ? 'text-btc-hi' : 'text-ink-2'" x-text="clock(bottomSide)" data-test="clock-bottom"></span></span>
                </div>
            </div>

            {{-- Status, actions, moves --}}
            <div @class(['flex min-w-0 flex-col gap-3', 'min-[87.5rem]:h-0 min-[87.5rem]:min-h-full' => $chat])>
                {{-- The block input (Blockli): a marked square moves the pawn; "Set a block" (or a tap in a groove) shows a block first, as
                     in the Blockli prototype. It is a toggle: pressed again it goes back to the pawn, as Move the pawn in the pop-up does.
                     Rotate and Confirm stand in the pop-up over the board. On a phone a bar of one row fixed above the chat sheet (P2):
                     whose move it is and "Set a block", so the board keeps the height (DerCaddy, 2026-10-10: "wieder vergrößern"); on a
                     desktop the head of this column, beside the board. --}}
                <template x-if="layout.input === 'blocks' && color && state.status === 'active'">
                    <div @class(['flex flex-col gap-2 max-lg:fixed max-lg:inset-x-0 max-lg:z-20 max-lg:flex-row max-lg:items-center max-lg:gap-3 max-lg:border-t max-lg:border-line max-lg:bg-bar max-lg:px-4 max-lg:py-1.5 max-lg:shadow-[0_-16px_32px_rgba(10,10,11,.8)]', $chat ? 'max-lg:bottom-[72px]' : 'max-lg:bottom-0']) x-ref="dock" data-page-bar data-test="block-input">
                        {{-- On a phone the bar says whose move it is: the status chip gives way to it. --}}
                        <p class="m-0 line-clamp-2 min-w-0 grow text-xs text-ink-2 lg:hidden" x-text="statusLine" data-test="block-status"></p>
                        <button type="button" class="inline-flex h-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md px-[18px] text-[13px] disabled:cursor-default disabled:opacity-50 lg:w-full"
                                :class="blockMode ? 'border border-btc bg-btc-press font-bold text-btc-hi' : 'btn-w border border-line bg-well text-ink'"
                                :aria-pressed="blockMode ? 'true' : 'false'" :disabled="!myTurn || !canSetBlocks" x-on:click="setBlockMode(!blockMode, $event)" aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight R Enter Escape" data-test="mode-block">
                            {{ __('Set a block') }} <span class="tabular-nums" x-text="blocksLeft"></span>
                        </button>
                        {{-- Always in the page, so a screen reader hears each new hint (the keys say where the block is); the eye has the pop-up over the board. --}}
                        <p class="sr-only" role="status" aria-live="polite" x-text="blockHint" data-test="block-hint"></p>
                    </div>
                </template>
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
                {{-- One time before the repetition draw (Blockli), for as long as the position stands. --}}
                <p class="m-0 text-[13px] font-bold text-btc-hi" x-show="repetitionWarning" x-text="repetitionWarning" data-test="repetition-note"></p>
                {{-- Who would be ahead in the race (Blockli): steps to the goal and blocks left a side (DerCaddy, 2026-10-09). --}}
                <p class="m-0 text-[13px] text-ink-2" x-show="standingLine" x-text="standingLine" data-test="standing"></p>
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

                <div class="flex min-h-0 flex-col gap-2 rounded-lg bg-card p-4 lg:max-h-60 min-[87.5rem]:max-h-none min-[87.5rem]:shrink">
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
                    <ol class="m-0 flex min-h-0 list-none flex-wrap content-start gap-x-1 gap-y-1 overflow-y-auto p-0 text-[13px] tabular-nums" data-test="moves">
                        <template x-for="m in state.moves" :key="m.ply">
                            <li><button type="button" x-on:click="browse(m.ply)" :aria-current="shownPly === m.ply ? 'step' : null" data-test="move"
                                        class="cursor-pointer rounded-sm px-1.5 py-0.5 text-ink" :class="shownPly === m.ply ? 'bg-btc-press text-btc-hi' : 'hover:bg-row-hover'"><span class="text-ink-3" x-text="m.ply + '.'"></span> <span x-text="m.notation"></span></button></li>
                        </template>
                    </ol>
                </div>

            </div>

            {{-- The players' chat (P1), placed as on daily chess: under both columns from lg, its own column as tall as the
                 board from 87.5rem; the bottom sheet on a phone. --}}
            @if ($chat)
                @include('pages.games.partials.chat', ['chat' => $chat, 'panelClass' => 'lg:col-span-2 lg:h-[400px] min-[87.5rem]:col-span-1 min-[87.5rem]:col-start-3 min-[87.5rem]:row-start-1 min-[87.5rem]:h-0 min-[87.5rem]:min-h-full'])
            @endif
        </div>
    </div>
</div>
