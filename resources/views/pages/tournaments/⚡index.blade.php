<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\PageMeta;
use App\Support\SeasonChain\Seasons;
use App\Support\Tournaments\FormatCopy;
use App\Support\Tournaments\OrganizerBoard;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Tournaments (Tournaments.dc.html; before Block 0 the head of
 * TournamentsPrelaunch.dc.html). The organizers' tournaments come first and
 * large, with their covers (user, 2026-09-28: "alle manuell angelegten
 * Turniere sind die WICHTIGSTEN ... oben groß und nicht unten klein"): the
 * one whose sign-up closes next as the hero, then every other one as a card
 * in one grid, open first, then in progress, then past (OrganizerBoard).
 * The casual cups' board follows, then the ended cups as a list and the
 * formats in one line each. Drafts never show here. Tournaments with a
 * prize pot carry its chip (P9, <x-prize-chip>); the artboard's line
 * "rated tournament games mine blocks" is outdated (user, 2026-09-26:
 * tournaments never mine).
 *
 * `?game=<slug>` narrows every part to one game (plan "RL-Startseite", P1:
 * a game page leads to its own tournaments, not the global list); a chip
 * with the game's name leads back to every game. An unknown slug is no
 * filter. The canonical URL stays the unfiltered list (LocalizedUrls keeps
 * no `game`).
 */
new #[Title('Tournaments')] #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    /** The game the list is narrowed to; '' or an unknown slug = every game. */
    #[Url(except: '')]
    public string $game = '';

    public function rendering(\Illuminate\View\View $view): void
    {
        app(PageMeta::class)->describe(__('Tournaments'), __(':games tournaments of the TWENTY ONE esports league: open sign-ups, running brackets and results, with a draw from a Bitcoin block anyone can re-check.', ['games' => implode(', ', array_map(fn (string $game): string => \App\Support\GameNames::game($game), array_keys(app(\App\Games\GameRegistry::class)->all())))]));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('tournaments'));
    }

    /** The filter's game when it names one the league runs, else null (every game). */
    #[Computed]
    public function gameFilter(): ?string
    {
        return array_key_exists($this->game, app(\App\Games\GameRegistry::class)->all()) ? $this->game : null;
    }

    /**
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        // Blockfill's weekly boards (plan "Blockfill", P6) live on the game's own pages.
        return Tournament::query()->where('status', '!=', TournamentStatus::Draft)->exceptLeagueWeeks()
            ->when($this->gameFilter !== null, fn ($query) => $query->where('game', $this->gameFilter))
            ->orderByRaw("case status when 'signup' then 0 when 'drawing' then 1 when 'running' then 2 else 3 end")
            ->orderByDesc('starts_at')->limit(100)->get();
    }

    #[Computed]
    public function next(): ?Tournament
    {
        return Tournament::query()->special()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
            ->when($this->gameFilter !== null, fn ($query) => $query->where('game', $this->gameFilter))
            ->orderBy('signup_closes_at')->first();
    }

    /**
     * @return array{open: list<array<string, mixed>>, progress: list<array<string, mixed>>, past: list<array<string, mixed>>}
     */
    #[Computed]
    public function organizers(): array
    {
        return app(OrganizerBoard::class)->groups(viewerZone: auth()->user()?->timezone, game: $this->gameFilter);
    }
}; ?>

