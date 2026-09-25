{{--
    A daily chess game while it runs, after ChessCorrespondence.dc.html
    (desktop) and MobileChessCorrespondence.dc.html (below lg), with the
    double-check of ChessOverlays. Driven by resources/js/dailyGame.js.

    Desktop follows the live game (⚡show): the board on the left, the players
    around the turn and the moves in the middle column, the chat filling the
    third column from 87.5rem; the middle column and the chat take the board's
    height and never grow it, so all of it stays in the first viewport. The
    game details sit collapsed under the board. Below lg the middle column
    dissolves (display: contents) and its parts slot around the board by order.

    From lg the middle column belongs to the moves. Making a move (type it,
    clear it, make it), a received draw offer and the note on the move sit
    under the board, where the piece was picked. Turn, the one running clock,
    last move and deadline are one block. The strips carry no clock and no
    bio: a daily game has one running clock, and the bio is in the player card.
--}}
@php
    use App\Support\Nostr\NostrKeys;

    $config = $this->dailyConfig();
    $viewer = auth()->user();
    $settings = $viewer?->chessSettings();
    $opponentName = $opponent['name'] ?? '';
    $opponentUser = $game->opponentOf($viewer);
    $channels = array_filter([$settings?->dm ? __('Nostr DM') : null, $settings?->push ? __('browser push') : null]);
    $channelSummary = $channels === [] ? __('Notifications are off') : __(':channels on', ['channels' => implode(' '.__('and').' ', $channels)]);
    $moves = $game->moves()->with('nostrEvent')->get();
    $firstNote = $moves->first()?->nostrEvent;
    $lastNote = $moves->last()?->nostrEvent;
    // The moves' notes go to the league relays; the chat relays carry only gift wraps (P5d: public in production).
    $relay = config('esports.relays')[0] ?? __('none configured');
    $started = $game->created_at?->timezone($viewer->timezone ?? config('app.timezone'));
    $myColorName = $color === 'w' ? __('White') : ($color === 'b' ? __('Black') : null);
    $cards = ['w' => $players['w'], 'b' => $players['b']];
    $deadline = \Illuminate\Support\Carbon::createFromTimestampMs((int) $game->deadline_ms)->timezone($viewer->timezone ?? config('app.timezone'))->isoFormat('ddd YYYY-MM-DD HH:mm');
@endphp

