@props(['game' => null, 'headingId' => 'next-tournament-h'])

{{--
    The next tournament open for sign-up (P8), of one game or of any: name,
    game and format, when sign-up closes, and the way in. Renders nothing
    when no tournament is open, so a page never shows an empty teaser.
    The tournaments index has its own, larger version.
--}}
@php
    use App\Enums\TournamentStatus;
    use App\Models\Tournament;
    use App\Support\GameNames;
    use App\Support\PreSeason;
    use App\Support\Tournaments\Lobbies;

    $next = Tournament::query()->special()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
        ->when($game !== null, fn ($query) => $query->where('game', $game))
        ->orderBy('signup_closes_at')->first();
    $zone = PreSeason::timezoneFor(auth()->user());
@endphp

@if ($next !== null)
    <section aria-labelledby="{{ $headingId }}" {{ $attributes->class('flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6') }} data-test="next-tournament-teaser">
        <span class="flex items-baseline justify-between gap-3"><h2 id="{{ $headingId }}" class="m-0 text-[15px] font-bold">{{ __('Next tournament') }}</h2><a href="{{ route('tournaments.index') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('All tournaments') }}</a></span>
        <a href="{{ route('tournaments.show', $next) }}" class="flex flex-col gap-1 text-ink hover:text-ink">
            <b class="font-display text-lg leading-[1.25] break-words">{{ $next->name }}</b>
            <span class="text-xs text-ink-2">{{ GameNames::full($next->game, $next->mode) }} · {{ Lobbies::formatLabel($next) }}</span>
        </a>
        <p class="m-0 text-[13px] text-ink-2">{{ __('Sign-up closes :when', ['when' => $next->signup_closes_at->copy()->timezone($zone)->locale(app()->getLocale())->isoFormat('ddd HH:mm')]) }}</p>
        <x-button variant="quiet" :href="auth()->check() ? route('tournaments.signup', $next) : route('tournaments.show', $next)" class="self-start" data-test="next-tournament-register">{{ auth()->check() ? __('Register') : __('See the tournament') }}</x-button>
    </section>
@endif
