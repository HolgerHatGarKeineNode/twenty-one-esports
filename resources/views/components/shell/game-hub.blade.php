@props(['played' => [], 'unplayed' => [], 'count' => 0])

{{--
    The game hub ("All N games", concept B): every registered game as one card
    in one grid, the viewer's games first and marked "Yours". A card is the
    cover (16:9), name and modes on one line, then the game's links in two
    columns, its primary one (Play blitz, Challenge a clan) first. Cards of a
    row share their height. A filter and an All / 1v1 / Clan vs clan toggle
    narrow the grid; they sit outside the scrolling list, so they stay put.
    From lg it drops below row 1 as a full-width panel that is as tall as its
    cards (4 columns from xl: 4 games are one row, 8 are two), below lg it is
    a bottom sheet above the tab bar. One element for both, so both widths
    reach the same links (P16): tests/Support/navigation.php counts its links
    through its openers ([aria-controls=game-hub]).
--}}
@php
    $games = [...$played, ...$unplayed];
    $kinds = ['all' => __('All'), 'solo' => __('1v1'), 'clan' => __('Clan vs clan')];
    // The card's links: the page's own "Overview" is the cover link already; the primary action leads.
    $links = function (array $game): array {
        $links = array_values(array_filter($game['actions'], fn (array $link): bool => ! ($link['key'] === 'play' && $link['href'] === $game['page'] && $link['label'] === __('Overview'))));
        $primary = array_key_first(array_filter($links, fn (array $link): bool => $link['key'] === 'play')) ?? array_key_first(array_filter($links, fn (array $link): bool => $link['key'] === 'challenge'));

        return $primary === null ? $links : [[...$links[$primary], 'primary' => true], ...array_values(array_diff_key($links, [$primary => true]))];
    };
@endphp

<div x-show="hub" x-cloak class="lg:hidden" aria-hidden="true">
    <div class="fixed inset-0 z-40 bg-ground/70" x-on:click="closeHub()"></div>
</div>

<div id="game-hub" tabindex="-1" data-nav-panel role="dialog" aria-modal="false" aria-labelledby="game-hub-h" x-show="hub" x-cloak x-trap.noautofocus.noreturn="hub"
     x-on:click.outside="$event.target.closest('[aria-controls=game-hub]') || closeHub(false)"
     class="hub fixed inset-x-0 bottom-[var(--tabbar-h)] z-50 flex max-h-[calc(100svh-var(--tabbar-h)-4.5rem)] flex-col rounded-t-xl border-t border-line bg-bar lg:absolute lg:inset-x-6 lg:top-[60px] lg:bottom-auto lg:max-h-[calc(100svh-5.5rem)] lg:rounded-lg lg:border lg:bg-card lg:shadow-[0_16px_48px_rgba(0,0,0,.5)] xl:inset-x-8"
     data-test="game-hub">
    <h2 id="game-hub-h" class="sr-only">{{ trans_choice('All :count game|All :count games', $count) }}</h2>
    <span aria-hidden="true" class="mx-auto mt-2 h-1 w-10 shrink-0 rounded-xs bg-edge lg:hidden"></span>

    <div class="flex shrink-0 flex-wrap items-center gap-2 px-4 pt-3 pb-3 lg:gap-3 lg:px-6 lg:pt-4 lg:pb-4">
        <label for="hub-filter" class="sr-only">{{ __('Filter games') }}</label>
        <span class="relative block min-w-0 grow basis-60 lg:max-w-80">
            <x-icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
            <input id="hub-filter" type="search" x-ref="hubFilter" x-model="filter" autocomplete="off" placeholder="{{ trans_choice('Filter :count game|Filter :count games', $count) }}"
                   class="h-11 w-full rounded-md border border-edge bg-ground pr-3 pl-9 text-sm text-ink placeholder:text-ink-3" data-test="hub-filter">
        </span>
        <div role="group" aria-label="{{ __('Game type') }}" class="flex gap-1">
            @foreach ($kinds as $key => $label)
                <button type="button" class="hub-seg" x-on:click="kind = @js($key)" x-bind:aria-pressed="(kind === @js($key)).toString()" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}" data-test="hub-kind-{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>
        <a href="{{ route('play') }}" class="flex min-h-11 items-center text-[13px] font-bold lg:ml-auto" data-test="hub-all-games">{{ __('All games and modes') }}</a>
    </div>

    <div class="min-h-0 overflow-y-auto overscroll-contain px-4 pb-4 lg:px-6 lg:pb-6" x-ref="hubTiles">
        <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 lg:grid-cols-3 lg:gap-4 xl:grid-cols-4" aria-label="{{ trans_choice('All :count game|All :count games', $count) }}">
            @foreach ($games as $game)
                <li class="hub-card" style="--game: {{ $game['colour'] }}" data-name="{{ mb_strtolower($game['name'].' '.$game['short'].' '.$game['slug']) }}" data-kinds="{{ implode(' ', $game['kinds']) }}"
                    x-show="shows($el)" data-test="hub-game-{{ $game['slug'] }}">
                    <a href="{{ $game['page'] }}" class="hub-tile-main" data-test="games-menu-{{ $game['slug'] }}">
                        <span class="hub-cover">
                            <x-game-cover :game="$game['slug']" size="card" class="w-full rounded-t-sm" />
                            @if ($game['played'])
                                <span class="hub-yours" data-test="hub-yours">{{ __('Yours') }}</span>
                            @endif
                        </span>
                        <span class="flex min-w-0 items-baseline gap-2 max-sm:flex-col max-sm:gap-0.5 sm:mt-2">
                            <b class="shrink-0 truncate text-sm font-bold text-ink max-sm:max-w-full">{{ $game['name'] }}</b>
                            <small class="min-w-0 truncate text-xs text-ink-3 max-sm:max-w-full">{{ $game['formats'] }}</small>
                        </span>
                    </a>
                    @php($cardLinks = $links($game))
                    @if ($cardLinks !== [])
                        {{-- The primary link spans the card's width; the others share two columns and wrap rather than truncate ("Spieler herausfordern" is wider than half a card). --}}
                        <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                            @foreach ($cardLinks as $link)
                                <li @class(['flex min-w-0', 'col-span-2' => $link['primary'] ?? false])><a href="{{ $link['href'] }}" @class(['hub-action', 'hub-action-primary' => $link['primary'] ?? false]) @if ($link['test']) data-test="{{ $link['test'] }}" @endif><x-icon :name="$link['icon']" :size="14" class="shrink-0" /><span class="min-w-0">{{ $link['label'] }}</span></a></li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
        <p x-show="! anyShown()" x-cloak class="m-0 py-6 text-sm text-ink-2" data-test="hub-empty">{{ __('No game matches this filter.') }}</p>
    </div>
</div>