{{-- Players get the chat sheet (72px) under the bottom bar on phones, hence the taller bottom padding. --}}
<div wire:ignore x-data="dailyGame(@js($config))" x-on:keydown.window="hotkey($event)" @class(['flex flex-col gap-3 lg:gap-5 lg:px-12 lg:pb-10', $color ? 'pb-[212px]' : 'pb-[140px]']) data-test="daily-game">

    {{-- Title --}}
    <div class="flex flex-col gap-1.5 px-4 lg:px-0">
        <div class="flex items-center gap-2.5 lg:gap-4">
            <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ __('Daily chess') }}</h1>
            <span class="text-[13px] text-btc lg:text-sm">{{ $game->number() }}</span>
            <button type="button" aria-label="{{ __('Copy game link') }}" x-on:click="navigator.clipboard?.writeText(window.location.href)"
                    class="hidden size-8 cursor-pointer items-center justify-center rounded-md bg-well text-ink-2 lg:flex"><x-icon name="copy" :size="14" /></button>
            {{-- The match dock's button from lg (P5f): the chat owns the bottom right here. --}}
            <div data-dock-slot class="max-lg:hidden"></div>
            <span class="grow"></span>
            <span role="status" data-test="daily-status"
                  class="flex h-7 items-center gap-2 rounded-md px-2.5 text-xs font-bold lg:h-[34px] lg:px-3.5 lg:text-[13px]"
                  :class="myTurn ? 'bg-btc text-on-btc' : 'bg-well text-ink-2'">
                <x-icon name="clock" :size="16" class="max-lg:hidden" x-show="myTurn" />
                <span class="lg:hidden" x-text="myTurn ? t.yourMoveLeft.replace(':left', hoursMinutes(leftMs)) : t.theirMove"></span>
                <span class="max-lg:hidden" x-text="statusPill"></span>
            </span>
        </div>
        <span class="text-xs text-ink-2 lg:hidden">{{ __('1 move per day · every move saved and verified') }}</span>
    </div>

    <div @class(['grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,560px)_380px] lg:gap-x-7 lg:gap-y-5', 'min-[87.5rem]:grid-cols-[560px_380px_minmax(0,1fr)]' => $color])>
        {{-- Board column: the board, typing a move, draw and resign --}}
        <div class="max-lg:contents lg:col-start-1 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            <div class="order-2 lg:order-none">
                <x-chess.board :playable="$color !== null" class="lg:mt-4 lg:max-w-[544px]">
                    {{-- Promotion picker --}}
                    <template x-if="promotion">
                        <div class="absolute inset-0">
                            <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.6)]" x-on:click="promotion = null"></div>
                            <div role="dialog" aria-label="{{ __('Promote to') }}" class="absolute top-0 flex w-[12.5%] flex-col overflow-hidden rounded-b-md bg-card"
                                 :style="`left: ${(flipped ? 7 - 'abcdefgh'.indexOf(promotion.to[0]) : 'abcdefgh'.indexOf(promotion.to[0])) * 12.5}%`">
                                @foreach (['q' => __('Queen'), 'r' => __('Rook'), 'b' => __('Bishop'), 'n' => __('Knight')] as $piece => $name)
                                    <button type="button" aria-label="{{ $name }}" x-on:click="pickPromotion('{{ $piece }}')" class="flex aspect-square w-full cursor-pointer items-center justify-center border-0 bg-[#CFCFD4] text-2xl text-ground hover:bg-btc-hi">{{ ['q' => '♛', 'r' => '♜', 'b' => '♝', 'n' => '♞'][$piece] }}&#xFE0E;</button>
                                @endforeach
                            </div>
                        </div>
                    </template>

                    {{-- Daily move, double-check (ChessOverlays) --}}
                    <template x-if="confirmOpen && pending">
                        <div class="absolute inset-0 flex items-center justify-center p-3" data-test="double-check">
                            <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.72)]"></div>
                            <div role="alertdialog" aria-modal="true" aria-labelledby="dcm-h" aria-describedby="dcm-d" class="relative flex w-full max-w-[368px] flex-col gap-3.5 rounded-lg bg-card p-5 shadow-[inset_0_0_0_1px_#2A2A30,0_16px_48px_rgba(0,0,0,.6)]">
                                <span class="flex items-baseline justify-between gap-3">
                                    <h2 id="dcm-h" class="m-0 text-base font-bold" x-text="@js(__('Make :move?')).replace(':move', pendingLabel)"></h2>
                                    <span class="text-xs text-ink-2 whitespace-nowrap" x-text="hoursMinutes(leftMs) + ' ' + @js(__('left'))"></span>
                                </span>
                                <span id="dcm-d" class="text-[13px] leading-normal text-ink-2">{{ __('Once made, the move is final and :name gets notified.', ['name' => $opponentName]) }}</span>
                                <div class="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                                    <x-button variant="quiet" x-on:click="confirmOpen = false" class="px-6">{{ __('Back') }}</x-button>
                                    <x-button icon="shield-check" x-on:click="commit()" x-init="$el.focus()" data-test="confirm-daily-move">{{ __('Make my move') }}</x-button>
                                </div>
                            </div>
                        </div>
                    </template>
                </x-chess.board>
            </div>

            @if ($color)
                <form class="hidden w-full max-w-[544px] items-center gap-2.5 lg:flex" x-on:submit.prevent="submitSan()">
                    <label for="mv" class="text-[13px] whitespace-nowrap text-ink-2">{{ __('Enter move') }}</label>
                    <input id="mv" x-model="sanInput" :placeholder="@js(__('e.g. :move', ['move' => 'f6']))" autocomplete="off" data-test="daily-san-input" :disabled="! myTurn || pending"
                           class="h-11 min-w-0 grow rounded-lg border border-edge bg-ground px-3.5 text-sm text-ink placeholder:text-ink-3 disabled:opacity-60">
                    <button type="button" x-show="myTurn && pending" x-on:click="clear()" :disabled="busy" aria-label="{{ __('Clear selection') }}" title="{{ __('Clear selection') }}"
                            class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink disabled:opacity-50"><x-icon name="close" :size="16" /></button>
                    <button type="button" x-show="myTurn" x-on:click="makeMove()" :disabled="! pending || busy" data-test="make-move"
                            class="btn-p inline-flex h-11 shrink-0 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-5 text-sm font-bold whitespace-nowrap text-on-btc disabled:cursor-not-allowed disabled:opacity-50"><x-icon name="shield-check" :size="18" />{{ __('Make my move') }}</button>
                </form>

                {{-- Draw offer received (below lg; from lg it takes over the row of draw and resign). order-7 keeps it right above that row, where it sat before. --}}
                <template x-if="state.drawOffer && state.drawOffer !== color">
                    <div class="order-7 mx-4 flex flex-col gap-3 rounded-lg bg-card px-4 py-4 shadow-ring lg:hidden" data-test="daily-draw-offer">
                        <b class="text-[15px]">{{ __(':name offers a draw', ['name' => $opponentName]) }}</b>
                        <span class="text-[13px] text-ink-2">{{ __('A casual game: a draw changes no rating. Moving counts as declining.') }}</span>
                        <span class="grid grid-cols-2 gap-2">
                            <x-button variant="quiet" x-on:click="call('declineDraw')">{{ __('Decline') }}</x-button>
                            <x-button icon="shield-check" x-on:click="call('acceptDraw')">{{ __('Accept draw') }}</x-button>
                        </span>
                    </div>
                </template>
            @endif

            @if ($color)
                {{-- Draw and resign (not drawn in the design; needed to end a daily game other than on the board). From lg a received offer replaces both: nobody resigns into a draw offer, and offering back would accept it. --}}
                <div class="order-7 mx-4 grid grid-cols-2 gap-2 lg:mx-0 lg:flex lg:max-w-[544px] lg:items-center lg:gap-3" x-data="{ get offered() { return state.drawOffer && state.drawOffer !== color } }">
                    {{-- The note on the move (lg): why it failed, the draw offer, the picked move, or what to do --}}
                    <p class="m-0 hidden min-w-0 grow text-xs leading-normal text-ink-2 lg:block" data-test="daily-move-note">
                        <span role="alert" class="text-loss" x-show="error" x-text="error"></span>
                        <span x-show="! error && offered" class="flex flex-col gap-0.5" data-test="daily-draw-offer-lg"><b class="text-[15px] text-ink">{{ __(':name offers a draw', ['name' => $opponentName]) }}</b>{{ __('A casual game: a draw changes no rating. Moving counts as declining.') }}</span>
                        <template x-if="! error && ! offered && myTurn && pending"><span><b class="text-[15px] text-ink" x-text="pendingLabel" data-test="pending-move"></b> <span x-text="pending.describe"></span></span></template>
                        <span x-show="! error && ! offered && myTurn && ! pending">{{ __('Pick a piece on the board, or type the move. Nothing is final until you make it.') }}</span>
                        <span x-show="! error && ! offered && ! myTurn">{{ __('You can move again once :name has played.', ['name' => $opponentName]) }}</span>
                    </p>
                    <template x-if="offered">
                        <span class="hidden shrink-0 gap-2 lg:flex">
                            <x-button variant="quiet" x-on:click="call('declineDraw')">{{ __('Decline') }}</x-button>
                            <x-button icon="shield-check" x-on:click="call('acceptDraw')" class="whitespace-nowrap">{{ __('Accept draw') }}</x-button>
                        </span>
                    </template>
                    <x-button variant="quiet" icon="draw" x-on:click="call('offerDraw')" x-bind:disabled="state.drawOffer === color" class="disabled:opacity-50 lg:shrink-0 lg:whitespace-nowrap" x-bind:class="offered && 'lg:hidden'">
                        <span x-text="state.drawOffer === color ? t.drawOffered : t.offerDraw"></span>
                    </x-button>
                    {{-- Second click against misclicks (ChessOverlays "Confirm resignation") --}}
                    <button type="button" x-data="{ sure: false }" x-on:click="sure ? call('resign') : (sure = true)" x-on:click.outside="sure = false" data-test="daily-resign" :class="offered && 'lg:hidden'"
                            class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] whitespace-nowrap text-loss lg:shrink-0">
                        <x-icon name="flag" :size="16" /><span x-text="sure ? @js(__('Click again to resign')) : @js(__('Resign'))"></span>
                    </button>
                </div>
            @endif
        </div>

        {{-- Middle column: players, turn, moves. Stretches to the board column; the moves list counts only its header and one row, 80px, towards that height, takes the free space up to its own content (max-h-max) and then scrolls; what is left pushes your card down (mt-auto). --}}
        <div class="max-lg:contents lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-2" data-test="daily-side">
            {{-- Player cards with the day clock: around the board below lg, top and bottom of this column from lg. From lg the clock is in the turn block and the bio only in the player card (the selector reaches into x-chess.player-card, which the live game shares). --}}
            @foreach (['top', 'bottom'] as $side)
                @php($sideColor = $side === 'top' ? ($color === 'b' ? 'w' : 'b') : ($color === 'b' ? 'b' : 'w'))
                @php($p = $cards[$sideColor])
                <div @class(['flex items-center gap-2.5 px-4 lg:shrink-0 lg:px-0 lg:[&_[data-test=player-about]]:hidden', 'order-1' => $side === 'top', 'order-3 lg:order-7 lg:mt-auto' => $side === 'bottom']) data-test="daily-player-{{ $side }}">
                    <span class="relative flex min-w-0 grow flex-col items-stretch gap-1">
                        <span class="relative flex min-w-0 items-center gap-2.5">
                            <x-chess.player-card :player="$p" :color="$sideColor" :you="$sideColor === $color"><x-rating :rating="$p['rating']" :label="__('Daily')" /></x-chess.player-card>
                        </span>
                        <x-chess.captured fen="(pending?.fen ?? state.fen)" color="'{{ $sideColor }}'" data-test="captured-{{ $side }}" />
                    </span>
                    <div role="timer" class="flex h-12 shrink-0 items-center gap-2 rounded-lg px-3 lg:hidden"
                         :class="state.turn === '{{ $sideColor }}' ? (low ? 'bg-loss text-on-btc' : 'bg-btc text-on-btc') : 'bg-card text-ink-2 shadow-ring'">
                        <span class="flex items-center gap-1 text-[11px] font-bold whitespace-nowrap"><x-icon name="warn" :size="14" x-show="state.turn === '{{ $sideColor }}' && low" /><span x-text="state.turn === '{{ $sideColor }}' ? t.clock.running : t.clock.idle"></span></span>
                        <span class="min-w-[72px] text-right font-display text-[22px] font-bold tabular-nums" x-text="state.turn === '{{ $sideColor }}' ? clockText(leftMs) : '24:00'"></span>
                    </div>
                </div>
            @endforeach

            {{-- Turn (lg): who moves, the one running clock, the last move and the deadline in one block --}}
            <div class="hidden flex-col gap-2 rounded-lg px-4 py-3 lg:order-2 lg:flex lg:shrink-0" data-test="daily-turn"
                 :class="myTurn ? 'bg-[radial-gradient(120%_160%_at_0%_0%,#2A1F0E_0%,#121215_60%)]' : 'bg-card'">
                <span class="flex items-center gap-3">
                    <span class="flex min-w-0 grow flex-col gap-1">
                        <b class="text-[15px]" x-text="myTurn ? @js(__('Your move')) : @js(__(':name to move')).replace(':name', state.turn === 'w' ? @js($cards['w']['name']) : @js($cards['b']['name']))"></b>
                        <template x-if="lastMove">
                            <span class="flex items-start gap-1.5 text-xs leading-normal text-ink-2">
                                <span class="min-w-0"><span x-text="(lastMove.mine ? @js(__('You played')) : @js(__(':name played')).replace(':name', state.moves.length % 2 === 1 ? @js($cards['w']['name']) : @js($cards['b']['name']))) + ' ' + lastMove.label"></span> <span class="text-ink-3" x-text="ago(lastMove.at)"></span></span>
                                <span class="mt-0.5 flex shrink-0 text-win" title="{{ __('saved and verified') }}"><x-icon name="shield-check" :size="14" /><span class="sr-only">{{ __('saved and verified') }}</span></span>
                            </span>
                        </template>
                    </span>
                    <span role="timer" class="flex h-12 shrink-0 items-center gap-1.5 rounded-lg px-3 font-display text-[22px] font-bold tabular-nums"
                          :class="low ? 'bg-loss text-on-btc' : (myTurn ? 'bg-btc text-on-btc' : 'bg-well text-ink')"><x-icon name="warn" :size="16" x-show="low" /><span x-text="clockText(leftMs)"></span></span>
                </span>
                <span class="text-xs leading-normal text-ink-2">
                    <span x-show="! myTurn">{{ __(':name has until', ['name' => $color ? $opponentName : __('The player to move')]) }}</span><span x-show="myTurn">{{ __('You have until') }}</span>
                    {{ $deadline }}.
                    @if ($color)<span x-show="! myTurn">{{ __('No move by then and you win on time.') }}</span><span x-show="myTurn">{{ __('No move by then and you lose on time.') }}</span>@endif
                </span>
            </div>

            @if ($color)
                {{-- Your move (below lg; from lg under the board and in the turn block) --}}
                <section x-show="myTurn" aria-labelledby="my-h" class="order-6 mx-4 flex flex-col gap-3.5 rounded-lg bg-[radial-gradient(120%_160%_at_0%_0%,#2A1F0E_0%,#121215_60%)] px-4 py-4 lg:hidden" data-test="your-move">
                    <span class="flex flex-wrap items-baseline justify-between gap-2">
                        <span id="my-h" class="text-[15px] font-bold">{{ __('Your move') }}</span>
                        <template x-if="lastMove">
                            <span class="text-xs text-ink-2" x-text="@js(__(':name played :move :ago', ['name' => $opponentName])).replace(':move', lastMove.label).replace(':ago', ago(lastMove.at))"></span>
                        </template>
                    </span>
                    <template x-if="pending">
                        <span class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <span class="font-display text-[30px] font-bold" x-text="pendingLabel"></span>
                            <span class="text-[13px] text-ink-2" x-text="pending.describe"></span>
                        </span>
                    </template>
                    <template x-if="! pending">
                        <span class="text-[13px] text-ink-2">{{ __('Pick a piece on the board, or type the move. Nothing is final until you make it.') }}</span>
                    </template>
                    <span class="flex flex-wrap gap-2">
                        <button type="button" x-on:click="makeMove()" :disabled="! pending || busy"
                                class="btn-p inline-flex h-12 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-[22px] text-sm font-bold whitespace-nowrap text-on-btc disabled:cursor-not-allowed disabled:opacity-50"><x-icon name="shield-check" :size="18" />{{ __('Make my move') }}</button>
                        <button type="button" x-on:click="clear()" :disabled="! pending || busy"
                                class="btn-w inline-flex h-12 cursor-pointer items-center justify-center rounded-md border border-line bg-well px-4 text-[13px] whitespace-nowrap text-ink disabled:opacity-50">{{ __('Clear selection') }}</button>
                    </span>
                    <span class="text-xs leading-normal text-ink-3">{{ __('The server checks the move first. Once made, it is final and :name gets notified.', ['name' => $opponentName]) }}</span>
                    <p class="m-0 text-[13px] text-loss" role="alert" x-show="error" x-text="error"></p>
                </section>

                {{-- Their move (MobileChessCorrespondence; from lg in the turn block) --}}
                <template x-if="! myTurn && lastMove">
                    <div class="order-6 mx-4 flex flex-col gap-1 rounded-lg bg-card px-3 py-2.5 text-[13px] lg:hidden" data-test="their-move">
                        <span><b x-text="(lastMove.mine ? @js(__('You played')) : @js(__(':name played', ['name' => $opponentName]))) + ' ' + lastMove.label"></b> <span class="text-ink-3" x-text="ago(lastMove.at)"></span></span>
                        <span class="flex items-center gap-1.5 text-xs text-win"><x-icon name="shield-check" :size="14" />{{ __('saved and verified') }}</span>
                    </div>
                </template>
                <div x-show="! myTurn" class="order-6 mx-4 flex items-center gap-2 lg:hidden">
                    <span class="min-w-0 grow text-xs leading-normal text-ink-2">{{ __('You can move again once :name has played.', ['name' => $opponentName]) }}</span>
                </div>
            @endif

            {{-- Deadline (below lg; from lg in the turn block) --}}
            <span class="order-4 -mt-1 px-4 text-[11px] leading-normal text-ink-2 lg:hidden" data-test="daily-deadline">
                <span x-show="! myTurn">{{ __(':name has until', ['name' => $color ? $opponentName : __('The player to move')]) }}</span><span x-show="myTurn">{{ __('You have until') }}</span>
                {{ $deadline }}.
                @if ($color)<span x-show="! myTurn">{{ __('No move by then and you win on time.') }}</span><span x-show="myTurn">{{ __('No move by then and you lose on time.') }}</span>@endif
            </span>

            {{-- Moves strip (mobile) --}}
            <div tabindex="0" aria-label="{{ __('Move list, newest move on the right') }}" class="order-5 mx-4 flex h-11 flex-row-reverse overflow-x-auto rounded-lg bg-card lg:hidden">
                <ol class="m-0 flex list-none items-center gap-1 px-2 py-0 text-[13px] whitespace-nowrap">
                    <template x-if="state.moves.length === 0"><li class="px-1 text-ink-3">{{ __('No moves yet') }}</li></template>
                    <template x-for="row in moveRows" :key="'s' + row.n">
                        <li class="flex items-center gap-1"><span class="text-ink-3" x-text="row.n + '.'"></span><span class="rounded-sm px-1.5 py-1" :class="row.wCur ? 'bg-btc-press text-btc-hi' : ''" x-text="row.w"></span><span class="rounded-sm px-1.5 py-1" :class="row.bCur ? 'bg-btc-press text-btc-hi' : ''" x-text="row.b"></span></li>
                    </template>
                </ol>
            </div>

            {{-- Moves with days (lg): as tall as its rows, scrolls inside once the column is full. With 32px rows, 30 plies at 1440x900 keep 10 rows in view on either turn, in English and German (measured 2026-09-26). --}}
            <section aria-labelledby="ev-h" class="mx-4 hidden min-h-0 flex-col rounded-lg bg-card lg:order-4 lg:mx-0 lg:flex lg:max-h-max lg:min-h-20 lg:grow lg:basis-20">
                <span class="flex items-baseline justify-between border-b border-hairline px-4 pt-3 pb-2"><span id="ev-h" class="text-[15px] font-bold">{{ __('Moves') }}</span><span class="text-xs text-ink-3">{{ __('one per day, each one saved') }}</span></span>
                <div tabindex="0" aria-label="{{ __('Move list with days') }}" class="flex min-h-0 grow flex-col-reverse overflow-y-auto">
                    <ol class="m-0 list-none px-3 py-0" data-test="daily-moves">
                        <template x-if="state.moves.length === 0"><li class="px-2 py-4 text-[13px] text-ink-3">{{ __('No moves yet. White starts.') }}</li></template>
                        <template x-for="row in moveRows" :key="row.n">
                            <li class="grid min-h-8 grid-cols-[36px_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2 border-b border-hairline text-sm last:border-0">
                                <span class="pl-2 text-ink-3" x-text="row.n + '.'"></span>
                                <span class="flex min-w-0 items-baseline gap-2 rounded-sm px-2 py-1" :class="row.wCur ? 'bg-btc-press' : ''"><b :class="row.wCur ? 'text-btc-hi' : 'text-ink'" x-text="row.w"></b><span class="text-[11px] text-ink-3" x-text="row.wt"></span></span>
                                <span class="flex min-w-0 items-baseline gap-2 rounded-sm px-2 py-1" :class="row.bCur ? 'bg-btc-press' : ''"><b :class="row.bCur ? 'text-btc-hi' : 'text-ink'" x-text="row.b"></b><span class="text-[11px] text-ink-3" x-text="row.bt"></span></span>
                            </li>
                        </template>
                    </ol>
                </div>
            </section>

            {{-- Spectators only get the chat's note, under the moves --}}
            @unless ($color)
                @include('pages.games.partials.chat', ['chat' => $this->chatConfig(), 'panelClass' => 'lg:order-5 lg:shrink-0'])
            @endunless

        </div>

        {{-- Chat (NIP-17, P5d): the third column from 87.5rem, as tall as the board; under both columns below that; the bottom sheet below lg --}}
        @if ($color)
            @include('pages.games.partials.chat', ['chat' => $this->chatConfig(), 'panelClass' => 'lg:col-span-2 lg:h-[400px] min-[87.5rem]:col-span-1 min-[87.5rem]:col-start-3 min-[87.5rem]:row-start-1 min-[87.5rem]:h-0 min-[87.5rem]:min-h-full'])
        @endif
    </div>

    {{-- Notifications for this game (desktop) --}}
    @if ($color)
        <section aria-labelledby="nt-h" class="hidden flex-wrap items-center gap-6 rounded-lg bg-card px-6 py-4 lg:flex">
            <span class="flex items-center gap-2.5 text-ink-2"><x-icon name="bell" :size="16" /><span id="nt-h" class="text-[15px] font-bold text-ink">{{ __('Tell me when :name moves', ['name' => $opponentName]) }}</span></span>
            <div role="radiogroup" aria-labelledby="nt-h" class="flex gap-2">
                @foreach (['dm' => __('Nostr DM'), 'push' => __('Browser push'), 'here' => __('Only here')] as $value => $label)
                    <button type="button" role="radio" :aria-checked="notify === '{{ $value }}' ? 'true' : 'false'" x-on:click="setNotify('{{ $value }}')" data-test="notify-{{ $value }}"
                            class="h-11 cursor-pointer rounded-md border border-line bg-transparent px-3.5 text-[13px] whitespace-nowrap text-ink-2 aria-checked:bg-raised aria-checked:text-btc aria-checked:shadow-[inset_0_-2px_0_#F7931A]">{{ $label }}</button>
                @endforeach
            </div>
            <span class="text-xs text-ink-3" x-show="notify === null">{{ __('Now: your settings (:summary)', ['summary' => $channelSummary]) }}</span>
            <span class="grow"></span>
            <label class="flex min-h-11 cursor-pointer items-center gap-2.5 text-[13px]">
                <input type="checkbox" :checked="remind" x-on:change="toggleRemind()" class="m-0 size-5 accent-btc" data-test="remind-toggle">
                {{ __('Remind me when :hours h are left', ['hours' => $settings->remindHours ?? 6]) }}
            </label>
        </section>
    @endif

    {{-- Details (desktop), collapsed under the board --}}
    <details class="group hidden rounded-lg bg-card lg:block" data-test="daily-details">
        <summary class="flex min-h-11 cursor-pointer items-center gap-2.5 px-6 text-[13px] text-ink-2">
            <x-icon name="clock" :size="16" /><b class="text-ink">{{ __('Game details') }}</b><span class="truncate">{{ __('Daily chess (1 move/day), max 24 h') }}</span><span class="grow"></span>
            <span class="text-xs text-btc group-open:hidden">{{ __('show') }}</span><span class="hidden text-xs text-btc group-open:inline">{{ __('hide') }}</span>
        </summary>
        <div class="grid grid-cols-2 gap-x-10 px-6 pb-2">
            <div>
                @foreach ([
                    [__('Opponent'), ($opponentUser ? '' : $opponentName).($opponentUser?->clanMember?->clan ? ' · '.$opponentUser->clanMember->clan->name : '').($myColorName ? ' · '.__('you play :color', ['color' => $myColorName]) : '')],
                    [__('Time control'), __('Daily chess (1 move/day), max 24 h')],
                    [__('Started'), ($started?->isoFormat('ddd YYYY-MM-DD') ?? '').' · '],
                ] as $i => [$key, $value])
                    <div class="grid h-11 grid-cols-[180px_minmax(0,1fr)] items-center border-b border-hairline text-sm last:border-0">
                        <span class="text-ink-2">{{ $key }}</span>
                        <span class="flex min-w-0 items-center gap-2">@if ($i === 0 && $opponentUser)<x-player-link :user="$opponentUser" class="flex min-w-0 shrink items-center gap-2" data-test="daily-opponent"><x-avatar :user="$opponentUser" :size="20" class="rounded-sm" /><span class="truncate">{{ $opponentName }}</span></x-player-link>@endif<span class="truncate">{{ $value }}@if ($i === 2)<span x-text="@js(__('today is day :n')).replace(':n', today)"></span>@endif</span>@if ($i === 0 && $color && isset($opponent['npub']))<x-copy-npub :npub="$opponent['npub']" :name="$opponentName" />@endif</span>
                    </div>
                @endforeach
            </div>
            <div>
                @foreach ([[__('Daily Elo'), ($game->rated ? '' : __('Casual').' ').$players['w']['rating']['rating'].' · '.$players['b']['rating']['rating'], 'text-ink'], [__('At stake'), $game->rated ? __('rated Elo with rank') : __('casual Elo only, no rank'), 'text-ink'], [__('Record'), __('every move saved and verified'), 'text-win']] as [$key, $value, $tone])
                    <div class="grid h-11 grid-cols-[180px_minmax(0,1fr)] items-center border-b border-hairline text-sm last:border-0"><span class="text-ink-2">{{ $key }}</span><span class="{{ $tone }} truncate">{{ $value }}</span></div>
                @endforeach
            </div>
        </div>
    </details>

    {{-- Proof --}}
    <div class="mx-4 lg:mx-0">
        <x-proof toggle="show" class="border-0 bg-proof-fill shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" :rows="[
            [__('Game'), $firstNote ? NostrKeys::shortNevent($firstNote->event_id, $firstNote->pubkey, 64) : __('first note with the first move')],
            [__('Moves'), __('each move is its own NIP-64 note, chained to the previous one, confirmed automatically by the player who made it')],
            [__('Relay'), $relay],
            [__('Last move'), $lastNote ? NostrKeys::shortNevent($lastNote->event_id, $lastNote->pubkey, 64).', '.($lastNote->pubkey === $game->white->pubkey ? $players['w']['name'] : $players['b']['name']) : __('none yet')],
        ]" />
    </div>

    {{-- Bottom bar (mobile) --}}
    {{-- Above the chat sheet for players (partials/chat: 72px closed, over this bar when open) --}}
    <div @class(['fixed inset-x-0 z-20 flex flex-col gap-2 border-t border-line bg-bar px-4 pt-3 pb-4 shadow-[0_-16px_32px_rgba(10,10,11,.8)] lg:hidden', $color ? 'bottom-[72px]' : 'bottom-0']) data-test="daily-bottom-bar" data-page-bar>
        <span class="flex justify-between gap-2 text-xs">
            <b x-text="myTurn ? t.yourMoveLeft.replace(':left', hoursMinutes(leftMs)) : @js(__(':name to move', ['name' => $color ? $opponentName : ''])).trim()"></b>
            <span class="text-right text-ink-3">{{ $channelSummary }}</span>
        </span>
        @if ($color)
            <template x-if="myTurn && pending">
                <button type="button" x-on:click="makeMove()" :disabled="busy" class="btn-p inline-flex h-[52px] cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-sm font-bold text-on-btc disabled:opacity-50" data-test="make-move-mobile">
                    <x-icon name="shield-check" :size="16" /><span x-text="@js(__('Make :move')).replace(':move', pendingLabel)"></span>
                </button>
            </template>
            <template x-if="! (myTurn && pending)">
                <x-button variant="quiet" icon="bell" :href="route('settings.chess')" class="h-[52px] text-sm">{{ __('Change notifications') }}</x-button>
            </template>
        @endif
    </div>
</div>
