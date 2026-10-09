{{--
    The strongest players across every game (P40, /ladder/strongest): the
    Global Rating of the live season (docs/nips/esports.md "Global Rating",
    App\Support\Rating\StrongestList), strongest first.

    Each row says where its strength comes from: a strip split by game in the
    game's colour, its width the player's rated results in that game, and the
    same numbers as text beside it, so the colour never carries them alone.
    A player below the minimum weight is not ranked; the list counts them.

    Without a live season (before Block 0, between seasons) or before anyone
    reaches the minimum, the page says so and offers the next action; it never
    shows a number the league did not compute.
--}}
@php
    use App\Games\GameRegistry;
    use App\Support\Badges\BadgeCopy;
    use App\Support\GameNames;
    use App\Support\Rating\StrongestList;
    use App\Support\SeasonChain\Seasons;

    $registry = app(GameRegistry::class);
    $live = Seasons::live();
    $list = new StrongestList($live?->slug);
    $top = $list->top(StrongestList::LIMIT);
    $rows = $top['rows'];
    $minimum = (int) config('season.global_rating_min_weight');
    $state = $live !== null ? 'live' : Seasons::state();
    $medal = [1 => 'text-btc', 2 => 'text-ink', 3 => 'text-btc-hi'];
    $short = fn (string $game): string => __($registry->get($game)->assets()->shortLabel);
    $colour = fn (string $game): string => $registry->get($game)->assets()->colour;

    // Mobile: place | player (with the strip under the name) | rating. From lg: the strip and the results get their own columns.
    $grid = 'grid grid-cols-[28px_minmax(0,1fr)_64px] items-center gap-3 lg:grid-cols-[40px_minmax(0,1fr)_minmax(0,240px)_80px_96px]';

    // The ladders behind the list: every game's first mode, as on the home page; a score game has no Elo ladder.
    $ladders = [];
    // Hyperbitcoinization (P6) and Proof of Pong (P4) have no Rating ladder and no Global Rating share: their ladders are their own pages.
    foreach ($registry->versus() as $game) {
        $mode = array_key_first($game->modes());
        if ($mode !== null && ! in_array($game->kind(), [\App\Games\GameKind::Strategy, \App\Games\GameKind::Arcade], true)) {
            $ladders[] = ['game' => $game->slug(), 'name' => GameNames::full($game->slug(), (string) $mode), 'href' => route('ladder.show', [$game->slug(), $mode])];
        }
    }

    app(App\Support\PageMeta::class)->describe(__('Strongest players'),
        __('The strongest players of the TWENTY ONE esports league across every game: one Global Rating from each player’s place on every ladder of the season.'));
    app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('strongest'));
@endphp

