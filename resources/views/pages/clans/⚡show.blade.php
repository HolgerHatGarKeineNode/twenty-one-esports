<?php

use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Clans\ClanStatsPreview;
use App\Support\Nostr\NostrKeys;
use App\Support\PageMeta;
use App\Support\Seo\LocalizedUrls;
use App\Support\Seo\StructuredData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Clan page, 1:1 from ClanShow.dc.html. Name, tag, meetup, players, roles,
 * lineups and the Proof block are real; Elo, tiers, Block Height, Clan
 * Rating, Hashrate, series and matches come from ClanStatsPreview until
 * P6/P7 deliver them.
 */
new #[Layout('layouts::app', ['section' => 'clans'])] class extends Component {
    #[Locked]
    public int $clanId;

    /** Hashrate window: `s` season, `w` last 7 days. */
    public string $window = 'w';

    public function mount(Clan $clan): void
    {
        $this->clanId = $clan->id;
    }

    public function pickWindow(string $window): void
    {
        $this->window = $window === 's' ? 's' : 'w';
    }

    #[Computed]
    public function clan(): Clan
    {
        return Clan::query()->with(['members.user', 'lineups.seats.user', 'lineups.clan'])->findOrFail($this->clanId);
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $clan = $this->clan;
        $view->title($clan->name);

        $locale = app()->getLocale();
        $description = trans_choice(':name [:tag] is a clan in the TWENTY ONE esports league with :count player.|:name [:tag] is a clan in the TWENTY ONE esports league with :count players.',
            $clan->members->count(), ['name' => $clan->name, 'tag' => $clan->clantag]);

        app(PageMeta::class)
            ->describe($clan->name, $description.($clan->meetup_name ? ' '.__('Meetup: :name', ['name' => $clan->meetup_name]).'.' : ''))
            ->addStructuredData(StructuredData::breadcrumbs([
                [__('Home'), LocalizedUrls::for($locale, route('home'))],
                [__('Clans'), LocalizedUrls::for($locale, route('clans.index'))],
                [$clan->name, LocalizedUrls::for($locale, route('clans.show', $clan))],
            ]));
    }

    /**
     * Owner first, then captains, then players in join order.
     *
     * @return list<ClanMember>
     */
    #[Computed]
    public function members(): array
    {
        $clan = $this->clan;

        return $clan->members->sortBy(fn (ClanMember $member) => [
            $member->user_id === $clan->owner_id ? 0 : ($member->role === ClanRole::Captain ? 1 : 2),
            $member->joined_at->getTimestamp(),
        ])->values()->all();
    }

    /**
     * RL lineups in mode order.
     *
     * @return array<string, Lineup>
     */
    #[Computed]
    public function lineups(): array
    {
        return $this->clan->lineups->where('game', 'rocket-league')->sortBy(fn (Lineup $lineup) => array_search($lineup->mode, ['3v3', '2v2', '1v1'], true))
            ->keyBy('mode')->all();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    #[Computed]
    public function proof(): array
    {
        $clan = $this->clan;
        $rows = [[__('Clan record'), $clan->naddr().' · kind 32150']];

        foreach ($this->lineups as $mode => $lineup) {
            $rows[] = [__(':mode lineup', ['mode' => $mode]), NostrKeys::naddr(Lineup::KIND, $clan->owner_pubkey, $lineup->d()).' · kind 32151'];
        }

        $rows[] = [__('Memberships'), trans_choice(':count player confirmed with their own key|:count players confirmed with their own key', $clan->members->count()).' · kind 12150'];

        $event = $clan->event_id === null ? null : NostrEvent::query()->where('event_id', $clan->event_id)->with('deliveries')->first();
        $relays = $event?->deliveries->where('accepted', true)->pluck('relay')->all() ?? [];
        $rows[] = [__('Relay'), $event === null ? __('not published yet') : ($relays === [] ? __('waiting for the relays') : implode(', ', $relays))];

        return $rows;
    }

    public function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->clan->memberOf($user)?->role === ClanRole::Captain;
    }
}; ?>

