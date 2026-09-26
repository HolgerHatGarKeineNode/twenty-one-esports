<?php

use App\Enums\ChessGameStatus;
use App\Models\ChatMute;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessPgn;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\GameRecords;
use App\Support\Chess\PresenceLookup;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\PageMeta;
use App\Support\Rating\Ratings;
use App\Support\Seo\LocalizedUrls;
use App\Support\Seo\StructuredData;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * One chess game: live (ChessGame, MobileChessGame, ChessOverlays,
 * ChessStates) or finished (ChessGameDone).
 *
 * The live board is Alpine (resources/js/chess.js) inside `wire:ignore`; the
 * actions below are #[Json]: they never re-render, they return the server's
 * state and the board shows that. Every change also reaches both players
 * (private `game.{id}`) and spectators (public `game.{id}.watch`) over Reverb;
 * without a websocket the page polls fetchState().
 *
 * Casual only (no Elo before P7), so every Elo, Hashrate and team-match
 * figure of the designs is replaced by "casual" wording.
 *
 * P5b: a daily game (mode `correspondence`) shows ChessCorrespondence /
 * MobileChessCorrespondence (partials/daily); each daily move is signed by
 * the mover as a NIP-64 note (GameRecords). Both have the NIP-17 chat
 * (partials/chat, in daily games since P5d); a live game also has the
 * "opponent disconnected" overlay with claim-win.
 * At the end of any game the players' app signs the NIP-64 record.
 */
