@props(['played' => [], 'unplayed' => [], 'count' => 0])

{{--
    The game hub ("All N games", concept B): every registered game as a cover
    tile with its own links, the viewer's games pinned first. A filter and an
    All / 1v1 / Clan vs clan toggle narrow the grid. From lg it drops below
    row 1 as a full-width panel; below lg it is a bottom sheet above the tab
    bar. One element for both, so both widths reach the same links (P16):
    tests/Support/navigation.php counts its links through its openers
    ([aria-controls=game-hub]).
--}}
@php
    $sections = array_values(array_filter([
        $played !== [] ? [__('Your games'), $played, true] : null,
        [$played !== [] ? __('All other games') : __('All games'), $unplayed, false],
    ], fn (?array $section): bool => $section !== null && $section[1] !== []));
    $kinds = ['all' => __('All'), 'solo' => __('1v1'), 'clan' => __('Clan vs clan')];
@endphp

<div x-show="hub" x-cloak class="lg:hidden" aria-hidden="true">
    <div class="fixed inset-0 z-40 bg-ground/70" x-on:click="closeHub()"></div>
</div>

<div id="game-hub" tabindex="-1" data-nav-panel role="dialog" aria-modal="false" aria-labelledby="game-hub-h" x-show="hub" x-cloak x-trap.noautofocus.noreturn="hub"
     x-on:click.outside="$event.target.closest('[aria-controls=game-hub]') || closeHub(false)"
     class="hub fixed inset-x-0 bottom-[var(--tabbar-h)] z-50 flex max-h-[calc(100svh-var(--tabbar-h)-4.5rem)] flex-col rounded-t-xl border-t border-line bg-bar lg:absolute lg:inset-x-6 lg:top-[60px] lg:bottom-auto lg:max-h-[min(36rem,calc(100svh-5.5rem))] lg:rounded-lg lg:border lg:bg-card lg:shadow-[0_16px_48px_rgba(0,0,0,.5)] xl:inset-x-8"
     data-test="game-hub">
    <h2 id="game-hub-h" class="sr-only">{{ trans_choice('All :count game|All :count games', $count) }}</h2>
    <span aria-hidden="true" class="mx-auto mt-2 h-1 w-10 shrink-0 rounded-xs bg-edge lg:hidden"></span>

    <div class="flex shrink-0 flex-wrap items-center gap-2 px-4 pt-3 pb-3 lg:gap-3 lg:px-6 lg:pt-4">
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

    <div class="min-h-0 overflow-y-auto overscroll-contain px-4 pb-5 lg:px-6 lg:pb-6" x-ref="hubTiles">
        @foreach ($sections as [$heading, $sectionGames, $pinned])
            <section x-show="sectionShows($el)" aria-label="{{ $heading }}" data-test="hub-section-{{ $pinned ? 'yours' : 'others' }}">
                <h3 class="m-0 pt-2 pb-2 text-xs font-normal text-ink-3">{{ $heading }}</h3>
                <ul class="m-0 grid list-none grid-cols-2 gap-x-3 gap-y-4 p-0 sm:grid-cols-3 lg:grid-cols-[repeat(auto-fill,minmax(11rem,1fr))]">
                    @foreach ($sectionGames as $game)
                        <li class="hub-tile flex min-w-0 flex-col" style="--game: {{ $game['colour'] }}" data-name="{{ mb_strtolower($game['name'].' '.$game['short'].' '.$game['slug']) }}" data-kinds="{{ implode(' ', $game['kinds']) }}"
                            x-show="shows($el)" data-test="hub-game-{{ $game['slug'] }}">
                            <a href="{{ $game['page'] }}" class="hub-tile-main" data-test="games-menu-{{ $game['slug'] }}">
                                <x-game-cover :game="$game['slug']" size="card" class="w-full rounded-t-sm" />
                                <b class="mt-2 block truncate text-sm font-bold text-ink">{{ $game['name'] }}</b>
                                <small class="block text-xs leading-snug text-ink-3">{{ $game['formats'] }}</small>
                            </a>
                            <ul class="m-0 mt-1 flex list-none flex-col p-0">
                                @foreach ($game['actions'] as $link)
                                    @continue($link['key'] === 'play' && $link['href'] === $game['page'])
                                    <li><a href="{{ $link['href'] }}" class="hub-action" @if ($link['test']) data-test="{{ $link['test'] }}" @endif>{{ $link['label'] }}</a></li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        <p x-show="! anyShown()" x-cloak class="m-0 py-6 text-sm text-ink-2" data-test="hub-empty">{{ __('No game matches this filter.') }}</p>
    </div>
</div>