@php
    $clan = $this->clan;
    $tag = $clan->clantag;
    $lineups = $this->lineups;
    $lead = $lineups['3v3'] ?? reset($lineups) ?: null;
    $leadStats = $lead ? ClanStatsPreview::lineup($tag, $lead->mode) : null;
    $rating = ClanStatsPreview::clanRating($tag);
    $ranks = ClanStatsPreview::ranks($tag);
    $hash = ClanStatsPreview::hashrate($tag);
    $record = ClanStatsPreview::record($tag);
    $season = $window === 's';
    $membersCount = $clan->members->count();
    $paid = $clan->members->filter(fn ($member) => $member->user->is_member)->count();
    $owner = $clan->members->firstWhere('user_id', $clan->owner_id)?->user;

    // Hashrate contributions: players plus the team-win bonus (P7 computes these).
    $contrib = collect($this->members)->map(fn ($member) => ['name' => $member->user->displayName(), 'points' => ClanStatsPreview::player((string) $member->user->name)[$season ? 'season' : 'week'], 'bonus' => false])
        ->push(['name' => __('Team wins +5'), 'points' => $season ? $hash['seasonBonus'] : $hash['weekBonus'], 'bonus' => true]);
    $sum = max(1, $contrib->sum('points'));
    $peak = max(1, $contrib->max('points'));
@endphp

