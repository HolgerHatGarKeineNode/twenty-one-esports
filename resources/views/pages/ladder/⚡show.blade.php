<?php

use App\Games\GameMode;
use App\Games\GameRegistry;
use App\Models\Rating;
use App\Support\PageMeta;
use App\Support\Rating\Ratings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The ladder of one game and mode (Ladder.dc.html, ChessLadder.dc.html,
 * LadderPrelaunch.dc.html), from the stored ratings (P7b).
 *
 * Two tabs: "Rated" is the season ladder with rank tiers; before Block 0 no
 * ladder is open and it shows the Pre-Season empty state. "Casual" is the
 * permanent casual rating, which never has a tier and counts for nothing
 * else. Chess rates players, Rocket League rates lineups.
 *
 * Not built here (later phases): share of wins, tier lines, form, Block
 * Height, Global Rating, the clan tab and the Proof with the ladder snapshot
 * (P7c publishes it).
 */
new #[Layout('layouts::app', ['section' => 'ladder'])] class extends Component {
    public string $game;

    public string $mode;

    #[Url(except: 'rated')]
    public string $pool = 'rated';

    public function mount(string $game, string $mode): void
    {
        abort_if(app(GameRegistry::class)->mode($game, $mode) === null, 404);

        $this->game = $game;
        $this->mode = $mode;
        $this->pool = $this->pool === Rating::CASUAL ? Rating::CASUAL : Rating::RATED;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $game = __(app(GameRegistry::class)->get($this->game)->name());
        $mode = $this->game === 'chess' ? __($this->gameMode->name) : $this->gameMode->name;
        $title = __(':game :mode ladder', ['game' => $game, 'mode' => $mode]);
        $view->title($title);
        $replace = ['game' => $game, 'mode' => $mode];
        app(PageMeta::class)->describe($title, $this->gameMode->rates === 'player'
            ? __('The rated season ladder and the casual ladder of :game :mode in the TWENTY ONE esports league: rank, rating and results of every player.', $replace)
            : __('The rated season ladder and the casual ladder of :game :mode in the TWENTY ONE esports league: rank, rating and results of every lineup.', $replace));
    }

    public function pickPool(string $pool): void
    {
        $this->pool = $pool === Rating::CASUAL ? Rating::CASUAL : Rating::RATED;
    }

    #[Computed]
    public function gameMode(): GameMode
    {
        return app(GameRegistry::class)->mode($this->game, $this->mode);
    }

    /** Null while this pool has no open ladder (rated before Block 0). */
    #[Computed]
    public function season(): ?string
    {
        return Ratings::season($this->pool, $this->game, $this->mode);
    }

    /**
     * Rows ordered by rating, then more results, then first rated. Rank
     * numbers count every row; ties share nothing, the order decides.
     *
     * @return Collection<int, array{rank: int, row: Rating, summary: array<string, mixed>}>
     */
    #[Computed]
    public function rows(): Collection
    {
        if ($this->season === null) {
            return collect();
        }

        return Rating::query()
            ->where(['pool' => $this->pool, 'season' => $this->season, 'game' => $this->game, 'mode' => $this->mode])
            ->where('results', '>', 0)
            ->with(['user.clanMember.clan', 'lineup.clan'])
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')
            ->limit(200)
            ->get()
            ->values()
            ->map(fn (Rating $row, int $index) => ['rank' => $index + 1, 'row' => $row, 'summary' => Ratings::summary($row, $this->pool)]);
    }

    /**
     * @return list<array{0: string, 1: string}> mode slug => label, for the switch
     */
    #[Computed]
    public function modes(): array
    {
        return array_values(array_map(fn (GameMode $mode) => [$mode->slug, $this->game === 'chess' ? __($mode->name) : $mode->name],
            app(GameRegistry::class)->get($this->game)->modes()));
    }
}; ?>

@php
    $registry = app(\App\Games\GameRegistry::class);
    $gameName = __($registry->get($game)->name());
    $modeName = $game === 'chess' ? __($this->gameMode->name) : $this->gameMode->name;
    $rows = $this->rows;
    $players = $this->gameMode->rates === 'player';
    $rated = $pool === 'rated';
    $tab = 'inline-flex h-11 cursor-pointer items-center border-0 px-4 text-[13px] whitespace-nowrap';
