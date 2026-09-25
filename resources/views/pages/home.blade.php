{{--
    Home before Block 0, from MainPrelaunch.dc.html (from 1024 px) and
    MobileHomePrelaunch.dc.html (below). There is no released season yet, so
    this is the only state of `/` for now.

    Everything shown is real or configured (config/esports.php, `preseason`):
    the date, pot and genesis message appear only when set; clans and casual
    games come from the database. Sections that would need data or features
    that do not exist yet (rewards per era, sign-ups, bounties, zapping the
    pot) are left out on purpose.
--}}
@php
    use App\Enums\ChessGameStatus;
    use App\Models\ChessGame;
    use App\Models\Clan;
    use App\Support\PreSeason;

    $user = auth()->user();
    $state = PreSeason::state();
    $timed = $state !== 'undated';
    $secondsLeft = PreSeason::secondsLeft();
    $block0At = PreSeason::block0At()?->setTimezone(PreSeason::timezoneFor($user))->locale(app()->getLocale());
    $pot = PreSeason::potSats();
    $genesis = PreSeason::genesisMessage();

    $parts = [
        'd' => intdiv($secondsLeft, 86400),
        'h' => intdiv($secondsLeft % 86400, 3600),
        'm' => intdiv($secondsLeft % 3600, 60),
        's' => $secondsLeft % 60,
    ];
    $units = [
        'd' => [__('days'), 7],
        'h' => [__('hours'), 24],
        'm' => [__('minutes'), 60],
        's' => [__('seconds'), 60],
    ];

    $clanCount = Clan::query()->count();
    $clans = Clan::query()->withCount('members')->latest('created_at')->latest('id')->limit(5)->get();

    $casual = ChessGame::query()->where('rated', false);
    $casualPlayed = (clone $casual)->where('status', ChessGameStatus::Finished)->count();
    $casualLive = (clone $casual)->where('status', ChessGameStatus::Active)->count();
    $casualGames = (clone $casual)
        ->with(['white', 'black'])
        ->whereIn('status', [ChessGameStatus::Active, ChessGameStatus::Finished])
        ->orderByRaw('case when status = ? then 0 else 1 end', [ChessGameStatus::Active->value])
        ->latest('id')
        ->limit(4)
        ->get();

    $labels = [
        'd' => __('d'),
        'days' => __('days'),
        'hours' => __('hours'),
        'minutes' => __('minutes'),
        'seconds' => __('seconds'),
        'in' => __('Block 0 in :time'),
        'due' => __('Block 0 is due. The board releases it.'),
    ];
    $barClock = $parts['d'].' '.__('d').' '.sprintf('%02d:%02d:%02d', $parts['h'], $parts['m'], $parts['s']);
    $spoken = $state === 'due'
        ? $labels['due']
        : __('Block 0 in :time', ['time' => "{$parts['d']} {$labels['days']} {$parts['h']} {$labels['hours']} {$parts['m']} {$labels['minutes']} {$parts['s']} {$labels['seconds']}"]);

    $hideWhenDue = $state === 'due' ? 'display: none' : null;
    $showWhenDue = $state === 'due' ? null : 'display: none';
@endphp

