@props(['banner' => null])

{{--
    The top of a tournament game's page (chess, board games, the series room),
    live and after the end (App\Support\Tournaments\TournamentGameEnd::banner();
    user, 2026-10-03: "groß fett rein, oben, dass es sich um ein Turnierspiel
    handelt"): the trophy, the tournament, its round and the game of a duel,
    what is at stake, the tournament desk for its players and direction, and
    the way to the tournament page. Nothing for any other game.
--}}
@if ($banner)
    <section aria-label="{{ $banner['cup'] ? __('Cup game') : __('Tournament game') }}"
             {{ $attributes->class('flex flex-wrap items-center gap-x-4 gap-y-3 rounded-lg bg-btc-tint px-4 py-3 shadow-[inset_0_0_0_2px_var(--color-btc)] lg:px-6 lg:py-4') }} data-test="tournament-banner">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-btc text-on-btc lg:size-12" aria-hidden="true"><x-icon name="trophy" :size="24" /></span>
        <span class="flex min-w-0 grow basis-56 flex-col gap-0.5">
            <span class="font-display text-lg leading-tight font-extrabold [overflow-wrap:anywhere] lg:text-2xl" data-test="tournament-banner-name">{{ $banner['tournament'] }}</span>
            <span class="flex flex-wrap items-center gap-x-2 text-sm font-bold text-btc-hi">
                <span data-test="tournament-banner-round">{{ $banner['round'] }}</span>
                @if ($banner['step'])
                    <span aria-hidden="true">·</span><span data-test="tournament-banner-step">{{ $banner['step'] }}</span>
                @endif
            </span>
            <span class="text-[13px] font-bold text-ink" data-test="tournament-banner-stake">{{ $banner['stake'] }}</span>
        </span>
        @php($desk = isset($banner['tournamentId']) && ($deskTournament = \App\Models\Tournament::query()->find($banner['tournamentId'])) !== null ? \App\Support\Tournaments\TournamentDesk::for($deskTournament, auth()->user()) : null)
        @if ($desk)
            {{-- The tournament desk (TournamentDesk), for the players and the direction only: a problem with this game goes there. --}}
            <x-tournaments.desk-button :desk="$desk" class="max-sm:w-full" />
        @endif
        <x-button :href="$banner['url']" icon="trophy" class="shrink-0 max-sm:w-full" data-test="tournament-banner-link">{{ __('Tournament page') }}</x-button>
    </section>
@endif