@endphp

<div class="flex grow flex-col gap-4 px-4 pb-8 lg:gap-6 lg:px-12" data-test="ladder">
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <h1 class="m-0 flex flex-wrap items-baseline gap-x-3 font-display text-[26px] font-bold lg:text-[34px]">
            {{ $gameName }} {{ $modeName }}
            <span class="font-sans text-xs font-normal text-ink-2">{{ __('Ladder') }}</span>
        </h1>

        <div class="flex flex-wrap items-center gap-3">
            <nav aria-label="{{ __('Mode') }}" class="flex overflow-hidden rounded-md border border-edge">
                @foreach ($this->modes as [$slug, $label])
                    <a href="{{ route('ladder.show', [$game, $slug]) }}{{ $rated ? '' : '?pool=casual' }}" wire:key="m-{{ $slug }}"
                       @if ($slug === $mode) aria-current="page" @endif
                       @class([$tab, 'border-l border-edge' => ! $loop->first, 'bg-btc font-bold text-on-btc hover:text-on-btc' => $slug === $mode, 'bg-ground text-ink-2 hover:text-ink' => $slug !== $mode])>{{ $label }}</a>
                @endforeach
            </nav>
            <div role="group" aria-label="{{ __('Ladder') }}" class="flex overflow-hidden rounded-md border border-edge">
                @foreach (['rated' => __('Rated'), 'casual' => __('Casual')] as $key => $label)
                    <button type="button" wire:click="pickPool('{{ $key }}')" aria-pressed="{{ $pool === $key ? 'true' : 'false' }}" data-test="pool-{{ $key }}"
                            @class([$tab, 'border-l border-edge' => $key === 'casual', 'bg-raised font-bold text-ink' => $pool === $key, 'bg-ground text-ink-2' => $pool !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    @if ($rated && $this->season === null)
        <section class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-8 lg:py-7" data-test="ladder-preseason">
            <x-empty-state :heading="__('Pre-Season starts at Block 0')"
                           :text="$players
                               ? __('The ladder fills with the first rated game after Block 0. Every player starts at :elo Elo. Until then every game is casual and counts for the casual ladder only.', ['elo' => (int) config('season.rating.start')])
                               : __('The ladder fills with the first rated series after Block 0. Every lineup starts at :elo Elo. Until then every match is casual and counts for the casual ladder only.', ['elo' => (int) config('season.rating.start')])">
                <button type="button" wire:click="pickPool('casual')" class="btn-p inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-btc px-5 text-sm font-bold text-on-btc">{{ __('Show the casual ladder') }}</button>
                <a href="{{ $game === 'chess' ? route('chess.lobby') : route('games.rocket-league') }}" class="inline-flex h-11 items-center rounded-md border border-edge bg-ground px-5 text-sm text-ink hover:text-ink">{{ __('Play a casual game') }}</a>
            </x-empty-state>
        </section>
    @else
        <section aria-labelledby="ladder-h" class="flex flex-col rounded-lg bg-card px-2 py-4 lg:px-6 lg:py-5">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-2 pb-3 lg:px-0">
                <h2 id="ladder-h" class="m-0 text-[15px] font-bold">{{ $rated ? __('Rated ladder') : __('Casual ladder') }}</h2>
                <span class="text-xs text-ink-3">{{ $rated ? __('rank from Elo alone, provisional until :n rated results', ['n' => (int) config('season.rating.provisional')]) : __('permanent, no tier, no reward, no season reset') }}</span>
            </span>

            <div @class(['grid h-8 items-center gap-3 border-b border-hairline px-2 text-xs font-bold text-ink-2',
                    'grid-cols-[28px_minmax(0,1fr)_56px] lg:grid-cols-[40px_minmax(0,1fr)_150px_72px_72px_96px]' => $rated,
                    'grid-cols-[28px_minmax(0,1fr)_56px] lg:grid-cols-[40px_minmax(0,1fr)_72px_72px_96px]' => ! $rated])>
                <span>#</span>
                <span>{{ $players ? __('Player') : __('Lineup') }}</span>
                @if ($rated)<span class="hidden lg:block">{{ __('Tier') }}</span>@endif
                <span class="text-right">{{ __('Elo') }}</span>
                <span class="hidden text-right lg:block">{{ $this->game === 'chess' ? __('Games') : __('Series played') }}</span>
                <span class="hidden text-right lg:block">{{ $this->gameMode->allowsDraws ? __('W / D / L') : __('W / L') }}</span>
            </div>

            @forelse ($rows as $entry)
                @php
                    $row = $entry['row'];
                    $summary = $entry['summary'];
                    $badge = \App\Support\Rating\Ratings::badge($summary['tier']);
                    $href = $players ? ($row->user ? route('players.show', $row->user->npub) : null) : ($row->lineup?->clan ? route('clans.show', $row->lineup->clan) : null);
                    $name = $players ? ($row->user?->displayName() ?? __('Deleted account')) : ($row->lineup?->clan?->name ?? __('Deleted lineup'));
                    $clan = $players ? $row->user?->clanMember?->clan : $row->lineup?->clan;
                @endphp
                <a @if ($href) href="{{ $href }}" @endif wire:key="r-{{ $row->id }}" data-test="ladder-row"
                   @class(['tr grid min-h-[52px] items-center gap-3 rounded-sm px-2 py-1.5 text-[13px] text-ink hover:text-ink',
                       'grid-cols-[28px_minmax(0,1fr)_56px] lg:grid-cols-[40px_minmax(0,1fr)_150px_72px_72px_96px]' => $rated,
                       'grid-cols-[28px_minmax(0,1fr)_56px] lg:grid-cols-[40px_minmax(0,1fr)_72px_72px_96px]' => ! $rated])>
                    <span class="text-ink-3">{{ $entry['rank'] }}</span>
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="flex min-w-0 items-center gap-2">
                            @if ($players && $row->user)<x-avatar :name="$row->user->displayName()" :src="$row->user->avatarUrl()" :size="22" />@endif
                            <x-clan-tag :clan="$clan" />
                            <span class="truncate font-bold" data-test="ladder-name">{{ $name }}</span>
                        </span>
                        @if ($rated)
                            <span class="lg:hidden"><x-rank-badge :tier="$badge['tier']" :level="$badge['level']" size="sm" /></span>
                        @elseif ($summary['provisional'])
                            <span class="text-[11px] text-ink-3">{{ __('provisional') }}</span>
                        @endif
                    </span>
                    @if ($rated)
                        <span class="hidden lg:block" data-test="ladder-tier"><x-rank-badge :tier="$badge['tier']" :level="$badge['level']" /></span>
                    @endif
                    <b class="text-right text-[15px]" data-test="ladder-elo">{{ $summary['rating'] }}</b>
                    <span class="hidden text-right text-ink-2 lg:block">{{ $summary['results'] }}</span>
                    <span class="hidden text-right whitespace-nowrap text-ink-2 lg:block">{{ $this->gameMode->allowsDraws ? $summary['wins'].' / '.$summary['draws'].' / '.$summary['losses'] : $summary['wins'].' / '.$summary['losses'] }}</span>
                </a>
            @empty
                <x-empty-state class="px-2 py-6" :heading="$rated ? __('No rated results yet') : __('No casual results yet')"
                               :text="__('The first finished game opens the table. Every new entry starts at :elo Elo.', ['elo' => (int) config($rated ? 'season.rating.start' : 'season.casual.start')])" />
            @endforelse

            @if (! $rated && config('season.casual.daily_pair_limit') !== null)
                <p class="mx-2 mt-3 mb-0 border-t border-hairline pt-3 text-xs leading-[1.6] text-ink-2 lg:mx-0">{{ __('Casual Elo is just for fun: it never counts for ranks, badges, Block Height, mining or rewards. At most :n games of the same pairing per day move it.', ['n' => (int) config('season.casual.daily_pair_limit')]) }}</p>
            @endif
        </section>
    @endif
</div>
