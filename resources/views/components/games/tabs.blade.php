@props(['game', 'potSats' => null, 'casual' => false])

{{--
    The game page's sections as tabs (plan "RL-Startseite", P3; artboard
    "Gewählt · Mischung"). "Tournaments & prizes" stands out in the league
    orange and leads to this game's tournaments (`/tournaments?game=`), with
    the prizes won so far as a chip; the others jump to the page's own
    sections. They wrap instead of scrolling sideways, so no label is cut.
    Not on a phone: there the start tiles right under them lead to the same
    places, and two rows of tabs pushed the Tournaments tile out of a
    375 × 667 first screen (InvitePlacementTest).

    `potSats`: the prizes won in this game (GameLanding::paidSats), no chip
    when 0. `casual`: the game offers the casual 1v1 (CasualLobby::offers).
--}}
@php
    $tab = 'inline-flex min-h-11 items-center gap-2 rounded-md px-3 text-[13px] font-bold whitespace-nowrap';
    $sections = array_filter([
        ['#game-matches', __('Matches')],
        $casual ? ['#casual', __('Play 1v1')] : null,
        ['#game-ladder', __('Ladder')],
        ['#game-clans', __('Clans')],
    ]);
@endphp

<nav aria-label="{{ __('Sections of the page') }}" {{ $attributes->class('max-sm:hidden') }} data-test="game-sections">
    <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
        <li class="flex">
            <a href="{{ route('tournaments.index', ['game' => $game]) }}" class="{{ $tab }} bg-btc text-on-btc hover:text-on-btc" data-test="section-tab-tournaments">
                <x-icon name="trophy" :size="16" />
                {{ __('Tournaments & prizes') }}
                @if (($potSats ?? 0) > 0)
                    <span class="rounded-xs bg-on-btc/15 px-1.5 text-xs tabular-nums" data-test="section-tab-pot">{{ \App\Support\Cards\ShareCard::sats((int) $potSats) }} sats</span>
                @endif
            </a>
        </li>
        @foreach ($sections as [$href, $label])
            <li class="flex">
                <a href="{{ $href }}" class="{{ $tab }} bg-card text-ink shadow-ring hover:bg-row-hover hover:text-ink" data-test="section-tab">{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
