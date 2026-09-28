@props(['game' => null, 'except' => null, 'filters' => false, 'heading' => false])

{{--
    The league's casual cups (P25) as a board (P53): open for sign-up or
    running, grouped by game with the game's cover as the anchor, each game's
    EU and US cup as a pair (user, 2026-09-28: separate EU and US cups). A row
    is the region, the start as clock, day and city, the places as a seat bar
    and the status as an icon and a word (user, 2026-09-28: "zu textlich.
    BILDER!!!!" and "Die Casual Cups liegen da so lose durcheinander rum").

    The start is in the viewer's own zone when signed in with one, else in
    the cup's region zone named by its city; the browser rewrites it to its
    own zone unless it reports a spoofed one (App\Support\Tournaments\CupBoard,
    cupStart in resources/js/tournamentLanding.js).

    Still a side mention, never a poster or a hero: those belong to the
    special tournaments (user, 2026-09-28: "Casual Cups sollen nicht so einen
    präsenten Platz bekommen und eher Seitenerwähnungen bleiben").

    `game`: one game's cups only, without covers (the page is that game's).
    `except`: a tournament id to leave out (the cup whose page this is).
    `heading`: a "Casual cups" heading (home and the tournaments page); without
    the filter bar it links to the tournaments page, where the filters are.
    `filters`: the filter bar (game, region, free places, order), without a
    reload; only where every game's cups show (the tournaments page).
    Renders nothing when no cup is on.
--}}
@php
    use App\Enums\TournamentStatus;
    use App\Support\GameNames;
    use App\Support\Tournaments\CasualCups;
    use App\Support\Tournaments\CupBoard;

    $cupGroups = app(CupBoard::class)->groups($game, $except, auth()->user()?->timezone);
    $cupSingle = $game !== null;
    $cupFilters = $filters && ! $cupSingle && $cupGroups !== [];
    $cupRegions = CasualCups::regions();
    $cupCount = array_sum(array_map(fn (array $group): int => count($group['cups']), $cupGroups));
    $segBtn = 'inline-flex h-11 shrink-0 cursor-pointer items-center gap-2 border-0 px-3 text-[13px] whitespace-nowrap';
@endphp

@if ($cupGroups !== [])
    <div {{ $attributes->class('flex flex-col gap-3') }} data-test="cup-mentions"
         @if ($cupFilters) x-data="cupBoard()" x-effect="apply()" wire:ignore @endif
         @if ($heading) role="region" aria-labelledby="cup-board-h" id="casual-cups" @endif>
        @if ($heading)
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="cup-board-h" class="m-0 text-[15px] font-bold">{{ __('Casual cups') }}</h2>
                @unless ($cupFilters)
                    <a href="{{ route('tournaments.index') }}#casual-cups" class="inline-flex min-h-11 items-center text-xs text-btc-hi hover:text-btc" data-test="cup-board-all">{{ __('Filter and sort the cups') }}</a>
                @endunless
            </div>
        @endif
        @if ($cupFilters)
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2" role="group" aria-label="{{ __('Filter the casual cups') }}" data-test="cup-filters">
                <div class="flex items-center gap-2">
                    <label for="cup-game-select" id="cup-game" class="text-xs text-ink-2">{{ __('Game title') }}</label>
                    {{-- Below sm a select (five buttons do not fit a phone), from sm the buttons with each game's cover. --}}
                    <select id="cup-game-select" x-model="game" class="h-11 w-[184px] rounded-md border border-edge bg-ground px-3 text-[13px] text-ink sm:hidden" data-test="cup-filter-game-select">
                        <option value="all">{{ __('All games') }}</option>
                        @foreach ($cupGroups as $group)
                            <option value="{{ $group['game'] }}">{{ GameNames::game($group['game']) }}</option>
                        @endforeach
                    </select>
                    <div role="group" aria-labelledby="cup-game" class="flex max-w-full overflow-hidden rounded-md border border-line max-sm:hidden">
                        <button type="button" x-on:click="game = 'all'" x-bind:aria-pressed="game === 'all'" aria-pressed="true" data-test="cup-filter-game-all"
                                class="{{ $segBtn }}" x-bind:class="game === 'all' ? 'bg-btc font-bold text-on-btc' : 'bg-ground text-ink-2 hover:text-ink'">{{ __('All') }}</button>
                        @foreach ($cupGroups as $group)
                            <button type="button" x-on:click="game = @js($group['game'])" x-bind:aria-pressed="game === @js($group['game'])" aria-pressed="false" data-test="cup-filter-game-{{ $group['game'] }}"
                                    class="{{ $segBtn }} border-l border-line" x-bind:class="game === @js($group['game']) ? 'bg-btc font-bold text-on-btc' : 'bg-ground text-ink-2 hover:text-ink'">
                                <x-game-cover :game="$group['game']" size="thumb" class="w-8 rounded-xs" />
                                <span class="xl:hidden">{{ __(app(\App\Games\GameRegistry::class)->find($group['game'])?->assets()->shortLabel ?? GameNames::game($group['game'])) }}</span><span class="max-xl:hidden">{{ GameNames::game($group['game']) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span id="cup-region" class="text-xs text-ink-2">{{ __('Region') }}</span>
                    <div role="group" aria-labelledby="cup-region" class="flex overflow-hidden rounded-md border border-line">
                        <button type="button" x-on:click="region = 'all'" x-bind:aria-pressed="region === 'all'" aria-pressed="true" data-test="cup-filter-region-all"
                                class="{{ $segBtn }}" x-bind:class="region === 'all' ? 'bg-btc font-bold text-on-btc' : 'bg-ground text-ink-2 hover:text-ink'">{{ __('All') }}</button>
                        @foreach ($cupRegions as $regionKey => $region)
                            <button type="button" x-on:click="region = @js($regionKey)" x-bind:aria-pressed="region === @js($regionKey)" aria-pressed="false" data-test="cup-filter-region-{{ $regionKey }}"
                                    class="{{ $segBtn }} border-l border-line" x-bind:class="region === @js($regionKey) ? 'bg-btc font-bold text-on-btc' : 'bg-ground text-ink-2 hover:text-ink'">{{ $region['label'] }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span id="cup-order" class="text-xs text-ink-2">{{ __('Order') }}</span>
                    <div role="group" aria-labelledby="cup-order" class="flex overflow-hidden rounded-md border border-line">
                        @foreach (['game' => __('By game'), 'start' => __('By start')] as $sortKey => $sortLabel)
                            <button type="button" x-on:click="sort = @js($sortKey)" x-bind:aria-pressed="sort === @js($sortKey)" aria-pressed="{{ $sortKey === 'game' ? 'true' : 'false' }}" data-test="cup-sort-{{ $sortKey }}"
                                    @class([$segBtn, 'border-l border-line' => ! $loop->first]) x-bind:class="sort === @js($sortKey) ? 'bg-btc font-bold text-on-btc' : 'bg-ground text-ink-2 hover:text-ink'">{{ $sortLabel }}</button>
                        @endforeach
                    </div>
                </div>
                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-[13px] text-ink-2 hover:text-ink">
                    <input type="checkbox" x-model="freeOnly" class="size-4 accent-btc" data-test="cup-filter-free">
                    {{ __('Free places only') }}
                </label>
                <p class="m-0 text-xs text-ink-2 tabular-nums lg:ml-auto" aria-live="polite" data-test="cup-filter-count">
                    <span x-text="shown === 1 ? @js(__('1 cup')) : @js(__(':count cups')).replace(':count', shown)">{{ $cupCount === 1 ? __('1 cup') : __(':count cups', ['count' => $cupCount]) }}</span>
                </p>
            </div>
        @endif

        <ul @if ($cupFilters) x-ref="groups" @endif aria-label="{{ __('Casual cups') }}"
            @class(['m-0 grid list-none gap-2 p-0', 'lg:grid-cols-2' => ! $cupSingle])>
            @foreach ($cupGroups as $group)
                @php
                    $groupGame = $group['game'];
                    $groupMode = GameNames::mode($groupGame, $group['cups'][0]['tournament']->mode);
                @endphp
                <li wire:key="cup-group-{{ $groupGame }}" data-cup-group data-order="{{ $loop->index }}" data-test="cup-group" data-game="{{ $groupGame }}"
                    @class(['min-w-0', 'grid grid-cols-[96px_minmax(0,1fr)] items-center gap-x-3 gap-y-2 rounded-lg border border-hairline bg-card p-3 lg:grid-cols-[152px_minmax(0,1fr)] lg:items-start lg:gap-x-4' => ! $cupSingle])>
                    @unless ($cupSingle)
                        <a href="{{ GameNames::page($groupGame) }}" class="block rounded-xs lg:row-span-2" tabindex="-1" aria-hidden="true">
                            <x-game-cover :game="$groupGame" size="card" class="w-full rounded-xs" data-test="cup-group-cover" />
                        </a>
                        <h3 class="m-0 flex min-w-0 flex-col text-[13px] leading-tight font-bold lg:pt-1">
                            <a href="{{ GameNames::page($groupGame) }}" class="truncate text-ink hover:text-btc-hi">{{ GameNames::game($groupGame) }}</a>
                            <span class="font-normal text-ink-2">{{ $groupMode }}, {{ __('casual cup') }}</span>
                        </h3>
                    @endunless
                    <ul @class(['m-0 grid list-none gap-1 p-0', 'col-span-2 lg:col-span-1' => ! $cupSingle, 'lg:grid-cols-2' => $cupSingle])>
                        @foreach ($group['cups'] as $cup)
                            @php
                                $cupRow = $cup['tournament'];
                                $running = $cupRow->status === TournamentStatus::Running;
                                $segment = match (true) { $cup['places'] <= 4 => 'w-3', $cup['places'] <= 8 => 'w-2', default => 'w-1.5' };
                            @endphp
                            <li wire:key="cup-mention-{{ $cupRow->id }}" data-cup-row data-order="{{ $loop->index }}" data-game="{{ $groupGame }}" data-region="{{ $cup['region'] ?? '' }}"
                                data-start="{{ (int) $cupRow->starts_at->getTimestampMs() }}" data-free="{{ $cup['free'] ? '1' : '0' }}">
                                <a href="{{ route('tournaments.show', $cupRow) }}"
                                   @class(['flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-2 py-1.5 text-xs text-ink-2 hover:bg-row-hover', 'border border-hairline bg-card' => $cupSingle])
                                   data-test="cup-mention" data-region="{{ $cup['regionLabel'] }}">
                                    <span class="sr-only">{{ $cupRow->name }}</span>
                                    <span class="inline-flex h-7 min-w-10 items-center justify-center rounded-tag bg-raised px-1.5 text-[13px] font-bold text-ink" aria-hidden="true" data-test="cup-region">{{ $cup['regionLabel'] ?? __('Casual') }}</span>
                                    <time datetime="{{ $cupRow->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="flex min-w-[7.5rem] flex-1 flex-col leading-tight" data-test="cup-mention-start"
                                          @unless ($cup['fixedZone']) x-data="cupStart({ at: {{ (int) $cupRow->starts_at->getTimestampMs() }}, zone: @js($cup['zone']) })" @endunless>
                                        <span class="font-display text-[15px] font-bold text-ink tabular-nums" @unless ($cup['fixedZone']) x-text="clock || @js($cup['clock'])" @endunless data-test="cup-clock">{{ $cup['clock'] }}</span>
                                        <span class="text-ink-2"><span @unless ($cup['fixedZone']) x-text="day || @js($cup['day'])" @endunless data-test="cup-day">{{ $cup['day'] }}</span>, <span class="text-ink-3" @unless ($cup['fixedZone']) x-text="city || @js($cup['city'])" @endunless data-test="cup-city">{{ $cup['city'] }}</span></span>
                                    </time>
                                    <span class="flex items-center gap-2" data-test="cup-seats">
                                        <span class="flex items-center gap-0.5" aria-hidden="true">
                                            @for ($seat = 0; $seat < min($cup['places'], 16); $seat++)
                                                <span @class([$segment, 'h-3.5 rounded-[2px]', 'bg-btc' => $seat < $cup['taken'], 'border border-edge' => $seat >= $cup['taken']])></span>
                                            @endfor
                                        </span>
                                        <span class="tabular-nums text-ink-2" aria-hidden="true">{{ $cup['taken'] }}/{{ $cup['places'] }}</span>
                                        <span class="sr-only">{{ __(':taken of :places spots taken', ['taken' => $cup['taken'], 'places' => $cup['places']]) }}</span>
                                    </span>
                                    <span class="inline-flex w-[8.5rem] items-center gap-1.5 text-ink-2" data-test="cup-status">
                                        <x-icon :name="$running ? 'play' : 'clock'" :size="14" :class="$running ? 'text-win' : 'text-btc-hi'" />
                                        {{ $cupRow->status->label() }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>

        @if ($cupFilters)
            <div x-show="shown === 0" x-cloak class="flex flex-wrap items-center gap-3 rounded-lg border border-hairline px-4 py-3 text-[13px] text-ink-2" data-test="cup-filter-empty">
                <span>{{ __('No casual cup matches these filters.') }}</span>
                <x-button variant="secondary" type="button" x-on:click="reset()">{{ __('Show every cup') }}</x-button>
            </div>
        @endif
    </div>
@endif
