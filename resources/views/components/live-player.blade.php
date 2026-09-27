{{--
    The floating live player (P20), bottom left of every shell page while the
    stream is on air: a "Watch live" tab that opens a muted 16:9 mini player
    (320 px from sm, 288 px below) with sound, full page (/live), minimise and
    close. resources/js/livePlayer.js plays it and keeps it clear of the
    bottom bars (`data-live-floor`, `data-page-bar`).

    It also seeds the page's live feed (P20b, resources/js/liveFeed.js): the
    status the server saw, the poll URL and interval. The feed keeps
    Alpine.store('live') current, so the tab appears when the stream starts
    and the count ("N watching") follows while the page is open. Off air the
    player is in the page but hidden, and loads nothing until it is played.

    @persist keeps the element, and the stream in it, across wire:navigate.
    Not on /live itself (it has the big player), and not outside layouts/app
    (the TV view, the stream scenes).
--}}
@php
    $status = App\Support\TwentyOne\LiveStatus::current();
    $watching = $status->viewers === null ? null : trans_choice(':count watching|:count watching', $status->viewers);
    $feed = [
        'url' => route('stream.status', absolute: false),
        'interval' => (int) config('esports.live.poll_seconds'),
        'live' => $status->live,
        'viewers' => $status->viewers,
    ];
    $config = [
        'url' => App\Support\TwentyOne\LiveStatus::playlistUrl(),
        'watching' => [
            'one' => trans_choice(':count watching|:count watching', 1, ['count' => '#']),
            'many' => trans_choice(':count watching|:count watching', 2, ['count' => '#']),
            'none' => __('Matches and music'),
        ],
        'labels' => [
            'loading' => __('Tuning in…'),
            'retrying' => __('Signal lost. Reconnecting…'),
            'ended' => __('The stream went off air.'),
            'unsupported' => __('This browser cannot play the stream.'),
            'blocked' => __('Your browser blocks autoplay. Press play, or allow autoplay for this site in the address bar.'),
        ],
    ];
@endphp

{{-- The seed of the live feed, read once by resources/js/app.js (on /live too, whose page follows it). --}}
<script type="application/json" data-live-feed>@json($feed)</script>

