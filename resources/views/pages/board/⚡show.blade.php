<?php

use App\Enums\BoardGameStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChatMute;
use App\Models\User;
use App\Support\Board\BlockspacePractice;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Board\BoardThrottle;
use App\Support\Board\GoLabels;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use App\Support\Notifications\DmRelays;
use App\Support\Tournaments\TournamentGameEnd;
use App\Support\Tournaments\TournamentRunner;
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
 * the chat sheet (P2, boardGame.js fitBoard); the bar also holds the hint and,
 * with a block shown, Rotate, Confirm and Move the pawn: nothing of it stands
 * over the board.
 *
 * A board of a Blockli team match (NIP rev. 9.25) is played here as a chess
 * board on its page (plan "Schach Rapid und Clan", P6): a banner names the
 * board and the team match, its players cannot abort it, the first-move
 * window loses the board, and the way on is back to the team match. The
 * first visit of each player is kept (BoardGameService::markSeen()).
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
        $this->markSeen();
    }

    /** A player opened a team match board: the first visit is kept (a missed first move, as chess). */
    private function markSeen(): void
    {
        $user = auth()->user();

        if ($user instanceof User && $this->boardGame->series_match_id !== null) {
            app(BoardGameService::class)->markSeen($this->boardGame, $user);
        }
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

        // Search and link preview, as a chess game's page has them: the players, the state, the board as a card. The sides
        // by the game's names for them (Go: Black moves first).
        $kind = ($game->rated ? __('Rated') : __('Casual')).' · '.GameNames::full($game->game, $game->mode);
        $description = __(':kind: :white :whiteSide vs :black :blackSide in the TWENTY ONE esports league.', ['whiteSide' => GameNames::sideNotes($game->game)['w'], 'blackSide' => GameNames::sideNotes($game->game)['b'], 'kind' => $kind, 'white' => $this->name('w'), 'black' => $this->name('b')]).' '.match ($game->status) {
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
    public function fetchState(?int $known = null): array
    {
        $this->markSeen();

        $game = $this->boardGame->refresh();

        // Nothing new since the page's last state: the move list stays with the page (Blockspace carries all stones per
        // move). The whole record is the costly answer, so a visitor gets it a few times a minute (BoardThrottle); beyond
        // that the state comes without it and the board asks again (boardGame.js resync()).
        $record = $known !== $game->version && BoardThrottle::allows('history', BoardThrottle::HISTORY_PER_MINUTE);

        return app(BoardGameService::class)->snapshot($game, withMoves: $record);
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
    public function takeBack(): array
    {
        return $this->act(fn (BoardGameService $games, User $user) => $games->takeBack($this->boardGame, $user));
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

        return ['ok' => true, 'error' => null, 'state' => $games->snapshot($this->boardGame->refresh(), withMoves: false)];
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

        // Without the record: the board joins its own to the state (boardGame.js follow()) and asks for the whole one only
        // where it fell behind. A refused action of a long go game answered with 1 MB before.
        return ['ok' => $error === null, 'error' => $error, 'state' => $games->snapshot($this->boardGame->refresh(), withMoves: false)];
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
            'startPieces' => $rules === null ? [] : $rules->view($rules->deserialize($games->startOf($this->boardGame, $rules)))['pieces'],
            'color' => $this->boardGame->colorOf(auth()->user()),
            'labels' => [
                'white' => GameNames::sides($this->boardGame->game)['w'],
                'black' => GameNames::sides($this->boardGame->game)['b'],
                'names' => ['w' => $this->name('w'), 'b' => $this->name('b')],
                'firstMove' => __(':side: first move within :s s'),
                // A tournament game's first-move deadline, in the player's words (user, 2026-10-03).
                'cupFirstMove' => match (true) {
                    $this->boardGame->tournament_match_id !== null => ['mine' => __('Make your first move within :time or you lose this cup game'), 'theirs' => __(':side must move within :time or loses this cup game')],
                    // A team match board: a missed first move loses the board (NIP rev. 9.25).
                    $this->boardGame->series_match_id !== null => ['mine' => __('Make your first move within :time or you lose this board'), 'theirs' => __(':side must move within :time or loses this board')],
                    default => null,
                },
                'drawOffer' => __(':name offers a draw', ['name' => $this->name($this->boardGame->colorOf(auth()->user()) === 'w' ? 'b' : 'w')]),
                // A move taken back within take_back_seconds (BoardGameService::takeBack(); DerCaddy, 2026-10-15).
                'takeBack' => ['button' => __('Take back'), 'left' => __('Take back your move, :seconds s left'), 'notice' => __(':name took the move back.')],
                'status' => ['live' => __('Live · move :move · :side to move'), 'daily' => __('Correspondence · move :move · :side to move'), 'over' => __('Game over'), 'aborted' => __('Aborted')],
                'deadline' => __(':side moves by :time, or loses on time.'),
                // The turn card of the harmonised match view (MatchBoards.dc.html, P5): who is to move, "your move" for the player.
                'toMove' => __(':name to move'),
                'yourMove' => __('Your move'),
                'connection' => ['connected' => __('Connected'), 'connecting' => __('Connecting …'), 'polling' => __('Live via server')],
                'outcome' => ['wins' => __(':name wins'), 'draw' => __('Draw'), 'aborted' => __('Game aborted')],
                'reasons' => [
                    'resignation' => __('Resignation'), 'timeout' => __('Out of time'), 'agreement' => __('Draw by agreement'), 'aborted' => __('Aborted'),
                    'forfeit' => __('No first move'), 'voided' => __('Voided by the league'),
                    ...array_map(fn (string $label): string => __($label), $reasons),
                ],
                // Blockspace's own input (resources/js/blockspace/board.js).
                'pieces' => BlockspacePractice::boardWords(),
                'errors' => ['illegal_move' => __('That move is not legal here.'), 'not_your_turn' => __('It is not your turn.'),
                    'out_of_sync' => __('The board was behind. It shows the latest position now.'), 'game_over' => __('The game is already over.'),
                    'not_a_player' => __('Only the two players can do that.'), 'too_late_to_abort' => __('Both sides have moved, the game can no longer be aborted.'),
                    'tournament_game' => __('A tournament game cannot be aborted.'), 'take_back_closed' => __('The move can no longer be taken back.'),
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
                // The stone input and the count (Go, DerCaddy 2026-10-27).
                ...GoLabels::board(),
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
    $banner = $boardGame->tournament_match_id !== null ? TournamentGameEnd::banner($boardGame) : null;
    // A board of a Blockli team match (NIP rev. 9.25): its way back is the team match, as a chess board's.
    $teamUrl = $boardGame->series_match_id !== null ? route('matches.show', (int) $boardGame->matchNumber()) : null;
    // Go (the stone input): a stone and the score in the players' cards, the score block, the result with the count.
    $stones = ($config['layout']['input'] ?? null) === 'stones';
    // The players' pictures come from the server (x-avatar); the page's script puts the viewer's side at the bottom, a spectator sees the first side there.
    $bottomSide = $boardGame->colorOf(auth()->user()) === 'b' ? 'b' : 'w';
    $seats = ['topSide' => $boardGame->player($bottomSide === 'b' ? 'w' : 'b'), 'bottomSide' => $boardGame->player($bottomSide)];
@endphp

<div class="flex grow flex-col px-4 pb-8 lg:px-12 lg:pb-10" data-test="board-page" data-focus-page>
    {{-- A tournament game says so first, above everything else (user, 2026-10-03). Below lg it is one line in the title row
         instead (game-banner-row, DerCaddy 2026-10-15): above the game, and the board keeps the size of a casual game's. --}}
    <x-tournaments.game-banner :banner="$banner" class="mx-auto mb-4 w-full max-w-[1000px] max-lg:hidden lg:mb-5" />
    @if ($teamUrl !== null)
        {{-- Which board of which team match, and the way back to all boards (as pages/games/partials/team-board). --}}
        <a href="{{ $teamUrl }}" @navigate($teamUrl) class="mx-auto mb-4 flex min-h-11 w-full max-w-[1000px] min-w-0 items-center gap-3 rounded-lg bg-card px-4 py-2 text-[13px] text-ink shadow-ring hover:text-ink lg:mb-5" data-test="team-board-banner">
            <x-icon name="clans" :size="18" class="shrink-0 text-btc" />
            <span class="min-w-0 grow truncate">{{ __('Board :board of team match :number', ['board' => $boardGame->board, 'number' => '#'.$boardGame->matchNumber()]) }}</span>
            <b class="shrink-0">{{ __('All boards') }}</b>
        </a>
    @endif

    <div wire:ignore x-data="boardGame(@js($config))" x-on:keydown.window="browseKey($event)" @class(["mx-auto flex w-full max-w-[1000px] flex-col gap-2 lg:gap-5", "min-[87.5rem]:max-w-[1360px]" => $chat]) data-test="board-game" data-mode="{{ $boardGame->mode }}">

        {{-- Title row --}}
        {{-- 8 px apart below lg: at 320 px the connection pill fell onto a line of its own and took the board 40 px of height. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-2 lg:gap-x-4">
            {{-- On a phone the credit stands beside the name: the board gets the height (P2). A tournament game shows its
                 banner there instead; the name stays for screen readers. --}}
            <div @class(['flex min-w-0 items-baseline gap-2 lg:block', 'max-lg:sr-only' => $banner])>
                <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ GameNames::game($boardGame->game) }}</h1>
                <x-game-credit :game="$boardGame->game" />
            </div>
            {{-- The lobby of this board game (P5): the next opponent, the ladder. --}}
            <a href="{{ route('board.lobby', $boardGame->game) }}" @class(['-my-2 inline-flex min-h-11 min-w-11 items-center justify-center text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc', 'max-lg:hidden' => $banner]) data-test="board-lobby-link">{{ __('Lobby') }}</a>
            <x-tournaments.game-banner-row :banner="$banner" />
            {{-- No separate "Correspondence games" link: board games are correspondence only, the lobby is that page (2026-10-08; it pushed the board below the fold at 1440). --}}
            {{-- The match dock's button from lg, as on daily chess: the chat owns the bottom right here (its floating bar covered the send button, PR #5). --}}
            <div data-dock-slot class="max-lg:hidden"></div>
            <span @class(['grow', 'max-lg:hidden' => $banner])></span>
            <span role="status" class="flex h-[34px] items-center gap-2 rounded-md px-2.5 text-[13px] lg:px-3"
                  :class="connection === 'connected' ? 'bg-[#122016] text-win' : 'bg-[#241D10] text-btc-hi'">
                <span class="size-2 rounded-full" :class="connection === 'connected' ? 'bg-win' : 'bg-btc-hi'"></span>
                <span x-text="connectionLabel" class="max-lg:sr-only" data-test="connection"></span>
            </span>
            <span class="flex h-7 items-center rounded-md bg-btc-press px-3 text-xs font-bold text-btc-hi max-lg:w-full lg:h-[34px] lg:px-3.5 lg:text-[13px]" :class="hasDock || (layout.input === 'stones' && state.status !== 'active') ? 'max-lg:hidden' : ''" x-text="statusLine" data-test="status-line"></span>
        </div>

        {{-- The match view template T2 (MatchBoards.dc.html, plan "Refactor und Design-Revamp", P5), harmonised to daily
             chess, the model (user: "Chat viel Platz, Verlauf in der Mitte, gut sichtbar wer dran ist"): the board left
             with the game's actions under it, the middle column with the opponent on top, who is to move with the clock,
             the moves as a two-column list and the player at the bottom, the chat right from 87.5rem. On a phone both
             players share one row above the board (P2) and the board keeps the height. --}}
        <div @class(['grid grid-cols-1 gap-3 lg:gap-x-7 lg:gap-y-5', $boardColumn, 'min-[87.5rem]:grid-cols-[minmax(0,560px)_380px_minmax(0,1fr)]' => $chat])>
            {{-- The board: the rules describe it, the script draws it --}}
            <div class="grid min-w-0 grid-cols-2 gap-2 lg:flex lg:flex-col lg:gap-3">
                <div class="flex min-w-0 flex-col items-start rounded-lg bg-card px-3 py-1 lg:hidden" data-test="player-top">
                    @if ($stones)
                        @include('pages.board.partials.go-player', ['at' => 'top'])
                    @else
                        <span class="max-w-full min-w-0 truncate text-xs leading-4"><span x-text="t.names[topSide]"></span></span>
                    <span class="flex shrink-0 items-baseline gap-1.5"><span class="text-[11px] text-ink-3" x-text="topSide === 'w' ? t.white : t.black"></span><span role="timer" class="shrink-0 font-display text-lg font-bold whitespace-nowrap tabular-nums max-lg:leading-6" :class="state.clock.running === topSide ? 'text-btc-hi' : 'text-ink-2'" x-text="clock(topSide)" data-test="clock-top"></span></span>
                    @endif
                </div>
                {{-- Black sees the board from its own side. --}}
                {{-- Nothing of the block input stands over the board (a player on an iPhone SE, 2026-10-14: the hint and the buttons
                     over a board of 200 px left no groove to tap): they live in Blockli's bar. Over the board only the warning one time
                     before the repetition draw, for a few seconds after each such move, like a push message (DerCaddy, 2026-10-09),
                     gone while a block is being set and never in the way of a tap; beside the board it stays. fitBoard sizes this box
                     (data-board-box), so the warning keeps to the board. The live stream's tab steps aside while the board lies where it
                     would stand (data-live-clear, resources/js/livePlayer.js): over the lower left corner it took the squares' clicks. --}}
                @if (($config['layout']['input'] ?? null) === 'pieces')
                    <div class="col-span-2 min-w-0">@include('pages.board.partials.blockspace-stage')</div>
                @else
                <div class="relative col-span-2 mx-auto w-full" data-board-box>
                    {{-- Go's board stays the same way round for both (layout `upright`: its coordinates read alike); the board takes the
                         focus there, and the arrow keys move the stone shown (boardGame.js, the stone input). The ring shows only for
                         the keyboard: after a tap or a click the browser would draw its own around the board. --}}
                    <svg x-ref="board" data-focus-board data-live-clear viewBox="0 0 {{ $config['layout']['width'] ?? 100 }} {{ $config['layout']['height'] ?? 100 }}"
                         x-on:click="pick($event)" x-on:keydown="pickKey($event)"
                         role="group" aria-label="{{ __('Game board') }}"
                         x-bind:tabindex="layout.input === 'stones' && color ? 0 : null"
                         x-bind:class="color === 'b' && !layout.upright ? 'rotate-180' : ''"
                         style="aspect-ratio: {{ $config['layout']['width'] ?? 1 }} / {{ $config['layout']['height'] ?? 1 }}"
                         class="block w-full touch-manipulation rounded-[4px] bg-well select-none [&:focus:not(:focus-visible)]:outline-none" data-test="board"></svg>
                    <template x-if="layout.input === 'blocks'">
                        <div>
                            <p x-show="warningShown" x-transition.opacity.duration.300ms class="pointer-events-none absolute inset-x-2 z-10 m-0 rounded-[4px] border border-btc-ring bg-feature py-2 pr-3 pl-4 text-[13px] font-bold text-btc-hi shadow-[inset_3px_0_0_var(--color-btc),0_16px_32px_rgba(10,10,11,.8)]"
                               :style="popupAt(true)" role="alert" x-text="repetitionWarning" data-test="repetition-warning"></p>
                        </div>
                    </template>
                </div>
                @endif
                <div class="flex min-w-0 flex-col items-start rounded-lg bg-card px-3 py-1 max-lg:order-first max-lg:col-start-2 max-lg:row-start-1 lg:hidden" data-test="player-bottom">
                    @if ($stones)
                        @include('pages.board.partials.go-player', ['at' => 'bottom'])
                    @else
                        <span class="max-w-full min-w-0 truncate text-xs leading-4"><span x-text="t.names[bottomSide]"></span></span>
                    <span class="flex shrink-0 items-baseline gap-1.5"><span class="text-[11px] text-ink-3" x-text="bottomSide === 'w' ? t.white : t.black"></span><span role="timer" class="shrink-0 font-display text-lg font-bold whitespace-nowrap tabular-nums max-lg:leading-6" :class="state.clock.running === bottomSide ? 'text-btc-hi' : 'text-ink-2'" x-text="clock(bottomSide)" data-test="clock-bottom"></span></span>
                    @endif
                </div>

                @if ($stones)
                    {{-- Go's score on a phone: under the board, in the room between it and the bar (from lg a card of the middle column). --}}
                    <template x-if="score && phone && state.status === 'active'">
                        <div class="col-span-2 min-w-0" data-fit-skip>@include('pages.board.partials.go-score')</div>
                    </template>
                @endif
                {{-- Under the board, as on daily chess: the draw offer to answer, then abort or draw and resign. --}}
                <template x-if="color && state.status === 'active' && state.drawOffer && state.drawOffer !== color">
                    <div class="col-span-2 flex flex-col gap-3 rounded-lg bg-card p-4" data-fit-skip data-test="draw-offer">
                        <span class="text-sm font-bold" x-text="t.drawOffer"></span>
                        <div class="grid grid-cols-2 gap-2">
                            <x-button variant="quiet" x-on:click="call('declineDraw')" data-test="decline-draw">{{ __('Decline') }}</x-button>
                            <x-button x-on:click="call('acceptDraw')" data-test="accept-draw">{{ __('Accept draw') }}</x-button>
                        </div>
                    </div>
                </template>
                <template x-if="color && state.status === 'active'">
                    <div class="col-span-2 flex flex-wrap items-center justify-end gap-2" data-fit-skip data-test="board-actions">
                        <span class="mr-auto text-[13px] text-ink-2 max-lg:hidden" x-text="state.moves.length ? (Math.ceil(state.moves.length / 2) + (state.moves.length % 2 ? '. ' : '… ') + state.moves[state.moves.length - 1].notation) : ''"></span>
                        {{-- Take the own move back, as in Blockli's bar and Go's (which have the button there): nine men's morris, checkers. --}}
                        <template x-if="layout.input !== 'blocks' && layout.input !== 'stones' && canTakeBack">
                            <x-button variant="quiet" x-on:click="takeBack()" x-bind:aria-label="t.takeBack.left.replace(':seconds', takeBackLeft)" data-test="take-back">
                                {{ __('Take back') }} <span class="min-w-[2ch] tabular-nums" x-text="takeBackLeft"></span>
                            </x-button>
                        </template>
                        {{-- A tournament game or a team match board cannot be aborted: a missed first move is the league's to decide (P5). --}}
                        @if (! $boardGame->hasEnvelope())
                            <template x-if="state.ply < 2">
                                <x-button variant="quiet" x-on:click="call('abort')" data-test="abort">{{ __('Abort game') }}</x-button>
                            </template>
                        @endif
                        <template x-if="state.ply >= 2">
                            <x-button variant="quiet" x-on:click="call('offerDraw')" x-bind:disabled="state.drawOffer === color" data-test="offer-draw">
                                <span x-text="state.drawOffer === color ? @js(__('Draw offered')) : @js(__('Offer draw'))"></span>
                            </x-button>
                        </template>
                        <x-button variant="secondary" x-on:click="resign()" class="text-loss! border-[#5A2A2E]!" data-test="resign">
                            <span x-text="confirmResign ? @js(__('Resign this game?')) : @js(__('Resign'))"></span>
                        </x-button>
                    </div>
                </template>
            </div>

            {{-- The middle column: opponent, who is to move, the game's input, moves, the player. --}}
            {{-- Go's column is as tall as what it holds: its move list keeps three rows (five below 87.5rem) and takes what the board's
                 height leaves beyond them (measured 2026-11-14 at 1440 x 900: half a row, in every state). --}}
            <div @class(['flex min-w-0 flex-col gap-3', 'min-[87.5rem]:h-0 min-[87.5rem]:min-h-full' => $chat && ! $stones])>
                {{-- The opponent (from lg; on a phone the two cards above the board). --}}
                <div class="flex min-w-0 items-center gap-3 max-lg:hidden" data-test="side-card-top">
                    {{-- The player's picture, never a letter (x-avatar); Go adds the side's stone, drawn as the board's stones are. --}}
                    <span class="relative shrink-0">
                        @if ($seats['topSide'])
                            <x-avatar :user="$seats['topSide']" :size="44" class="rounded-[4px]" />
                        @else
                            <span class="grid size-11 place-items-center rounded-[4px] bg-btc-press font-display text-sm font-bold text-btc" aria-hidden="true" x-text="(t.names[topSide] || '?').slice(0, 1).toUpperCase()"></span>
                        @endif
                        @if ($stones)
                            <span class="absolute -right-1 -bottom-1 block size-4 rounded-full bg-ground ring-2 ring-ground" x-html="stoneGlyph(topSide)"></span>
                        @endif
                    </span>
                    <span class="flex min-w-0 grow flex-col">
                        <b class="truncate text-[15px]" x-text="t.names[topSide]"></b>
                        <span class="text-[13px] text-ink-2" x-text="topSide === 'w' ? t.white : t.black"></span>
                    </span>
                    <span role="timer" class="shrink-0 font-display text-base font-bold whitespace-nowrap tabular-nums" :class="state.clock.running === topSide ? 'text-btc-hi' : '{{ $stones ? 'text-ink' : 'text-ink-3' }}'" x-text="clock(topSide)" @if ($stones) x-show="state.status === 'active'" @endif></span>
                </div>

                {{-- Who is to move, with the clock of that side (the chess turn card). --}}
                <template x-if="state.status === 'active'">
                    <div class="flex items-center gap-4 rounded-[4px] border border-btc-ring bg-feature px-4 py-3 max-lg:hidden" role="status" data-test="turn-card">
                        <span class="flex min-w-0 grow flex-col gap-1">
                            <b class="text-[15px]" x-text="color && color === state.turn ? t.yourMove : t.toMove.replace(':name', t.names[state.turn])"></b>
                            <span class="text-[13px] leading-normal text-ink-2" x-show="deadlineLine" x-text="deadlineLine"></span>
                        </span>
                        <span role="timer" class="shrink-0 rounded-[4px] bg-btc px-3 py-2 font-display text-[26px] leading-none font-bold whitespace-nowrap text-on-btc tabular-nums" x-text="clock(state.turn)"></span>
                    </div>
                </template>

                {{-- Go: the result takes the turn card's place, so on a phone it follows the board at once (the next action on the
                     first screen at 320 x 640; boardGame.js fitBoard gives it the room). --}}
                @if ($stones)
                    @include('pages.board.partials.result')
                @endif

                {{-- The block input (Blockli): a marked square moves the pawn; "Set a block" (or a tap in a groove) shows a block first, as
                     in the Blockli prototype, and is a toggle. Everything stays off the board (a player on an iPhone SE, 2026-10-14). On a
                     phone a bar of one row fixed above the chat sheet (P2), which changes what it holds: whose move it is and "Set a
                     block"; in set-a-block mode what to do; with a block shown Rotate, Confirm and Move the pawn. The row never grows, so
                     the board keeps its height (DerCaddy, 2026-10-10). On a desktop the head of this column: the toggle, the hint under
                     it, and the three buttons for a finger or the keys (a mouse sets with its click, R turns). While a block is being
                     set the toggle reads "Move the pawn", as the phone's button (DerCaddy, 2026-11-12), and from lg stands in for it;
                     the keys keep "Set a block", Enter on it sets the block they show. --}}
                <template x-if="layout.input === 'blocks' && color && state.status === 'active'">
                    {{-- The line (in the wrapper Go's bar shares, x-board.input-bar): on a phone whose move it is (the status chip gives way
                         to the bar), in set-a-block mode the hint; from lg the hint alone, under the toggle. Gone on a phone while the three
                         buttons need the row. --}}
                    <x-board.input-bar name="block-input" status="block-status" :bottom="$chat ? 'max-lg:bottom-[72px]' : 'max-lg:bottom-0'" line="lg:order-2"
                                       tone="[preview && !preview.move && settingBlock ? 'text-loss' : 'text-ink-2', blockButtons ? 'max-lg:hidden' : '', settingBlock ? '' : 'lg:hidden']">
                        <button type="button" class="inline-flex h-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md px-[18px] max-[359px]:px-2 text-[13px] disabled:cursor-default disabled:opacity-50 lg:order-1 lg:w-full"
                                :class="[blockMode ? 'border border-btc bg-btc-press font-bold text-btc-hi' : 'btn-w border border-line bg-well text-ink', blockButtons ? 'max-lg:hidden' : '']"
                                :aria-pressed="toggleMoves ? 'false' : (blockMode ? 'true' : 'false')" :disabled="!myTurn || !canSetBlocks" x-on:click="setBlockMode(!blockMode, $event)" aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight R Enter Escape"
                                x-show="!canTakeBack" data-test="mode-block">
                            {{-- Both labels in one cell, the one not shown invisible: the button is as wide as the longer one and does not jump
                                 when the label swaps or the count goes from 10 to 9 (measured 2026-11-13: 8 to 16 px in German on a phone). --}}
                            <span class="grid whitespace-nowrap" data-toggle-label>
                                <span class="col-start-1 row-start-1" :class="toggleMoves ? 'invisible' : ''">{{ __('Set a block') }} <span class="inline-block w-[2ch] text-start tabular-nums" x-text="blocksLeft"></span></span>
                                <span class="col-start-1 row-start-1" :class="toggleMoves ? '' : 'invisible'">{{ __('Move the pawn') }}</span>
                            </span>
                        </button>
                        {{-- Right after the player's own move, until the opponent answers: take it back, with the seconds left (BoardGameService::takeBack();
                             DerCaddy and the players, 2026-10-15: a misclick, 21 seconds, no accept step). In the toggle's place, which is off then anyway. --}}
                        <button type="button" class="inline-flex h-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-btc bg-btc-press px-[18px] text-[13px] font-bold whitespace-nowrap text-btc-hi lg:order-1 lg:w-full"
                                x-show="canTakeBack" x-cloak x-on:click="takeBack()" :aria-label="t.takeBack.left.replace(':seconds', takeBackLeft)" data-test="take-back">
                            {{ __('Take back') }} <span class="min-w-[2ch] tabular-nums" x-text="takeBackLeft"></span>
                        </button>
                        {{-- With a block shown: Rotate, Confirm and Move the pawn, side by side in the row, each as wide as its label and never
                             wrapping it (R11; "Figur bewegen" wrapped at 320 to 390 px in German): 12 px under 360 px. --}}
                        <div class="min-w-0 grow gap-2 lg:order-3" :class="blockButtons ? 'flex' : 'hidden'" data-block-buttons>
                            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-line bg-well px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] text-ink btn-w hover:text-ink disabled:cursor-default disabled:opacity-50"
                                    x-bind:disabled="!preview" x-on:click="rotateBlock()" data-test="rotate-block">{{ __('Rotate') }}</button>
                            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md bg-btc px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] font-bold text-on-btc btn-p hover:text-on-btc disabled:cursor-default disabled:opacity-50"
                                    x-bind:disabled="!preview || !preview.move" x-on:click="setBlock()" data-test="set-block">{{ __('Confirm') }}</button>
                            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-edge px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] text-ink btn-s hover:text-ink"
                                    :class="toggleMoves ? 'lg:hidden' : ''" x-on:click="setBlockMode(false)" data-test="mode-move">{{ __('Move the pawn') }}</button>
                        </div>
                        {{-- Always in the page, so a screen reader hears each new hint (the keys say where the block is). --}}
                        <p class="sr-only" role="status" aria-live="polite" x-text="blockHint" data-test="block-hint"></p>
                    </x-board.input-bar>
                </template>
                {{-- Go's bar (the stone input): Place, Pass, Take back, as Blockli's bar on a phone fixed above the chat sheet. --}}
                @include('pages.board.partials.stone-input', ['bottom' => $chat ? 'max-lg:bottom-[72px]' : 'max-lg:bottom-0', 'quietIdle' => true])
                @if ($boardGame->hasEnvelope())
                    {{-- A tournament game or a team match board ends at the first-move deadline by the league's decision: the countdown leads, the rule stays under it. --}}
                    <div class="flex flex-col gap-1 rounded-lg bg-btc-tint px-4 py-3 shadow-[inset_0_0_0_1px_var(--color-btc)]" role="status" x-show="firstMoveLine" data-test="first-move">
                        <b class="font-display text-base leading-snug text-btc-hi lg:text-lg" data-test="first-move-cup"
                           x-text="firstMoveLeft === null ? '' : (color === state.turn ? t.cupFirstMove.mine : t.cupFirstMove.theirs.replace(':side', t.names[state.turn])).replace(':time', Math.floor(firstMoveLeft / 60) + ':' + String(firstMoveLeft % 60).padStart(2, '0'))"></b>
                        <span class="text-xs text-ink-2" x-text="firstMoveLine"></span>
                    </div>
                @else
                    <p class="m-0 text-[13px] text-ink-2" x-show="firstMoveLine" x-text="firstMoveLine" data-test="first-move"></p>
                @endif
                {{-- From lg the deadline sits in the turn card. --}}
                <p class="m-0 text-[13px] text-ink-2 lg:hidden" x-show="deadlineLine" x-text="deadlineLine" data-test="deadline"></p>
                {{-- One time before the repetition draw (Blockli), for as long as the position stands. --}}
                <p class="m-0 rounded-[4px] border border-btc-ring bg-feature py-2 pr-3 pl-4 text-[13px] font-bold text-btc-hi shadow-[inset_3px_0_0_var(--color-btc)]" x-show="repetitionWarning" x-text="repetitionWarning" data-test="repetition-note"></p>
                {{-- Who would be ahead in the race (Blockli): steps to the goal and blocks left a side (DerCaddy, 2026-10-09). --}}
                <p class="m-0 text-[13px] text-ink-2" x-show="standingLine" x-text="standingLine" data-test="standing"></p>
                {{-- What each side would have if the game were counted now (Go), from lg; on a phone it stands under the board. Once the
                     game is over the result card holds it from lg (partials/result), and on a phone it follows that card. --}}
                @if ($stones)
                    <template x-if="score && phone === (state.status !== 'active')">@include('pages.board.partials.go-score')</template>
                @endif
                @php
                    // The deciding game of a two-leg pairing in a tournament whose drawn decider goes to the race standing (user, 2026-10-11).
                    $deciderMatch = $boardGame->isActive() ? $boardGame->tournamentMatch : null;
                    $standingDecides = $deciderMatch !== null && $deciderMatch->tournament?->decidesDrawByStanding()
                        && TournamentRunner::canDecideByStanding($boardGame->game)
                        && TournamentRunner::finishedBoardGames($deciderMatch)->where('id', '<', $boardGame->id)->count() >= 2;
                @endphp
                @if ($standingDecides)
                    <p class="m-0 rounded-[4px] border border-line bg-card px-3 py-2 text-[13px] text-ink" data-test="decider-standing">{{ __('This game decides the pairing. If it is drawn, the race standing of the final position decides; a level standing is replayed.') }}</p>
                @endif
                {{-- The other side took its move back (on a phone Blockli's bar says it too). --}}
                <p role="status" class="m-0 text-[13px] font-bold text-ink" x-show="noticeShown" x-text="notice" data-test="board-notice"></p>
                <p role="alert" class="m-0 text-[13px] text-loss" x-show="error && layout.input !== 'pieces'" x-text="error" data-test="board-error"></p>

                @if (! $stones)
                    @include('pages.board.partials.result')
                @endif

                {{-- The moves, two columns per move number as on daily chess; browsing steps through them. --}}
                <div @class(['flex flex-col gap-2 rounded-lg bg-card p-4', 'min-h-0 lg:grow min-[87.5rem]:shrink' => ! $stones, 'lg:min-h-80 lg:flex-[1_1_0px] min-[87.5rem]:min-h-56' => $stones]) data-test="moves-card">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="m-0 text-[15px] font-bold">{{ __('Moves') }}</h2>
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
                    <ol @class(['m-0 grid max-h-60 min-h-0 list-none grid-cols-2 content-start gap-x-2 overflow-y-auto p-0 text-[13px] tabular-nums lg:max-h-none', 'lg:grow' => ! $stones, 'lg:flex-[1_1_0px]' => $stones]) data-test="moves">
                        <template x-for="m in state.moves" :key="m.ply">
                            @if ($stones)
                                {{-- Go: a row of 44 px; a pass reads as a pass (plain, grey), not as a point; what a stone took stands behind it ("×2"). --}}
                                <li class="min-w-0 border-b border-hairline"><button type="button" x-on:click="browse(m.ply)" :aria-current="shownPly === m.ply ? 'step' : null" data-test="move"
                                            class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-sm px-2 text-left text-ink" :class="shownPly === m.ply ? 'bg-btc-press font-bold text-btc-hi' : 'hover:bg-row-hover'"><span class="w-7 shrink-0 text-ink-3" x-text="m.ply % 2 ? Math.ceil(m.ply / 2) + '.' : ''"></span><b class="truncate" :class="m.path.length ? 'font-bold' : (shownPly === m.ply ? '' : 'font-normal text-ink-2')" x-text="m.notation"></b><template x-if="m.taken?.length"><span class="shrink-0" :class="shownPly === m.ply ? '' : 'font-normal text-ink-2'" data-test="move-taken"><span aria-hidden="true" x-text="'×' + m.taken.length"></span><span class="sr-only" x-text="t.stones.captured.replace(':count', m.taken.length)"></span></span></template></button></li>
                            @else
                            <li class="min-w-0 border-b border-hairline"><button type="button" x-on:click="browse(m.ply)" :aria-current="shownPly === m.ply ? 'step' : null" data-test="move"
                                        class="flex min-h-10 w-full cursor-pointer items-center gap-2 rounded-sm px-2 text-left text-ink" :class="shownPly === m.ply ? 'bg-btc-press font-bold text-btc-hi' : 'hover:bg-row-hover'"><span class="w-7 shrink-0 text-ink-3" x-text="m.ply % 2 ? Math.ceil(m.ply / 2) + '.' : ''"></span><b class="truncate font-bold" x-text="m.notation"></b></button></li>
                            @endif
                        </template>
                    </ol>
                </div>

                {{-- The player (from lg). --}}
                <div class="flex min-w-0 items-center gap-3 max-lg:hidden" data-test="side-card-bottom">
                    {{-- The player's picture, never a letter (x-avatar); Go adds the side's stone, drawn as the board's stones are. --}}
                    <span class="relative shrink-0">
                        @if ($seats['bottomSide'])
                            <x-avatar :user="$seats['bottomSide']" :size="44" class="rounded-[4px]" />
                        @else
                            <span class="grid size-11 place-items-center rounded-[4px] bg-btc-press font-display text-sm font-bold text-btc" aria-hidden="true" x-text="(t.names[bottomSide] || '?').slice(0, 1).toUpperCase()"></span>
                        @endif
                        @if ($stones)
                            <span class="absolute -right-1 -bottom-1 block size-4 rounded-full bg-ground ring-2 ring-ground" x-html="stoneGlyph(bottomSide)"></span>
                        @endif
                    </span>
                    <span class="flex min-w-0 grow flex-col">
                        <b class="truncate text-[15px]"><span x-text="t.names[bottomSide]"></span> <span class="font-normal text-ink-2" x-show="color === bottomSide">({{ __('you') }})</span></b>
                        <span class="text-[13px] text-ink-2" x-text="bottomSide === 'w' ? t.white : t.black"></span>
                    </span>
                    <span role="timer" class="shrink-0 font-display text-base font-bold whitespace-nowrap tabular-nums" :class="state.clock.running === bottomSide ? 'text-btc-hi' : '{{ $stones ? 'text-ink' : 'text-ink-3' }}'" x-text="clock(bottomSide)" @if ($stones) x-show="state.status === 'active'" @endif></span>
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
