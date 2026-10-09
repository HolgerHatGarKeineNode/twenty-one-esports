@props([
    'finished' => [],
    'running' => [],
    'size' => 'auto',
    'focus' => null,
    'legend' => true,
    'title' => null,
    'lead' => null,
    'chainLive' => true,
])

{{--
    The mempool strip, grown out of BlockStrip.dc.html (approved 2026-09-25).
    A read-only feed of MATCHES across all games, not blocks: finished on the
    left (newest next to the divider), running and scheduled on the right.
    The season chain is the one blockchain (/mining); a finished rated match
    that mined carries its block under its cube ("Block 812", linked there),
    the one orange text of the strip. The match number (#40) sits quiet above
    the cube, so it never reads as a block height. When the row is wider than
    the screen it opens on the divider. Every cube shows its game by colour
    AND logo and links to its match; the sides show faces (players) or clan
    logos. The legend lists only the games on screen, in the registry's order.

    Cubes: App\Support\Matches\MatchBlocks::shape().
    size:  md (120 px cubes), sm (100 px, mobile), auto (sm below 1024 px, md above)
    focus: a colour family (chess|rl|fc|morris|checkers) marks that game's cubes on a game page; nothing is hidden
    title, lead: a visible name and one line of explanation above the strip
    chainLive: whether a season runs now; the legend promises mining only then
    chain stamp (App\Support\Matches\ChainStamps): the block, and under it a
    second line for what it must not lose (void, or an older season's name);
    it links only to a block /mining shows.
--}}
@php
    use App\Support\GameNames;
    $knight = '<svg class="bs-logo" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M17 18C17.5 12 17 6.5 12.5 4L11.5 2L10 4.2C8 5.2 6 7.8 4.6 10.2C4.3 10.9 4.7 11.7 5.4 11.9L6.4 12.3C7.1 12.5 7.9 12.2 8.3 11.6L9.6 10.6C10.3 10.3 10.8 10.4 11.2 10.8C9.4 12.8 8 15 7.6 18ZM9.9 6.2a.9 .9 0 1 0 .01 0ZM5 19.5h14V22H5z"></path></svg>';
    $groups = [['fin', $finished], ['run', $running]];
    $all = [...$finished, ...$running];
    $chainRow = collect($all)->contains(fn (array $block): bool => ($block['chain'] ?? null) !== null);
    // The legend names the games on screen in the registry's display order (Nine Men's Morris and Checkers last, user 2026-10-03); a game no longer registered after them.
    $registryOrder = array_flip(array_keys(app(\App\Games\GameRegistry::class)->all()));
    $games = collect($all)->unique('slug')->map(fn (array $block): array => ['slug' => $block['slug'], 'game' => $block['game'], 'icon' => $block['icon']])
        ->sortBy(fn (array $game, int $index): array => [$registryOrder[$game['slug']] ?? PHP_INT_MAX, $index])->values();
    $titleId = $title ? 'bs-title-'.substr(md5((string) $title), 0, 6) : null;
@endphp

<section {{ $attributes->class(['bs', 'bs--sm' => $size === 'sm', 'bs--auto' => $size === 'auto', 'bs--focus-'.$focus => $focus]) }}
         @if ($titleId) aria-labelledby="{{ $titleId }}" @else aria-label="{{ __('Matches of every game, finished on the left, running and next on the right') }}" @endif
         data-test="block-strip">
    @if ($title)
        <div class="bs-head bs-pad w-full">
            <p id="{{ $titleId }}" class="bs-title">{{ $title }}</p>
            @if ($lead)
                <p class="bs-lead">{{ $lead }}</p>
            @endif
        </div>
    @endif

    <div class="bs-scroll w-full" x-data x-init="$nextTick(() => { if ($el.scrollWidth > $el.clientWidth) { const newest = $el.querySelector('.bs-grp--fin > .bs-col:last-child'); if (newest) { $el.scrollLeft = newest.offsetLeft - parseFloat(getComputedStyle($el.querySelector('.bs-pad')).paddingLeft); } } })">
        <div class="bs-row bs-pad mx-auto">
            @foreach ($groups as [$group, $blocks])
                @if ($group === 'run')
                    <span class="bs-div" aria-hidden="true"></span>
                @endif
                <div @class(['bs-grp', 'bs-grp--fin' => $group === 'fin'])>
                    @foreach ($blocks as $block)
                        <div @class(['bs-col', 'is-newest' => $block['newest']]) data-test="strip-cube" data-game="{{ $block['slug'] }}" data-state="{{ $block['state'] }}">
                            <span class="bs-h">
                                <span class="bs-num" data-test="strip-number">{{ $block['number'] }}</span>
                                @if ($block['casual'])
                                    <span class="bs-tag">{{ __('casual') }}</span>
                                @endif
                            </span>
                            <a href="{{ $block['href'] }}" @if ($block['blank'] ?? false) target="_blank" @endif
                               @class(['bs-cube', 'g-'.$block['game'], 'is-'.$block['state'], 'is-casual' => $block['casual'], 'is-mined' => ($block['chain']['state'] ?? null) === 'mined'])
                               style="--lvl: {{ $block['level'] }}"
                               aria-label="{{ $block['aria'] }}">
                                @if ($block['state'] === 'live')
                                    <span class="bs-fill" aria-hidden="true"></span>
                                @endif
                                {{-- The game by name first (user, 2026-10-08: the mini icon and the mode did not say which game it was). The names
                                     under the cube already show who played, so a finished or next cube's third line is the mode and its last the time;
                                     a running one keeps whose turn it is and puts the mode beside its live dot (the fill says it runs). --}}
                                @php($running = $block['state'] === 'live')
                                <span class="bs-r1" data-test="strip-game-name">{{ GameNames::cube($block['slug']) }}</span>
                                <span @class(['bs-score', 'bs-score--word' => $block['word']])>{{ $block['score'] }}</span>
                                @if ($running)
                                    <span class="bs-who">{{ $block['who'] }}</span>
                                @else
                                    <span class="bs-who bs-mode" data-test="strip-mode">{{ $block['mode'] }}</span>
                                @endif
                                <span class="bs-when">
                                    @if ($block['dot'])
                                        <span class="bs-dot" aria-hidden="true"></span>
                                    @endif
                                    @if ($running)<span class="bs-mode" data-test="strip-mode">{{ $block['mode'] }}</span>@else{{ $block['when'] }}@endif
                                </span>
                            </a>
                            <span class="bs-cap" aria-hidden="true">
                                @foreach ($block['sides'] as $index => $side)
                                    <span @class(['bs-side', 'is-won' => $side['won']])>
                                        @if ($index === 1)<span class="bs-vs">vs</span>@endif
                                        @if ($side['user'] ?? null)
                                            <x-avatar :user="$side['user']" :size="14" class="bs-face rounded-[3px]" />
                                        @elseif ($side['clan']?->localLogoUrl())
                                            <img src="{{ $side['clan']->localLogoUrl() }}" alt="" width="14" height="14" loading="lazy" decoding="async" class="bs-face rounded-[3px] bg-card object-cover">
                                        @endif
                                        <span class="bs-name">{{ $side['name'] }}</span>
                                    </span>
                                @endforeach
                            </span>
                            @if ($chainRow)
                                <span class="bs-chain">
                                    @if ($block['chain'] !== null && $block['chain']['state'] !== 'none')
                                        @if ($block['chain']['href'] !== null)
                                            <a href="{{ $block['chain']['href'] }}" @class(['bs-stamp', 'is-void' => $block['chain']['state'] === 'void']) title="{{ $block['chain']['title'] }}" data-test="strip-block">
                                                <span class="bs-mini" aria-hidden="true"></span><span class="bs-stamp-text">{{ $block['chain']['text'] }}</span>@if ($block['chain']['note'] !== null)<span class="sr-only">{{ ' '.$block['chain']['note'] }}</span>@endif
                                            </a>
                                        @else
                                            <span @class(['bs-stamp is-elsewhere', 'is-void' => $block['chain']['state'] === 'void']) title="{{ $block['chain']['title'] }}" data-test="strip-block">
                                                <span class="bs-mini" aria-hidden="true"></span><span class="bs-stamp-text">{{ $block['chain']['text'] }}</span>@if ($block['chain']['note'] !== null)<span class="sr-only">{{ ' '.$block['chain']['note'] }}</span>@endif
                                            </span>
                                        @endif
                                        @if ($block['chain']['note'] !== null)
                                            <span @class(['bs-note', 'is-void' => $block['chain']['state'] === 'void']) aria-hidden="true" data-test="strip-block-note">{{ $block['chain']['note'] }}</span>
                                        @endif
                                    @elseif ($block['chain'] !== null)
                                        <span class="bs-stamp is-none" title="{{ $block['chain']['title'] }}" data-test="strip-no-block"><span class="bs-stamp-text">{{ $block['chain']['text'] }}</span><span class="sr-only">{{ ': '.$block['chain']['reason'] }}</span></span>
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    @if ($legend)
        <div class="bs-legend-wrap w-full">
            <div class="bs-legend" data-test="strip-legend">
                @foreach ($games as $game)
                    <span @class(['bs-key', 'g-'.$game['game']]) data-test="strip-legend-game" data-game="{{ $game['slug'] }}"><span class="bs-chip bs-chip--game" aria-hidden="true"></span><span class="bs-key-logo">@if ($game['game'] === 'chess'){!! $knight !!}@else<x-icon :name="$game['icon']" :size="16" class="bs-logo" />@endif</span>{{ \App\Support\GameNames::game($game['slug']) }}</span>
                @endforeach
                <span class="bs-sep bs-key--state" aria-hidden="true"></span>
                <span class="bs-key bs-key--state"><span class="bs-chip bg-ink-2" aria-hidden="true"></span>{{ __('Finished') }}</span>
                <span class="bs-key bs-key--state"><span class="bs-chip" aria-hidden="true" style="border: 1px solid #ADADB0; background: linear-gradient(0deg, #ADADB0 50%, transparent 50%)"></span>{{ __('Playing, fills up as it goes') }}</span>
                <span class="bs-key bs-key--state"><span class="bs-chip" aria-hidden="true" style="border: 2px solid #ADADB0"></span>{{ __('Up next') }}</span>
                <span class="bs-key bs-key--state"><span class="bs-tag">{{ __('casual') }}</span>{{ __('Unrated') }}</span>
                <a href="{{ route('mining') }}" class="bs-key bs-key--chain" data-test="strip-legend-chain"><span class="bs-mini" aria-hidden="true"></span>{{ $chainLive ? __('Rated wins mine a block of the season chain') : __('Season chain') }}</a>
            </div>
        </div>
    @endif
</section>
