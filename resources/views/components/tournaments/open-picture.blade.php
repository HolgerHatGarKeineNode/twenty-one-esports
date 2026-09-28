@props(['cta' => null])

{{--
    The picture of an empty "tournaments" state (P53: "zu textlich. BILDER!!!!"):
    the next tournament open for sign-up, as its game's cover with its name and
    start, a special one before a casual cup. Real data only: with nothing open
    it renders nothing, and the caller's sentence stands alone.
    `cta`: the one button's label, to every tournament; none without it.
--}}
@php
    use App\Enums\TournamentStatus;
    use App\Models\Tournament;

    $openNext = Tournament::query()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
        ->orderByRaw('case when cup_series is null then 0 else 1 end')->orderBy('starts_at')->first();
@endphp

@if ($openNext !== null)
    <a href="{{ route('tournaments.show', $openNext) }}" {{ $attributes->class('flex min-w-0 items-center gap-3 rounded-md bg-well p-2 text-ink-2 hover:bg-row-hover hover:text-ink-2') }} data-test="open-picture">
        <x-game-cover :game="$openNext->game" size="thumb" class="w-20 rounded-xs" data-test="open-picture-cover" />
        <span class="flex min-w-0 flex-col gap-0.5 text-xs leading-tight">
            <span>{{ __('Open for sign-up') }}</span>
            <b class="truncate text-[13px] text-ink">{{ $openNext->name }}</b>
            <x-league-time :at="$openNext->starts_at" class="truncate" />
        </span>
    </a>
@endif
@if ($cta !== null)
    <x-button variant="quiet" :href="route('tournaments.index')" class="self-start" data-test="open-picture-cta">{{ $cta }}</x-button>
@endif
