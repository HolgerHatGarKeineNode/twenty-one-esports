<?php

use App\Support\PageMeta;
use App\Support\SeasonChain\Seasons;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\TournamentsBoard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Tournaments (Tournaments.dc.html, TournamentsPhone.dc.html; plan "Refactor und Design-Revamp", P6, rules R8, R9).
 * The head with the way to the archive, the filter rows (game chips as Priority+, the cup region, free places
 * only), the featured tournament (an organizer's or one with a pot, open for sign-up), the table of everything
 * open for sign-up (pot and organizer tournaments first, then the casual cups) and the table of what runs now
 * (App\Support\Tournaments\TournamentsBoard). Finished and called-off tournaments are on the archive.
 *
 * `?game=<slug>` narrows the page to one game (a game page leads here with it), `?region=eu|us` the casual cups
 * to one region, `?free=1` to tournaments with a free place. An unknown value is no filter; the canonical URL
 * stays the unfiltered list (LocalizedUrls keeps none of them).
 */
new #[Title('Tournaments')] #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    #[Url(except: '')]
    public string $game = '';

    #[Url(except: '')]
    public string $region = '';

    #[Url(except: false)]
    public bool $free = false;

    public function rendering(\Illuminate\View\View $view): void
    {
        app(PageMeta::class)->describe(__('Tournaments'), __(':games tournaments of the TWENTY ONE esports league: open sign-ups, running brackets and results, with a draw from a Bitcoin block anyone can re-check.', ['games' => implode(', ', array_map(fn (string $game): string => \App\Support\GameNames::game($game), array_keys(app(\App\Games\GameRegistry::class)->all())))]));
        app(PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('tournaments'));
    }

    /** The filter's game when it names one the league runs, else null (every game). */
    #[Computed]
    public function gameFilter(): ?string
    {
        return array_key_exists($this->game, app(\App\Games\GameRegistry::class)->all()) ? $this->game : null;
    }

    /** The filter's region when it names a casual-cup region, else null. */
    #[Computed]
    public function regionFilter(): ?string
    {
        return array_key_exists($this->region, CasualCups::regions()) ? $this->region : null;
    }

    #[Computed]
    public function board(): TournamentsBoard
    {
        return new TournamentsBoard(auth()->user()?->timezone);
    }

    public function toggleFree(): void
    {
        $this->free = ! $this->free;
    }

    /**
     * The page's URL with one filter changed.
     *
     * @param  array<string, string|null>  $change
     */
    protected function filterUrl(array $change): string
    {
        $query = array_filter([...['game' => $this->gameFilter, 'region' => $this->regionFilter, 'free' => $this->free ? '1' : null], ...$change], fn (?string $value): bool => $value !== null && $value !== '');

        return route('tournaments.index', $query);
    }
}; ?>

@php
    use App\Support\Engagement\HomeBoard;

    $board = $this->board;
    $game = $this->gameFilter;
    $region = $this->regionFilter;
    $featured = $board->featured($game);
    $open = $board->open($game, $region, $free);
    $running = $board->running($game, $region);
    $zoneLabel = $board->zoneLabel();
    $gameChips = array_map(fn (array $chip): array => [
        'key' => $chip['key'], 'label' => $chip['label'], 'count' => $chip['count'],
        'href' => $this->filterUrl(['game' => $chip['key'] === 'all' ? null : $chip['key']]),
        'active' => $chip['key'] === 'all' ? $game === null : $game === $chip['key'],
    ], $board->gameChips());
    $regionChips = [
        ['key' => 'all', 'label' => __('All'), 'count' => null, 'href' => $this->filterUrl(['region' => null]), 'active' => $region === null],
        ...array_map(fn (string $key, array $info): array => ['key' => $key, 'label' => $info['label'], 'count' => null, 'href' => $this->filterUrl(['region' => $key]), 'active' => $region === $key], array_keys(CasualCups::regions()), CasualCups::regions()),
    ];
    $sats = fn (int $value): string => HomeBoard::sats($value);
    // The featured pot's first place: "alles an Platz 1" with a single place, else what place 1 wins.
    $featuredPool = $featured !== null && $featured['pot'] !== null ? app(\App\Support\Tournaments\TournamentPrizePool::class)->for($featured['tournament']) : null;
    $featuredSplit = $featuredPool['split'] ?? [];
@endphp

