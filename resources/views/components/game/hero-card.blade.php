@props(['game', 'title' => null, 'lede' => null, 'stat' => null, 'statLabel' => null, 'statLive' => false])

{{--
    The default hero card of the lobby template (x-game.page, T1; GameLobby.dc.html): the cover, the page's h1 with a
    community game's credit, one data line, and on the right one big number, or the default slot (an action,
    HyperIndex.dc.html "Gegen Bots spielen") in its place.
--}}
<section class="gp-hero" data-test="game-hero">
    <x-game-cover :game="$game" size="card" class="gp-hero-cover" loading="eager" />
    <div class="min-w-0">
        <div data-test="lobby-title">
            <h1 class="gp-title">{{ \App\Support\GameNames::breakable($title ?? \App\Support\GameNames::game((string) $game)) }}</h1>
            <x-game-credit :game="$game" class="mt-1" />
        </div>
        @if ($lede)
            <p class="gp-lede" data-test="game-lede">{{ $lede }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex justify-start pr-3 sm:justify-end">{{ $slot }}</div>
    @elseif ($stat !== null)
        <div class="gp-stat" data-test="game-stat">
            <b class="flex items-center gap-2">@if ($statLive)<span class="size-2 rounded-full bg-live" aria-hidden="true"></span>@endif{{ $stat }}</b>
            <span>{{ $statLabel }}</span>
        </div>
    @endif
</section>
