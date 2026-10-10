{{--
    The key-art band with the Blockfill week (HomeGuest.dc.html hero, HomeGuestPhone.dc.html): the week as a mark,
    "ohne Konto spielbar", the time to beat with its holder, and the way in: a practice run for a guest (it never
    counts) with "Anmelden, damit es zählt" beside it, a ranked run for a player. On a phone a guest also gets the
    featured tournament as a card under it.

    $week: HomeBoard::blockfillWeek(); $hub: HomeHub; $user: the viewer or null; $featured: a HomeHub::cups() entry or null;
    $live: matches playing now.
--}}
@php
    use App\Games\Blockfill;
    use App\Support\Engagement\HomeBoard;
    use App\Support\Stacker\BlockfillRules;
    use App\Support\Stacker\BlockfillWeeks;

    $blocks = BlockfillRules::blocks(app(BlockfillWeeks::class)->difficultyOf($week['week']));
    $board = collect($hub->scores())->firstWhere('game', Blockfill::SLUG);
    $leader = $board['rows'][0] ?? null;
@endphp

<section aria-labelledby="hero-h" class="rv-band lg:h-[300px]" data-test="home-hero" data-hero="blockfill">
    <x-game-cover game="blockfill" size="hero" loading="eager" class="mx-4 mt-4 rounded-[4px] lg:absolute lg:top-0 lg:right-0 lg:m-0 lg:h-[300px] lg:rounded-none [&_img]:object-cover" />
    <div class="absolute inset-0 hidden bg-[linear-gradient(90deg,#0A0A0B_48%,rgba(10,10,11,.15)_80%,rgba(10,10,11,.6))] lg:block" aria-hidden="true"></div>

    <div class="relative mx-auto flex w-full max-w-[1440px] flex-col gap-3 p-4 lg:px-12 lg:py-7">
        <span class="flex flex-wrap items-center gap-2">
            <span class="rv-tag is-ok">{{ __('Week :week running', ['week' => $week['number']]) }}</span>
            @guest
                <span class="rv-tag"><span class="max-lg:hidden">{{ __('playable without an account') }}</span><span class="lg:hidden">{{ __('without an account') }}</span></span>
            @endguest
        </span>

        <h1 id="hero-h" class="m-0 max-w-[1040px] font-display text-2xl leading-[1.05] font-bold lg:text-[44px]">{{ __('Blockfill: :blocks against the clock', ['blocks' => $blocks]) }}</h1>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:gap-6">
            <span class="flex flex-col max-lg:hidden" data-test="time-to-beat">
                <span class="rv-m">{{ __('Time to beat this week') }}</span>
                @if ($leader !== null)
                    <span class="font-display text-[30px] font-bold text-btc tabular-nums">{{ $leader['value'] }}</span>
                    <span class="text-ink-2">{{ $leader['name'] }}</span>
                @else
                    <span class="font-display text-[30px] font-bold text-btc">{{ __('#1 is free') }}</span>
                @endif
            </span>
            <span class="text-[13px] text-ink-2 lg:hidden">
                {{ __('Time to beat this week') }}
                @if ($leader !== null)
                    <b class="text-ink tabular-nums">{{ $leader['value'] }}</b> {{ $leader['name'] }}
                @else
                    <b class="text-ink">{{ __('#1 is free') }}</b>
                @endif
            </span>
            <span class="flex flex-col gap-3 lg:flex-row lg:gap-4">
                @guest
                    <a href="{{ route('stacker.play') }}" class="rv-b h-11 px-6 lg:h-[52px]" data-test="hero-cta">{{ __('Start a practice run') }}</a>
                    <a href="{{ route('login') }}" class="rv-b2 h-[52px] max-lg:hidden">{{ __('Log in so it counts') }}</a>
                    <a href="{{ route('login') }}" class="rv-lk self-center text-center lg:hidden">{{ __('Log in so it counts') }}</a>
                @else
                    <a href="{{ route('stacker.play') }}" class="rv-b h-11 px-6 lg:h-[52px]" data-test="hero-cta">{{ __('Start a ranked run') }}</a>
                @endguest
            </span>
        </div>

        @if ($featured !== null)
            @php($tournament = $featured['tournament'])
            <a href="{{ route('tournaments.show', $tournament) }}" @navigate(route('tournaments.show', $tournament)) class="rv-card rv-glow flex items-center gap-3 border-btc-ring bg-feature p-2.5 lg:hidden" data-test="hero-featured">
                <x-game-cover :game="$tournament->game" size="thumb" class="w-20 rounded-[2px] [&_img]:object-cover" />
                <span class="flex min-w-0 flex-col">
                    <b class="truncate" title="{{ $tournament->name }}">{{ HomeBoard::shortName($tournament) }}</b>
                    <span class="flex flex-wrap items-baseline gap-x-2">
                        @if (isset($featured['pot']['sats']))
                            <span class="font-display font-bold text-btc tabular-nums">{{ HomeBoard::sats((int) $featured['pot']['sats']) }} Sats</span>
                        @endif
                        <span class="rv-m">{{ HomeBoard::dayClock($tournament->starts_at, false) }}, {{ $featured['places']['taken'] }}/{{ $featured['places']['places'] }}</span>
                    </span>
                </span>
            </a>
        @endif
    </div>

    <a href="{{ route('matches.index') }}" @navigate(route('matches.index')) class="absolute right-6 bottom-4 hidden items-center gap-2 rounded-[4px] border border-live-ring bg-ground px-3 py-1.5 text-[13px] text-ink hover:text-ink lg:flex" data-test="hero-live">
        <span class="rv-live h-5"><i aria-hidden="true"></i>LIVE</span><b class="tabular-nums">{{ $live }}</b><span class="text-ink-2">{{ __('matches playing') }}</span>
    </a>
</section>