@php
    $live = Seasons::isLive();
    $filter = $this->gameFilter;
    // Label, count, icon, fill: one status bar instead of three number tiles (P53). The colours mark state, never alone:
    // every segment has its icon, word and count in the legend.
    $counts = [
        [__('Open for sign-up'), $this->tournaments->where('status', TournamentStatus::Signup)->count(), 'clock', 'bg-btc', 'text-btc-hi', 'signup'],
        [__('Running'), $this->tournaments->whereIn('status', [TournamentStatus::Drawing, TournamentStatus::Running])->count(), 'play', 'bg-win', 'text-win', 'running'],
        [__('Finished'), $this->tournaments->where('status', TournamentStatus::Finished)->count(), 'check', 'bg-ink-3', 'text-ink-3', 'finished'],
    ];
    $countTotal = array_sum(array_column($counts, 1));
    $next = $this->next;
    $modeLabel = fn (Tournament $t): string => \App\Support\Tournaments\Lobbies::gameLine($t);
    // The organizers' tournaments, the hero taken out of its group; the covers of the first screen load at once.
    $organizers = $this->organizers;
    $hero = null;
    foreach ($organizers['open'] as $index => $card) {
        if ($next !== null && $card['tournament']->id === $next->id) {
            $hero = $card;
            unset($organizers['open'][$index]);
        }
    }
    // One grid for the rest, in the board's order (open, in progress, past): no half-empty row per state, the chip says it.
    $cards = [...array_values($organizers['open']), ...$organizers['progress'], ...$organizers['past']];
    $organizerCount = count($cards) + ($hero === null ? 0 : 1);
    // The covers of the first screen load at once: the hero's, or without one the first row of cards.
    $eager = $hero === null ? 4 : 0;
    // The cups open for sign-up or running stand on the cup board (P53); the ended ones are listed under it.
    $listed = $this->tournaments->filter(fn (Tournament $t): bool => $t->isCasualCup() && ! in_array($t->status, [TournamentStatus::Signup, TournamentStatus::Running], true));
    // Every prize chip of the page from one set of grouped queries, not five per tournament (P2).
    \App\Support\Prizes\PrizeChips::prime([...($hero === null ? [] : [$hero['tournament']]), ...array_column($cards, 'tournament'), ...$listed->all()]);
@endphp

