{{--
    The key-art band with the featured tournament (Main.dc.html hero, HomePhone.dc.html; rule R9): the game's art on
    the right, faded into the ground; the marks, the name, the pot in sats, the start with its countdown, the seats
    and the one main action (Eintragen while sign-up is open). On a phone the art sits on top, full width.

    $cup: a HomeHub::cups() entry; $live: matches playing now (HomeBoard::counts()).
--}}
@php
    use App\Support\Engagement\HomeBoard;
    use App\Support\GameNames;
    use App\Support\Tournaments\Lobbies;

    $tournament = $cup['tournament'];
    $places = $cup['places'];
    $pot = isset($cup['pot']['sats']) ? (int) $cup['pot']['sats'] : null;
    $show = route('tournaments.show', $tournament);
    $signup = route('tournaments.signup', $tournament);
    $meta = implode(', ', array_filter([Lobbies::gameLine($tournament), Lobbies::formatLabel($tournament)]));
    [$ctaHref, $ctaLabel, $ctaClass] = match ($cup['cta']) {
        'open' => [$signup, __('Enter the tournament'), 'rv-b'],
        'entered' => [$show, __('You are entered'), 'rv-b2'],
        default => [$show, __('See the tournament'), 'rv-b2'],
    };
@endphp

<section aria-labelledby="hero-h" class="rv-band lg:h-[300px]" data-test="home-hero" data-tournament="{{ $tournament->id }}">
    <x-game-cover :game="$tournament->game" size="hero" loading="eager" class="mx-4 mt-4 rounded-[4px] lg:absolute lg:top-0 lg:right-0 lg:m-0 lg:h-[300px] lg:rounded-none [&_img]:object-cover" />
    <div class="absolute inset-0 hidden bg-[linear-gradient(90deg,#0A0A0B_48%,rgba(10,10,11,.15)_80%,rgba(10,10,11,.6))] lg:block" aria-hidden="true"></div>

    <div class="relative mx-auto flex w-full max-w-[1440px] flex-col gap-3 p-4 lg:px-12 lg:py-7">
        <span class="flex flex-wrap items-center gap-2">
            <span class="rv-tag is-btc"><x-icon name="trophy" :size="12" />{{ __('Organizer tournament') }}</span>
            <span class="rv-tag max-lg:hidden">{{ __('Open for sign-up') }}</span>
            <span class="rv-m max-lg:hidden">{{ $meta }}</span>
            <span class="rv-m lg:hidden">{{ HomeBoard::dayClock($tournament->starts_at) }}</span>
        </span>

        <h1 id="hero-h" class="m-0 max-w-[1040px] font-display text-2xl leading-[1.05] font-bold lg:text-[44px]">
            <a href="{{ $show }}" @navigate($show) class="text-ink hover:text-ink">
                <span class="lg:hidden">{{ HomeBoard::shortName($tournament) }}</span><span class="max-lg:hidden">{{ $tournament->name }}</span>
            </a>
        </h1>

        <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
            @if ($pot !== null)
                <span class="flex flex-col">
                    <span class="rv-m max-lg:hidden">{{ __('Prize pot in sats') }}</span>
                    <span class="font-display text-[22px] font-bold whitespace-nowrap text-btc tabular-nums lg:text-[30px]" data-test="hero-pot">{{ HomeBoard::sats($pot) }} Sats</span>
                </span>
            @endif
            <span class="flex flex-col gap-1 max-lg:hidden">
                <span class="rv-m">{{ __('Start :when in', ['when' => HomeBoard::dayClock($tournament->starts_at)]) }}</span>
                <x-ui.countdown :at="$tournament->starts_at" :label="__('Start in')" />
            </span>
            <span class="flex flex-col gap-1">
                <span class="rv-m max-lg:hidden">{{ __('Places') }}</span>
                <span class="flex items-center gap-2">
                    <x-ui.seats :taken="$places['taken']" :places="$places['places']" />
                    <span class="tabular-nums" data-test="hero-taken">{{ $places['taken'] }}/{{ $places['places'] }}</span>
                </span>
            </span>
            <a href="{{ $ctaHref }}" @navigate($ctaHref) class="{{ $ctaClass }} h-11 w-full px-6 lg:h-[52px] lg:w-auto" data-test="hero-cta">{{ $ctaLabel }}</a>
        </div>
    </div>

    <a href="{{ route('matches.index') }}" @navigate(route('matches.index')) class="absolute right-6 bottom-4 hidden items-center gap-2 rounded-[4px] border border-live-ring bg-ground px-3 py-1.5 text-[13px] text-ink hover:text-ink lg:flex" data-test="hero-live">
        <span class="rv-live h-5"><i aria-hidden="true"></i>LIVE</span><b class="tabular-nums">{{ $live }}</b><span class="text-ink-2">{{ __('matches playing') }}</span>
    </a>
</section>