<div class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 pb-10 lg:px-12" data-test="tournaments-index">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
        <h1 class="m-0 font-display text-2xl leading-[1.15] font-bold lg:text-[36px]">{{ __('Tournaments') }}</h1>
        <span class="text-[13px] text-ink-2 max-lg:hidden" data-test="tournaments-lead">
            {{ Seasons::isLive() ? __('Tournament matches count for Elo and never mine season blocks.') : __('Until Block 0 every tournament match is casual.') }}
        </span>
        <span class="grow"></span>
        @can('create-tournaments')
            {{-- Organizers and admins: the way to set one up (the admin list holds the rest). --}}
            <a href="{{ route('admin.tournaments.create') }}" class="rv-b2 shrink-0" data-test="index-new-tournament">+ {{ __('New tournament') }}</a>
        @endcan
        <a href="{{ route('tournaments.archive') }}" class="rv-b2 max-lg:hidden" data-test="to-archive"><x-icon name="list" :size="16" />{{ __('Archive') }}</a>
        <a href="{{ route('tournaments.archive') }}" class="rv-lk text-[13px] lg:hidden" data-test="to-archive-phone">{{ __('Archive') }}</a>
    </div>

    {{-- The tournaments the viewer is signed up for, each with the way out while sign-up is open (2026-10-02). --}}
    @auth
        <livewire:upcoming-events only="tournament" wire:key="upcoming-tournaments" />
    @endauth

    <div class="flex flex-col gap-2 lg:gap-3" data-test="tournament-filters">
        <x-ui.chips :label="__('Game title')" :items="$gameChips" test="filter-game" />
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 max-lg:hidden">
            <x-ui.chips :label="__('Region')" :items="$regionChips" test="filter-region" class="flex-none" />
            <button type="button" role="checkbox" aria-checked="{{ $free ? 'true' : 'false' }}" wire:click="toggleFree" class="rv-ck" data-test="filter-free"><i aria-hidden="true"></i>{{ __('Only free places') }}</button>
            <span class="grow"></span>
            <span class="rv-m" data-test="times-in">{{ __('Times in :zone', ['zone' => $zoneLabel]) }}</span>
        </div>
    </div>

    @if ($featured !== null)
        @php
            $ft = $featured['tournament'];
            $signup = route('tournaments.signup', $ft);
        @endphp
        {{-- The featured tournament (rule R9): its cover, its marks, name and data line, the seats, the pot big, the countdown to sign-up close and Eintragen. --}}
        <section aria-labelledby="ft-h" class="rv-feat grid gap-4 p-4 lg:grid-cols-[minmax(0,480px)_minmax(0,1fr)_300px] lg:gap-6" data-test="featured-tournament" data-tournament="{{ $ft->id }}">
            <a href="{{ $featured['href'] }}" tabindex="-1" aria-hidden="true" class="block">
                <x-game-cover :game="$ft->game" size="header" loading="eager" class="w-full rounded-[4px] [&_img]:object-cover" />
            </a>
            <div class="flex min-w-0 flex-col gap-3">
                <span class="flex flex-wrap items-center gap-2">
                    <span class="rv-tag is-btc"><x-icon name="trophy" :size="12" />{{ __('Organizer tournament') }}</span>
                    <span class="rv-tag max-lg:hidden">{{ __('Open for sign-up') }}</span>
                </span>
                <h2 id="ft-h" class="m-0 font-display text-xl leading-[1.15] font-bold [overflow-wrap:anywhere] lg:text-[30px]">
                    <a href="{{ $featured['href'] }}" class="text-ink hover:text-ink"><span class="lg:hidden">{{ $featured['name'] }}</span><span class="max-lg:hidden">{{ $ft->name }}</span></a>
                </h2>
                <span class="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-ink-2 max-lg:hidden">
                    <span>{{ \App\Support\Tournaments\Lobbies::gameLine($ft) }}</span>
                    <span>{{ \App\Support\Tournaments\Lobbies::formatLabel($ft) }}</span>
                    <span>{{ __('Start :when', ['when' => $featured['start']]) }}</span>
                    @if ($openEnd = \App\Support\Tournaments\TournamentLanding::openEnd($ft, $board->zone()))
                        <span data-test="featured-open-end">{{ $openEnd }}</span>
                    @endif
                </span>
                @if ($featured['pot'] !== null)
                    <span class="flex items-baseline gap-3 lg:hidden">
                        <b class="font-display text-[22px] font-bold whitespace-nowrap text-btc tabular-nums" data-test="featured-pot-phone">{{ $sats($featured['pot']) }} Sats</b>
                        <span class="rv-m">{{ \App\Support\Tournaments\TournamentStartVote::cardStart($ft, HomeBoard::dayClock($ft->starts_at, false)) }}</span>
                    </span>
                @endif
                <span class="flex items-center gap-2">
                    <x-ui.seats :taken="$featured['taken']" :places="$featured['places']" />
                    <span class="text-[13px] text-ink-2 tabular-nums" data-test="featured-seats">
                        <span class="max-lg:hidden">{{ __(':taken of :places, :free free', ['taken' => $featured['taken'], 'places' => $featured['places'], 'free' => max(0, $featured['places'] - $featured['taken'])]) }}</span>
                        <span class="lg:hidden">{{ $featured['taken'] }}/{{ $featured['places'] }}</span>
                    </span>
                </span>
                <span class="rv-m max-lg:hidden">{{ __('Seeded by Elo; the hash of the next Bitcoin block draws the bracket.') }}</span>
            </div>
            <div class="flex flex-col justify-center gap-3 max-lg:hidden lg:border-l lg:border-line lg:pl-6">
                @if ($featured['pot'] !== null)
                    <span class="rv-m">{{ __('Prize pot in sats') }}</span>
                    <b class="font-display text-[36px] leading-none font-bold text-btc tabular-nums" data-test="featured-pot">{{ $sats($featured['pot']) }}</b>
                    <span class="text-ink-2">{{ count($featuredSplit) === 1 ? __('Sats, all to place 1') : __('Sats, :sats to place 1', ['sats' => $sats((int) ($featuredSplit[0]['sats'] ?? 0))]) }}</span>
                @endif
                @if ($ft->signup_closes_at !== null)
                    <span class="rv-m">{{ __('Sign-up ends in') }}</span>
                    <x-ui.countdown :at="$ft->signup_closes_at" size="sm" :label="__('Sign-up ends in')" />
                @endif
                <a href="{{ $signup }}" class="rv-b" data-test="featured-cta">{{ __('Enter the tournament') }}</a>
            </div>
        </section>
    @endif

    <section aria-labelledby="open-h" class="flex flex-col gap-3" data-test="open-tournaments">
        <div class="flex items-center gap-3">
            <h2 id="open-h" class="rv-h2 max-lg:font-mono max-lg:text-base max-lg:font-bold">{{ __('Open for sign-up') }}</h2>
            <span class="rv-n max-lg:hidden">{{ count($open) }}</span>
            <span class="rv-m max-lg:hidden">{{ __('Tournaments with a pot first, then casual cups') }}</span>
        </div>
        @if ($open === [])
            <div class="rv-card flex flex-wrap items-center gap-3 p-4 text-[13px] text-ink-2" data-test="open-empty">
                <span>{{ $game !== null || $region !== null || $free ? __('No tournament open for sign-up matches these filters.') : __('No tournament is open for sign-up right now.') }}</span>
                @if ($game !== null || $region !== null || $free)
                    <a href="{{ route('tournaments.index') }}" class="rv-b2 is-sm">{{ __('Show every tournament') }}</a>
                @endif
            </div>
        @else
            {{-- Desktop: the board's table. --}}
            <div class="rv-card max-lg:hidden">
                <table class="rv-tb">
                    <thead>
                        <tr><th class="w-[84px]"><span class="sr-only">{{ __('Cover') }}</span></th><th>{{ __('Cup') }}</th><th>{{ __('Region / pot') }}</th><th>{{ __('Start (:zone)', ['zone' => $zoneLabel]) }}</th><th>{{ __('Places') }}</th><th><span class="sr-only">{{ __('Action') }}</span></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($open as $row)
                            <tr wire:key="open-{{ $row['key'] }}" @class(['is-featured' => $row['featured']]) data-test="open-row" data-tournament="{{ $row['tournament']->id }}" @if ($row['featured']) data-featured @endif data-game="{{ $row['game'] }}" data-region="{{ $row['regionKey'] }}">
                                <td class="w-[84px]"><x-game-cover :game="$row['game']" size="thumb" class="h-9 w-16 rounded-[2px] [&_img]:object-cover" /></td>
                                <td class="[overflow-wrap:anywhere]">
                                    <a href="{{ $row['href'] }}" class="font-bold text-ink hover:text-ink">{{ $row['name'] }}</a>
                                    <div class="rv-m">{{ $row['sub'] }}</div>
                                </td>
                                <td>
                                    @if ($row['pot'] !== null)
                                        {{-- What is still to be won of the pot as set (PrizeChips, primed for the whole list). --}}
                                        <x-prize-chip :tournament="$row['tournament']" />
                                    @elseif ($row['region'] !== null)
                                        <span class="rv-tag" data-test="row-region">{{ $row['region'] }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap tabular-nums">{{ $row['start'] }}</td>
                                <td class="whitespace-nowrap tabular-nums"><x-ui.seats :taken="$row['taken']" :places="$row['places']" /> <span class="ml-1.5 text-ink-2">{{ $row['taken'] }}/{{ $row['places'] }}</span></td>
                                <td class="is-r"><a href="{{ route('tournaments.signup', $row['tournament']) }}" class="rv-b is-sm" data-test="row-signup">{{ __('Enter the tournament') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{-- Phone: one row per tournament, the seats on the right (TournamentsPhone.dc.html). --}}
            <ul class="m-0 flex list-none flex-col p-0 lg:hidden" aria-labelledby="open-h">
                @foreach ($open as $row)
                    @continue($featured !== null && $row['tournament']->id === $featured['tournament']->id)
                    <li wire:key="open-p-{{ $row['key'] }}" class="border-b border-hairline" data-test="open-row-phone" data-tournament="{{ $row['tournament']->id }}" data-game="{{ $row['game'] }}" data-region="{{ $row['regionKey'] }}">
                        <a href="{{ $row['href'] }}" class="flex min-h-[68px] items-center gap-3 py-3 text-ink hover:text-ink">
                            <x-game-cover :game="$row['game']" size="thumb" class="h-[41px] w-[72px] flex-none rounded-[2px] [&_img]:object-cover" />
                            <span class="flex min-w-0 grow flex-col">
                                <b class="text-sm leading-tight [overflow-wrap:anywhere]">{{ $row['name'] }}</b>
                                <span class="rv-m">{{ implode(', ', array_filter([$row['start'], $row['pot'] !== null ? $sats($row['pot']).' Sats' : $row['region']])) }}</span>
                            </span>
                            <span class="flex flex-none flex-col items-end gap-1">
                                <x-ui.seats :taken="$row['taken']" :places="$row['places']" :max="8" />
                                <span class="rv-m tabular-nums">{{ $row['taken'] }}/{{ $row['places'] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($running !== [])
        <section aria-labelledby="run-h" class="flex flex-col gap-3" data-test="running-tournaments">
            <div class="flex items-center gap-3">
                <h2 id="run-h" class="rv-h2 max-lg:font-mono max-lg:text-base max-lg:font-bold">{{ __('Running') }}</h2>
                <span class="rv-n">{{ count($running) }}</span>
            </div>
            <div class="rv-card overflow-x-auto">
                <table class="rv-tb">
                    <tbody>
                        @foreach ($running as $row)
                            <tr wire:key="run-{{ $row['key'] }}" data-test="running-row" data-tournament="{{ $row['tournament']->id }}" data-game="{{ $row['game'] }}" data-region="{{ $row['regionKey'] }}" data-kind="{{ $row['kind'] }}">
                                <td class="w-[84px] max-sm:hidden"><x-game-cover :game="$row['game']" size="thumb" class="h-9 w-16 rounded-[2px] [&_img]:object-cover" /></td>
                                <td class="[overflow-wrap:anywhere]">
                                    <a href="{{ $row['href'] }}" class="font-bold text-ink hover:text-ink">{{ $row['name'] }}</a>
                                    <div class="rv-m">{{ $row['sub'] }}</div>
                                </td>
                                <td class="whitespace-nowrap text-ink-2 tabular-nums max-md:hidden">{{ $row['start'] }}</td>
                                <td class="max-sm:hidden"><span @class(['rv-tag', 'is-ok' => $row['status'] === __('Running')])>{{ $row['status'] }}</span></td>
                                <td class="is-r"><a href="{{ $row['href'] }}" class="rv-b2 is-sm">{{ $row['kind'] === 'week' ? __('Leaderboard') : __('See the tournament') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