<div class="flex grow flex-col gap-4 px-4 pb-6 lg:gap-6 lg:px-12 lg:pb-8">
    {{-- Header: tag cube, name, chips, manage --}}
    <div class="flex flex-wrap items-end gap-5 pt-4 lg:flex-nowrap lg:gap-7">
        {{-- The clan logo, or the tag cube when there is none (x-clan-tag tile). --}}
        <x-clan-tag :clan="$clan" :tile="88" class="cube mr-4 flex size-16 shrink-0 items-center justify-center bg-[linear-gradient(180deg,#F9B25F,#F7931A)] font-display text-base font-extrabold text-on-btc lg:size-[88px] lg:text-[22px]" />
        <div class="flex min-w-0 basis-full flex-col gap-3 sm:basis-auto">
            <h1 class="m-0 font-display text-[28px] leading-[1.1] font-bold break-words lg:text-4xl">{{ $clan->name }}</h1>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                @if ($clan->meetup_name)
                    <a href="{{ $clan->meetup_url ?? '#' }}" @if ($clan->meetup_url) target="_blank" rel="noopener" @endif class="inline-flex h-7 items-center gap-1.5 rounded-sm bg-btc-chip px-2.5 text-btc-hi hover:text-btc-hi">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.1-7-11a7 7 0 0 1 14 0c0 4.9-7 11-7 11z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>
                        {{ __('Meetup: :name', ['name' => $clan->meetup_name]) }}
                    </a>
                @endif
                @if ($leadStats)
                    <span class="inline-flex h-7 items-center rounded-sm border border-line bg-ground px-2.5"><x-rank-badge :tier="$leadStats['tier']" :level="$leadStats['level']" /></span>
                @endif
                <span class="inline-flex h-7 items-center rounded-sm bg-well px-2.5 text-ink-2">{{ $lineups === [] ? __('No lineups yet') : __('Lineups :modes', ['modes' => implode(' · ', array_keys($lineups))]) }}</span>
                <span class="inline-flex h-7 items-center rounded-sm bg-well px-2.5 text-ink-2">{{ __('founded :date', ['date' => $clan->created_at?->translatedFormat('M j')]) }}</span>
                @if ($clan->isMemberClan())
                    <span title="{{ __('Most players in this clan are EINUNDZWANZIG members') }}" class="inline-flex h-7 items-center rounded-sm border border-btc-deep px-2.5 font-bold text-btc">{{ __('EINUNDZWANZIG Member clan') }}</span>
                @endif
            </div>
        </div>
        <span class="hidden grow lg:block"></span>
        @if ($this->canManage())
            <a href="{{ route('clans.manage', $clan) }}" class="btn-p inline-flex h-11 items-center gap-2 rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc">{{ __('Manage clan') }}</a>
        @endif
    </div>

    {{-- Players and lineup numbers --}}
    <div class="grid grid-cols-1 gap-6 rounded-lg bg-card px-4 pt-2 pb-4 lg:grid-cols-2 lg:gap-12 lg:px-6">
        <div class="flex flex-col">
            <div class="flex h-11 items-center justify-between gap-3 border-b border-hairline text-sm"><span class="text-ink-2">{{ __('Players') }}</span><span class="hidden text-xs text-ink-3 sm:inline">{{ __('everyone joined with their own invite') }}</span></div>
            @foreach ($this->members as $member)
                @php($stats = ClanStatsPreview::player((string) $member->user->name))
                <div wire:key="m-{{ $member->id }}" class="row grid h-14 grid-cols-[32px_minmax(0,1fr)_76px] items-center gap-3 border-b border-hairline px-2 text-[13px] sm:grid-cols-[32px_minmax(0,1fr)_132px_110px]">
                    {{-- The Nostr picture, or the generated Blockpile (P10a). --}}
                    <x-avatar :user="$member->user" :size="32" />
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span class="flex min-w-0 items-center gap-2"><x-player-link :user="$member->user" class="relative inline-flex min-h-6 min-w-0 items-center font-bold after:absolute after:inset-x-0 after:-inset-y-2.5" data-test="roster-name"><span class="truncate">{{ $member->user->displayName() }}</span></x-player-link>@if ($member->user->is_member)<x-member-badge />@endif</span>
                        <span class="text-[11px] text-ink-3">{{ $member->role === ClanRole::Captain ? __('captain') : __('player') }}</span>
                    </span>
                    <span class="hidden gap-1 sm:flex">
                        @foreach (['3v3', '2v2', '1v1'] as $mode)
                            @php($seated = isset($lineups[$mode]) && $lineups[$mode]->seats->contains(fn (LineupSeat $seat) => $seat->user_id === $member->user_id && $seat->accepted_at !== null))
                            <span title="{{ $seated ? __('in the :mode lineup', ['mode' => $mode]) : __('not in the :mode lineup', ['mode' => $mode]) }}"
                                  @class(['inline-flex h-[22px] items-center rounded-sm border border-dash px-1.5 text-[11px]', 'text-ink' => $seated, 'border-dashed text-ink-3' => ! $seated])>{{ $mode }}</span>
                        @endforeach
                    </span>
                    <span class="flex flex-col items-end gap-0.5 text-xs" title="{{ __('Block Height: rated games in any game') }}"><b class="text-sm">{{ $stats['blockHeight'] }}</b><span class="text-[11px] text-ink-3">{{ __('Block Height') }}</span></span>
                </div>
            @endforeach
            <div class="grid h-11 grid-cols-[120px_minmax(0,1fr)] items-center border-b border-hairline text-sm lg:grid-cols-[180px_minmax(0,1fr)]"><span class="text-ink-2">{{ __('Captain') }}</span>
                @if ($owner)<x-player-link :user="$owner" class="relative inline-flex min-w-0 after:absolute after:inset-x-0 after:-inset-y-3"><span class="truncate">{{ $owner->displayName() }}</span></x-player-link>@else<span class="text-ink-3">–</span>@endif
            </div>
            <div class="grid h-11 grid-cols-[120px_minmax(0,1fr)] items-center border-b border-hairline text-sm lg:grid-cols-[180px_minmax(0,1fr)]"><span class="text-ink-2">{{ __('Members') }}</span><span class="truncate">{{ __(':paid of :total are EINUNDZWANZIG members', ['paid' => $paid, 'total' => $membersCount]) }}</span></div>
        </div>

        <div class="flex flex-col">
            <div class="grid h-11 grid-cols-[minmax(0,1fr)_56px_56px_96px] items-center border-b border-hairline text-xs text-ink-2 lg:grid-cols-[minmax(0,1fr)_64px_72px_136px] lg:text-[13px]"><span></span><span class="text-right">{{ __('30 days') }}</span><span class="text-right">{{ __('Total') }}</span><span class="text-right">Testnet Cup</span></div>
            @foreach ($record['stats'] ?? [['Series', '0', '0', '–'], ['Wins', '0', '0', '–'], ['Goals (team)', '0', '0', '–']] as [$label, $days, $total, $cup])
                <div class="grid h-11 grid-cols-[minmax(0,1fr)_56px_56px_96px] items-center border-b border-hairline text-sm lg:grid-cols-[minmax(0,1fr)_64px_72px_136px]"><span class="text-ink-2">{{ __($label) }}</span><span class="text-right">{{ $days }}</span><b class="text-right">{{ $total }}</b><span class="text-right">{{ $cup }}</span></div>
            @endforeach
            @foreach (['3v3', '2v2', '1v1'] as $mode)
                @continue(! isset($lineups[$mode]))
                @php($line = ClanStatsPreview::lineup($tag, $mode))
                <div class="grid h-11 grid-cols-[minmax(0,1fr)_auto_48px] items-center gap-3 border-b border-hairline text-sm sm:grid-cols-[minmax(0,1fr)_auto_64px_136px]">
                    <span class="text-ink-2">{{ __('Elo :mode', ['mode' => $mode]) }}</span>
                    <span @class(['inline-flex h-6 items-center rounded-sm bg-ground px-2', 'border border-dashed border-edge' => $line['tier'] === 'provisional', 'border border-line' => $line['tier'] !== 'provisional'])><x-rank-badge :tier="$line['tier']" :level="$line['level']" /></span>
                    <b class="text-right">{{ $line['elo'] }}</b>
                    <span class="hidden text-right text-ink-2 sm:block">{{ $line['series'] === 0 ? __('no series yet') : __('#:rank of :of', ['rank' => $line['rank'], 'of' => $line['of']]) }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)] lg:gap-6">
        {{-- Clan Rating (P7) --}}
        <section aria-labelledby="cr-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex items-baseline justify-between gap-3"><h2 id="cr-h" class="m-0 text-[15px] font-bold">{{ __('Clan Rating · chess') }}</h2>
                <span class="text-xs text-ink-3">{{ $ranks['rating'] ? __('#:rank of :of rated clans', ['rank' => $ranks['rating'], 'of' => $ranks['ratedClans']]) : __('not rated yet') }}</span></span>
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <b class="font-display text-[40px] leading-[1.1] font-bold">{{ $rating['rating'] ?? '–' }}</b>
                @if ($tag === 'LSR')
                    <span class="inline-flex min-h-6 items-center gap-1.5 text-[13px] font-bold text-win">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"></path></svg>
                        {{ __('+9 from team match #404') }}
                    </span>
                @endif
            </div>
            <span class="text-[13px] leading-[1.6] text-ink-2">{{ __('Average of the 3 best solo Elos in the clan. Chess has no separate team Elo.') }}</span>
            <div class="flex flex-col">
                @php($top = collect($this->members)->map(fn ($member) => ['user' => $member->user, 'stats' => ClanStatsPreview::player((string) $member->user->name)])->filter(fn ($row) => $row['stats']['blitz'] > 0)->sortByDesc(fn ($row) => $row['stats']['blitz'])->take(3)->values())
                @forelse ($top as $index => $row)
                    <div class="grid h-11 grid-cols-[20px_minmax(0,1fr)_auto_48px] items-center gap-3 border-b border-hairline text-[13px] lg:grid-cols-[20px_minmax(0,1fr)_124px_48px]">
                        <span class="text-ink-3">{{ $index + 1 }}</span>
                        <x-player-link :user="$row['user']" class="relative inline-flex min-h-6 min-w-0 items-center font-bold after:absolute after:inset-x-0 after:-inset-y-2.5"><span class="truncate">{{ $row['user']->displayName() }}</span></x-player-link>
                        <x-rank-badge :tier="$row['stats']['tier']" :level="$row['stats']['level']" class="font-normal" />
                        <b class="text-right">{{ $row['stats']['blitz'] }}</b>
                    </div>
                @empty
                    <p class="m-0 text-[13px] text-ink-2">{{ __('No player has a blitz Elo yet.') }}</p>
                @endforelse
            </div>
            <span class="text-xs text-ink-3">
                @if ($rating['rating'])
                    ({{ implode(' + ', $rating['top']) }}) / 3 = {{ $rating['rating'] }}@if ($tag === 'LSR'){{ __(', before #404: 1082') }}@endif
                @else
                    {{ __('needs 3 blitz Elos') }}
                @endif
            </span>
        </section>

        {{-- Clan Hashrate (P7) --}}
        <section aria-labelledby="hc-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex flex-wrap items-center justify-between gap-3">
                <span class="flex flex-col gap-0.5"><h2 id="hc-h" class="m-0 text-[15px] font-bold">{{ __('Clan Hashrate') }}</h2><span class="text-xs text-ink-3">{{ __('points from every rated game, chess and Rocket League') }}</span></span>
                <div role="group" aria-label="{{ __('Time window') }}" class="flex shrink-0 overflow-hidden rounded-md border border-edge">
                    @foreach (['s' => __('Pre-Season'), 'w' => __('7 days')] as $key => $label)
                        <button type="button" wire:click="pickWindow('{{ $key }}')" aria-pressed="{{ $window === $key ? 'true' : 'false' }}"
                                @class(['h-[42px] cursor-pointer px-3.5 text-[13px]', 'border-l border-edge' => $key === 'w', 'bg-btc font-bold text-on-btc' => $window === $key, 'bg-ground text-ink-2' => $window !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </span>
            <div class="grid grid-cols-2 gap-3">
                @foreach ([['s', __('Pre-Season'), $hash['season'], $ranks['season']], ['w', __('last 7 days'), $hash['week'], $ranks['week']]] as [$key, $label, $points, $rank])
                    <span @class(['flex flex-col gap-1 rounded-md bg-ground px-4 py-3', 'shadow-[inset_0_0_0_1px_#F7931A]' => $window === $key, 'shadow-ring' => $window !== $key])>
                        <span class="text-xs text-ink-2">{{ $label }}</span><b class="font-display text-2xl">{{ $points }}</b>
                        <span class="text-xs text-ink-3">{{ $rank ? __('#:rank of :of clans', ['rank' => $rank, 'of' => $ranks['clans']]) : __('no points yet') }}</span>
                    </span>
                @endforeach
            </div>
            <div class="flex flex-col">
                <div class="grid h-8 grid-cols-[110px_minmax(0,1fr)_40px_44px] items-center gap-3 border-b border-hairline text-xs text-ink-3 lg:grid-cols-[170px_minmax(0,1fr)_56px_64px]"><span>{{ __('Share :window', ['window' => $season ? __('Pre-Season') : __('7 days')]) }}</span><span></span><span class="text-right">{{ __('Points') }}</span><span class="text-right">{{ __('Share') }}</span></div>
                @foreach ($contrib as $row)
                    <div class="grid h-10 grid-cols-[110px_minmax(0,1fr)_40px_44px] items-center gap-3 border-b border-hairline text-[13px] lg:grid-cols-[170px_minmax(0,1fr)_56px_64px]" title="{{ $row['name'] }}: {{ $row['points'] }}">
                        <span @class(['truncate', 'text-ink-2' => $row['bonus']])>{{ $row['name'] }}</span>
                        <span class="block h-3 rounded-r bg-raised"><span @class(['block h-3 animate-fill rounded-r', 'bg-btc-deep' => $row['bonus'], 'bg-btc' => ! $row['bonus']]) style="width: {{ number_format($row['points'] / $peak * 100, 1) }}%"></span></span>
                        <b class="text-right">{{ $row['points'] }}</b>
                        <span class="text-right text-ink-2">{{ round($row['points'] / $sum * 100) }}%</span>
                    </div>
                @endforeach
                <div class="grid h-10 grid-cols-[110px_minmax(0,1fr)_40px_44px] items-center gap-3 text-[13px] lg:grid-cols-[170px_minmax(0,1fr)_56px_64px]"><b>{{ __('Total') }}</b><span class="truncate text-xs text-ink-3">{{ __('win 3, draw 2, loss 1, team match +5') }}</span><b class="text-right text-btc">{{ $contrib->sum('points') }}</b><span class="text-right text-ink-2">100%</span></div>
            </div>
        </section>
    </div>

    {{-- Elo over time (P6/P7) --}}
    <section aria-labelledby="elo-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1"><h2 id="elo-h" class="m-0 text-[15px] font-bold">{{ __('Elo over time') }}</h2><span class="text-xs text-ink-3">{{ __('Rocket League 3v3 lineup, per rated series') }}</span></div>
        @if ($record)
            @php($points = collect($record['line'])->map(fn ($elo, $i) => number_format($i / (count($record['line']) - 1) * 600, 1, '.', '').','.number_format(160 - ($elo - 1000) / 200 * 160, 1, '.', ''))->implode(' '))
            <div class="grid h-[200px] grid-cols-[44px_minmax(0,1fr)] gap-2">
                <div class="flex flex-col justify-between pb-5 text-right text-[11px] text-ink-3"><span>1200</span><span>1100</span><span>1000</span></div>
                <div class="flex flex-col gap-1.5">
                    <div class="relative grow border-b border-line [background-image:linear-gradient(#1E1E22_1px,transparent_1px)] [background-size:100%_50%]">
                        <svg width="100%" height="100%" viewBox="0 0 600 160" preserveAspectRatio="none" class="absolute inset-0" role="img" aria-label="{{ __('Elo of the 3v3 lineup over all :n rated series, from 1000 at the start to :elo', ['n' => count($record['line']) - 1, 'elo' => end($record['line'])]) }}">
                            <polyline points="{{ $points }}" fill="none" stroke="#F7931A" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke"></polyline>
                        </svg>
                        <span class="absolute top-1 right-0 bg-card px-1 text-xs font-bold">{{ end($record['line']) }}</span>
                    </div>
                    <div class="flex justify-between text-[11px] text-ink-3"><span>{{ __('start') }}</span><span>{{ __('series :n', ['n' => 10]) }}</span><span>{{ __('series :n', ['n' => 20]) }}</span></div>
                </div>
            </div>
        @else
            <p class="m-0 py-6 text-center text-[13px] text-ink-2">{{ __('The Elo line starts with the first rated series.') }}</p>
        @endif
    </section>

    {{-- Matches (P6) --}}
    <section aria-labelledby="mt-h" class="flex flex-col rounded-lg bg-card px-4 pt-2 pb-4 lg:px-6">
        <div class="flex h-[52px] items-center justify-between gap-3"><h2 id="mt-h" class="m-0 text-[15px] font-bold">{{ __('Matches') }}</h2><a href="{{ route('matches.index') }}" class="flex h-11 items-center truncate text-[13px]">{{ __('All :clan matches', ['clan' => $clan->name]) }}</a></div>
        @if ($record)
            <div class="grid h-10 grid-cols-[48px_minmax(0,1fr)_52px_64px] items-center gap-3 border-b border-hairline px-2 text-[13px] font-bold text-ink-2 lg:grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_88px_120px] lg:gap-4">
                <span>{{ __('Match') }}</span><span>{{ __('Opponent') }}</span><span>{{ __('Score') }}</span><span class="hidden lg:block">{{ __('Format') }}</span><span>{{ __('Result') }}</span><span class="hidden text-right lg:block">Elo</span><span class="hidden text-right lg:block">{{ __('When') }}</span>
            </div>
            @foreach ($record['matches'] as [$height, $oppTag, $opponent, $score, $format, $result, $elo, $when])
                <a href="{{ route('matches.show', ltrim($height, '#')) }}" class="tr grid h-11 grid-cols-[48px_minmax(0,1fr)_52px_64px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_88px_120px] lg:gap-4">
                    <span class="font-bold text-btc">{{ $height }}</span>
                    <span class="flex min-w-0 items-center gap-2 whitespace-nowrap"><x-clan-tag :tag="$oppTag" size="sm" /><span class="truncate">{{ $opponent }}</span></span>
                    <span class="font-bold whitespace-nowrap">{{ $score }}</span>
                    <span class="hidden text-ink-2 lg:block">{{ $format }}</span>
                    <span @class(['flex items-center gap-1.5 font-bold', 'text-win' => $result === 'win', 'text-loss' => $result === 'loss', 'text-btc-hi' => $result === 'wait'])>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0"><path d="{{ ['win' => 'M5 12.5 10 17 19 7', 'loss' => 'M6 6l12 12M18 6 6 18', 'wait' => 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0'][$result] }}"></path></svg>
                        <span class="truncate">{{ $result === 'wait' ? __('to confirm') : __($result) }}</span>
                    </span>
                    <span @class(['hidden text-right font-bold lg:block', 'text-win' => str_starts_with($elo, '+'), 'text-loss' => str_starts_with($elo, '−'), 'text-ink-3' => ! str_starts_with($elo, '+') && ! str_starts_with($elo, '−')])>{{ __($elo) }}</span>
                    <span class="hidden text-right text-ink-2 lg:block">{{ __($when) }}</span>
                </a>
            @endforeach
        @else
            <p class="m-0 py-6 text-center text-[13px] text-ink-2">{{ __('No matches yet. The first challenge shows up here.') }}</p>
        @endif
    </section>

    <x-proof :rows="$this->proof" toggle="show" class="rounded-lg !bg-proof-fill px-1.5 !shadow-[inset_0_0_0_1px_#3B2F5C]">
        {{ __('Each player confirmed joining this clan from their own account.') }} <a href="{{ route('protocol') }}">{{ __('Open protocol') }}</a>
    </x-proof>
</div>
