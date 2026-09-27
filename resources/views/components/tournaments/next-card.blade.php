@props(['tournament', 'headingId' => 'next-h', 'cover' => true])

{{--
    The next tournament open for sign-up as a large card (Tournaments.dc.html,
    "Next tournament"): the game's cover, the name, game, mode and format,
    the start in the league's zone, the prize pot chip, the places taken and
    when sign-up closes with the way in. The tournaments index and every game
    page (series pages, the chess lobby) show it.

    `cover`: false where the game's cover already heads the page (a series
    page), so the same picture does not stand twice on top of each other.
    The slot is an optional note under the places.
--}}
@php
    $nextPlaces = app(\App\Support\Tournaments\TournamentSignups::class)->places($tournament);
    $nextOpenEnd = \App\Support\Tournaments\TournamentLanding::openEnd($tournament, (string) config('esports.preseason.display_timezone'));
@endphp

<section aria-labelledby="{{ $headingId }}" {{ $attributes->class(['flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:flex-row lg:gap-8 lg:px-6']) }} data-test="next-tournament" data-tournament="{{ $tournament->id }}">
    @if ($cover)
        <x-game-cover :game="$tournament->game" size="card" class="w-full rounded-md sm:w-[280px] lg:self-center" data-test="next-tournament-cover" />
    @endif
    <div class="flex min-w-0 grow flex-col gap-2">
        <span class="text-xs text-ink-2">{{ __('Next tournament') }}</span>
        <h2 id="{{ $headingId }}" class="m-0 font-display text-[26px] leading-[1.2] font-bold break-words"><a href="{{ route('tournaments.show', $tournament) }}" class="text-ink hover:text-ink" data-test="next-tournament-name">{{ $tournament->name }}</a></h2>
        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ \App\Support\GameNames::full($tournament->game, $tournament->mode) }} · {{ $tournament->format->label() }} · <x-league-time :at="$tournament->starts_at" data-test="next-tournament-start" />@if ($nextOpenEnd !== null) · <span data-test="open-end">{{ $nextOpenEnd }}</span>@endif</p>
        <x-prize-chip :tournament="$tournament" class="h-7 text-[13px]" />
        <div class="flex flex-col gap-1.5 pt-2">
            <span class="text-[13px]" data-test="next-tournament-places">{{ __(':taken of :places places taken', ['taken' => $nextPlaces['taken'], 'places' => $nextPlaces['places']]) }}</span>
            <span class="h-2 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true"><span class="block h-full bg-btc" style="width: {{ $nextPlaces['places'] > 0 ? min(100, (int) round(100 * $nextPlaces['taken'] / $nextPlaces['places'])) : 0 }}%"></span></span>
        </div>
        @if ($slot->isNotEmpty())
            <p class="m-0 pt-1 text-xs leading-[1.6] text-ink-2">{{ $slot }}</p>
        @endif
    </div>
    <div class="flex shrink-0 flex-col gap-3 lg:w-[320px]">
        <span class="text-xs text-ink-2">{{ __('Registration closes') }}</span>
        <x-league-time :at="$tournament->signup_closes_at" class="font-display text-xl font-bold" />
        <span class="text-xs text-ink-3">{{ $tournament->signup_closes_at->diffForHumans() }}</span>
        <x-button :href="auth()->check() ? route('tournaments.signup', $tournament) : route('login')" data-test="register">{{ __('Sign up') }}</x-button>
    </div>
</section>