@unless (request()->routeIs('live'))
    @persist('live-player')
        <div x-data="livePlayer(@js($config))" data-test="live-player">
            {{-- Room below the footer, so the end of a page never sits under the tab. --}}
            <div aria-hidden="true" class="h-[72px]" x-show="shown" @unless ($status->live) style="display: none" @endunless></div>

            <section aria-label="{{ __('Live stream') }}" x-ref="box" x-bind:style="position" x-show="shown && ! stepAside" @unless ($status->live) x-cloak @endunless
                     class="fixed left-4 z-[35] sm:left-6" style="bottom: calc(16px + env(safe-area-inset-bottom, 0px))"
                     x-on:keydown.escape="view === 'open' && minimise()">
                {{-- The tab: the same card as the match dock's handle on the other side. --}}
                <div class="rounded-lg bg-card p-1 shadow-[0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]" x-show="view === 'tab'">
                    <button type="button" x-ref="tab" x-on:click="open()" data-test="live-tab"
                            class="flex h-12 cursor-pointer items-center gap-3 rounded-md pr-3 pl-3.5 text-left text-ink transition-colors duration-150 hover:bg-row-hover">
                        <span class="on-air" aria-hidden="true"></span>
                        <span class="flex flex-col gap-0.5">
                            <span class="text-[13px] leading-4 font-bold whitespace-nowrap">{{ __('Watch live') }}</span>
                            {{-- The line keeps the width of a three-digit count (an invisible "999 watching" under it), so a new count moves nothing. --}}
                            <span class="grid text-[11px] leading-[14px] whitespace-nowrap text-ink-2 tabular-nums" x-show="status !== 'playing'">
                                <span class="invisible col-start-1 row-start-1" aria-hidden="true">{{ str_replace('#', '999', $config['watching']['many']) }}</span>
                                <span class="col-start-1 row-start-1" x-text="watching" x-effect="$store.live.tick($el)" data-test="live-tab-viewers">{{ $watching ?? __('Matches and music') }}</span>
                            </span>
                            <span class="text-[11px] leading-[14px] whitespace-nowrap text-ink-2" x-show="status === 'playing'" x-cloak>{{ __('Sound on, tap to see it') }}</span>
                        </span>
                        <x-icon name="play" :size="16" class="text-ink-3" x-show="status !== 'playing'" />
                        <x-icon name="volume" :size="16" class="text-btc" x-show="status === 'playing'" x-cloak />
                    </button>
                </div>

                {{-- The mini player. --}}
                <div class="live-rise flex w-[288px] flex-col gap-1 rounded-lg bg-card p-1 shadow-[0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)] sm:w-80" x-show="view === 'open'" x-cloak data-test="live-mini">
                    <div class="relative aspect-video overflow-hidden rounded-md bg-black">
                        <video x-ref="video" class="size-full object-contain" playsinline muted preload="none" aria-label="{{ __('TWENTY ONE live stream') }}" data-test="live-mini-video"></video>
                        <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-[rgba(10,10,11,.72)] p-3 text-center text-xs text-ink-2"
                             x-show="status !== 'playing' && status !== 'idle'" role="status" data-test="live-mini-status">
                            {{-- Autoplay refused even muted: a click plays it (a gesture is always allowed). --}}
                            <button type="button" class="live-play size-12" x-show="status === 'blocked'" x-on:click="resume()" aria-label="{{ __('Play the live stream') }}" data-test="live-mini-play">
                                <x-icon name="play" :size="22" />
                            </button>
                            <span class="text-[11px] leading-[15px]" x-text="statusText"></span>
                            <button type="button" class="btn-w inline-flex h-9 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-ink" x-show="status === 'ended'" x-on:click="retry()">{{ __('Try again') }}</button>
                        </div>
                    </div>
                    <div class="flex h-11 items-center gap-1 pl-2">
                        <span class="on-air" aria-hidden="true"></span>
                        <span class="ml-1 font-display text-[11px] leading-none font-extrabold tracking-[0.06em]">LIVE</span>
                        {{-- The count, always: the number next to LIVE on a phone (as in the header), "N watching" from sm. --}}
                        <span class="ml-1.5 inline-block min-w-[3ch] text-xs text-ink-2 tabular-nums sm:hidden" x-show="$store.live.viewers !== null" x-text="$store.live.viewers" x-effect="$store.live.tick($el)"
                              @if ($status->viewers === null) style="display: none" @endif data-test="live-mini-count">{{ $status->viewers }}</span>
                        <span class="ml-1.5 hidden truncate text-xs text-ink-2 tabular-nums sm:inline" x-show="$store.live.viewers !== null" x-text="watching" x-effect="$store.live.tick($el)"
                              @if ($status->viewers === null) style="display: none" @endif data-test="live-mini-viewers">{{ $watching }}</span>
                        <span class="grow"></span>
                        <button type="button" x-on:click="toggleSound()" x-bind:aria-pressed="(! muted).toString()" aria-pressed="false" class="live-ctl" data-test="live-sound">
                            <span class="sr-only">{{ __('Sound') }}</span>
                            <x-icon name="mute" :size="18" x-show="muted" />
                            <x-icon name="volume" :size="18" x-show="! muted" x-cloak />
                        </button>
                        <a href="{{ route('live') }}" class="live-ctl" aria-label="{{ __('Open the live page') }}" title="{{ __('Open the live page') }}" data-test="live-expand"><x-icon name="expand" :size="18" /></a>
                        <button type="button" x-ref="minimise" x-on:click="minimise()" class="live-ctl" aria-label="{{ __('Minimise the player') }}" title="{{ __('Minimise the player') }}" data-test="live-minimise"><x-icon name="minimize" :size="18" /></button>
                        <button type="button" x-on:click="close()" class="live-ctl" aria-label="{{ __('Close the player') }}" title="{{ __('Close the player') }}" data-test="live-close"><x-icon name="close" :size="18" /></button>
                    </div>
                </div>
            </section>
        </div>
    @endpersist
@endunless
