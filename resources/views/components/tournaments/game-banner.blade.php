@props(['banner' => null])

{{--
    The top of a tournament game's page (chess, board games, the series room),
    live and after the end (App\Support\Tournaments\TournamentGameEnd::banner();
    user, 2026-10-03: "groß fett rein, oben, dass es sich um ein Turnierspiel
    handelt"): the trophy, the tournament, its round and the game of a duel,
    what is at stake, the tournament desk for its players and direction, and
    the way to the tournament page. Nothing for any other game.
    Below `lg` it goes under the game, the last item of its flex column (DerCaddy, 2026-10-14: "Im Turnier Modus mobile
    ist das Spielbrett mega klein"; user: "Das Turnier-Banner darf auf keinen Fall in mobile stören und muss am besten wo
    anders hin"): the board fits the first screen, and above it the banner took 222 px of it (board 203 px wide at
    390x844). On a phone it is one row: the trophy, the stake and the button labels give way, the buttons keep 44 px
    and their names for screen readers. Wherever it stands, the shell header carries a chip of it on a phone while no
    banner of the page is in view (people, 2026-10-15: a small mark always at the top that this is a tournament match). A tap on
    the chip, or on a board game's line, opens the whole banner over the page (game-banner-panel; DerCaddy, 2026-10-15).
--}}
@if ($banner)
    {{-- The shell header shows the tournament as a chip on a phone, always at the top (components/shell/header). --}}
    @php(request()->attributes->set('tournament-banner', $banner))
    <section data-tournament-inline aria-label="{{ $banner['cup'] ? __('Cup game') : __('Tournament game') }}"
             {{ $attributes->class('flex items-center gap-x-4 gap-y-3 rounded-lg bg-btc-tint px-4 py-3 shadow-[inset_0_0_0_2px_var(--color-btc)] max-lg:order-last max-lg:mt-4 max-lg:mb-0 max-sm:gap-x-2 max-sm:py-2 max-sm:pr-2 sm:flex-wrap lg:px-6 lg:py-4') }} data-test="tournament-banner">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-btc text-on-btc max-sm:hidden lg:size-12" aria-hidden="true"><x-icon name="trophy" :size="24" /></span>
        <span class="flex min-w-0 grow basis-0 flex-col gap-0.5 sm:basis-56">
            <span class="font-display text-lg leading-tight font-extrabold [overflow-wrap:anywhere] max-sm:truncate max-sm:text-base lg:text-2xl" data-test="tournament-banner-name">{{ $banner['tournament'] }}</span>
            <span class="flex flex-wrap items-center gap-x-2 text-sm font-bold text-btc-hi">
                <span data-test="tournament-banner-round">{{ $banner['round'] }}</span>
                @if ($banner['step'])
                    <span aria-hidden="true">·</span><span data-test="tournament-banner-step">{{ $banner['step'] }}</span>
                @endif
            </span>
            <span class="text-[13px] font-bold text-ink max-sm:hidden" data-test="tournament-banner-stake">{{ $banner['stake'] }}</span>
            {{-- The other games of the pairing (DerCaddy 2026-10-30); on a phone they are in the panel the line opens. --}}
            <x-tournaments.game-switch :games="$banner['games'] ?? []" class="mt-1.5 max-lg:hidden" />
        </span>
        @php($desk = isset($banner['tournamentId']) && ($deskTournament = \App\Models\Tournament::query()->find($banner['tournamentId'])) !== null ? \App\Support\Tournaments\TournamentDesk::for($deskTournament, auth()->user()) : null)
        @if ($desk)
            {{-- The tournament desk (TournamentDesk), for the players and the direction only: a problem with this game goes there. --}}
            <x-tournaments.desk-button :desk="$desk" compact class="max-sm:w-11 max-sm:px-0" />
        @endif
        <x-button :href="$banner['url']" icon="trophy" class="shrink-0 max-sm:w-11 max-sm:px-0" :title="__('Tournament page')" data-test="tournament-banner-link"><span class="max-sm:sr-only">{{ __('Tournament page') }}</span></x-button>
    </section>
    {{-- On a phone the one-line forms of this banner (the header's chip, a board game's title row) open it whole over the page. --}}
    <x-tournaments.game-banner-panel :banner="$banner" />
@endif
