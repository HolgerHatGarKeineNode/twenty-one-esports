<?php

use App\Games\GameRegistry;
use App\Models\Clan;
use App\Support\Clans\ClanStatsPreview;
use App\Support\SampleData;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Rocket League game page, 1:1 from RocketLeague.dc.html. The modes and
 * best-of options come from the game registry and the clan list is real.
 * PLACEHOLDER until later phases: Hashrate (P7), series, results and
 * challenges (P6), the next tournament (P8) and the block strip feed (P7).
 * The "what a series is worth" numbers are the Elo formula (K 32) itself.
 */
new #[Title('Rocket League')] #[Layout('layouts::app', ['section' => 'matches'])] class extends Component {
    /** Rocket League share of each clan's 7-day Hashrate (ledger 2.1: total minus chess). */
    private const RL_WEEK = ['HDL' => 36, 'LSR' => 30, 'OPS' => 17, 'MMP' => 11, 'B21' => 5, 'STK' => 4, 'LNB' => 0, 'NCE' => 0];

    /**
     * @return Collection<int, array{clan: Clan, rank: int, points: int, bonus: int, rl: int, width: string, share: string}>
     */
    #[Computed]
    public function hashrate(): Collection
    {
        $rows = Clan::query()->orderBy('name')->get()->map(function (Clan $clan) {
            $hash = ClanStatsPreview::hashrate($clan->clantag);

            return ['clan' => $clan, 'points' => $hash['week'], 'bonus' => $hash['weekBonus'], 'rl' => self::RL_WEEK[$clan->clantag] ?? 0];
        })->sortByDesc('points')->values();

        $top = max(1, (int) $rows->max('points'));
        $total = max(1, (int) $rows->sum('points'));

        return $rows->map(fn (array $row, int $index) => [...$row, 'rank' => $index + 1,
            'width' => number_format($row['points'] / $top * 100, 1).'%',
            'share' => number_format($row['points'] / $total * 100, 1).'%']);
    }

    /**
     * Elo change of a win and a loss against an opponent `gap` points away (K 32, scale 400).
     *
     * @return array{win: int, loss: int}
     */
    public function stake(int $gap): array
    {
        $expected = 1 / (1 + 10 ** ($gap / 400));

        return ['win' => (int) round(32 * (1 - $expected)), 'loss' => (int) round(32 * $expected)];
    }
}; ?>

@php
    $modes = app(GameRegistry::class)->get('rocket-league')->modes();
    $rows = $this->hashrate;
    $rl = $rows->sortByDesc('rl')->values();
    $rlTotal = $rl->sum('rl');
    $weekTotal = $rows->sum('points');
    $weeks = [4, 5, 8, 5, 5, 6, 11, 8, 5, 7, 21, 14];
    $cups = [6 => true, 10 => true, 11 => true];
    $wins = [['Laser Eyes', 17], ['HODL Rockets', 13], ['Mempool Maniacs', 11], ['Orange Pill Squad', 7], ['Block 21', 7], [__('3 other clans'), 5]];
    $recent = [['#403', 'HODL Rockets', __('2 h ago'), '2:1', '2v2'], ['#402', __('Laser Eyes, to confirm'), __('today'), '3:1', '3v3'], ['#373', 'HODL Rockets', __('yesterday'), '2:0', '2v2'], ['#374', 'Orange Pill Squad', __('2 days ago'), '3:2', '3v3'], ['#370', 'Mempool Maniacs', __('2 days ago'), '2:1', '2v2'], ['#359', 'HODL Rockets', __('5 days ago'), '2:0', '2v2']];
    $open = [['B21', __('challenges :clan', ['clan' => 'Laser Eyes']), '3v3 · BO3', 974, 'Sat 18:40'], ['OPS', __('challenges :clan', ['clan' => 'HODL Rockets']), '3v3 · BO5', 987, 'Sat 19:10'], ['LNB', __('challenges :clan', ['clan' => 'Stack Sats Crew']), '2v2 · BO3', 986, 'Sun 18:00'], ['B21', '#411 vs Stack Sats Crew', '3v3 · BO3', 974, __('today :time', ['time' => '22:30'])], ['NCE', __('#394 vs LNB, casual'), '2v2 · BO5', 941, __('time open')]];
@endphp

