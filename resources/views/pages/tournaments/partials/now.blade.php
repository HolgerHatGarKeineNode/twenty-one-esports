{{--
    "What to do now" (App\Support\Tournaments\TournamentNow; user, 2026-10-03: "Oben müssen sie sehen, was sie
    machen müssen"): the first thing on a running tournament page, above the organizer's bar. One state, the
    state's name in large type, one primary action. It replaces the cup match card, the lobby pin and the
    match countdowns that stood here one below the other: their details sit inside it now (the cup's times
    form, the lobby's name and password, the league's automatic decision).

    The wire:key carries the state, so a push or a poll that changes it builds a new hero; tournamentNow.js
    then flips it and chimes once it turns into "Play now". It never opens the game on its own (user,
    2026-10-03: "Das bitte ausmachen"): the big button does. The state's colour, the game's cover and the
    two players' avatars make it the unmistakable first thing (user, 2026-10-03).

    `$now` (TournamentNow::of()), `$tournament`, `$cup` (cupMatch() or null), `$error`, `$waits` (myWaits()),
    `$lobby` (myLobby() or null), `$desk` (TournamentDesk::for() or null: the desk button under the action).
--}}
@php
    use App\Support\Tournaments\TournamentNow;

    $state = $now['state'];
    // The state's colour: act now (orange), waiting on somebody else (violet), won (green), live boards for a spectator (red), over (neutral).
    $tone = match (true) {
        in_array($state, TournamentNow::ACTIVE, true) => 'act',
        in_array($state, TournamentNow::WAITING, true) => 'wait',
        $state === 'won' => 'won',
        $state === 'watch' => 'watch',
        default => 'over',
    };
    $round = $now['round'];
    $tz = auth()->user()?->timezone ?? (string) config('esports.preseason.display_timezone');
    $format = fn ($at) => $at->copy()->setTimezone($tz)->translatedFormat('D j M, H:i');
    $action = $now['action'];
    $cover = app(\App\Games\GameRegistry::class)->cover((string) $now['game']);
    // Waiting on a phone: the band and the title take the pinned lobby's low size, so the round's running games and the
    // countdown to their latest end stay above the tab bar (at 375 x 812 the hero ended at 826, the tab bar starts at 748).
    $waitCompact = $lobby === null && $tone === 'wait';
    // Without an action of its own, the desk button joins the row of live boards (icon only on a phone): both are the
    // secondary links of a waiting hero, and its own row pushed the hero 60 px further down.
    $deskInBoards = $action === null && ($desk ?? null) && $now['boards'] !== [];
@endphp
<section aria-labelledby="now-h" wire:key="now-{{ $state }}"
         x-data="tournamentNow({ id: {{ $tournament->id }}, state: @js($state) })" :class="flipped && 'tn-flip'"
         @class([
             '@container relative isolate flex flex-col overflow-hidden rounded-[4px] border-2',
             'border-btc bg-btc-tint' => $tone === 'act',
             'border-proof-ring bg-proof-fill' => $tone === 'wait',
             'border-win bg-win-tint' => $tone === 'won',
             'border-live-ring bg-card' => $tone === 'watch',
             'border-line bg-card' => $tone === 'over',
         ])
         data-test="now-hero" data-state="{{ $state }}" data-tone="{{ $tone }}">
    {{-- The game's cover as a band, the two players on its edge: whose game this is, before a word is read. --}}
    {{-- A pinned lobby brings its own access block: the band stays low, so the name and the password share the first screen. --}}
    <div @class(['relative shrink-0', 'h-24 lg:h-28' => $lobby === null && ! $waitCompact, 'h-16 lg:h-28' => $waitCompact, 'h-16 lg:h-16' => $lobby !== null]) aria-hidden="true" data-test="now-cover">
        @if ($cover)
            <img src="{{ asset($cover->path($cover->widths[0], 'webp')) }}" alt="" width="480" height="270" loading="eager" decoding="async" class="absolute inset-y-0 right-0 h-full w-full object-cover object-[center_30%] opacity-50 [mask-image:linear-gradient(to_bottom,black_35%,transparent)] lg:w-[45%] lg:opacity-70 lg:[mask-image:linear-gradient(to_right,transparent,black_50%),linear-gradient(to_bottom,black_45%,transparent)] lg:[mask-composite:intersect]">
        @endif
        <span @class(['absolute inset-0 bg-linear-to-t to-transparent lg:bg-linear-to-r', 'from-btc-tint' => $tone === 'act', 'from-proof-fill' => $tone === 'wait', 'from-win-tint' => $tone === 'won', 'from-card' => in_array($tone, ['watch', 'over'], true)])></span>
        @if ($now['me'] || $now['opponent'])
            <span @class(['absolute left-4 flex items-center gap-2 lg:left-8', 'bottom-3' => $lobby === null && ! $waitCompact, 'bottom-1 lg:bottom-3' => $waitCompact, 'bottom-1' => $lobby !== null])>
                @if ($now['me'])
                    <x-avatar :user="$now['me']" :size="52" class="size-[52px] rounded-full shadow-[0_0_0_3px_var(--color-card)]" />
                @endif
                @if ($now['opponent'])
                    <span class="font-display text-[13px] font-bold text-ink-2">vs</span>
                    <x-avatar :user="$now['opponent']" :size="52" class="size-[52px] rounded-full shadow-[0_0_0_3px_var(--color-card)]" />
                @endif
            </span>
        @endif
    </div>

    {{--
        Wide (the hero 52rem and more): the state and its details on the left, the action beside them at the bottom, as before.
        Narrower, the action follows the title and its line, before the details (deadline, decisions, boards): beside the
        chat rail at 1280 the text column kept 328 px, the 44 px title broke in two and "Play now" sank to y 922 of an 800 px
        screen, and under the details it still stood at 883 (V2, rule R3: the primary action without scrolling).
    --}}
    <div class="flex flex-col gap-4 px-4 pt-3 pb-5 lg:px-8 lg:pb-7 @min-[52rem]:grid @min-[52rem]:grid-cols-[minmax(0,1fr)_auto] @min-[52rem]:gap-x-12 @min-[52rem]:gap-y-0">
    <div class="flex min-w-0 flex-col gap-2 @min-[52rem]:col-start-1" data-test="now-head">
        @if ($now['context'])
            <p class="m-0 flex min-w-0 items-center gap-2 text-[13px] text-ink-2" data-test="now-context">
                <span @class(['size-2 shrink-0 rounded-full', 'animate-live bg-btc' => $tone === 'act', 'animate-live bg-proof' => $tone === 'wait', 'bg-win' => $tone === 'won', 'animate-live bg-live' => $tone === 'watch', 'bg-ink-3' => $tone === 'over']) aria-hidden="true"></span>
                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $now['context'] }}</span>
            </p>
        @endif

        <h2 id="now-h" @class(['m-0 font-display leading-[1.12] font-bold text-balance [overflow-wrap:anywhere]', 'text-[28px] lg:text-[44px]' => $lobby === null && ! $waitCompact, 'text-2xl lg:text-[44px]' => $waitCompact, 'text-2xl lg:text-[30px]' => $lobby !== null]) data-test="now-title">{{ $now['title'] }}</h2>

        @if ($round !== null && $round['total'] > 1 && $round['total'] <= 32 && ! in_array($now['state'], ['play', 'ready', 'start', 'invited', 'schedule', 'room', 'lobby'], true))
            {{-- The round as one strip: a segment per match, decided ones filled, the ones still playing pulse. The count is in the context line. --}}
            <div class="tn-strip my-1 max-w-[420px]" style="--matches: {{ $round['total'] }}" aria-hidden="true" data-test="now-strip">
                @foreach (range(1, $round['total']) as $segment)
                    <span @class(['is-playing' => $segment > $round['total'] - $round['playing']])></span>
                @endforeach
            </div>
        @endif

        @if ($now['starts'] ?? null)
            {{-- The start as a big clock with its zone and a countdown (user, 2026-10-25: inside the grey line the time was too small to find). --}}
            <div class="my-1 flex flex-wrap items-baseline gap-x-4 gap-y-1 rounded-md border border-line bg-well px-4 py-3" data-test="now-starts">
                <span class="basis-full text-[13px] font-bold text-ink-2">{{ __('Your match starts at') }}</span>
                <time datetime="{{ $now['starts']->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="font-display text-[40px] leading-none font-bold text-btc-hi tabular-nums lg:text-[56px]" data-test="now-starts-clock">{{ $now['starts']->copy()->setTimezone($tz)->format('H:i') }}</time>
                <span class="flex flex-col text-[13px] text-ink-2">
                    <span data-test="now-starts-zone">{{ $now['starts']->copy()->setTimezone($tz)->translatedFormat('D j M') }} · {{ __(':city time', ['city' => str_replace('_', ' ', \Illuminate\Support\Str::afterLast($tz, '/'))]) }}</span>
                    <span>{{ __('Starts in') }} <b class="text-ink tabular-nums" role="timer" x-data="countdown({ at: {{ (int) $now['starts']->getTimestampMs() }}, days: @js(__(':count day|:count days')) })" x-text="text" data-test="now-starts-countdown">{{ sprintf('%02d:%02d:%02d', intdiv($left = max(0, (int) now()->diffInSeconds($now['starts'])), 3600), intdiv($left % 3600, 60), $left % 60) }}</b></span>
                </span>
            </div>
        @endif

        @if ($now['line'])
            <p class="m-0 max-w-[60ch] text-base leading-normal text-ink-2" data-test="now-line">{{ $now['line'] }}</p>
        @endif
    </div>

    @if ($action !== null || (($desk ?? null) && ! $deskInBoards))
        {{-- data-live-clear (resources/js/livePlayer.js): the folded "Watch live" tab steps aside while it would cover the primary
             action; at 1280 x 800 on air it covered its lower 15 px, at 390 x 844 its left 173 px (E4, 2026-10-20).
             Without an action of its own (the desk button alone), the page's primary action is in the details (a clan
             captain's "Confirm team"): narrow, the desk button goes after them, or it pushed "Confirm team" from y 771-819
             to 839-887 of an 844 px phone (rule R3). Wide, this column stands beside them as before. --}}
        <div @class(['flex min-w-0 flex-col gap-2 @min-[52rem]:col-start-2 @min-[52rem]:row-span-2 @min-[52rem]:row-start-1 @min-[52rem]:items-end @min-[52rem]:self-end', 'order-last' => $action === null]) data-live-clear>
            @if (isset($action['href']))
                <a href="{{ $action['href'] }}" @if (isset($action['target'])) target="{{ $action['target'] }}" rel="noopener" @endif class="btn-p inline-flex min-h-16 w-full items-center justify-center gap-3 rounded-md bg-btc px-8 text-lg font-bold text-on-btc hover:text-on-btc @min-[52rem]:min-w-[300px]" data-test="now-action">
                    <x-icon :name="$action['icon']" :size="22" />{{ $action['label'] }}
                </a>
            @elseif ($action !== null)
                <button type="button" wire:click="{{ $action['wire'] }}" wire:loading.attr="disabled" class="btn-p inline-flex min-h-16 w-full cursor-pointer items-center justify-center gap-3 rounded-md bg-btc px-8 text-lg font-bold text-on-btc hover:text-on-btc disabled:opacity-60 @min-[52rem]:min-w-[300px]" data-test="now-action">
                    <x-icon :name="$action['icon']" :size="22" />{{ $action['label'] }}
                </button>
            @endif
            {{-- The tournament desk (TournamentDesk): open right below (from xl beside), this button brings it into view. --}}
            <x-tournaments.desk-button :desk="$desk ?? null" on-page class="w-full @min-[52rem]:w-auto" />
        </div>
    @endif

    {{-- Hidden when nothing below applies; wide, its 8 px step to the head is its own padding, so an empty row adds nothing. --}}
    <div class="flex min-w-0 flex-col gap-2 @min-[52rem]:col-start-1 @min-[52rem]:pt-2 [&:not(:has(*))]:hidden" data-test="now-details">
        @if ($now['lineup'] ?? null)
            {{-- A Hyperbitcoinization clan bracket (P5b): the captain names who plays this match; nothing moves on by itself. --}}
            @php $pick = $now['lineup']; @endphp
            <div class="flex max-w-[560px] flex-col gap-3 border-t border-hairline pt-3" x-data="{ picked: @js($pick['picked']), size: {{ $pick['size'] }} }" data-test="now-lineup">
                <ul class="m-0 grid list-none gap-1.5 p-0 sm:grid-cols-2">
                    @foreach ($pick['players'] as $player)
                        <li wire:key="lineup-{{ $pick['match'] }}-{{ $player->id }}">
                            <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md bg-card px-3 text-[13px] shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:shadow-[inset_0_0_0_1px_var(--color-btc)] has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60">
                                <input type="checkbox" class="size-4 accent-[#F7931A]" value="{{ $player->id }}" x-model.number="picked"
                                       x-bind:disabled="! picked.includes({{ $player->id }}) && picked.length >= size" data-test="lineup-player">
                                <x-avatar :user="$player" :size="24" />
                                <span class="min-w-0 grow truncate">{{ $player->displayName() }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" x-on:click="$wire.nameHyperLineup({{ $pick['match'] }}, picked)" x-bind:disabled="picked.length !== size" wire:loading.attr="disabled"
                            class="btn-p inline-flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-6 font-bold text-on-btc hover:text-on-btc disabled:cursor-not-allowed disabled:opacity-60" data-test="lineup-confirm">
                        <x-icon name="shield-check" :size="18" />{{ __('Confirm team') }}
                    </button>
                    <span class="text-[13px] text-ink-2 tabular-nums" x-text="@js(__(':picked of :size picked')).replace(':picked', picked.length).replace(':size', size)" data-test="lineup-count"></span>
                </div>
            </div>
        @endif

        @if ($now['until'])
            <p class="m-0 flex max-w-[560px] items-center justify-between gap-x-3 text-[13px] text-ink-2 @min-[52rem]:justify-start" data-test="now-until">
                <span class="min-w-0">{{ __('Round deadline in') }}</span>
                <b class="shrink-0 font-display text-xl font-bold text-ink tabular-nums" role="timer" x-data="countdown({ at: {{ (int) $now['until']->getTimestampMs() }}, days: @js(__(':count day|:count days')) })" x-text="text" data-test="now-countdown">{{ $format($now['until']) }}</b>
            </p>
        @endif

        @if ($now['eta'] ?? null)
            {{-- When the round's games are over at the latest (both clocks of the slowest game): time for a break. The label
                 wraps beside its countdown instead of standing above it: one row less on a phone. --}}
            <p class="m-0 flex max-w-[560px] items-center justify-between gap-x-3 text-[13px] text-ink-2 @min-[52rem]:justify-start" data-test="now-eta">
                <span class="min-w-0">{{ __('The running games are over at the latest in about') }}</span>
                <b class="shrink-0 font-display text-xl font-bold text-ink tabular-nums" role="timer" x-data="countdown({ at: {{ (int) $now['eta']->getTimestampMs() }}, days: @js(__(':count day|:count days')) })" x-text="text" data-test="now-eta-countdown">{{ sprintf('%02d:%02d:%02d', intdiv($etaLeft = max(0, (int) now()->diffInSeconds($now['eta'])), 3600), intdiv($etaLeft % 3600, 60), $etaLeft % 60) }}</b>
            </p>
        @endif

        @if ($now['others'])
            <p class="m-0 text-[13px] text-ink-2" data-test="now-others">{{ $now['others'] }}</p>
        @endif

        @if ($now['paused'])
            <p class="m-0 text-[13px] font-bold text-btc-hi" data-test="now-paused">{{ __('The tournament is paused: no match starts and no deadline runs until it goes on.') }}</p>
        @endif

        @if ($now['lobby'] !== null)
            {{-- A series the league started: the lobby the players set in the match room, private to the two sides. --}}
            <dl class="m-0 grid max-w-[420px] grid-cols-[88px_minmax(0,1fr)] gap-x-3 gap-y-1 border-t border-hairline pt-3 text-[13px]" x-data="{ show: false }" data-test="now-lobby">
                @if (filled($now['lobby']['name']))
                    <dt class="text-ink-2">{{ __('Lobby') }}</dt><dd class="m-0 min-w-0 font-bold break-all" data-test="now-lobby-name">{{ $now['lobby']['name'] }}</dd>
                    <dt class="text-ink-2">{{ __('Password') }}</dt>
                    <dd class="m-0 flex min-w-0 items-center gap-2">
                        <span x-show="! show">••••••</span><b x-show="show" x-cloak class="font-bold break-all" data-test="now-lobby-password">{{ $now['lobby']['password'] ?? '–' }}</b>
                        <button type="button" x-on:click="show = ! show" :aria-pressed="show ? 'true' : 'false'" aria-label="{{ __('Show password') }}" class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="eye" :size="16" /></button>
                    </dd>
                @elseif ($now['lobby']['chat'])
                    {{-- A casual cup series: the host shares the lobby in the match room's chat (CasualMatches::shareLobby()). --}}
                    <dd class="col-span-2 m-0 text-ink-2" data-test="now-lobby-room">{{ __('The host shares the lobby name and password in the match room chat.') }}</dd>
                @else
                    <dd class="col-span-2 m-0 text-ink-2" data-test="now-lobby-room">{{ __('The lobby name and password are in the match room as soon as one of you sets them.') }}</dd>
                @endif
            </dl>
        @endif

        @if ($now['state'] === 'lobby' && $lobby)
            @include('pages.tournaments.partials.my-lobby', ['match' => $lobby, 'tournament' => $tournament, 'embedded' => true])
        @endif

        @if ($now['state'] === 'schedule' && $cup)
            <div class="flex flex-col gap-3 border-t border-hairline pt-3">
                @include('pages.tournaments.partials.cup-schedule', ['cup' => $cup, 'error' => $error, 'format' => $format])
            </div>
        @elseif ($error !== '')
            <p class="m-0 text-[13px] text-loss" role="alert" data-test="cup-match-error">{{ $error }}</p>
        @endif

        {{-- The league's automatic decision for the viewer's open match (P18, slice 5), without a second button. --}}
        @foreach ($waits as $wait)
            <div class="flex min-w-0 flex-col gap-1 border-t border-hairline pt-3 text-[13px]" wire:key="my-wait-{{ $wait->matchId }}" data-test="my-wait" data-state="{{ $wait->state }}">
                <x-tournaments.auto-decision :wait="$wait" class="text-ink" />
                @if ($wait->waitsOn((int) auth()->id()) && $wait->action !== null)
                    <span class="text-ink-2" data-test="my-wait-action">{{ $wait->actionText() }}</span>
                @endif
            </div>
        @endforeach

        @if ($now['boards'] !== [])
            {{-- The boards wrap in their own column, so a long pairing never pushes the desk button onto a row of its own. --}}
            <div class="flex min-w-0 items-start gap-2">
                <ul class="m-0 flex min-w-0 grow list-none flex-wrap gap-2 p-0" data-test="now-boards">
                    @foreach ($now['boards'] as $board)
                        <li class="min-w-0"><a href="{{ $board['url'] }}" @if (isset($board['target'])) target="{{ $board['target'] }}" rel="noopener" @endif class="btn-w inline-flex min-h-11 max-w-full items-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink hover:text-ink" data-test="now-board"><x-icon name="pawn" :size="14" class="shrink-0 text-btc" /><span class="min-w-0 truncate">{{ $board['label'] }}</span></a></li>
                    @endforeach
                </ul>
                @if ($deskInBoards)
                    <x-tournaments.desk-button :desk="$desk" on-page compact />
                @endif
            </div>
        @endif
    </div>
    </div>
</section>
