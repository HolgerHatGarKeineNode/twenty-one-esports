{{--
    The public record under the player header (P31), from
    App\Support\Players\PlayerStats: the ladders first (the standing is what
    a visitor comes for), one card per ladder of a versus game and per mode of
    a score game, game by game in the registry's order (PlayerStats::games(),
    so a game registered later shows with no change here; a game never
    played has no card); the latest results under them; tournaments, seasons
    and clans in the side column from lg. Each part with nothing in it says
    so in one line, with the way to change that on the player's own page.

    $stats: PlayerStats, $name: the player's display name, $isMe: the own page.
--}}
@php
    use App\Support\PreSeason;
    use App\Support\Rating\Ratings;

    $games = $stats->games();
    $results = $stats->results();
    $tournaments = $stats->tournaments();
    $seasons = collect($stats->seasons());
    $clans = $stats->clans();
    $side = 'flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-5';
    $sideHead = 'm-0 text-[15px] font-bold';
    $empty = 'm-0 text-[13px] leading-normal text-ink-2';
    $record = fn (int $wins, int $draws, int $losses): string => __(':w W, :d D, :l L', ['w' => $wins, 'd' => $draws, 'l' => $losses]);
@endphp

<div class="mx-4 grid grid-cols-1 gap-6 lg:mx-0 lg:grid-cols-12 lg:gap-8" data-test="player-stats">
    <div class="flex min-w-0 flex-col gap-6 lg:col-span-8 lg:gap-8">
        {{-- Ladders: rating, rank, form, share of wins, peak --}}
        <section aria-labelledby="ps-ladders-h" class="flex flex-col gap-3 lg:gap-4" data-test="player-ladders">
            <h2 id="ps-ladders-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Ladders') }}</h2>
            @if ($games === [])
                <div class="flex flex-col items-start gap-3 rounded-lg px-4 py-4 shadow-ring-hairline lg:px-5" data-test="player-ladders-empty">
                    <p class="{{ $empty }}">{{ $isMe ? __('No results yet. Your first finished game puts you on a ladder.') : __('No results yet. The first finished game puts :name on a ladder.', ['name' => $name]) }}</p>
                    @if ($isMe)
                        <x-button variant="quiet" :href="route('play')">{{ __('Find a game') }}</x-button>
                    @endif
                </div>
            @else
                <ul role="list" class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 lg:gap-4">
                    @foreach ($games as $card)
                        @if ($card['kind'] === 'score')
                            @include('pages.players.partials.score-card', ['score' => $card['score']])
                            @continue
                        @endif
                        @php
                            $ladder = $card['ladder'];
                            $rating = $ladder['rating'];
                            $rated = $rating['pool'] === \App\Models\Rating::RATED;
                            $badge = Ratings::badge($rating['tier']);
                            $share = $rating['results'] > 0 ? (int) round($rating['wins'] / $rating['results'] * 100) : 0;
                            $form = array_pad($ladder['form'], -\App\Support\Players\PlayerStats::FORM, null);
                        @endphp
                        <li wire:key="ladder-{{ $ladder['key'] }}" class="flex min-w-0 flex-col gap-3 rounded-card bg-card p-4" data-test="player-ladder" data-game="{{ $ladder['game'] }}" data-ladder="{{ $ladder['game'] }}/{{ $ladder['mode'] }}" data-pool="{{ $rating['pool'] }}">
                            <a href="{{ $ladder['href'] }}" class="grid min-h-11 grid-cols-[48px_minmax(0,1fr)] items-center gap-3 text-ink hover:text-ink">
                                <x-game-cover :game="$ladder['game']" size="thumb" class="w-12 rounded-tag" />
                                <span class="flex min-w-0 flex-col">
                                    <b class="truncate text-[13px]">{{ $ladder['name'] }}</b>
                                    <span class="truncate text-xs text-ink-3">{{ $ladder['lineup'] !== null ? $ladder['lineup']->clan->name.', ' : '' }}{{ $rated ? __('Rated') : __('Casual') }}</span>
                                </span>
                            </a>

                            <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <b class="font-display text-[30px] leading-none font-bold tabular-nums" data-test="player-ladder-rating">{{ $rating['rating'] }}</b>
                                @if ($rated)
                                    <x-rank-badge :tier="$badge['tier']" :level="$badge['level']" />
                                @elseif ($rating['provisional'])
                                    <span class="text-xs text-ink-3">{{ __('provisional') }}</span>
                                @endif
                                @if ($ladder['place'] !== null)
                                    <span class="text-xs text-ink-2 tabular-nums" data-test="player-ladder-place">{{ __('#:place of :count', ['place' => $ladder['place'], 'count' => $ladder['of']]) }}</span>
                                @endif
                                <span class="ml-auto text-xs text-ink-3" data-test="player-ladder-peak">{{ __('Peak') }} <b class="text-ink-2 tabular-nums">{{ $ladder['peak'] }}</b></span>
                            </span>

                            <span class="relative flex items-center gap-1" data-test="player-ladder-form">
                                <span class="sr-only">{{ __('Last :n results, oldest first:', ['n' => \App\Support\Players\PlayerStats::FORM]) }}</span>
                                @foreach ($form as $outcome)
                                    <x-players.outcome :outcome="$outcome" />
                                @endforeach
                            </span>

                            <span class="flex flex-col gap-1.5">
                                <span class="flex h-1.5 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true">
                                    <span class="bg-win" style="width: {{ $rating['wins'] / $rating['results'] * 100 }}%"></span>
                                    <span class="bg-ink-3" style="width: {{ $rating['draws'] / $rating['results'] * 100 }}%"></span>
                                    <span class="bg-loss" style="width: {{ $rating['losses'] / $rating['results'] * 100 }}%"></span>
                                </span>
                                <span class="flex items-baseline justify-between gap-3 text-xs text-ink-2">
                                    <span class="tabular-nums" data-test="player-ladder-record">{{ $record($rating['wins'], $rating['draws'], $rating['losses']) }}</span>
                                    <span class="shrink-0 tabular-nums" data-test="player-ladder-share">{{ __(':n% won', ['n' => $share]) }}</span>
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- The last results, faces, score and what each did to the rating --}}
        <section aria-labelledby="ps-results-h" class="flex flex-col gap-3 lg:gap-4" data-test="player-results">
            <h2 id="ps-results-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Recent results') }}</h2>
            @if ($results === [])
                <p class="{{ $empty }} rounded-lg px-4 py-4 shadow-ring-hairline lg:px-5" data-test="player-results-empty">{{ __('No finished games yet.') }}</p>
            @else
                <ol role="list" class="m-0 list-none rounded-lg bg-card p-0 px-4 lg:px-5">
                    @foreach ($results as $result)
                        <li wire:key="result-{{ $result['key'] }}" class="border-b border-hairline last:border-0">
                            <a href="{{ $result['href'] }}" class="grid min-h-14 grid-cols-[24px_28px_minmax(0,1fr)_auto_40px] items-center gap-2.5 py-2 sm:gap-3 text-ink hover:text-ink" data-test="player-result" data-outcome="{{ $result['outcome'] }}">
                                <x-players.outcome :outcome="$result['outcome']" :size="24" />
                                @if ($result['face'])
                                    <x-avatar :user="$result['face']" :size="28" class="rounded-tag" />
                                @else
                                    <x-clan-tag :clan="$result['clan']" :tag="$result['tag']" :tile="28" class="flex size-7 items-center justify-center rounded-tag bg-btc-tint text-[9px] font-bold text-btc" />
                                @endif
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <b class="truncate text-[13px]">{{ $result['opponent'] }}</b>
                                    <span class="flex min-w-0 text-xs text-ink-2"><span class="truncate">{{ $result['game'] }},</span>&nbsp;<span class="shrink-0" data-test="player-result-when">{{ $result['at']?->diffForHumans(['short' => true]) }}</span></span>
                                </span>
                                <b class="font-display text-[15px] whitespace-nowrap tabular-nums" data-test="player-result-score">{{ $result['score'] }}</b>
                                <span class="text-right text-xs font-bold tabular-nums" data-test="player-result-delta">
                                    @if ($result['delta'] !== null)
                                        <span @class(['text-win' => $result['delta'] > 0, 'text-loss' => $result['delta'] < 0, 'text-ink-2' => $result['delta'] === 0])>{{ $result['delta'] > 0 ? '+'.$result['delta'] : ($result['delta'] < 0 ? '−'.abs($result['delta']) : '±0') }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>

    <div class="flex min-w-0 flex-col gap-6 lg:col-span-4 lg:pt-12">
        {{-- Tournaments played: place and the prize the league paid --}}
        <section aria-labelledby="ps-tournaments-h" class="{{ $side }}" data-test="player-tournaments">
            <span class="flex items-baseline justify-between gap-3">
                <h2 id="ps-tournaments-h" class="{{ $sideHead }}">{{ __('Tournaments') }}</h2>
                @if ($tournaments['count'] > 0)
                    <span class="text-xs text-ink-2 tabular-nums">{{ trans_choice(':count played|:count played', $tournaments['count']) }}</span>
                @endif
            </span>
            @if ($tournaments['rows'] === [])
                <p class="{{ $empty }}" data-test="player-tournaments-empty">{{ __('No tournament played yet.') }}</p>
                {{-- The next open tournament as the picture (P53); the one button only on the own page. --}}
                <x-tournaments.open-picture :cta="$isMe ? __('See tournaments') : null" />
            @else
                @if ($tournaments['prizes'] > 0)
                    <p class="m-0 text-[13px] text-ink-2" data-test="player-prizes">{{ __('Prizes won:') }} <b class="text-btc tabular-nums">{{ PreSeason::formatSats($tournaments['prizes']) }} sats</b></p>
                @endif
                <ol role="list" class="m-0 list-none p-0">
                    @foreach ($tournaments['rows'] as $played)
                        <li wire:key="tournament-{{ $played['tournament']->id }}" class="border-t border-hairline">
                            <a href="{{ route('tournaments.show', $played['tournament']) }}" class="grid min-h-14 grid-cols-[40px_minmax(0,1fr)_auto] items-center gap-3 py-2 text-ink hover:text-ink" data-test="player-tournament">
                                @if ($played['place'] !== null)
                                    <b @class(['font-display text-lg leading-none tabular-nums', 'text-btc' => $played['place'] === 1]) data-test="player-tournament-place">{{ __(':place.', ['place' => $played['place']]) }}</b>
                                @elseif ($played['tournament']->status === \App\Enums\TournamentStatus::Running)
                                    <span class="flex items-center gap-1 text-[11px] font-bold text-btc"><span class="size-1.5 animate-live rounded-full bg-btc" aria-hidden="true"></span>{{ __('Live') }}</span>
                                @else
                                    <span class="text-ink-3" data-test="player-tournament-place">–</span>
                                @endif
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <b class="truncate text-[13px]">{{ $played['tournament']->name }}</b>
                                    <span class="truncate text-xs text-ink-2">{{ \App\Support\GameNames::full($played['tournament']->game, $played['tournament']->mode) }}, {{ trans_choice(':count entry|:count entries', $played['of']) }}</span>
                                </span>
                                @if ($played['prize'] !== null)
                                    <b class="text-xs whitespace-nowrap text-btc tabular-nums" data-test="player-tournament-prize">{{ PreSeason::formatSats($played['prize']) }} sats</b>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        {{-- Season record: every released season, then Season 0 (casual) --}}
        <section aria-labelledby="ps-seasons-h" class="{{ $side }}" data-test="player-seasons">
            <h2 id="ps-seasons-h" class="{{ $sideHead }}">{{ __('Season record') }}</h2>
            @if ($seasons->sum('results') === 0)
                <p class="{{ $empty }}" data-test="player-seasons-empty">{{ __('No result in any season yet.') }}</p>
            @else
                <ul role="list" class="m-0 list-none p-0">
                    @foreach ($seasons as $season)
                        <li wire:key="season-{{ $season['key'] }}" class="flex min-h-11 items-center justify-between gap-3 border-t border-hairline text-[13px]" data-test="player-season" data-season="{{ $season['key'] }}">
                            <span class="flex min-w-0 flex-col">
                                <b class="truncate">{{ $season['name'] }}</b>
                                <span class="text-xs text-ink-3">{{ $season['casual'] ? __('Casual') : __('Rated') }}</span>
                            </span>
                            <span class="text-right text-xs text-ink-2 tabular-nums" data-test="player-season-record">
                                @if ($season['results'] === 0)
                                    {{ __('no results') }}
                                @else
                                    {{ $record($season['wins'], $season['draws'], $season['losses']) }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Clans: the one now, then the ones left --}}
        <section aria-labelledby="ps-clans-h" class="{{ $side }}" data-test="player-clans">
            <h2 id="ps-clans-h" class="{{ $sideHead }}">{{ __('Clans') }}</h2>
            @if ($clans === [])
                <p class="{{ $empty }}" data-test="player-clans-empty">{{ $isMe ? __('You are not in a clan.') : __(':name is not in a clan.', ['name' => $name]) }}</p>
                {{-- The league's clans as the picture (P53); the one button only on the own page. --}}
                <x-clans.join-picture :cta="$isMe ? __('Find a clan') : null" />
            @else
                <ul role="list" class="m-0 list-none p-0">
                    @foreach ($clans as $row)
                        <li class="border-t border-hairline" data-test="player-clan" data-current="{{ $row['until'] === null ? 'true' : 'false' }}">
                            @php
                                $when = $row['until'] === null
                                    ? __('since :date', ['date' => $row['since']?->translatedFormat('M Y')])
                                    : __('until :date', ['date' => $row['until']->translatedFormat('M Y')]);
                            @endphp
                            @if ($row['clan'])
                                <a href="{{ route('clans.show', $row['clan']) }}" class="flex min-h-12 items-center gap-3 text-[13px] text-ink hover:text-ink">
                                    <x-clan-tag :clan="$row['clan']" size="sm" />
                                    <span @class(['min-w-0 grow truncate', 'font-bold' => $row['until'] === null])>{{ $row['name'] }}</span>
                                    <span class="shrink-0 text-xs text-ink-3">{{ $when }}</span>
                                </a>
                            @else
                                <span class="flex min-h-12 items-center gap-3 text-[13px] text-ink-2">
                                    <span class="min-w-0 grow truncate">{{ $row['name'] }}</span>
                                    <span class="shrink-0 text-xs text-ink-3">{{ $when }}</span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
