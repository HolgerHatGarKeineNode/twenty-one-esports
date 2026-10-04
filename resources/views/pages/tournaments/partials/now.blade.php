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
@endphp
<section aria-labelledby="now-h" wire:key="now-{{ $state }}"
         x-data="tournamentNow({ id: {{ $tournament->id }}, state: @js($state) })" :class="flipped && 'tn-flip'"
         @class([
             'relative isolate mx-4 mt-2 flex flex-col overflow-hidden rounded-card border-2 lg:mx-12 lg:mt-4',
             'border-btc bg-btc-tint' => $tone === 'act',
             'border-proof-ring bg-proof-fill' => $tone === 'wait',
             'border-win bg-win-tint' => $tone === 'won',
             'border-live-ring bg-card' => $tone === 'watch',
             'border-line bg-card' => $tone === 'over',
         ])
         data-test="now-hero" data-state="{{ $state }}" data-tone="{{ $tone }}">
    {{-- The game's cover as a band, the two players on its edge: whose game this is, before a word is read. --}}
    <div class="relative h-24 shrink-0 lg:h-28" aria-hidden="true" data-test="now-cover">
        @if ($cover)
            <img src="{{ asset($cover->path($cover->widths[0], 'webp')) }}" alt="" width="480" height="270" loading="eager" decoding="async" class="absolute inset-y-0 right-0 h-full w-full object-cover object-[center_30%] opacity-50 [mask-image:linear-gradient(to_bottom,black_35%,transparent)] lg:w-[45%] lg:opacity-70 lg:[mask-image:linear-gradient(to_right,transparent,black_50%),linear-gradient(to_bottom,black_45%,transparent)] lg:[mask-composite:intersect]">
        @endif
        <span @class(['absolute inset-0 bg-linear-to-t to-transparent lg:bg-linear-to-r', 'from-btc-tint' => $tone === 'act', 'from-proof-fill' => $tone === 'wait', 'from-win-tint' => $tone === 'won', 'from-card' => in_array($tone, ['watch', 'over'], true)])></span>
        @if ($now['me'] || $now['opponent'])
            <span class="absolute bottom-3 left-4 flex items-center gap-2 lg:left-8">
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

    <div class="flex flex-col gap-4 px-4 pt-3 pb-5 lg:grid lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end lg:gap-x-12 lg:px-8 lg:pb-7">
    <div class="flex min-w-0 flex-col gap-2">
        @if ($now['context'])
            <p class="m-0 flex min-w-0 items-center gap-2 text-[13px] text-ink-2" data-test="now-context">
                <span @class(['size-2 shrink-0 rounded-full', 'animate-live bg-btc' => $tone === 'act', 'animate-live bg-proof' => $tone === 'wait', 'bg-win' => $tone === 'won', 'animate-live bg-live' => $tone === 'watch', 'bg-ink-3' => $tone === 'over']) aria-hidden="true"></span>
                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $now['context'] }}</span>
            </p>
        @endif

        <h2 id="now-h" class="m-0 font-display text-[28px] leading-[1.12] font-bold text-balance [overflow-wrap:anywhere] lg:text-[44px]" data-test="now-title">{{ $now['title'] }}</h2>

        @if ($round !== null && $round['total'] > 1 && $round['total'] <= 32 && ! in_array($now['state'], ['play', 'ready', 'start', 'invited', 'schedule', 'room', 'lobby'], true))
            {{-- The round as one strip: a segment per match, decided ones filled, the ones still playing pulse. The count is in the context line. --}}
            <div class="tn-strip my-1 max-w-[420px]" style="--matches: {{ $round['total'] }}" aria-hidden="true" data-test="now-strip">
                @foreach (range(1, $round['total']) as $segment)
                    <span @class(['is-playing' => $segment > $round['total'] - $round['playing']])></span>
                @endforeach
            </div>
        @endif

        @if ($now['line'])
            <p class="m-0 max-w-[60ch] text-base leading-normal text-ink-2" data-test="now-line">{{ $now['line'] }}</p>
        @endif

        @if ($now['until'])
            <p class="m-0 flex flex-wrap items-baseline gap-x-2 text-[13px] text-ink-2" data-test="now-until">
                <span>{{ __('Round deadline in') }}</span>
                <b class="font-display text-xl font-bold text-ink tabular-nums" role="timer" x-data="countdown({ at: {{ (int) $now['until']->getTimestampMs() }}, days: @js(__(':count day|:count days')) })" x-text="text" data-test="now-countdown">{{ $format($now['until']) }}</b>
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
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0" data-test="now-boards">
                @foreach ($now['boards'] as $board)
                    <li class="min-w-0"><a href="{{ $board['url'] }}" class="btn-w inline-flex min-h-11 max-w-full items-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink hover:text-ink" data-test="now-board"><x-icon name="pawn" :size="14" class="shrink-0 text-btc" /><span class="min-w-0 truncate">{{ $board['label'] }}</span></a></li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($action !== null || ($desk ?? null))
        <div class="flex min-w-0 flex-col gap-2 lg:items-end">
            @if (isset($action['href']))
                <a href="{{ $action['href'] }}" class="btn-p inline-flex min-h-16 w-full items-center justify-center gap-3 rounded-md bg-btc px-8 text-lg font-bold text-on-btc hover:text-on-btc lg:min-w-[300px]" data-test="now-action">
                    <x-icon :name="$action['icon']" :size="22" />{{ $action['label'] }}
                </a>
            @elseif ($action !== null)
                <button type="button" wire:click="{{ $action['wire'] }}" wire:loading.attr="disabled" class="btn-p inline-flex min-h-16 w-full cursor-pointer items-center justify-center gap-3 rounded-md bg-btc px-8 text-lg font-bold text-on-btc hover:text-on-btc disabled:opacity-60 lg:min-w-[300px]" data-test="now-action">
                    <x-icon :name="$action['icon']" :size="22" />{{ $action['label'] }}
                </button>
            @endif
            {{-- The tournament desk (TournamentDesk): open right below (from xl beside), this button brings it into view. --}}
            <x-tournaments.desk-button :desk="$desk ?? null" on-page class="w-full lg:w-auto" />
        </div>
    @endif
    </div>
</section>
