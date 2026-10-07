@props(['game', 'headingId' => 'next-empty-h', 'fluid' => false])

{{--
    A game page without a tournament open for sign-up: one compact row that
    says so and leads on, to every tournament and, for admins and
    organizers (gate `create-tournaments`), to a new one. The counterpart of
    <x-tournaments.next-card> at the same place on the page.

    A slot replaces the "All tournaments" button: the prize band (plan
    "RL-Startseite", P2) puts "Notify me of new <game> tournaments" there,
    with its own primary button to this game's tournaments right under it.
    `fluid`: text and actions stand side by side only when the enclosing
    container (the band, `@container`) is wide enough, not the window: beside
    the game chat the column is narrow on a wide window.
--}}
<section aria-labelledby="{{ $headingId }}" {{ $attributes->class($fluid ? 'flex flex-col gap-3 rounded-lg bg-card px-4 py-4 @2xl:flex-row @2xl:items-center @2xl:justify-between @2xl:gap-6 lg:px-6' : 'flex flex-col gap-3 rounded-lg bg-card px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6 lg:px-6') }} data-test="next-tournament-empty">
    <div class="flex min-w-0 flex-col gap-1">
        <h2 id="{{ $headingId }}" class="m-0 text-[15px] font-bold">{{ __('Next tournament') }}</h2>
        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('No :game tournament is open for sign-up right now.', ['game' => \App\Support\GameNames::game((string) $game)]) }}</p>
    </div>
    <div class="flex shrink-0 flex-wrap gap-2">
        @can('create-tournaments')
            <x-button :href="route('admin.tournaments.create')" data-test="next-tournament-create">{{ __('Create tournament') }}</x-button>
        @endcan
        @if ($slot->isEmpty())
            <x-button variant="quiet" :href="route('tournaments.index')" data-test="next-tournament-all">{{ __('All tournaments') }}</x-button>
        @else
            {{ $slot }}
        @endif
    </div>
</section>
