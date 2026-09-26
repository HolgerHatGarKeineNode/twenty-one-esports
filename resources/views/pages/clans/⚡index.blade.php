<?php

use App\Models\Clan;
use App\Models\ClanMember;
use App\Support\Clans\ClanStatsPreview;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Clans, 1:1 from Clans.dc.html. Clans, players and meetup pins are real;
 * Clan Rating, Hashrate and "Blocks mined" come from ClanStatsPreview until
 * P6/P7 compute them.
 */
new #[Layout('layouts::app', ['section' => 'clans'])] class extends Component {
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Clans'));
        app(PageMeta::class)->describe(__('Clans'), __('All clans of the TWENTY ONE esports league: players, meetups on the map, Clan Rating and Hashrate.'));
    }

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Hashrate window: `s` season, `w` last 7 days (design default). */
    public string $window = 'w';

    public function pickWindow(string $window): void
    {
        $this->window = $window === 's' ? 's' : 'w';
    }

    /**
     * @return Collection<int, Clan>
     */
    #[Computed]
    public function clans(): Collection
    {
        $search = trim($this->search);

        return Clan::query()
            ->with('members.user')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('clantag', "%{$search}%")
                ->orWhereLike('meetup_city', "%{$search}%")))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{clans: int, newest: Clan|null, players: int, meetups: int, blocks: int}
     */
    #[Computed]
    public function counters(): array
    {
        return [
            'clans' => Clan::query()->count(),
            'newest' => Clan::query()->latest('created_at')->latest('id')->first(),
            'players' => ClanMember::query()->count(),
            'meetups' => Clan::query()->whereNotNull('meetup_name')->count(),
            'blocks' => ClanStatsPreview::BLOCKS_MINED, // P7: count of confirmed rated results
        ];
    }

    /**
     * Pins on the placeholder map: a plain projection of the meetup
     * coordinates onto the German-speaking area.
     *
     * @return list<array{clan: Clan, tag: string, city: string, x: string, y: string}>
     */
    #[Computed]
    public function pins(): array
    {
        return Clan::query()->whereNotNull('meetup_latitude')->whereNotNull('meetup_longitude')->get()
            ->map(fn (Clan $clan) => [
                'clan' => $clan,
                'tag' => $clan->clantag,
                'city' => (string) $clan->meetup_city,
                'x' => max(1, min(80, round(((float) $clan->meetup_longitude - 7.0) / 12.3 * 100))).'%',
                'y' => max(4, min(86, round((52.3 - (float) $clan->meetup_latitude) / 5.85 * 100))).'%',
            ])->values()->all();
    }

    /**
     * @return list<array{clan: Clan, rank: int|string, rating: int|null, top: list<int>, member: bool}>
     */
    #[Computed]
    public function byRating(): array
    {
        $rows = $this->clans->map(fn (Clan $clan) => ['clan' => $clan, ...ClanStatsPreview::clanRating($clan->clantag), 'member' => $clan->isMemberClan()])
            ->sortBy([fn ($a, $b) => ($b['rating'] ?? -1) <=> ($a['rating'] ?? -1)])->values()->all();

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $row['rating'] === null ? '–' : $index + 1;
        }

        return $rows;
    }

    /**
     * @return array{rows: list<array{clan: Clan, rank: int, points: int, bonus: int, width: string, share: string, member: bool}>, total: int}
     */
    #[Computed]
    public function byHash(): array
    {
        $season = $this->window === 's';
        $rows = $this->clans->map(function (Clan $clan) use ($season) {
            $hashrate = ClanStatsPreview::hashrate($clan->clantag);

            return ['clan' => $clan, 'points' => $season ? $hashrate['season'] : $hashrate['week'], 'bonus' => $season ? $hashrate['seasonBonus'] : $hashrate['weekBonus'], 'member' => $clan->isMemberClan()];
        })->sortByDesc('points')->values();

        $top = max(1, (int) $rows->max('points'));
        $total = (int) $rows->sum('points');

        return ['total' => $total, 'rows' => $rows->map(fn (array $row, int $index) => [...$row,
            'rank' => $index + 1,
            'width' => number_format($row['points'] / $top * 100, 1).'%',
            'share' => number_format($total === 0 ? 0 : $row['points'] / $total * 100, 1).'%',
        ])->all()];
    }
}; ?>

