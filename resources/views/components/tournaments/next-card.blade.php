@props(['card', 'headingId' => 'next-h'])

{{--
    The organizer tournament whose sign-up closes next, as the hero of the
    tournaments page (user, 2026-09-28: the organizers' tournaments "oben
    groß", with their pictures): the game's cover large on the left (on top
    on a phone), then where it stands, the prize pot chip, the name, game,
    mode and format, the start on the viewer's clock with the expected end
    of an open-ended one, when sign-up closes, the places as one tile per
    seat and the way in. Never a casual cup (Tournament::special()).

    $card: an OrganizerBoard entry of the tournament.
--}}
@php
    use App\Support\GameNames;
    use App\Support\LeagueTime;

    $tournament = $card['tournament'];
    $show = route('tournaments.show', $tournament);
    // The expected end of an open-ended tournament, on the same clock as the start (the browser rewrites both).
    $expectedEnd = $tournament->expectedEnd()['typical'] ?? null;
    $endClock = $expectedEnd === null ? null : \App\Support\Tournaments\CupBoard::start($expectedEnd, $card['zone'])['clock'];
    $fixed = $card['fixedZone'];
    $seatTiles = $card['places'] > 0 && $card['places'] <= 32;
@endphp

<section aria-labelledby="{{ $headingId }}" {{ $attributes->class('grid overflow-hidden rounded-card bg-card shadow-ring-hairline lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]') }}
         data-test="next-tournament" data-tournament="{{ $tournament->id }}">
    <a href="{{ $show }}" class="block bg-well" tabindex="-1" aria-hidden="true">
        <x-game-cover :game="$tournament->game" size="hero" loading="eager" class="w-full lg:aspect-auto lg:h-full lg:min-h-full lg:[&_img]:object-cover" data-test="next-tournament-cover" />
    </a>

    <div class="flex min-w-0 flex-col gap-4 p-4 sm:p-6 lg:justify-center lg:gap-5 lg:p-8">
        <p class="m-0 flex flex-wrap items-center gap-2 text-xs font-bold">
            <span class="inline-flex h-6 items-center gap-1.5 rounded-tag bg-btc-chip px-2 text-btc-hi"><x-icon name="clock" :size="14" />{{ __('Open for sign-up') }}</span>
            <x-prize-chip :tournament="$tournament" />
        </p>

        <div class="flex flex-col gap-2">
            <h3 id="{{ $headingId }}" class="m-0 font-display text-[26px] leading-[1.15] font-bold break-words sm:text-[32px] xl:text-[40px]">
                <a href="{{ $show }}" class="text-ink hover:text-btc-hi" data-test="next-tournament-name">{{ $tournament->name }}</a>
            </h3>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ GameNames::full($tournament->game, $tournament->mode) }}, {{ $tournament->format->label() }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <span class="text-xs text-ink-2">{{ __('Starts') }}</span>
                <time datetime="{{ $tournament->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="flex flex-col gap-1" data-test="next-tournament-start"
                      @unless ($fixed) x-data="cupStart({ at: {{ (int) $tournament->starts_at->getTimestampMs() }}, zone: @js($card['zone']) })" @endunless>
                    <b class="font-display text-2xl leading-none text-ink tabular-nums sm:text-[30px]" @unless ($fixed) x-text="clock || @js($card['clock'])" @endunless>{{ $card['clock'] }}</b>
                    <span class="text-[13px] text-ink-2"><span @unless ($fixed) x-text="day || @js($card['day'])" @endunless>{{ $card['day'] }}</span>, <span class="text-ink-3" @unless ($fixed) x-text="city || @js($card['city'])" @endunless>{{ $card['city'] }}</span></span>
                </time>
                @if ($endClock !== null)
                    <span class="text-xs text-ink-2" data-test="open-end">{!! __('Open end, expected around :time', ['time' => '<span'.($fixed ? '' : ' x-data="cupStart({ at: '.(int) $expectedEnd->getTimestampMs().', zone: '.e(json_encode($card['zone'])).' })" x-text="clock || '.e(json_encode($endClock)).'"').'>'.e($endClock).'</span>']) !!}</span>
                @endif
            </div>
            <div class="flex min-w-0 flex-col gap-1">
                <span class="text-xs text-ink-2">{{ __('Sign-up closes in') }}</span>
                <span class="font-display text-2xl leading-none font-bold sm:text-[30px]" title="{{ LeagueTime::stamp($tournament->signup_closes_at) }}" data-test="next-tournament-closes">{{ $tournament->signup_closes_at->diffForHumans(['parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
            </div>
        </div>

        <div class="flex flex-col gap-1.5" data-test="next-tournament-places">
            @if ($seatTiles)
                <span class="grid h-3 auto-cols-fr grid-flow-col gap-1" aria-hidden="true">
                    @for ($seat = 0; $seat < $card['places']; $seat++)
                        <span @class(['rounded-[1px]', 'bg-btc' => $seat < $card['taken'], 'border border-edge' => $seat >= $card['taken']])></span>
                    @endfor
                </span>
            @else
                <span class="h-3 overflow-hidden rounded-[1px] border border-edge" aria-hidden="true"><span class="block h-full bg-btc" style="width: {{ $card['places'] > 0 ? min(100, (int) round(100 * $card['taken'] / $card['places'])) : 0 }}%"></span></span>
            @endif
            <span class="text-[13px]">{{ __(':taken of :places places taken', ['taken' => $card['taken'], 'places' => $card['places']]) }}</span>
        </div>

        <x-button :href="auth()->check() ? route('tournaments.signup', $tournament) : route('login')" class="sm:self-start sm:min-w-48" data-test="register">{{ __('Sign up') }}</x-button>
    </div>
</section>