<div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12" data-test="tournaments-index">
    {{-- The tournaments the viewer is signed up for first, each with the way out while sign-up is open (2026-10-02). --}}
    @auth
        <livewire:upcoming-events only="tournament" wire:key="upcoming-tournaments" />
    @endauth

    <div class="flex flex-col gap-2 lg:flex-row lg:items-baseline lg:gap-4">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Tournaments') }}</h1>
        <p class="m-0 text-[13px] leading-normal text-ink-2">
            {{ $live
                ? __('Tournament matches are normal challenges and count for Elo. They never mine season blocks; mix teams play without Elo.')
                : __('Sign-ups are open. Until Block 0 every tournament match is casual and moves the casual Elo.') }}
        </p>
        @if ($filter !== null)
            <a href="{{ route('tournaments.index') }}" class="inline-flex min-h-11 items-center gap-2 self-start rounded-tag bg-btc-chip px-3 text-[13px] font-bold whitespace-nowrap text-btc-hi hover:text-btc-hi lg:self-center"
               aria-label="{{ __(':game only. Show every game', ['game' => \App\Support\GameNames::game($filter)]) }}" data-test="tournaments-game-filter" data-game="{{ $filter }}">
                {{ \App\Support\GameNames::game($filter) }}
                <x-icon name="close" :size="14" />
            </a>
        @endif
        @can('create-tournaments')
            <div class="flex flex-wrap gap-2 lg:ml-auto lg:shrink-0 lg:self-center">
                <x-button :href="route('admin.tournaments.create')" class="whitespace-nowrap" data-test="index-new-tournament">+ {{ __('New tournament') }}</x-button>
                <x-button variant="secondary" :href="route('admin.tournaments')" class="whitespace-nowrap" data-test="index-manage-tournaments">{{ __('Manage tournaments') }}</x-button>
            </div>
        @endcan
    </div>

    {{-- The organizers' tournaments: first, large, with their covers; never behind a casual cup. --}}
    <section aria-labelledby="organizers-h" class="flex flex-col gap-4 lg:gap-6" data-test="organizer-tournaments">
        <h2 id="organizers-h" class="sr-only">{{ __('Organizer tournaments') }}</h2>
        @if ($organizerCount === 0)
            <div class="flex flex-col gap-2 rounded-card bg-card px-4 py-6 shadow-ring-hairline lg:px-6" data-test="organizer-empty">
                <p class="m-0 font-display text-xl font-bold">{{ __('No organizer tournament yet') }}</p>
                <p class="m-0 max-w-[65ch] text-[13px] leading-normal text-ink-2">{{ __('When an organizer publishes a tournament, it shows here first, with its game, start, places and prize pot.') }}</p>
            </div>
        @endif
        @if ($hero !== null)
            <x-tournaments.next-card :card="$hero" />
        @endif
        @if ($cards !== [])
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6 xl:grid-cols-4" aria-label="{{ __('Organizer tournaments') }}">
                @foreach ($cards as $card)
                    <li class="flex min-w-0" wire:key="organizer-{{ $card['tournament']->id }}">
                        <x-tournaments.organizer-card :card="$card" :loading="$eager-- > 0 ? 'eager' : 'lazy'" class="w-full" />
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Every tournament by state, cups included: the overview between the organizers' cards and the cup board. --}}
    <section aria-label="{{ __('Tournaments by state') }}" class="mt-4 flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6" data-test="tournament-states">
        @if ($countTotal > 0)
            <div class="flex h-2 gap-0.5 overflow-hidden rounded-[2px]" aria-hidden="true">
                @foreach ($counts as [$label, $count, $icon, $fill, $ink, $key])
                    @if ($count > 0)
                        <span class="{{ $fill }} h-full first:rounded-l-[2px] last:rounded-r-[2px]" style="flex-grow: {{ $count }}" data-test="state-segment-{{ $key }}"></span>
                    @endif
                @endforeach
            </div>
        @endif
        <ul class="m-0 flex list-none flex-wrap gap-x-6 gap-y-2 p-0 text-[13px]">
            @foreach ($counts as [$label, $count, $icon, $fill, $ink, $key])
                <li class="inline-flex items-center gap-2 text-ink-2" data-test="state-count-{{ $key }}">
                    <x-icon :name="$icon" :size="16" :class="$ink" />
                    <b class="font-display text-lg text-ink tabular-nums">{{ $count }}</b>
                    <span>{{ $label }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <x-tournaments.cup-mentions heading filters :game="$filter" />

    @if ($listed->isNotEmpty())
    <section aria-labelledby="all-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
        <h2 id="all-h" class="m-0 text-[15px] font-bold">{{ __('Past casual cups') }}</h2>
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($listed as $tournament)
                    <li class="flex flex-col gap-1 border-t border-hairline py-2.5 text-[13px] sm:flex-row sm:items-center sm:gap-4" wire:key="t-{{ $tournament->id }}" data-test="tournament-item">
                        <span class="flex min-w-0 flex-col gap-1.5 sm:w-[30%]">
                            <a href="{{ route('tournaments.show', $tournament) }}" class="flex min-w-0 items-center gap-2.5 font-bold"><x-game-cover :game="$tournament->game" size="thumb" class="w-12 rounded-xs" data-test="tournament-item-cover" /><span class="min-w-0 break-words sm:truncate">{{ $tournament->name }}</span></a>
                            <x-prize-chip :tournament="$tournament" class="sm:ml-[58px]" />
                        </span>
                        <x-league-time :at="$tournament->starts_at" class="text-ink-2 sm:w-[22%]" />
                        <span class="text-ink-2 sm:w-[20%]">{{ \App\Support\Tournaments\Lobbies::formatLabel($tournament) }}</span>
                        <span class="text-ink-2 sm:grow">{{ $modeLabel($tournament) }}</span>
                        <span class="inline-flex h-6 items-center self-start rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi sm:self-auto">{{ $tournament->status->label() }}</span>
                    </li>
                @endforeach
            </ul>
    </section>
    @endif

    <section aria-labelledby="formats-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
        <h2 id="formats-h" class="m-0 text-[15px] font-bold">{{ __('Formats') }}</h2>
        <dl class="m-0 grid gap-x-6 lg:grid-cols-2">
            @foreach ([TournamentFormat::SingleElimination, TournamentFormat::DoubleElimination, TournamentFormat::Swiss, TournamentFormat::RoundRobin, TournamentFormat::TwoStage] as $format)
                <div class="flex flex-col gap-0.5 border-t border-hairline py-2.5">
                    <dt class="text-[13px] font-bold">{{ $format->label() }}</dt>
                    <dd class="m-0 text-xs text-ink-2">{{ __(FormatCopy::for($format)['short']) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</div>