<div class="flex grow flex-col gap-4 px-4 pb-6 lg:gap-6 lg:px-12 lg:pb-8">
    <div class="flex flex-wrap items-center gap-3 lg:flex-nowrap lg:gap-4">
        <h1 class="m-0 font-display text-2xl font-bold lg:text-[28px]">{{ __('Clans') }}</h1>
        <span class="hidden grow lg:block"></span>
        <label for="clan-q" class="sr-only">{{ __('Search clans') }}</label>
        <span class="relative order-3 flex w-full items-center lg:order-none lg:w-80">
            <x-icon name="search" :size="16" class="pointer-events-none absolute left-3 text-ink-3" />
            <input id="clan-q" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Clan, tag or meetup city') }}"
                   class="h-11 w-full rounded-md border border-edge bg-ground pr-3 pl-9 text-[13px] text-ink placeholder:text-ink-3">
        </span>
        <a href="{{ route('clans.create') }}" class="btn-p ml-auto inline-flex h-11 items-center gap-2 rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc lg:ml-0">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
            {{ __('Start a clan') }}
        </a>
    </div>

    @php($counters = $this->counters)
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-6">
        <div class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="text-[13px] text-ink-2">{{ __('Clans') }}</span>
            <b class="font-display text-2xl font-bold lg:text-[28px]">{{ $counters['clans'] }}</b>
            <span class="text-xs text-ink-2">
                @if ($counters['newest'])
                    {{ __('newest: :name, :date', ['name' => $counters['newest']->name, 'date' => $counters['newest']->created_at?->translatedFormat('M j')]) }}
                @else
                    {{ __('none yet') }}
                @endif
            </span>
        </div>
        <div class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="text-[13px] text-ink-2">{{ __('Players') }}</span>
            <b class="font-display text-2xl font-bold lg:text-[28px]">{{ $counters['players'] }}</b>
            <span class="text-xs text-ink-3">{{ __('in a clan') }}</span>
        </div>
        <div class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="text-[13px] text-ink-2">{{ __('Meetups') }}</span>
            <b class="font-display text-2xl font-bold lg:text-[28px]">{{ $counters['meetups'] }}</b>
            <span class="text-xs text-ink-3">{{ __('clans linked to an EINUNDZWANZIG meetup') }}</span>
        </div>
        <div class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="text-[13px] text-ink-2">{{ __('Blocks mined') }}</span>
            <b class="font-display text-2xl font-bold lg:text-[28px]">{{ $counters['blocks'] }}</b>
            <span class="text-xs text-ink-3">{{ __('rated results since launch') }}</span>
        </div>
    </div>

    <section aria-labelledby="map-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
        <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="map-h" class="m-0 text-[15px] font-bold">{{ __('Meetup map') }}</h2>
            <span class="text-xs text-ink-3">{{ __('Clans by meetup city, from the EINUNDZWANZIG portal') }}</span>
        </span>
        <div role="img" aria-label="{{ trans_choice('Map placeholder with :count clan at its meetup city|Map placeholder with :count clans at their meetup cities', count($this->pins)) }}"
             class="relative h-[168px] overflow-hidden rounded-md bg-ground [background-image:radial-gradient(#26262C_1.2px,transparent_1.2px)] [background-size:12px_12px]">
            @foreach ($this->pins as $pin)
                <span class="absolute flex items-center gap-2" style="left: {{ $pin['x'] }}; top: {{ $pin['y'] }}">
                    <span class="block size-3 rounded-full bg-btc shadow-[0_0_0_4px_rgba(247,147,26,.25)]"></span>
                    <span class="flex items-center gap-1.5 rounded-sm bg-card px-1.5 py-0.5 text-xs">@if ($pin['clan']->localLogoUrl())<x-clan-tag :clan="$pin['clan']" :tile="16" class="block size-4 shrink-0 rounded-xs" aria-hidden="true" />@endif<b>{{ $pin['tag'] }}</b> <span class="hidden text-ink-2 sm:inline">{{ $pin['city'] }}</span></span>
                </span>
            @endforeach
            <span class="absolute right-3 bottom-2.5 hidden text-[11px] text-ink-3 sm:block">{{ __('Map placeholder: coordinates come from the portal meetup') }}</span>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-6">
        {{-- Strongest clans: Clan Rating (P7 computes it; preview numbers until then). --}}
        <section aria-labelledby="cr-h" class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex min-h-10 items-center justify-between gap-3 pb-3">
                <span class="flex flex-col gap-0.5">
                    <h2 id="cr-h" class="m-0 text-[15px] font-bold">{{ __('Strongest clans · Clan Rating') }}</h2>
                    <span class="text-xs text-ink-3">{{ __('chess, average of the 3 best solo Elos') }}</span>
                </span>
                <a href="{{ route('rules') }}" class="inline-flex min-h-11 shrink-0 items-center text-xs">{{ __('How it counts') }}</a>
            </span>
            <div class="grid h-8 grid-cols-[20px_minmax(0,1fr)_72px] items-center gap-3 border-b border-hairline px-2 text-xs font-bold text-ink-2 lg:grid-cols-[24px_minmax(0,1fr)_96px_168px]">
                <span>#</span><span>{{ __('Clan') }}</span><span class="text-right">{{ __('Clan Rating') }}</span><span class="hidden text-right lg:block">{{ __('Top 3 solo Elo') }}</span>
            </div>
            @forelse ($this->byRating as $row)
                <a href="{{ route('clans.show', $row['clan']) }}" wire:key="cr-{{ $row['clan']->id }}"
                   class="tr grid h-[52px] grid-cols-[20px_minmax(0,1fr)_72px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[24px_minmax(0,1fr)_96px_168px]">
                    <span class="text-ink-3">{{ $row['rank'] }}</span>
                    <span class="flex min-w-0 items-center gap-2.5">
                        <x-clan-tag :clan="$row['clan']" />
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="flex min-w-0 items-center gap-2"><span class="truncate">{{ $row['clan']->name }}</span>@if ($row['member'])<x-member-badge />@endif</span>
                            <span class="text-[11px] whitespace-nowrap text-ink-2">{{ trans_choice(':count player|:count players', $row['clan']->members->count()) }}</span>
                        </span>
                    </span>
                    <span class="flex flex-col items-end gap-0.5">
                        <b @class(['text-[15px]', 'text-ink-3' => $row['rating'] === null])>{{ $row['rating'] ?? '–' }}</b>
                        <span class="text-[11px] whitespace-nowrap text-ink-3">{{ $row['rating'] === null ? __('needs 3 blitz Elos') : __('avg top 3') }}</span>
                    </span>
                    <span class="hidden text-right text-xs whitespace-nowrap text-ink-2 lg:block">{{ implode(' · ', [...$row['top'], ...(count($row['top']) < 3 ? [__('missing')] : [])]) }}</span>
                </a>
            @empty
                <p class="m-0 py-6 text-center text-[13px] text-ink-2">{{ __('No clan matches your search.') }}</p>
            @endforelse
            <p class="mt-3 mb-0 border-t border-hairline pt-3 text-xs leading-[1.6] text-ink-2">{{ __('Chess has no separate team Elo: every board of a team match is a rated solo game. Rocket League keeps its Elo per lineup.') }}</p>
        </section>

        {{-- Most active clans: Hashrate (P7 computes it; preview numbers until then). --}}
        @php($hash = $this->byHash)
        <section aria-labelledby="hs-h" class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex min-h-10 flex-wrap items-center justify-between gap-3 pb-3">
                <span class="flex flex-col gap-0.5">
                    <h2 id="hs-h" class="m-0 text-[15px] font-bold">{{ __('Most active clans · Hashrate') }}</h2>
                    <span class="text-xs text-ink-3">{{ __('points from every game, chess and Rocket League') }}</span>
                </span>
                <div role="group" aria-label="{{ __('Time window') }}" class="flex shrink-0 overflow-hidden rounded-md border border-edge">
                    @foreach (['s' => __('Pre-Season'), 'w' => __('7 days')] as $key => $label)
                        <button type="button" wire:click="pickWindow('{{ $key }}')" aria-pressed="{{ $window === $key ? 'true' : 'false' }}"
                                @class(['h-[42px] cursor-pointer px-3 text-[13px] lg:px-3.5', 'border-l border-edge' => $key === 'w',
                                    'bg-btc font-bold text-on-btc' => $window === $key, 'bg-ground text-ink-2' => $window !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </span>
            <div class="grid h-8 grid-cols-[20px_minmax(0,1fr)_96px] items-center gap-3 border-b border-hairline px-2 text-xs font-bold text-ink-2 lg:grid-cols-[24px_minmax(0,1fr)_150px_56px_64px]">
                <span>#</span><span>{{ __('Clan') }}</span><span>{{ __('Hashrate') }}</span><span class="hidden text-right lg:block">{{ __('Team wins') }}</span><span class="hidden text-right lg:block">{{ __('Share') }}</span>
            </div>
            @forelse ($hash['rows'] as $row)
                <a href="{{ route('clans.show', $row['clan']) }}" wire:key="hs-{{ $row['clan']->id }}"
                   class="tr grid h-[52px] grid-cols-[20px_minmax(0,1fr)_96px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[24px_minmax(0,1fr)_150px_56px_64px]">
                    <span class="text-ink-3">{{ $row['rank'] }}</span>
                    <span class="flex min-w-0 items-center gap-2.5">
                        <x-clan-tag :clan="$row['clan']" />
                        <span class="truncate">{{ $row['clan']->name }}</span>
                        @if ($row['member'])<x-member-badge />@endif
                    </span>
                    <span class="grid grid-cols-[minmax(0,1fr)_32px] items-center gap-2 lg:grid-cols-[minmax(0,1fr)_36px]">
                        <span class="block h-2 rounded-r bg-raised"><span class="block h-2 animate-fill rounded-r bg-btc" style="width: {{ $row['width'] }}"></span></span>
                        <b class="text-right">{{ $row['points'] }}</b>
                    </span>
                    <span class="hidden text-right text-ink-2 lg:block">+{{ $row['bonus'] }}</span>
                    <span class="hidden text-right text-ink-2 lg:block">{{ $row['share'] }}</span>
                </a>
            @empty
                <p class="m-0 py-6 text-center text-[13px] text-ink-2">{{ __('No clan matches your search.') }}</p>
            @endforelse
            <p class="mt-3 mb-0 border-t border-hairline pt-3 text-xs leading-[1.6] text-ink-2">
                {{ $window === 'w' ? __('Last 7 days: :n points.', ['n' => $hash['total']]) : __('Pre-Season: :n points.', ['n' => $hash['total']]) }}
                {{ __('Win 3, draw 2, loss 1 per rated game; a won team match or series adds +5 for the clan. Casual games don\'t count.') }}
            </p>
        </section>
    </div>
</div>