new #[Layout('layouts::app', ['section' => 'chess', 'realtime' => true, 'scripts' => ['resources/js/chess.js']])] class extends Component {
    #[Locked]
    public ChessGame $game;

    public function mount(ChessGame $game): void
    {
        // A flag that fell while nobody was looking is settled before the clock is shown.
        $this->game = $game->isActive() ? app(ChessGameService::class)->checkClock($game) : $game;
        $this->markPresent();
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $game = $this->game;
        $locale = app()->getLocale();
        $daily = $game->isCorrespondence();
        $name = trim(($daily ? __('Daily chess') : __('Game')).' '.($game->number !== null ? $game->number() : ''));
        $white = $game->white->displayName();
        $black = $game->black->displayName();
        $title = $name.': '.$white.' vs '.$black;
        $view->title($title);

        $kind = $game->rated ? ($daily ? __('Rated · Daily chess') : __('Rated · Blitz 5+3')) : ($daily ? __('Casual · Daily chess') : __('Casual · Blitz 5+3'));
        $description = __(':kind: :white (white) vs :black (black) in the TWENTY ONE esports league.', ['kind' => $kind, 'white' => $white, 'black' => $black]).' '.match ($game->status) {
            ChessGameStatus::Active => __('Live now: watch the board move by move.'),
            ChessGameStatus::Aborted => __('Aborted before both first moves'),
            ChessGameStatus::Finished => __('Result: :result.', ['result' => $game->result ?? '?']),
        };

        app(PageMeta::class)
            ->describe($title, $description)
            ->addStructuredData(StructuredData::chessGame($game, $title, LocalizedUrls::for($locale)))
            ->addStructuredData(StructuredData::breadcrumbs([
                [__('Home'), LocalizedUrls::for($locale, route('home'))],
                [__('Matches'), LocalizedUrls::for($locale, route('matches.index'))],
                [$name, LocalizedUrls::for($locale, route('games.show', $game))],
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
        $this->markPresent();

        return app(ChessGameService::class)->snapshot($this->game->refresh());
    }

    /**
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    #[Json]
    public function move(string $uci, int $ply): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->move($this->game, $user, $uci, $ply));
    }

    #[Json]
    public function resign(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->resign($this->game, $user));
    }

    #[Json]
    public function offerDraw(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->offerDraw($this->game, $user));
    }

    #[Json]
    public function acceptDraw(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->acceptDraw($this->game, $user));
    }

    #[Json]
    public function declineDraw(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->declineDraw($this->game, $user));
    }

    #[Json]
    public function abort(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->abort($this->game, $user));
    }

    #[Json]
    public function offerRematch(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->offerRematch($this->game, $user));
    }

    #[Json]
    public function acceptRematch(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->acceptRematch($this->game, $user));
    }

    #[Json]
    public function declineRematch(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->declineRematch($this->game, $user));
    }

    /* ---------- Opponent disconnected (live) --------------------------------------------------------------- */

    /**
     * The page saw the opponent leave the game's presence channel.
     *
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    #[Json]
    public function reportGone(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->reportGone($this->game, $user, app(PresenceLookup::class)));
    }

    /**
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    #[Json]
    public function claimWin(): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => $games->claimWin($this->game, $user, app(PresenceLookup::class)));
    }

    /* ---------- NIP-64 records ------------------------------------------------------------------------------ */

    /**
     * The final record to sign, or null (game runs, aborted, already recorded, spectator).
     *
     * @return array<string, mixed>|null
     */
    #[Json]
    public function recordTemplate(): ?array
    {
        $this->game->refresh();

        return $this->game->colorOf(auth()->user()) === null ? null : app(GameRecords::class)->finalTemplate($this->game);
    }

    /**
     * @param  string  $signed  the signed event as JSON (never trimmed: TrimStrings skips nothing inside it)
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    #[Json]
    public function submitRecord(string $signed): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => app(GameRecords::class)->submitFinal($this->game, $user, $signed));
    }

    /* ---------- Daily moves --------------------------------------------------------------------------------- */

    /**
     * Check a daily move and return the note to sign for it.
     *
     * @return array{ok: bool, error: string|null, move: array<string, mixed>|null}
     */
    #[Json]
    public function prepareMove(string $uci, int $ply): array
    {
        $user = auth()->user();

        try {
            if (! $user instanceof User) {
                throw new ChessRuleViolation('not_a_player');
            }

            return ['ok' => true, 'error' => null, 'move' => app(GameRecords::class)->prepareMove($this->game, $user, $uci, $ply)];
        } catch (ChessRuleViolation $violation) {
            return ['ok' => false, 'error' => $violation->reason, 'move' => null];
        }
    }

    /**
     * @param  string  $signed  the player's signed note of this move, as JSON
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    #[Json]
    public function playMove(string $uci, int $ply, string $signed): array
    {
        return $this->act(fn (ChessGameService $games, User $user) => app(GameRecords::class)->playSigned($this->game, $user, $uci, $ply, $signed));
    }

    /**
     * "Tell me when … moves": dm, push, or here (only on this page).
     */
    #[Json]
    public function setNotify(string $choice): bool
    {
        return $this->setPreference('notify', in_array($choice, ['dm', 'push', 'here'], true) ? $choice : null);
    }

    #[Json]
    public function setRemind(bool $remind): bool
    {
        return $this->setPreference('remind', $remind);
    }

    /* ---------- Chat -------------------------------------------------------------------------------------- */

    /**
     * Mute or unmute a pubkey for this player (the browser keeps its own copy).
     */
    #[Json]
    public function setMuted(string $pubkey, bool $muted): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! NostrKeys::isHexPubkey($pubkey) || $pubkey === $user->pubkey) {
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
     * Config for the chat panel (resources/js/gameChat.js).
     *
     * @return array<string, mixed>
     */
    public function chatConfig(): array
    {
        $viewer = auth()->user();
        $opponent = $viewer instanceof User ? $this->game->opponentOf($viewer) : null;

        return [
            'me' => $opponent !== null ? $viewer?->pubkey : null,
            'meName' => $viewer?->displayName(),
            'opponent' => $opponent === null ? null : ['pubkey' => $opponent->pubkey, 'name' => $opponent->displayName()],
            'match' => $this->game->id,
            // Gift wraps are backdated up to two days (NIP-59); gameChat.js reads that far before this.
            'since' => $this->game->created_at?->getTimestamp(),
            'relays' => array_values(config('esports.chat.relays', [])),
            'muted' => $viewer instanceof User ? $viewer->mutedPubkeys() : [],
            'labels' => [
                'mute' => __('Mute :name', ['name' => $opponent?->displayName() ?? '']),
                'muted' => __(':name muted', ['name' => $opponent?->displayName() ?? '']),
                'mutedPeek' => __(':name is muted', ['name' => $opponent?->displayName() ?? '']),
                'server' => __('Server'),
                'unread' => __(':count new'),
                'open' => __('open'),
                'close' => __('close'),
                'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
                'notSent' => __('The message did not reach any relay. Please try again.'),
                'failed' => __('That did not work. Please try again.'),
            ],
        ];
    }

    /**
     * A client whose clock shows zero asks; the server's clock decides.
     *
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    #[Json]
    public function checkClock(): array
    {
        app(ChessGameService::class)->checkClock($this->game);

        return ['ok' => true, 'error' => null, 'state' => app(ChessGameService::class)->snapshot($this->game->refresh())];
    }

    /**
     * @param  Closure(ChessGameService, User): mixed  $action
     * @return array{ok: bool, error: string|null, state: array<string, mixed>}
     */
    private function act(Closure $action): array
    {
        $games = app(ChessGameService::class);
        $user = auth()->user();
        $error = null;

        try {
            if (! $user instanceof User) {
                throw new ChessRuleViolation('not_a_player');
            }

            $action($games, $user);
        } catch (ChessRuleViolation $violation) {
            $error = $violation->reason;
        } catch (RejectedEvent $rejected) {
            report($rejected);
            $error = 'signature_rejected';
        }

        return ['ok' => $error === null, 'error' => $error, 'state' => $games->snapshot($this->game->refresh())];
    }

    private function markPresent(): void
    {
        $user = auth()->user();

        if ($user instanceof User && $this->game->isActive()) {
            app(ChessGameService::class)->markPresent($this->game, $user);
        }
    }

    /**
     * @param  'notify'|'remind'  $key
     */
    private function setPreference(string $key, string|bool|null $value): bool
    {
        $color = $this->game->colorOf(auth()->user());

        if ($color === null) {
            return false;
        }

        $this->game->forceFill([($color === 'w' ? 'white_' : 'black_').$key => $value])->save();

        return true;
    }

    /**
     * Name card data for one side (kit section 6), with the player's rating
     * in this game's ladder (casual before Block 0) and this game's change
     * once it is over (P7b).
     *
     * @return array{name: string, avatar: string|null, tag: string|null, member: bool, url: string, npub: string, user: User, rating: array<string, mixed>}
     */
    public function player(User $user): array
    {
        return [
            'name' => $user->displayName(),
            'avatar' => $user->avatarUrl(),
            'tag' => $user->clanMember?->clan?->clantag,
            'member' => $user->is_member,
            'url' => route('players.show', $user->npub),
            'npub' => $user->npub,
            'user' => $user,
            'rating' => Ratings::forChessGame($this->game)[$this->game->colorOf($user) ?? 'w'],
        ];
    }

    /**
     * Strings the board script needs, translated once here.
     *
     * @return array<string, mixed>
     */
    public function labels(): array
    {
        return [
            'white' => __('White'),
            'board' => __('Board, :side to move. Last move :move.'),
            'firstMove' => __(':side: first move within :s s'),
            'offerDraw' => __('Offer draw'),
            'drawOffered' => __('Draw offered'),
            'black' => __('Black'),
            'names' => ['w' => $this->game->white->displayName(), 'b' => $this->game->black->displayName()],
            'pieces' => ['q' => __('Queen'), 'r' => __('Rook'), 'b' => __('Bishop'), 'n' => __('Knight')],
            'clock' => ['idle' => __('waiting'), 'running' => __('to move'), 'low' => __('under 30 s'), 'flagged' => __('out of time'), 'stopped' => __('stopped')],
            'clockAria' => __('Clock :time, :state'),
            'status' => ['live' => __('Live · move :move · :side to move'), 'short' => __('Live · move :move'), 'over' => __('Game over'), 'aborted' => __('Aborted')],
            'connection' => ['connected' => __('Connected'), 'connectedMs' => __('Connected · :ms ms'), 'connecting' => __('Connecting …'),
                'disconnected' => __('Disconnected · :s s'), 'polling' => __('Live via server')],
            'outcome' => ['win' => __('Win'), 'loss' => __('Loss'), 'draw' => __('Draw'), 'aborted' => __('Game aborted'), 'wins' => __(':name wins')],
            'reasons' => ['checkmate' => __('Checkmate'), 'resignation' => __('Resignation'), 'timeout' => __('Out of time'), 'agreement' => __('Draw by agreement'),
                'stalemate' => __('Stalemate'), 'threefold_repetition' => __('Threefold repetition'), 'fifty_move_rule' => __('50-move rule'),
                'insufficient_material' => __('Insufficient material'), 'aborted' => __('Aborted')],
            'errors' => ['illegal_move' => __('That move is not legal here.'), 'not_your_turn' => __('It is not your turn.'),
                'out_of_sync' => __('The board was behind. It shows the latest position now.'), 'game_over' => __('The game is already over.'),
                'not_a_player' => __('Only the two players can do that.'), 'too_late_to_abort' => __('Both sides have moved, the game can no longer be aborted.'),
                'already_playing' => __('One of you is already in another live game.'), 'no_claim' => __('The win cannot be claimed: your opponent is back or not gone long enough.'),
                'signature_rejected' => __('Your confirmation did not match this move. Nothing was played.'), 'already_recorded' => __('The game record is already published.'),
                'default' => __('That did not work. The board shows the server\'s state.')],
            'disconnected' => [
                'title' => __(':name disconnected', ['name' => $this->game->opponentOf(auth()->user())?->displayName() ?? '']),
                'ago' => __(':s s ago. Their clock is at :clock and running.'),
                'agoIdle' => __(':s s ago. Their clock is at :clock.'),
                'claimIn' => __('Claim win in :time'),
                'claim' => __('Claim win'),
                'gone' => __(':name was disconnected. Their clock keeps running.', ['name' => $this->game->opponentOf(auth()->user())?->displayName() ?? '']),
                'back' => __(':name is back after :s s.', ['name' => $this->game->opponentOf(auth()->user())?->displayName() ?? '']),
            ],
            'claimSeconds' => (int) config('esports.chess.disconnect_claim_seconds'),
            'signer' => SignerMessages::labels(),
            'pubkey' => auth()->user()?->pubkey,
        ];
    }

    /**
     * Config for the daily game (resources/js/dailyGame.js).
     *
     * @return array<string, mixed>
     */
    public function dailyConfig(): array
    {
        $viewer = auth()->user();
        $color = $this->game->colorOf($viewer);
        $settings = $viewer instanceof User ? $viewer->chessSettings() : null;
        $opponent = $this->game->opponentOf($viewer);

        return [
            'state' => app(ChessGameService::class)->snapshot($this->game),
            'color' => $color,
            'startedAt' => $this->game->created_at?->getTimestampMs() ?? 0,
            'doubleCheck' => $settings->doubleCheck ?? true,
            'alwaysQueen' => $settings->alwaysQueen ?? false,
            'coordinates' => $settings->coordinates ?? true,
            'remindHours' => $settings->remindHours ?? 6,
            'notify' => $color === null ? null : ($color === 'w' ? $this->game->white_notify : $this->game->black_notify),
            'remind' => $color === null ? false : ($color === 'w' ? $this->game->white_remind : $this->game->black_remind),
            'labels' => [
                ...$this->labels(),
                'left' => __(':h h :m min'),
                'yourMoveLeft' => __('Your move · :left left'),
                'theirMove' => __('Their move'),
                'day' => __('day :n'),
                'agoMinutes' => __(':m min ago'),
                'agoHours' => __(':h h :m min ago'),
                'describe' => ['move' => __(':piece :from to :to'), 'takes' => __('takes the :piece'), 'check' => __('check'), 'mate' => __('checkmate')],
                'opponentName' => $opponent?->displayName() ?? '',
            ],
        ];
    }

    /**
     * Positions and moves for the replay of a finished game.
     *
     * @return array{fens: list<string>, moves: list<array{uci: string, san: string}>}
     */
    public function replay(): array
    {
        $moves = $this->game->moves;

        return [
            'fens' => [$this->game->startFen(), ...$moves->pluck('fen')->all()],
            'moves' => $moves->map(fn ($move) => ['uci' => $move->uci, 'san' => $move->san])->all(),
        ];
    }
}; ?>

@php
    $game = $this->game;
    $viewer = auth()->user();
    $color = $game->colorOf($viewer);
    $live = $game->status === ChessGameStatus::Active;
    $players = ['w' => $this->player($game->white), 'b' => $this->player($game->black)];
    $opponent = $color === null ? null : $players[$color === 'w' ? 'b' : 'w'];
@endphp

<div class="flex grow flex-col">
    @if ($live && $game->isCorrespondence())
        @include('pages.games.partials.daily', ['game' => $game, 'players' => $players, 'color' => $color, 'opponent' => $opponent])
    @elseif ($live)
        <div wire:ignore
             x-data="chessGame(@js(['state' => app(ChessGameService::class)->snapshot($game), 'color' => $color, 'labels' => $this->labels()]))"
             x-on:keydown.window="hotkey($event)"
             @class(['flex flex-col gap-4 px-4 pb-8 lg:gap-5 lg:px-12 lg:pb-10', 'max-lg:pb-28' => $color !== null])
             data-test="chess-game">

            {{-- Title row --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ __('Game') }}</h1>
                <span class="text-sm text-btc">{{ $game->number() }}</span>
                <button type="button" aria-label="{{ __('Copy game link') }}" x-on:click="navigator.clipboard?.writeText(window.location.href)"
                        class="hidden size-8 cursor-pointer items-center justify-center rounded-md bg-well text-ink-2 lg:flex"><x-icon name="copy" :size="14" /></button>
                {{-- The match dock's button from lg (P5f): the chat composer owns the bottom right here. --}}
                <div data-dock-slot class="max-lg:hidden"></div>
                <span class="grow"></span>
                <span role="status" class="hidden h-[34px] items-center gap-2 rounded-md px-3 text-[13px] lg:flex"
                      :class="connection === 'connected' ? 'bg-[#122016] text-win' : 'bg-[#241D10] text-btc-hi'">
                    <span class="size-2 animate-live rounded-full" :class="connection === 'connected' ? 'bg-win' : 'bg-btc-hi'"></span>
                    <span x-text="connectionLabel" data-test="connection"></span>
                </span>
                <span class="flex h-[34px] items-center rounded-md bg-btc-press px-3.5 text-[13px] font-bold text-btc-hi" data-test="status-line"><span class="lg:hidden" x-text="statusShort"></span><span class="max-lg:hidden" x-text="statusLine"></span></span>
            </div>
            <span class="-mt-2 flex items-center gap-2 text-[13px] lg:hidden" :class="connection === 'connected' ? 'text-win' : 'text-btc-hi'">
                <span class="size-2 animate-live rounded-full" :class="connection === 'connected' ? 'bg-win' : 'bg-btc-hi'"></span><span x-text="connectionLabel"></span>
            </span>

            {{-- Board, players, moves, chat --}}
            <div class="grid grid-cols-1 gap-3 lg:mt-4 lg:grid-cols-[minmax(0,576px)_380px] lg:grid-rows-[auto_minmax(0,1fr)_auto_auto] lg:gap-x-7 lg:gap-y-2.5 min-[87.5rem]:grid-cols-[592px_380px_minmax(0,1fr)]">

                @foreach (['top', 'bottom'] as $side)
                    @php($sideColor = $side === 'top' ? 'topColor' : 'bottomColor')
                    <div @class([
                        'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 lg:col-start-2 lg:flex lg:flex-col lg:items-stretch lg:gap-2.5',
                        'lg:row-start-1' => $side === 'top',
                        'order-3 lg:order-none lg:row-start-3' => $side === 'bottom',
                    ]) data-test="player-{{ $side }}">
                        {{-- Name card (kit section 6), with the opponent's Nostr profile (P10a) --}}
                        <div @class(['relative flex min-h-14 min-w-0 items-center gap-3 lg:items-start lg:overflow-hidden lg:rounded-lg lg:bg-card lg:p-3', 'lg:order-2' => $side === 'bottom'])>
                            @foreach ($players as $pc => $p)
                                <span x-show="{{ $sideColor }} === '{{ $pc }}'" class="contents" data-test="player-card-{{ $pc }}">
                                    <x-chess.player-card :player="$p" :color="$pc" :you="$pc === $color"><x-rating :rating="$p['rating']" :label="__('Solo')" /></x-chess.player-card>
                                </span>
                            @endforeach
                        </div>
                        {{-- Captured pieces and material lead, on every screen size --}}
                        <x-chess.captured fen="state.fen" :color="$sideColor" data-test="captured-{{ $side }}" @class(['col-span-2 lg:px-1', 'lg:order-3' => $side === 'bottom']) />
                        {{-- Clock (kit section 5) --}}
                        <div role="timer" aria-live="off" :aria-label="clock({{ $sideColor }}).aria" data-test="clock-{{ $side }}"
                             :style="`background: ${clock({{ $sideColor }}).bg}; box-shadow: ${clock({{ $sideColor }}).ring}; color: ${clock({{ $sideColor }}).fg}`"
                             @class(['flex h-12 min-w-[150px] items-center justify-between gap-3 rounded-lg px-3 lg:h-[72px] lg:px-5', 'lg:order-1' => $side === 'bottom'])>
                            <span class="flex items-center gap-1.5 text-[11px] font-bold lg:text-xs"><x-icon name="warn" :size="14" x-show="clock({{ $sideColor }}).low" /><span x-text="clock({{ $sideColor }}).label"></span></span>
                            <span class="min-w-[72px] text-right font-display text-[26px] font-bold tabular-nums lg:min-w-32 lg:text-4xl" x-text="clock({{ $sideColor }}).t"></span>
                        </div>
                        {{-- Bio on phones, one line under the strip (MobileChessGame) --}}
                        @foreach ($players as $pc => $p)
                            @if ($p['user']->profile_event_at !== null && filled($p['user']->about))
                                <p x-show="{{ $sideColor }} === '{{ $pc }}'" class="col-span-2 m-0 -mt-1 line-clamp-1 text-xs leading-normal text-ink-3 lg:hidden">{{ $p['user']->about }}</p>
                            @endif
                        @endforeach
                    </div>
                @endforeach

                {{-- Board column --}}
                <div class="order-2 -mx-4 flex flex-col gap-4 lg:order-none lg:col-start-1 lg:row-span-4 lg:row-start-1 lg:mx-0 lg:mt-4">
                    <x-chess.board playable class="lg:max-w-[576px]">
                        {{-- Promotion picker, on the target file (ChessOverlays 2) --}}
                        <template x-if="promotion">
                            <div class="absolute inset-0">
                                <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.6)]" x-on:click="promotion = null"></div>
                                <div role="dialog" aria-label="{{ __('Promote to') }}" class="absolute top-0 flex w-[12.5%] flex-col overflow-hidden rounded-b-md bg-card shadow-[0_0_0_1px_#2A2A30,0_16px_48px_rgba(0,0,0,.6)]" :style="`left: ${promotionLeft}`">
                                    <template x-for="p in promotionPieces" :key="p.key">
                                        <button type="button" :aria-label="p.name" x-on:click="pickPromotion(p.key)" class="relative block aspect-square w-full cursor-pointer border-0 bg-[#CFCFD4] p-0 hover:bg-btc-hi" :data-promote="p.key">
                                            <svg viewBox="0 0 45 45" width="100%" height="100%" class="absolute top-0 left-0 block" aria-hidden="true"><g text-anchor="middle" style="font-family: 'DejaVu Sans', 'Noto Sans Symbols 2', 'Segoe UI Symbol', 'Apple Symbols', sans-serif; font-variant-emoji: text; font-size: 40px"><text x="22.5" y="38" :fill="p.fill" x-text="p.solid"></text><text x="22.5" y="38" fill="#0A0A0B" x-text="p.outline"></text></g></svg>
                                        </button>
                                    </template>
                                    <button type="button" aria-label="{{ __('Cancel promotion') }}" x-on:click="promotion = null" class="flex h-11 cursor-pointer items-center justify-center border-0 bg-well text-ink-2"><x-icon name="close" :size="16" /></button>
                                </div>
                            </div>
                        </template>

                        {{-- Connection lost (ChessStates) --}}
                        <template x-if="reconnecting">
                            <div class="absolute inset-0" data-test="reconnecting">
                                <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.72)]"></div>
                                <div role="status" class="absolute top-4 right-4 left-4 flex flex-col gap-2 rounded-lg bg-[#241D10] px-3.5 py-3 shadow-[inset_0_0_0_1px_#5A4418]">
                                    <span class="flex items-center gap-2.5 text-[13px]"><x-icon name="wifi" :size="18" class="text-btc-hi" /><b>{{ __('Reconnecting …') }}</b></span>
                                    <span class="text-xs leading-normal text-ink-2">{{ __('Your clock keeps running on the server. Moves you make now are not sent.') }}</span>
                                    <x-button variant="quiet" icon="retry" class="self-start" x-on:click="reconnectNow()">{{ __('Reconnect now') }}</x-button>
                                </div>
                                <span class="absolute bottom-4 left-4 flex h-[34px] items-center gap-2 rounded-md bg-[#241D10] px-3 text-[13px] text-btc-hi"><span class="size-2 animate-live rounded-full bg-btc-hi"></span><span x-text="connectionLabel"></span></span>
                            </div>
                        </template>

                        {{-- Opponent disconnected (ChessOverlays): claim the win after the timeout --}}
                        <template x-if="disconnect">
                            <div class="absolute inset-0 flex items-center justify-center p-3" data-test="opponent-disconnected">
                                <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.72)]"></div>
                                <div role="alertdialog" aria-labelledby="dc-h" aria-describedby="dc-d" class="relative flex w-full max-w-[368px] flex-col gap-4 rounded-lg bg-card p-5 shadow-[inset_0_0_0_1px_#2A2A30,0_16px_48px_rgba(0,0,0,.6)]">
                                    <div class="flex items-center gap-4">
                                        <span class="relative flex size-[62px] shrink-0 items-center justify-center" aria-hidden="true">
                                            <svg viewBox="0 0 62 62" class="absolute inset-0 size-full -rotate-90"><circle cx="31" cy="31" r="28" fill="none" stroke="#2A2A30" stroke-width="4"></circle><circle cx="31" cy="31" r="28" fill="none" stroke="#F7931A" stroke-width="4" stroke-linecap="round" :stroke-dasharray="175.9" :stroke-dashoffset="175.9 * (1 - disconnect.left / t.claimSeconds)"></circle></svg>
                                            <b class="font-display text-lg" x-text="disconnect.left"></b>
                                        </span>
                                        <span class="flex min-w-0 flex-col gap-1">
                                            <h2 id="dc-h" class="m-0 text-base font-bold" x-text="t.disconnected.title"></h2>
                                            <span id="dc-d" class="text-xs leading-normal text-ink-2" x-text="disconnect.text"></span>
                                        </span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <x-button variant="quiet" x-on:click="dismissDisconnect()">{{ __('Keep waiting') }}</x-button>
                                        <button type="button" x-on:click="call('claimWin')" :disabled="disconnect.left > 0" data-test="claim-win"
                                                class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-line bg-transparent px-3 text-[13px] whitespace-nowrap text-ink disabled:cursor-not-allowed disabled:text-ink-3"
                                                x-text="disconnect.left > 0 ? t.disconnected.claimIn.replace(':time', disconnect.clock) : t.disconnected.claim"></button>
                                    </div>
                                </div>
                            </div>
                        </template>

                        {{-- Draw offer received (ChessOverlays 3) --}}
                        <template x-if="color && state.status === 'active' && state.drawOffer && state.drawOffer !== color && !reconnecting">
                            <div class="absolute inset-0 flex items-center justify-center p-3" data-test="draw-offer">
                                <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.72)]"></div>
                                <div role="dialog" aria-labelledby="dr-h" class="relative flex w-full max-w-[368px] flex-col gap-3.5 rounded-lg bg-card p-5 shadow-[inset_0_0_0_1px_#2A2A30,0_16px_48px_rgba(0,0,0,.6)]">
                                    <span class="flex items-center gap-2.5"><x-icon name="draw" :size="16" class="text-ink-2" /><h2 id="dr-h" class="m-0 text-base font-bold">{{ __(':name offers a draw', ['name' => $opponent['name'] ?? '']) }}</h2></span>
                                    <span class="text-[13px] leading-normal text-ink-2">{{ __('A casual game: a draw moves only the casual Elo.') }}</span>
                                    <div class="grid grid-cols-2 gap-2">
                                        <x-button variant="quiet" x-on:click="call('declineDraw')">{{ __('Decline') }}</x-button>
                                        <x-button icon="shield-check" x-on:click="call('acceptDraw')" data-test="accept-draw">{{ __('Accept draw') }}</x-button>
                                    </div>
                                    <span class="text-xs text-ink-3">{{ __('Just keep playing and the offer counts as declined.') }}</span>
                                </div>
                            </div>
                        </template>

                        {{-- Confirm resignation / abort (ChessOverlays 4 and 6) --}}
                        <template x-if="confirm === 'resign' && state.status === 'active'">
                            <div class="absolute inset-0 flex items-center justify-center p-3">
                                <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.72)]"></div>
                                <div role="alertdialog" aria-modal="true" aria-labelledby="rs-h" aria-describedby="rs-d" class="relative flex w-full max-w-[368px] flex-col gap-3.5 rounded-lg bg-card p-5 shadow-[inset_0_0_0_1px_#2A2A30,0_16px_48px_rgba(0,0,0,.6)]">
                                    <span class="flex items-center gap-2.5"><x-icon name="flag" :size="16" class="text-loss" /><h2 id="rs-h" class="m-0 text-base font-bold">{{ __('Resign this game?') }}</h2></span>
                                    <span id="rs-d" class="text-[13px] leading-normal text-ink-2">{{ __(':name wins. A casual game: only the casual Elo changes.', ['name' => $opponent['name'] ?? '']) }}</span>
                                    <div class="grid grid-cols-2 gap-2">
                                        <x-button variant="quiet" x-init="$el.focus()" x-on:click="confirm = null">{{ __('Keep playing') }}</x-button>
                                        <button type="button" x-on:click="confirmResign()" data-test="confirm-resign" class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss"><x-icon name="flag" :size="16" />{{ __('Resign') }}</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template x-if="confirm === 'abort' && state.status === 'active'">
                            <div class="absolute inset-0 flex items-center justify-center p-3">
                                <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.72)]"></div>
                                <div role="alertdialog" aria-modal="true" aria-labelledby="ab-h" class="relative flex w-full max-w-[368px] flex-col gap-3.5 rounded-lg bg-card p-5 shadow-[inset_0_0_0_1px_#2A2A30,0_16px_48px_rgba(0,0,0,.6)]">
                                    <h2 id="ab-h" class="m-0 text-base font-bold">{{ __('Abort game?') }}</h2>
                                    <span class="text-[13px] leading-normal text-ink-2">{{ __('Not both sides have moved yet. The game disappears without a result and nothing is recorded.') }}</span>
                                    <div class="grid grid-cols-2 gap-2">
                                        <x-button variant="quiet" x-on:click="confirm = null">{{ __('Keep waiting') }}</x-button>
                                        <button type="button" x-on:click="confirmAbort()" class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss">{{ __('Abort game') }}</button>
                                    </div>
                                    <span class="text-xs text-ink-3">{{ __('With no first move after 30 s the server aborts on its own.') }}</span>
                                </div>
                            </div>
                        </template>

                        {{-- Game over (ChessOverlays 1) and aborted (ChessStates) --}}
                        <template x-if="outcome">
                            <div class="absolute inset-0 flex items-center justify-center p-3" data-test="game-over">
                                <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.72)]"></div>
                                <div role="dialog" aria-modal="true" aria-labelledby="go-h" class="relative flex w-full max-w-[368px] flex-col gap-3 rounded-lg bg-card p-5 shadow-[inset_0_0_0_1px_#2A2A30,0_16px_48px_rgba(0,0,0,.6)]">
                                    <template x-if="outcome.key !== 'aborted'">
                                        <div class="flex flex-col gap-3">
                                            <div class="flex items-center gap-3">
                                                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                                                      :class="{ 'bg-[#122016] text-win': outcome.tone === 'win', 'bg-loss-tint text-loss': outcome.tone === 'loss', 'bg-well text-ink-2': outcome.tone === 'draw' }">
                                                    <x-icon name="trophy" :size="22" x-show="outcome.tone === 'win'" /><x-icon name="flag" :size="22" x-show="outcome.tone === 'loss'" /><x-icon name="draw" :size="22" x-show="outcome.tone === 'draw'" />
                                                </span>
                                                <span class="flex min-w-0 flex-col gap-0.5">
                                                    <h2 id="go-h" class="m-0 font-display text-2xl font-bold" :class="{ 'text-win': outcome.tone === 'win', 'text-loss': outcome.tone === 'loss', 'text-ink': outcome.tone === 'draw' }" x-text="outcome.title" data-test="outcome"></h2>
                                                    <span class="text-[13px] text-ink-2" x-text="outcome.reason"></span>
                                                </span>
                                                <span class="grow"></span><b class="font-display text-lg whitespace-nowrap" x-text="outcome.result"></b>
                                            </div>
                                            <div class="flex flex-col border-t border-hairline">
                                                <div class="grid h-[38px] grid-cols-[120px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Rating') }}</span><span data-test="game-over-rating" x-text="color && state.rating?.[color] ? (state.rating[color].pool === 'casual' ? @js(__('Casual')) : @js(__('Elo'))) + ' ' + state.rating[color].after + ' ' + (state.rating[color].delta > 0 ? '+' + state.rating[color].delta : (state.rating[color].delta < 0 ? '−' + Math.abs(state.rating[color].delta) : '±0')) : @js(__('casual, no Elo change'))">{{ __('casual, no Elo change') }}</span></div>
                                                <div class="grid min-h-[38px] grid-cols-[120px_minmax(0,1fr)] items-center border-b border-hairline py-1.5 text-[13px]"><span class="text-ink-2">{{ __('Hashrate') }}</span><span data-test="game-over-mining" :class="state.mining?.status === 'block' ? 'text-win' : ''" x-text="state.mining ? state.mining.text : @js(__('casual games do not count'))">{{ __('casual games do not count') }}</span></div>
                                            </div>
                                            <span class="inline-flex h-7 items-center gap-1.5 self-start rounded-sm bg-[#122016] px-2.5 text-xs font-bold text-win"><x-icon name="shield-check" :size="14" />{{ __('Saved') }}</span>
                                            <x-button :href="route('chess.lobby', ['search' => 1, ...($game->rated ? ['rated' => 1] : [])])" class="w-full" data-test="find-next">{{ __('Find next opponent') }}</x-button>
                                            {{-- P11: a rated result can be a rank up or a mined block; the share cards live on one page. --}}
                                            <template x-if="color && state.rating?.[color]?.pool === 'rated'">
                                                <a href="{{ route('settings.badges') }}#share" class="inline-flex min-h-11 items-center justify-center gap-1.5 text-[13px]" data-test="game-over-share"><x-icon name="send" :size="14" /><span x-text="state.mining?.status === 'block' && outcome.tone === 'win' ? @js(__('Share your block')) : @js(__('Badges and share cards'))"></span></a>
                                            </template>
                                            <template x-if="color">
                                                <div class="grid grid-cols-2 gap-2">
                                                    <template x-if="!state.rematchOffer">
                                                        <x-button variant="quiet" icon="retry" x-on:click="call('offerRematch')" data-test="rematch">{{ __('Rematch') }}</x-button>
                                                    </template>
                                                    <template x-if="state.rematchOffer === color">
                                                        <x-button variant="quiet" disabled class="opacity-70">{{ __('Rematch offered') }}</x-button>
                                                    </template>
                                                    <template x-if="state.rematchOffer && state.rematchOffer !== color">
                                                        <x-button icon="retry" x-on:click="call('acceptRematch')" data-test="accept-rematch">{{ __('Accept rematch') }}</x-button>
                                                    </template>
                                                    <x-button variant="quiet" :href="route('games.show', $game)">{{ __('Replay game') }}</x-button>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="outcome.key === 'aborted'">
                                        <div class="flex flex-col gap-3.5" data-test="aborted">
                                            <b id="go-h" class="text-base">{{ __('Game aborted') }}</b>
                                            <span class="text-[13px] leading-normal text-ink-2">{{ __('The game ended before both sides made their first move. It does not count.') }}</span>
                                            <span class="grid grid-cols-2 gap-2">
                                                <x-button :href="route('chess.lobby', ['search' => 1, ...($game->rated ? ['rated' => 1] : [])])" data-test="search-again">{{ __('Search again') }}</x-button>
                                                <x-button variant="quiet" :href="route('chess.lobby')">{{ __('Back to lobby') }}</x-button>
                                            </span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </x-chess.board>

                    {{-- First-move notice --}}
                    <template x-if="firstMoveLeft !== null">
                        <p class="m-0 px-4 text-[13px] text-btc-hi lg:px-0" role="status" data-test="first-move">
                            <span x-text="t.firstMove.replace(':side', state.turn === 'w' ? t.white : t.black).replace(':s', firstMoveLeft)"></span>
                        </p>
                    </template>

                    @if ($color)
                        <form class="flex items-center gap-2.5 px-4 max-lg:hidden lg:w-full lg:max-w-[576px] lg:px-0" x-on:submit.prevent="submitSan()">
                            <label for="mv" class="text-[13px] whitespace-nowrap text-ink-2">{{ __('Enter move') }}</label>
                            <input id="mv" x-model="sanInput" placeholder="{{ __('e.g. Nf3') }}" autocomplete="off" data-test="san-input"
                                   class="h-11 min-w-0 grow rounded-lg border border-edge bg-ground px-3.5 text-sm text-ink placeholder:text-ink-3">
                            <x-button variant="quiet" type="submit">{{ __('Move') }}</x-button>
                        </form>
                        <p class="m-0 px-4 text-[13px] text-loss lg:px-0" role="alert" x-show="error" x-text="error"></p>
                    @endif
                </div>

                {{-- Moves: list from lg, one scrolling row below (MobileChessGame) --}}
                <div class="order-4 lg:order-none lg:col-start-2 lg:row-start-2 lg:flex lg:min-h-[180px] lg:flex-col lg:rounded-lg lg:bg-card">
                    <span class="hidden items-baseline justify-between border-b border-hairline px-4 pt-3 pb-2 lg:flex"><span class="text-[15px] font-bold">{{ __('Moves') }}</span><span class="text-[11px] text-ink-3">{{ __('seconds per move') }}</span></span>
                    <div tabindex="0" aria-label="{{ __('Move list, newest move at the bottom') }}" class="hidden min-h-0 grow flex-col-reverse overflow-y-auto lg:flex" data-test="move-list">
                        <ol class="m-0 list-none px-2 py-0">
                            <template x-for="row in moveRows" :key="row.n">
                                <li class="grid h-9 grid-cols-[40px_minmax(0,1fr)_minmax(0,1fr)] items-center border-b border-hairline px-2 text-sm">
                                    <span class="text-ink-3" x-text="row.n + '.'"></span>
                                    <span class="flex h-7 items-center gap-2 rounded-sm px-1.5" :aria-current="row.wCur ? 'step' : 'false'" :class="row.wCur ? 'bg-btc-press text-btc-hi' : 'text-ink'"><span x-text="row.w"></span><span class="text-[11px] text-ink-3" x-text="row.wt"></span></span>
                                    <span class="flex h-7 items-center gap-2 rounded-sm px-1.5" :aria-current="row.bCur ? 'step' : 'false'" :class="row.bCur ? 'bg-btc-press text-btc-hi' : 'text-ink'"><span x-text="row.b"></span><span class="text-[11px] text-ink-3" x-text="row.bt"></span></span>
                                </li>
                            </template>
                        </ol>
                    </div>
                    <div class="flex h-12 items-center overflow-x-auto rounded-lg bg-card px-2 text-sm whitespace-nowrap lg:hidden" x-effect="state.moves.length; $nextTick(() => $el.scrollLeft = $el.scrollWidth)" aria-label="{{ __('Moves') }}">
                        <template x-if="state.moves.length === 0"><span class="px-2 text-ink-3">{{ __('No moves yet') }}</span></template>
                        <template x-for="row in moveRows" :key="'m' + row.n">
                            <span class="flex items-center gap-1 pr-2">
                                <span class="pl-1 text-ink-3" x-text="row.n + '.'"></span>
                                <span class="rounded-sm px-1.5 py-1" :class="row.wCur ? 'bg-btc-press text-btc-hi' : ''" x-text="row.w"></span>
                                <span class="rounded-sm px-1.5 py-1" :class="row.bCur ? 'bg-btc-press text-btc-hi' : ''" x-text="row.b"></span>
                            </span>
                        </template>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="order-5 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_44px] gap-2 lg:order-none lg:col-start-2 lg:row-start-4">
                    @if ($color)
                        <template x-if="state.status === 'active' && state.ply < 2">
                            <button type="button" x-on:click="confirm = 'abort'" data-test="abort" class="col-span-2 inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss">{{ __('Abort game') }}</button>
                        </template>
                        <template x-if="state.status !== 'active' || state.ply >= 2">
                            <div class="col-span-2 grid grid-cols-2 gap-2">
                                <x-button variant="quiet" icon="draw" class="px-3 whitespace-nowrap disabled:opacity-50" x-on:click="call('offerDraw')" x-bind:disabled="state.status !== 'active' || state.drawOffer === color" data-test="offer-draw">
                                    <span x-text="state.drawOffer === color ? t.drawOffered : t.offerDraw"></span>
                                </x-button>
                                <button type="button" x-on:click="confirm = 'resign'" :disabled="state.status !== 'active'" data-test="resign" class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss disabled:opacity-50"><x-icon name="flag" :size="16" />{{ __('Resign') }}</button>
                            </div>
                        </template>
                    @else
                        <span class="col-span-2 flex items-center text-[13px] text-ink-2">{{ __('You are watching this game.') }}</span>
                    @endif
                    <button type="button" aria-label="{{ __('Flip board') }}" :aria-pressed="flipped ? 'true' : 'false'" x-on:click="flipped = !flipped" class="btn-w flex h-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink"><x-icon name="flip" :size="18" /></button>
                </div>

                {{-- Chat (NIP-17): desktop panel in this grid, bottom sheet on mobile --}}
                @include('pages.games.partials.chat', ['chat' => $this->chatConfig()])
            </div>

            {{-- Details (desktop) --}}
            <div class="hidden grid-cols-2 gap-5 lg:grid">
                <div class="rounded-lg bg-card px-6 py-2">
                    @foreach ([[__('Time control'), __('Blitz 5+3 · 5 min, +3 s per move')], [__('Started'), $game->created_at?->timezone(config('app.timezone'))->isoFormat('ddd YYYY-MM-DD · HH:mm')], [__('Kind'), $game->rated ? __('Rated') : __('Casual · casual Elo only')], [__('Moves'), __('checked by the server, one by one')]] as [$key, $value])
                        <div class="grid h-11 grid-cols-[180px_minmax(0,1fr)] items-center border-b border-hairline text-sm last:border-0"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
                    @endforeach
                </div>
                <div class="rounded-lg bg-card px-6 py-2">
                    @foreach ([[__('Rating'), $game->rated ? __('rated Elo with rank') : __('casual Elo, no rank')], [__('Hashrate'), __('casual games do not count')], [__('Spectators'), __('anyone with the link, live')], [__('Game number'), $game->number()]] as [$key, $value])
                        <div class="grid h-11 grid-cols-[180px_minmax(0,1fr)] items-center border-b border-hairline text-sm last:border-0"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
                    @endforeach
                </div>
            </div>

            {{-- What happens at the end + Proof --}}
            <div class="flex flex-col gap-3">
                <div class="flex items-start gap-3 lg:items-center lg:gap-4 lg:rounded-lg lg:bg-card lg:px-6 lg:py-4">
                    <span class="flex size-6 shrink-0 items-center justify-center text-win lg:size-10 lg:rounded-lg lg:bg-well"><x-icon name="shield-check" :size="18" /></span>
                    <span class="flex flex-col gap-1 text-[13px] leading-normal">
                        <span class="lg:font-bold">{{ __('When the game ends, the result is saved on the server for both of you.') }}</span>
                        <span class="hidden text-ink-2 lg:block">{{ __('The server checks every move and runs both clocks. Casual games move only the casual Elo.') }}</span>
                    </span>
                </div>
                <x-proof toggle="show" class="border-0 bg-proof-fill shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" :rows="[
                    [__('Record'), __('PGN of this game, published after the last move (NIP-64, kind 64)')],
                    [__('Confirmation'), __(':white or :black, signed automatically by the app (kind 64)', ['white' => $players['w']['name'], 'black' => $players['b']['name']])],
                    [__('Moves'), __('league server only, not published one by one')],
                ]" />
            </div>
        </div>
    @else
        @include('pages.games.partials.done', ['game' => $game, 'players' => $players, 'color' => $color, 'chat' => $color ? $this->chatConfig() : null])
    @endif
</div>
