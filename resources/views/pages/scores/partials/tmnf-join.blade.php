{{--
    How to join the TMNF week (plan "Trackmania und Restposten", P2): four steps as cards in reading order (get the
    game, join our server, link the login, drive the track), each with its one action, then the week's facts as chips.
    On the week page and on the game's score page, anchored at #join.
    $week: the week shown (null: none opened yet); $track: TmnfWeeks::track() of its course (null: unknown).
--}}
@php
    $serverName = (string) config('esports.tmnf.server.name');
    $serverAddress = config('esports.tmnf.server.address');
    $serverAddress = is_string($serverAddress) && trim($serverAddress) !== '' ? trim($serverAddress) : null;
    // A free Nations account reaches a player-hosted server only from its Favourites: hand out that link when the login is known.
    $serverLogin = config('esports.tmnf.server.login');
    $favourite = is_string($serverLogin) && preg_match('/^[A-Za-z0-9_.-]{1,40}$/', trim($serverLogin)) === 1 ? 'tmtp://#addfavourite='.trim($serverLogin) : null;
    $serverAddress = $favourite ?? $serverAddress;
    $linked = auth()->check() && \App\Support\Tmnf\TmnfLinks::isLinked(auth()->user());
    $authorTime = ($track['author_ms'] ?? 0) > 0 ? \App\Games\ScoreMetric::time()->format((int) $track['author_ms']) : null;
    $trackName = $track['name'] ?? __('the track of the week');
    $card = 'relative flex min-w-0 flex-col gap-3 rounded-lg bg-card p-4 shadow-ring lg:p-5';
    $number = 'grid size-10 shrink-0 place-items-center rounded-md bg-[color-mix(in_oklab,var(--color-tmnf)_18%,transparent)] font-display text-lg font-bold text-tmnf';
    $action = 'btn-s inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-md border border-edge px-4 text-[13px] font-bold text-ink hover:text-ink';