<x-layouts::app :title="__('Strongest players')" section="strongest">
    <div class="flex grow flex-col gap-4 px-4 pb-8 lg:gap-6 lg:px-12" data-test="strongest">
        <header class="flex max-w-[68ch] flex-col gap-3">
            <h1 class="m-0 font-display text-[26px] leading-[1.15] font-bold lg:text-[34px]">{{ __('Strongest players') }}</h1>
            <p class="m-0 text-[15px] leading-normal text-ink-2">{{ __('One rating across every game: each player’s place on every ladder of the season, weighted by their rated results there.') }}</p>
            @if ($live !== null)
                <p class="m-0 flex flex-wrap items-center gap-2 text-xs" data-test="strongest-season">
                    <span class="inline-flex h-8 items-center gap-2 rounded-md bg-card px-3 shadow-ring"><b>{{ BadgeCopy::season($live->slug) }}</b><span class="text-ink-2">{{ __('since :date', ['date' => $live->genesis_at->locale(app()->getLocale())->translatedFormat(app()->getLocale() === 'de' ? 'j. M' : 'M j')]) }}</span></span>
                </p>
            @endif
        </header>

        @if ($rows === [])
            <section class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-8 lg:py-7" data-test="strongest-empty" data-state="{{ $state }}">
                @if ($state === 'live')
                    <x-empty-state :heading="__('Nobody has :n rated results yet', ['n' => $minimum])"
                                   :text="$top['waiting'] > 0
                                       ? trans_choice(':count player is on the way. :n rated results in any game of the season put a player on this list.|:count players are on the way. :n rated results in any game of the season put a player on this list.', $top['waiting'], ['n' => $minimum])
                                       : __('The first rated results of the season start this list. :n rated results in any game put a player on it.', ['n' => $minimum])">
                        <a href="{{ route('play') }}" class="btn-p inline-flex h-11 items-center rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc" data-test="strongest-play">{{ __('Play a rated game') }}</a>
                    </x-empty-state>
                @elseif ($state === 'between')
                    <x-empty-state :heading="__('The season has ended')" :text="__('This list ranks the players of a running season. It opens again when the board releases a new Block 0.')">
                        <a href="{{ route('mining') }}" class="btn-s inline-flex h-11 items-center rounded-md border border-edge bg-ground px-5 text-sm text-ink hover:text-ink">{{ __('See the season') }}</a>
                    </x-empty-state>
                @else
                    <x-empty-state :heading="__('The list opens at Block 0')" :text="__('Rated play starts with the season at Block 0. From then on, :n rated results in any game put a player on this list.', ['n' => $minimum])">
                        <a href="{{ route('mining') }}" class="btn-p inline-flex h-11 items-center rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc">{{ __('How the season works') }}</a>
                        <a href="{{ route('play') }}" class="btn-s inline-flex h-11 items-center rounded-md border border-edge bg-ground px-5 text-sm text-ink hover:text-ink">{{ __('Play a casual game') }}</a>
                    </x-empty-state>
                @endif
            </section>
        @else
            <section aria-labelledby="strongest-h" class="flex flex-col rounded-lg bg-card px-2 py-4 lg:px-6 lg:py-5">
                <h2 id="strongest-h" class="m-0 px-2 pb-3 text-[15px] font-bold lg:px-0">{{ trans_choice(':count ranked player|:count ranked players', $top['ranked']) }}</h2>
                <div class="{{ $grid }} h-8 border-b border-hairline px-2 text-xs font-bold text-ink-2">
                    <span>#</span>
                    <span>{{ __('Player') }}</span>
                    <span class="hidden lg:block">{{ __('Rated results by game') }}</span>
                    <span class="hidden text-right lg:block">{{ __('Results') }}</span>
                    <span class="text-right" title="{{ __('Global Rating: strength across every game of the season') }}">{{ __('Global') }}</span>
                </div>
                @php $most = max(array_column($rows, 'weight')); @endphp
                <ol class="m-0 list-none p-0">
                    @foreach ($rows as $row)
                        @php
                            $user = $row['user'];
                            $games = $row['games'];
                            $legend = implode(', ', array_map(fn (string $game, int $weight): string => $short($game).' '.$weight, array_keys($games), $games));
                        @endphp
                        <li data-test="strongest-row" data-user="{{ $user->id }}">
                            <a href="{{ route('players.show', $user->npub) }}" class="tr {{ $grid }} min-h-[52px] rounded-sm px-2 py-1.5 text-[13px] text-ink hover:text-ink">
                                <b @class(['font-display text-sm', $medal[$row['place']] ?? 'font-normal text-ink-3'])>{{ $row['place'] }}</b>
                                <span class="flex min-w-0 flex-col gap-1.5">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <x-avatar :user="$user" :size="28" class="rounded-tag" />
                                        <x-clan-tag :clan="$user->clanMember?->clan" compact />
                                        <span class="truncate font-bold" data-test="strongest-name">{{ $user->displayName() }}</span>
                                    </span>
                                    <span class="flex max-w-60 flex-col gap-1 lg:hidden">
                                        @include('pages.ladder.partials.game-strip', ['games' => $games, 'colour' => $colour, 'share' => $row['weight'] / $most])
                                        <span class="text-[11px] text-ink-2">{{ $legend }}</span>
                                    </span>
                                </span>
                                <span class="hidden min-w-0 flex-col gap-1 lg:flex" data-test="strongest-games">
                                    @include('pages.ladder.partials.game-strip', ['games' => $games, 'colour' => $colour, 'share' => $row['weight'] / $most])
                                    <span class="truncate text-[11px] text-ink-2">{{ $legend }}</span>
                                </span>
                                <span class="hidden text-right text-ink-2 tabular-nums lg:block" data-test="strongest-weight">{{ $row['weight'] }}</span>
                                <b class="text-right font-display text-[15px] tabular-nums lg:text-base" data-test="strongest-rating">{{ $row['rating'] }}</b>
                            </a>
                        </li>
                    @endforeach
                </ol>
                <div class="mx-2 mt-3 flex flex-col gap-1 border-t border-hairline pt-3 text-xs leading-[1.6] text-ink-2 lg:mx-0">
                    <p class="m-0">{{ __('1000 is the middle of the field; 1200 is ahead of 84 % of it, 1400 ahead of 98 %.') }}</p>
                    <p class="m-0" data-test="strongest-waiting">
                        {{ $top['waiting'] > 0
                            ? trans_choice(':count more player has fewer than :n rated results this season and is not ranked yet.|:count more players have fewer than :n rated results this season and are not ranked yet.', $top['waiting'], ['n' => $minimum])
                            : __('A player shows here from :n rated results in the season.', ['n' => $minimum]) }}
                    </p>
                </div>
            </section>
        @endif

        <nav aria-labelledby="strongest-ladders-h" class="flex flex-col gap-3" data-test="strongest-ladders">
            <h2 id="strongest-ladders-h" class="m-0 text-[15px] font-bold">{{ __('The ladders behind it') }}</h2>
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                @foreach ($ladders as $ladder)
                    <li>
                        <a href="{{ $ladder['href'] }}" class="inline-flex min-h-11 items-center gap-2.5 rounded-md bg-card py-1.5 pr-3.5 pl-1.5 text-[13px] text-ink shadow-ring hover:text-ink">
                            <x-game-cover :game="$ladder['game']" size="thumb" class="w-12 rounded-xs" />{{ $ladder['name'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="m-0 flex flex-wrap items-center gap-x-2 text-xs text-ink-3">
                <span>{{ __('Anyone can recompute every Global Rating from the league’s signed ladders.') }}</span>
                <a href="{{ route('protocol') }}#verify" class="inline-flex min-h-11 items-center">{{ __('Check it yourself') }}</a>
            </p>
        </nav>
    </div>
</x-layouts::app>