<x-layouts::app section="home" flush>
    <div id="block0" class="flex flex-col"
         @if ($timed) x-data="blockZeroCountdown(@js(['secondsLeft' => $secondsLeft, 'labels' => $labels]))" @endif
         data-state="{{ $state }}">

        {{-- The bar that runs on top of the page until Block 0 --}}
        <div class="pl-bar" data-test="prelaunch-bar">
            <x-icon name="clock" :size="16" />
            @if ($timed)
                <span class="min-w-0 flex-[1_1_220px]" x-show="!due" @if ($hideWhenDue) style="{{ $hideWhenDue }}" @endif>{{ __('Casual until Block 0 — rated play and mining start :when', ['when' => $block0At->isoFormat('ddd HH:mm')]) }}</span>
                <span class="min-w-0 flex-[1_1_220px]" x-show="due" @if ($showWhenDue) style="{{ $showWhenDue }}" @endif>{{ __('Block 0 is due — rated play and mining start once the board releases it.') }}</span>
                <b x-show="!due" x-text="barClock" @if ($hideWhenDue) style="{{ $hideWhenDue }}" @endif data-test="bar-clock">{{ $barClock }}</b>
            @else
                <span class="min-w-0">{{ __('Casual until Block 0 — rated play and mining start at Block 0. The date is coming soon.') }}</span>
            @endif
        </div>

        <section aria-labelledby="cd-h1" class="flex flex-col gap-6 px-4 pt-7 lg:gap-8 lg:px-10 lg:pt-12">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between lg:gap-8 lg:px-2">
                <h1 id="cd-h1" class="m-0 font-display text-3xl leading-[1.15] font-extrabold tracking-[-0.01em] lg:text-5xl lg:leading-[1.1]">
                    @if ($timed)
                        <span x-show="!due" @if ($hideWhenDue) style="{{ $hideWhenDue }}" @endif>{{ __('Pre-Season starts at Block 0') }}</span>
                        <span x-show="due" @if ($showWhenDue) style="{{ $showWhenDue }}" @endif>{{ __('Block 0 is due') }}</span>
                    @else
                        {{ __('Pre-Season starts at Block 0') }}
                    @endif
                </h1>
                <p class="m-0 hidden max-w-[44ch] text-right text-sm leading-[1.6] text-ink-2 lg:block">
                    @if ($timed)
                        {{ __(':when. From then on every fair rated win mines a block.', ['when' => $block0At->isoFormat('ddd YYYY-MM-DD, HH:mm z')]) }}
                    @else
                        {{ __('Block 0 · date coming soon. The board sets the date; from then on every fair rated win mines a block.') }}
                    @endif
                </p>
            </div>

            {{-- The stage: the block strip in its genesis form --}}
            <div class="cd" role="timer" data-test="countdown"
                 @if ($timed) :aria-label="spoken" aria-label="{{ $spoken }}" @else aria-label="{{ __('Block 0, date coming soon') }}" @endif>
                <div class="cd-void" aria-hidden="true"><span>prev 000000000000</span></div>

                <div class="cd-genesis">
                    <div class="cd-col">
                        <span class="cd-h cd-h--g0">{{ __('Block 0') }}</span>
                        @if ($timed)
                            <div class="cd-cube g0" :class="due ? 'is-mined' : 'is-wait'" data-test="ghost-cube">
                                <span class="g0-sm">{{ __('genesis') }}</span>
                                <span class="g0-big">{{ $block0At->isoFormat('HH:mm') }}</span>
                                <span class="g0-sm" x-text="due ? @js(__('due now')) : @js(__('no reward'))">{{ $state === 'due' ? __('due now') : __('no reward') }}</span>
                            </div>
                        @else
                            <div class="cd-cube g0" data-test="ghost-cube">
                                <span class="g0-sm">{{ __('genesis') }}</span>
                                <span class="g0-big">{{ __('soon') }}</span>
                                <span class="g0-sm">{{ __('no reward') }}</span>
                            </div>
                        @endif
                        <span class="cd-cap">{{ __('The board releases it') }}</span>
                    </div>
                    <p class="cd-when">
                        @if ($timed)
                            {{ __(':when. A board member releases it.', ['when' => $block0At->isoFormat('ddd YYYY-MM-DD, HH:mm z')]) }}
                        @else
                            {{ __('Block 0 · date coming soon. A board member sets the date and releases it.') }}
                        @endif
                    </p>
                </div>

                <span class="cd-div" aria-hidden="true"></span>

                <div class="cd-units" aria-hidden="true">
                    @foreach ($units as $key => [$label, $max])
                        <div class="cd-col">
                            <span class="cd-h">{{ $label }}</span>
                            @if ($timed)
                                <div class="cd-cube"
                                     data-on="{{ $parts[$key] > 0 ? 1 : 0 }}"
                                     :data-on="isOn('{{ $key }}')"
                                     style="--lvl: {{ number_format(min(100, $parts[$key] / $max * 100), 1, '.', '') }}%"
                                     :style="{ '--lvl': level('{{ $key }}') }"
                                     data-unit="{{ $key }}">
                                    <span class="cd-fill"></span>
                                    <span class="cd-num" data-wide="{{ $key === 'd' && $parts['d'] > 99 ? 1 : 0 }}" :data-wide="isWide('{{ $key }}')" :class="'tk' + ticks.{{ $key }}" x-text="value('{{ $key }}')">{{ sprintf('%02d', $parts[$key]) }}</span>
                                </div>
                            @else
                                <div class="cd-cube" data-on="0" data-unit="{{ $key }}">
                                    <span class="cd-num">--</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col gap-6 lg:items-center lg:pt-2">
                @if ($genesis)
                    <figure class="m-0 flex flex-col gap-1.5 lg:items-center" data-test="genesis">
                        <figcaption class="text-xs text-ink-3">{{ __('Written into Block 0') }}</figcaption>
                        <p class="m-0 max-w-[72ch] text-sm leading-[1.6] break-words lg:text-center lg:text-[15px]">“{{ $genesis }}”</p>
                    </figure>
                @endif

                <div class="flex flex-col gap-3 lg:flex-row">
                    @if (! $user)
                        <a href="{{ route('login') }}" class="btn-p inline-flex h-13 items-center justify-center gap-2.5 rounded-lg bg-btc px-7 text-[15px] font-bold text-on-btc hover:text-on-btc">
                            <x-icon name="bell" :size="20" />{{ __('Notify me at Block 0') }}
                        </a>
                    @elseif ($user->notify_block0_at === null)
                        <form method="POST" action="{{ route('notify.block0') }}" class="flex">
                            @csrf
                            <button type="submit" class="btn-p inline-flex h-13 w-full cursor-pointer items-center justify-center gap-2.5 rounded-lg bg-btc px-7 text-[15px] font-bold text-on-btc">
                                <x-icon name="bell" :size="20" />{{ __('Notify me at Block 0') }}
                            </button>
                        </form>
                    @else
                        <p role="status" class="m-0 inline-flex h-13 items-center justify-center gap-2.5 rounded-lg border border-btc-ring bg-btc-chip px-7 text-[15px] font-bold text-btc-hi" data-test="notify-set">
                            <x-icon name="check" :size="20" />{{ __("We'll tell you at Block 0") }}
                        </p>
                    @endif
                    <a href="{{ route('chess.lobby') }}" class="btn-s inline-flex h-11 items-center justify-center gap-2.5 rounded-lg border border-edge px-[22px] text-sm text-ink hover:text-ink lg:h-13">
                        <x-icon name="pawn" :size="18" />{{ __('Play a casual game now') }}
                    </a>
                </div>
                <p class="pl-note lg:text-center">{{ __('Casual games run before and after Block 0: no rating, no reward.') }}</p>
            </div>
        </section>
    </div>

    <div class="grid grow grid-cols-1 content-start gap-4 px-4 pt-7 pb-6 lg:grid-cols-12 lg:gap-5 lg:px-12 lg:pt-14 lg:pb-12">
        @if ($pot)
            <section aria-labelledby="pot-h" class="pl-card lg:col-span-5" data-test="pot">
                <h2 id="pot-h" class="pl-h2">{{ __('The pot') }}</h2>
                <span class="flex items-baseline gap-2.5">
                    <b class="font-display text-3xl leading-[1.15] font-extrabold lg:text-4xl lg:leading-[1.1]">{{ PreSeason::formatSats($pot) }}</b>
                    <span class="text-sm text-ink-2">sats</span>
                </span>
                <p class="m-0 text-[13px] leading-[1.6] text-ink-2">{{ __('The whole Pre-Season supply. It is fixed at Block 0 and mined out win by win.') }}</p>
                <p class="pl-note mt-auto">{{ __('Nothing in it is promised to a later season.') }}</p>
            </section>
        @endif

        <section aria-labelledby="how-h" @class(['pl-card', 'lg:col-span-7' => $pot, 'lg:col-span-12' => ! $pot])>
            <h2 id="how-h" class="pl-h2">{{ __('How the Pre-Season works') }}</h2>
            <p class="m-0 max-w-[75ch] text-[13px] leading-[1.6] text-ink-2">{{ __('From Block 0 on, every fair rated win mines a block. Mined blocks earn sats from the pot. Rewards are paid once, after the season review.') }}</p>
            <h3 class="m-0 mt-2 text-[13px] font-bold">{{ __('A rated win mines a block when') }}</h3>
            <ul @class(['m-0 grid list-none grid-cols-1 gap-x-6 gap-y-2 p-0 md:grid-cols-2', 'xl:grid-cols-3' => ! $pot])>
                @foreach ([
                    1 => __('Both players are Trusted and have added each other as opponents'),
                    2 => __('It is a real game: 20 moves or more in chess, a fully played series in Rocket League'),
                    3 => __('The two sides are not from the same clan'),
                    4 => __('One block per pairing a day'),
                    5 => __('At most 5 blocks per player a day, per game'),
                    7 => __('The two players do not both get 90 % or more of their trust from the same person'),
                    8 => __('At most 3 blocks per pairing until the season ends'),
                    9 => __('The game has not used up its share of the era'),
                ] as $number => $rule)
                    <li class="grid grid-cols-[16px_minmax(0,1fr)] gap-2 text-xs leading-[1.5] text-ink-2 lg:grid-cols-[20px_minmax(0,1fr)]"><span class="text-ink-3">{{ $number }}</span><span>{{ $rule }}</span></li>
                @endforeach
            </ul>
            <p class="pl-note">{{ __('Draws mine nothing. Admins can tune these limits during the Pre-Season; a change only counts for blocks after it.') }}</p>
        </section>

        <section aria-labelledby="open-h" class="flex flex-col gap-3 lg:col-span-12 lg:mt-3">
            <h2 id="open-h" class="m-0 mt-2 font-display text-lg font-bold lg:mt-0 lg:text-xl">{{ __('Already open before Block 0') }}</h2>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-5">
                <div class="pl-card" data-test="casual-games">
                    <span class="flex items-baseline justify-between gap-3">
                        <h3 class="pl-h2">{{ __('Casual games') }}</h3>
                        <span class="flex items-center gap-1.5 text-xs text-ink-2">
                            @if ($casualLive > 0)
                                <span class="inline-block size-2 animate-live rounded-full bg-win" aria-hidden="true"></span>
                            @endif
                            {{ __(':played played, :live live', ['played' => $casualPlayed, 'live' => $casualLive]) }}
                        </span>
                    </span>
                    @if ($casualGames->isEmpty())
                        <p class="m-0 border-t border-hairline pt-3 text-[13px] leading-[1.6] text-ink-2">{{ __('No casual games yet. Start the first one.') }}</p>
                    @else
                        <ul class="m-0 list-none p-0">
                            @foreach ($casualGames as $game)
                                @php
                                    $live = $game->status === ChessGameStatus::Active;
                                    $clock = intdiv($game->initial_ms, 60000).'+'.intdiv($game->increment_ms, 1000);
                                    $result = str_replace(['1/2', '-'], ['½', '–'], (string) $game->result);
                                    $meta = $live
                                        ? __(':mode :clock, casual, live, move :move', ['mode' => __(ucfirst($game->mode)), 'clock' => $clock, 'move' => max(1, intdiv($game->ply + 1, 2))])
                                        : __(':mode :clock, casual, :result, :reason', ['mode' => __(ucfirst($game->mode)), 'clock' => $clock, 'result' => $result, 'reason' => mb_strtolower(__($game->end_reason?->label() ?? 'Finished'))]);
                                @endphp
                                <li class="border-t border-hairline">
                                    <a href="{{ route('games.show', $game) }}" class="flex min-h-12 flex-col justify-center gap-0.5 py-1 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                                        <span class="flex min-w-0 items-center gap-1.5">
                                            <x-avatar :name="$game->white->displayName()" :src="$game->white->avatarUrl()" :size="18" class="rounded-sm" />
                                            <span class="truncate">{{ $game->white->displayName() }}</span>
                                            <span class="shrink-0 text-ink-3">{{ __('vs') }}</span>
                                            <x-avatar :name="$game->black->displayName()" :src="$game->black->avatarUrl()" :size="18" class="rounded-sm" />
                                            <span class="truncate">{{ $game->black->displayName() }}</span>
                                        </span>
                                        <span class="flex items-center gap-1.5 text-[11px] text-ink-3">
                                            @if ($live)<span class="inline-block size-2 animate-live rounded-full bg-win" aria-hidden="true"></span>@endif{{ $meta }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <a href="{{ route('chess.lobby') }}" class="btn-p mt-auto flex h-11 items-center justify-center rounded-lg bg-btc text-sm font-bold text-on-btc hover:text-on-btc">{{ __('Play casual now') }}</a>
                </div>

                <div class="pl-card" data-test="clans">
                    <span class="flex items-baseline justify-between gap-3">
                        <h3 class="pl-h2">{{ __('Clans') }}</h3>
                        <span class="text-xs text-ink-2">{{ __(':count so far', ['count' => $clanCount]) }}</span>
                    </span>
                    @if ($clans->isEmpty())
                        <p class="m-0 border-t border-hairline pt-3 text-[13px] leading-[1.6] text-ink-2">{{ __('No clans yet. Start the first one for your meetup.') }}</p>
                    @else
                        <ul class="m-0 list-none p-0">
                            @foreach ($clans as $clan)
                                <li class="border-t border-hairline">
                                    <a href="{{ route('clans.show', $clan) }}" class="grid min-h-11 grid-cols-[28px_minmax(0,1fr)_auto] items-center gap-2.5 py-1 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                                        <span class="relative flex size-7 items-center justify-center" aria-hidden="true">
                                            <x-clan-tag :tag="$clan->clantag" size="sm" class="min-w-7 px-0.5" />
                                            @if ($clan->picture)
                                                <img src="{{ $clan->picture }}" alt="" width="28" height="28" loading="lazy" referrerpolicy="no-referrer" class="absolute inset-0 size-7 rounded-sm bg-card object-cover" onerror="this.remove()">
                                            @endif
                                        </span>
                                        <span class="flex min-w-0 flex-col gap-0.5">
                                            <span class="truncate">{{ $clan->name }}</span>
                                            @if ($clan->meetup_city)
                                                <span class="truncate text-[11px] text-ink-3">{{ $clan->meetup_city }}</span>
                                            @endif
                                        </span>
                                        <span class="text-xs whitespace-nowrap text-ink-2">{{ trans_choice(':count player|:count players', $clan->members_count, ['count' => $clan->members_count]) }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="mt-auto grid grid-cols-2 gap-2 pt-2">
                        <a href="{{ route('clans.create') }}" class="btn-s flex h-11 items-center justify-center rounded-lg border border-edge text-[13px] text-ink hover:text-ink">{{ __('Start a clan') }}</a>
                        <a href="{{ route('clans.index') }}" class="btn-s flex h-11 items-center justify-center rounded-lg border border-edge text-[13px] text-ink hover:text-ink">{{ __('Join a clan') }}</a>
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-layouts::app>