@endphp
<section id="join" aria-labelledby="tmnf-join-h" class="flex scroll-mt-24 flex-col gap-4" data-test="tmnf-join">
    <div class="flex flex-col gap-1">
        <h2 id="tmnf-join-h" class="m-0 font-display text-2xl font-bold lg:text-3xl">{{ __('How to join') }}</h2>
        <p class="m-0 text-[13px] text-ink-2">{{ __('Four steps, then every finish on our server counts for you.') }}</p>
    </div>

    <ol class="m-0 grid list-none grid-cols-1 gap-3 p-0 md:grid-cols-2 xl:grid-cols-4">
        <li class="{{ $card }}" data-test="join-step-game">
            <span class="flex items-center gap-3"><span class="{{ $number }}">1</span><b class="text-[15px]">{{ __('Get TMNF') }}</b></span>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('TrackMania Nations Forever is free to play on Steam.') }}</p>
            <a href="https://store.steampowered.com/app/11020/" rel="noopener noreferrer" target="_blank" class="{{ $action }}" data-test="join-steam"><x-icon name="download" :size="16" />{{ __('TMNF on Steam') }}</a>
        </li>

        <li class="{{ $card }}" data-test="join-step-server">
            <span class="flex items-center gap-3"><span class="{{ $number }}">2</span><b class="text-[15px]">{{ __('Join our server') }}</b></span>
            <span class="font-display text-lg leading-tight font-bold [overflow-wrap:anywhere]" data-test="join-server-name">{{ $serverName }}</span>
            @if ($serverAddress !== null)
                <span x-data="{ copied: false }" class="flex min-w-0 items-center gap-2">
                    <code class="min-w-0 rounded-sm bg-well px-2 py-1 font-mono text-[13px] text-ink [overflow-wrap:anywhere]" data-test="join-server-address">{{-- A narrow card breaks before the port, never inside it --}}{!! $favourite !== null ? e($serverAddress) : str_replace(':', '<wbr>:', e($serverAddress)) !!}</code>
                    <button type="button" class="inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2 hover:text-ink" aria-label="{{ __('Copy the server address') }}"
                            x-on:click="navigator.clipboard?.writeText(@js($serverAddress)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })" data-test="join-copy-address">
                        <x-icon name="copy" :size="16" x-show="! copied" /><x-icon name="check" :size="16" x-show="copied" x-cloak class="text-win" />
                    </button>
                </span>
                @if ($favourite !== null)
                    {{-- Free Nations accounts: favourite first, restart, then join (FreeZone FAQ); the in-game Explorer bar takes the tmtp:// link. --}}
                    <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5 text-[13px] leading-normal text-ink-2 marker:font-bold marker:text-tmnf" data-test="join-favourite-steps">
                        <li>{{ __('Copy the link with the button.') }}</li>
                        <li>{{ __('Open the Explorer in TMNF and paste the link into the bar at the top, then press Enter.') }}</li>
                        <li>{{ __('Restart TMNF.') }}</li>
                        <li>{!! __('Internet → Favourites → :server → join. Or search for :server in the server list.', ['server' => '<b class="text-ink" data-test="join-search-name">'.e($serverName).'</b>']) !!}</li>
                    </ol>
                @endif
            @else
                <span class="text-[13px] text-ink-2" data-test="join-server-soon">{{ __('The address shows here once the server is online.') }}</span>
            @endif
        </li>

        <li class="{{ $card }}" data-test="join-step-link">
            <span class="flex items-center gap-3"><span class="{{ $number }}">3</span><b class="text-[15px]">{{ __('Link your login') }}</b></span>
            @if ($linked)
                <span class="flex items-center gap-2 text-[13px] text-win" data-test="join-linked"><x-icon name="shield-check" :size="16" class="shrink-0" />{{ __('Your login is linked.') }}</span>
            @else
                <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Save your TMNF login, then type the code the site gives you in the server chat. Only you see the login.') }}</p>
                <a href="{{ auth()->check() ? route('gaming.edit').'#tag-tmnf' : route('login') }}" class="{{ $action }}" data-test="join-link-login"><x-icon name="link" :size="16" />{{ auth()->check() ? __('Link my login') : __('Log in to link') }}</a>
            @endif
        </li>

        <li class="{{ $card }}" data-test="join-step-drive">
            <span class="flex items-center gap-3"><span class="{{ $number }}">4</span><b class="text-[15px]">{{ __('Drive :track', ['track' => $trackName]) }}</b></span>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('As often as you like. Your best finish of the week counts, the fastest time wins.') }}</p>
            @if ($authorTime !== null)
                <span class="flex items-baseline gap-2"><span class="text-xs text-ink-2">{{ __('Author time') }}</span><b class="font-mono text-base tabular-nums" data-test="join-author-time">{{ $authorTime }}</b></span>
            @endif
        </li>
    </ol>

    <ul class="m-0 flex list-none flex-wrap gap-2 p-0" data-test="tmnf-facts">
        @if ($track !== null)
            <li class="inline-flex h-8 items-center gap-2 rounded-md bg-raised px-3 text-xs text-ink"><x-icon name="flag" :size="14" class="shrink-0 text-tmnf" />{{ __('Track') }} <b>{{ $track['name'] }}</b></li>
            @if ($track['environment'] !== '')
                <li class="inline-flex h-8 items-center gap-2 rounded-md bg-raised px-3 text-xs text-ink">{{ $track['environment'] }}@if ($track['author'] !== '') · {{ $track['author'] }}@endif</li>
            @endif
        @endif
        <li class="inline-flex h-8 items-center gap-2 rounded-md bg-raised px-3 text-xs text-ink"><x-icon name="clock" :size="14" class="shrink-0 text-tmnf" />{{ __('A new week every Monday 00:00 Berlin') }}</li>
        <li class="inline-flex h-8 items-center gap-2 rounded-md bg-raised px-3 text-xs text-ink"><x-icon name="shield-check" :size="14" class="shrink-0 text-tmnf" />{{ __('Timed by our server') }}</li>
    </ul>
</section>