<div class="flex grow flex-col">
    <x-block-strip :finished="SampleData::finishedBlocks()" :running="SampleData::runningBlocks()" focus="rl" />

    <div class="grid grow grid-cols-1 gap-4 px-4 pb-6 lg:grid-cols-2 lg:gap-5 lg:px-12 lg:pb-10">
        {{-- Clan Hashrate, last 7 days (P7) --}}
        <section aria-labelledby="rl-hr" class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 pb-2"><h2 id="rl-hr" class="m-0 text-[15px] font-bold">{{ __('Clan Hashrate · last 7 days') }}</h2><a href="{{ route('clans.index') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('All clans and season standings') }}</a></span>
            <div class="grid h-8 grid-cols-[20px_minmax(0,1fr)_96px] items-center gap-3 border-b border-hairline px-2 text-xs text-ink-2 lg:grid-cols-[24px_180px_minmax(0,1fr)_52px_52px_52px]">
                <span>#</span><span>{{ __('Clan') }}</span><span class="hidden lg:block">{{ __('Hashrate') }}</span><span class="text-right">{{ __('Points') }}</span><span class="hidden text-right lg:block">{{ __('Bonus') }}</span><span class="hidden text-right lg:block">{{ __('Share') }}</span>
            </div>
            @foreach ($rows as $row)
                <a href="{{ route('clans.show', $row['clan']) }}" wire:key="rl-{{ $row['clan']->id }}" class="tr grid h-[38px] grid-cols-[20px_minmax(0,1fr)_96px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[24px_180px_minmax(0,1fr)_52px_52px_52px]">
                    <span class="text-ink-3">{{ $row['rank'] }}</span>
                    <span class="flex min-w-0 items-center gap-2"><x-clan-tag :tag="$row['clan']->clantag" size="sm" /><span class="truncate">{{ $row['clan']->name }}</span></span>
                    <span class="hidden h-3.5 rounded-r-sm bg-raised lg:block"><span class="block h-3.5 animate-fill rounded-r-sm bg-btc" style="width: {{ $row['width'] }}"></span></span>
                    <b class="text-right">{{ $row['points'] }}</b>
                    <span class="hidden text-right text-ink-2 lg:block">+{{ $row['bonus'] }}</span>
                    <span class="hidden text-right text-ink-2 lg:block">{{ $row['share'] }}</span>
                </a>
            @endforeach
        </section>

        {{-- Rocket League share (P7) --}}
        <section aria-labelledby="rl-share" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h2 id="rl-share" class="m-0 text-[15px] font-bold">{{ __('Rocket League share') }}</h2><span class="text-xs text-ink-3">{!! __('last 7 days, <b class="text-ink">:rl</b> of :total points', ['rl' => $rlTotal, 'total' => $weekTotal]) !!}</span></span>
            <div role="img" aria-label="{{ __('Rocket League Hashrate per clan, last 7 days') }}" class="flex h-[180px] items-end gap-1.5 border-b border-line lg:gap-2">
                @foreach ($rl as $index => $row)
                    <span class="flex h-full min-w-0 flex-1 flex-col justify-end gap-1" title="{{ $row['clan']->name }}: {{ $row['rl'] }}">
                        <span class="truncate text-[10px] text-ink-2 lg:text-[11px]">{{ $row['clan']->clantag }} {{ $row['rl'] }}</span>
                        <span class="bar block bg-btc" style="height: {{ max($row['rl'] / 40 * 100, 1) }}%; animation-delay: {{ $index * 0.05 }}s"></span>
                    </span>
                @endforeach
            </div>
            <span class="flex justify-between text-[11px] text-ink-3"><span>{{ __('per clan, most first') }}</span><span>{{ __('the rest came from chess') }}</span></span>
            <p class="m-0 border-t border-hairline pt-3 text-xs leading-[1.6] text-ink-2">{{ __('Every rated game a clan player plays counts for their clan: win 3, draw 2, loss 1. A Rocket League series counts once per player, and a won team match or series adds 5. Casual games don\'t count.') }} <a href="{{ route('rules') }}">{{ __('How points are counted') }}</a></p>
        </section>

        {{-- What a series is worth: the Elo formula itself (K 32) --}}
        <section aria-labelledby="rl-worth" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex flex-wrap items-baseline gap-x-2 gap-y-1"><h2 id="rl-worth" class="m-0 text-[15px] font-bold">{{ __('What a series is worth') }}</h2><span class="text-xs text-ink-3">{{ __('Elo per lineup, K 32, first 5 series K 40') }}</span></span>
            <div class="grid grid-cols-3 gap-2 lg:gap-3">
                @foreach ([[__('Weaker opponent'), __('200 Elo below you'), -200, 'bg-btc-deep'], [__('Even match'), __('same Elo as you'), 0, 'bg-[#F8A23A]'], [__('Stronger opponent'), __('200 Elo above you'), 200, 'bg-btc-hi']] as [$label, $gap, $diff, $colour])
                    @php($stake = $this->stake($diff))
                    <div class="flex flex-col overflow-hidden rounded-md shadow-ring">
                        <span class="{{ $colour }} px-2 py-1.5 text-center text-[11px] font-bold text-on-btc lg:text-xs">{{ $label }}</span>
                        <span class="flex flex-col items-center gap-1 px-2 py-3">
                            <span class="text-center text-[10px] text-ink-2 lg:text-[11px]">{{ $gap }}</span>
                            <b class="font-display text-xl text-win lg:text-[22px]">+{{ $stake['win'] }}</b>
                            <span class="text-xs text-loss">−{{ $stake['loss'] }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
            <p class="m-0 text-xs text-ink-3">{{ __('Modes: :modes · best of 3 or 5', ['modes' => implode(', ', array_keys($modes))]) }}</p>
        </section>

        {{-- Next tournament (P8) --}}
        <section aria-labelledby="rl-cup" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex items-baseline justify-between"><h2 id="rl-cup" class="m-0 text-[15px] font-bold">{{ __('Next tournament') }}</h2><a href="{{ route('tournaments.index') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('All tournaments') }}</a></span>
            <span class="flex flex-wrap items-baseline justify-between gap-2"><b class="font-display text-lg">Halving Cup</b><span class="text-xs text-ink-2">{{ __(':t of :n teams, :s solo players', ['t' => 5, 'n' => 8, 's' => 7]) }}</span></span>
            <span class="block h-6 rounded-sm bg-raised"><span class="block h-6 animate-fill rounded-sm bg-[linear-gradient(90deg,#B9640A,#F7931A)]" style="width: 62.5%"></span></span>
            <div class="grid grid-cols-2 gap-3 text-center lg:grid-cols-4">
                @foreach ([['Oct 3', __('Cup night, 20:00'), 'text-ink'], ['3v3', __('Double Elimination'), 'text-ink'], ['315 000', __('sats, members\' prize pool'), 'text-bolt'], ['Oct 1', __('Entries close, 20:00'), 'text-ink']] as [$value, $label, $colour])
                    <span class="flex flex-col gap-1"><b class="text-lg {{ $colour }}">{{ $value }}</b><span class="text-[11px] text-ink-2">{{ $label }}</span></span>
                @endforeach
            </div>
            <p class="m-0 text-xs leading-[1.6] text-ink-2">{{ __('Captains enter a lineup, solo players get drawn into mix teams. Clan lineup matches count for Elo; mix teams play without Elo.') }}</p>
        </section>

        {{-- Series per week (P6) --}}
        <section aria-labelledby="rl-weeks" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex items-baseline justify-between"><h2 id="rl-weeks" class="m-0 text-[15px] font-bold">{{ __('Series per week') }}</h2><span class="text-xs text-ink-3">{{ __('all series, last 12 weeks') }}</span></span>
            <div class="grid h-[150px] grid-cols-[24px_minmax(0,1fr)] gap-2">
                <div class="flex flex-col justify-between text-right text-[11px] text-ink-3"><span>20</span><span>10</span><span>0</span></div>
                <div role="img" aria-label="{{ __('Series per week over the last 12 weeks') }}" class="flex items-end gap-1 border-b border-line lg:gap-2">
                    @foreach ($weeks as $index => $count)
                        <span class="flex h-full min-w-0 flex-1 flex-col justify-end gap-1" title="{{ $count }}">
                            <span class="text-center text-[10px] whitespace-nowrap text-ink-2">@if (isset($cups[$index]))<span class="hidden lg:inline">Cup · </span>@endif{{ $count }}</span>
                            <span @class(['bar block', 'bg-btc-hi' => isset($cups[$index]), 'bg-btc' => ! isset($cups[$index])]) style="height: {{ max($count / 22 * 100, 2) }}%; animation-delay: {{ $index * 0.05 }}s"></span>
                        </span>
                    @endforeach
                </div>
            </div>
            <span class="flex justify-between pl-8 text-[11px] text-ink-3"><span>{{ __('12 weeks ago') }}</span><span>{{ __('6 weeks ago') }}</span><span>{{ __('this week') }}</span></span>
        </section>

        {{-- Wins by clan (P6) --}}
        <section aria-labelledby="rl-wins" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex items-baseline justify-between"><h2 id="rl-wins" class="m-0 text-[15px] font-bold">{{ __('Wins by clan') }}</h2><span class="text-xs text-ink-3">{{ __('3v3, since launch') }}</span></span>
            @foreach ($wins as [$clan, $count])
                <div class="grid h-7 grid-cols-[120px_minmax(0,1fr)_96px] items-center gap-3 text-[13px] lg:grid-cols-[170px_minmax(0,1fr)_120px]">
                    <span class="truncate">{{ $clan }}</span>
                    <span class="block h-3.5 rounded-r-sm bg-raised"><span class="block h-3.5 animate-fill rounded-r-sm bg-btc" style="width: {{ $count / 17 * 100 }}%"></span></span>
                    <span class="text-right text-ink-2">{{ trans_choice(':count win|:count wins', $count) }} · {{ round($count / 60 * 100) }}%</span>
                </div>
            @endforeach
        </section>

        {{-- Latest series (P6) --}}
        <section aria-labelledby="rl-latest" class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <h2 id="rl-latest" class="m-0 pb-2 text-[15px] font-bold">{{ __('Latest series') }}</h2>
            <div class="grid h-8 grid-cols-[52px_minmax(0,1fr)_40px_36px] items-center gap-3 px-2 text-xs text-ink-2 lg:grid-cols-[64px_minmax(0,1fr)_100px_56px_56px]"><span>{{ __('Match') }}</span><span>{{ __('Winner') }}</span><span class="hidden lg:block">{{ __('When') }}</span><span>{{ __('Score') }}</span><span>{{ __('Mode') }}</span></div>
            @foreach ($recent as [$height, $winner, $when, $score, $mode])
                <a href="{{ route('matches.show', ltrim($height, '#')) }}" class="tr grid h-12 grid-cols-[52px_minmax(0,1fr)_40px_36px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[64px_minmax(0,1fr)_100px_56px_56px]">
                    <span class="text-btc">{{ $height }}</span><span class="truncate">{{ $winner }}</span><span class="hidden text-ink-2 lg:block">{{ $when }}</span><span>{{ $score }}</span><span class="text-ink-2">{{ $mode }}</span>
                </a>
            @endforeach
        </section>

        {{-- Open challenges and next series (P6) --}}
        <section aria-labelledby="rl-open" class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex items-baseline justify-between pb-2"><h2 id="rl-open" class="m-0 text-[15px] font-bold">{{ __('Open challenges and next series') }}</h2><a href="{{ route('challenges.create') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('Challenge a clan') }}</a></span>
            <div class="grid h-8 grid-cols-[minmax(0,1fr)_80px_64px] items-center gap-3 px-2 text-xs text-ink-2 lg:grid-cols-[minmax(0,1fr)_100px_64px_100px]"><span>{{ __('Clan') }}</span><span>{{ __('Format') }}</span><span>Elo</span><span class="hidden text-right lg:block">{{ __('When') }}</span></div>
            @foreach ($open as [$tag, $what, $format, $elo, $when])
                <div class="tr grid h-12 grid-cols-[minmax(0,1fr)_80px_64px] items-center gap-3 rounded-sm px-2 text-[13px] lg:grid-cols-[minmax(0,1fr)_100px_64px_100px]">
                    <span class="flex min-w-0 items-center gap-2"><x-clan-tag :tag="$tag" size="sm" /><span class="truncate">{{ $what }}</span></span>
                    <span class="text-ink-2">{{ $format }}</span><span>{{ $elo }}</span><span class="hidden text-right text-ink-2 lg:block">{{ $when }}</span>
                </div>
            @endforeach
            <p class="m-0 pt-3 text-xs leading-[1.6] text-ink-2">{{ __('Open to every clan. Rated challenges need a mutual opponent connection, casual ones do not.') }}</p>
        </section>
    </div>
</div>
