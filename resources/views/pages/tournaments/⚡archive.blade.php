<?php

use App\Support\PageMeta;
use App\Support\Tournaments\ArchiveBoard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The tournament archive (Archive.dc.html, Archive-Phone.dc.html; plan "Refactor und Design-Revamp", P6): the Hall
 * of Fame with the last champions, every finished tournament with its winner, and the called-off ones receded
 * (App\Support\Tournaments\ArchiveBoard). The tournaments page links here; finished tournaments no longer stand
 * there. `?game=<slug>` narrows the page to one game, `?page=<n>` pages the finished list.
 */
new #[Title('Tournament archive')] #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    #[Url(except: '')]
    public string $game = '';

    #[Url(except: 1)]
    public int $page = 1;

    public function rendering(\Illuminate\View\View $view): void
    {
        app(PageMeta::class)->describe(__('Tournament archive'), __('Every finished tournament of the TWENTY ONE esports league with its champion and prize, and the ones called off.'));
        app(PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('tournaments'));
    }

    #[Computed]
    public function gameFilter(): ?string
    {
        return array_key_exists($this->game, app(\App\Games\GameRegistry::class)->all()) ? $this->game : null;
    }

    #[Computed]
    public function board(): ArchiveBoard
    {
        return new ArchiveBoard($this->gameFilter, auth()->user()?->timezone);
    }
}; ?>

@php
    $board = $this->board;
    $game = $this->gameFilter;
    $hall = $board->hallOfFame();
    $finished = $board->finished(max(1, $page));
    $cancelled = $board->cancelled();
    $chips = array_map(fn (array $chip): array => [
        'key' => $chip['key'], 'label' => $chip['label'], 'count' => $chip['count'],
        'href' => route('tournaments.archive', $chip['key'] === 'all' ? [] : ['game' => $chip['key']]),
        'active' => $chip['key'] === 'all' ? $game === null : $game === $chip['key'],
    ], $board->gameChips());
    $sats = fn (int $value): string => ArchiveBoard::sats($value);
@endphp

<div class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 pb-10 lg:px-12" data-test="tournament-archive">
    <div class="flex items-baseline gap-4">
        <a href="{{ route('tournaments.index') }}" class="rv-lk text-[13px]" data-test="archive-back">{{ __('Tournaments') }}</a>
        <h1 class="m-0 font-display text-2xl leading-[1.15] font-bold lg:text-[36px]">{{ __('Archive') }}</h1>
    </div>

    @if ($hall !== [])
        <section aria-labelledby="hof-h" class="flex flex-col gap-4" data-test="hall-of-fame">
            <h2 id="hof-h" class="rv-h2 max-lg:sr-only">{{ __('Hall of Fame') }}</h2>
            <ul class="m-0 grid list-none gap-6 p-0 lg:grid-cols-3">
                @foreach ($hall as $card)
                    <li @class(['min-w-0', 'max-lg:hidden' => ! $loop->first]) wire:key="hof-{{ $card['tournament']->id }}">
                        <a href="{{ $card['href'] }}" @class(['rv-card flex h-full flex-col overflow-hidden text-ink hover:text-ink', 'rv-glow border-btc' => $card['featured'] && $card['pot'] !== null])
                           data-test="hof-card" @if ($card['featured']) data-featured @endif>
                            <x-game-cover :game="$card['tournament']->game" :games="$card['tournament']->coverGames()" size="card" :loading="$loop->first ? 'eager' : 'lazy'" class="w-full [&_img]:object-cover" />
                            <span class="flex flex-col gap-3 p-4">
                                <span class="flex items-center gap-2 text-xs text-ink-2"><x-icon name="trophy" :size="14" class="flex-none text-btc" /><span class="truncate">{{ $card['name'] }}</span></span>
                                <span class="flex min-w-0 items-center gap-3">
                                    @if ($card['champion']['user'])
                                        <x-avatar :user="$card['champion']['user']" :name="$card['champion']['name']" :size="40" class="flex-none" />
                                    @else
                                        <span class="rv-av size-10 flex-none"><x-icon name="clans" :size="18" /></span>
                                    @endif
                                    <b class="min-w-0 text-xl [overflow-wrap:anywhere]" data-test="hof-champion">{{ $card['champion']['name'] }}</b>
                                </span>
                                <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <span class="rv-m">{{ $card['date'] }}</span>
                                    @if ($card['prize'] !== null)
                                        <b class="font-display text-lg leading-none font-bold text-btc lg:text-xl" data-test="hof-prize">{{ __(':sats Sats prize money', ['sats' => $sats($card['prize'])]) }}</b>
                                    @else
                                        <span class="rv-m">{{ $card['tournament']->isCasualCup() ? __('Casual cup, no pot') : __('No pot') }}</span>
                                    @endif
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-ui.chips :label="__('Game title')" :items="$chips" test="archive-game" />

    <section aria-labelledby="done-h" class="flex flex-col gap-3" data-test="archive-finished">
        <h2 id="done-h" class="rv-h3">{{ __('Finished') }}</h2>
        @if ($finished->isEmpty())
            <p class="rv-card m-0 p-4 text-[13px] text-ink-2" data-test="archive-empty">{{ __('No tournament has finished yet.') }}</p>
        @else
            <div class="rv-card max-lg:hidden">
                <table class="rv-tb">
                    <thead>
                        <tr><th class="w-[84px]"><span class="sr-only">{{ __('Cover') }}</span></th><th>{{ __('Tournament') }}</th><th>{{ __('Date') }}</th><th>{{ __('Places') }}</th><th class="is-r">{{ __('Prize pot in sats') }}</th><th>{{ __('Status') }}</th><th>{{ __('Winner') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($finished as $row)
                            <tr wire:key="done-{{ $row['tournament']->id }}" @class(['is-featured' => $row['pot'] !== null]) data-test="archive-row" data-tournament="{{ $row['tournament']->id }}">
                                <td class="w-[84px]"><x-game-cover :game="$row['tournament']->game" :games="$row['tournament']->coverGames()" size="thumb" class="h-9 w-16 rounded-[2px] [&_img]:object-cover" /></td>
                                <td class="[overflow-wrap:anywhere]">
                                    <a href="{{ $row['href'] }}" class="font-bold text-ink hover:text-ink">{{ $row['name'] }}</a>
                                    <div class="rv-m">{{ $row['sub'] }}</div>
                                </td>
                                <td class="whitespace-nowrap tabular-nums">{{ $row['date'] }}</td>
                                <td class="tabular-nums">{{ $row['places'] }}</td>
                                <td class="is-r whitespace-nowrap tabular-nums">@if ($row['pot'] !== null)<b class="text-btc">{{ $sats($row['pot']) }} Sats</b>@else<span class="text-ink-3">–</span>@endif</td>
                                <td><span class="rv-tag is-ok"><x-icon name="check" :size="12" />{{ __('Finished') }}</span></td>
                                <td>
                                    @if ($row['champion'] !== null)
                                        <span class="flex items-center gap-2" data-test="archive-champion">
                                            @if ($row['champion']['user'])
                                                <x-avatar :user="$row['champion']['user']" :name="$row['champion']['name']" :size="24" class="flex-none" />
                                            @else
                                                <span class="rv-av is-sm"><x-icon name="clans" :size="12" /></span>
                                            @endif
                                            <b class="whitespace-nowrap">{{ $row['champion']['name'] }}</b>
                                            <x-icon name="trophy" :size="14" class="flex-none text-btc" />
                                        </span>
                                    @elseif ($row['week'])
                                        <a href="{{ $row['results'] }}" class="rv-lk">{{ __('See the best time') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="m-0 flex list-none flex-col p-0 lg:hidden">
                @foreach ($finished as $row)
                    <li wire:key="done-p-{{ $row['tournament']->id }}" class="border-b border-hairline" data-test="archive-row-phone">
                        <a href="{{ $row['href'] }}" @class(['flex min-h-[60px] items-center gap-3 py-2.5 text-ink hover:text-ink', 'border-l-4 border-btc pl-2' => $row['pot'] !== null])>
                            <x-game-cover :game="$row['tournament']->game" :games="$row['tournament']->coverGames()" size="thumb" class="h-9 w-16 flex-none rounded-[2px] [&_img]:object-cover" />
                            <span class="flex min-w-0 grow flex-col">
                                <b class="text-sm leading-tight [overflow-wrap:anywhere]">{{ $row['name'] }}</b>
                                <span class="rv-m [overflow-wrap:anywhere]">{{ implode(', ', array_filter([$row['date'], $row['pot'] !== null ? $sats($row['pot']).' Sats' : null, $row['champion']['name'] ?? null])) }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if ($finished->hasPages())
                <nav class="flex items-center gap-2" aria-label="{{ __('Pages') }}" data-test="archive-pages">
                    @if ($finished->previousPageUrl())<a href="{{ $finished->previousPageUrl() }}" class="rv-b2 is-sm">{{ __('Newer') }}</a>@endif
                    <span class="rv-m">{{ __('Page :page of :pages', ['page' => $finished->currentPage(), 'pages' => $finished->lastPage()]) }}</span>
                    @if ($finished->nextPageUrl())<a href="{{ $finished->nextPageUrl() }}" class="rv-b2 is-sm">{{ __('Older') }}</a>@endif
                </nav>
            @endif
        @endif
    </section>

    @if ($cancelled['total'] > 0)
        <section aria-labelledby="off-h" class="flex flex-col gap-3" data-test="archive-cancelled">
            <h2 id="off-h" class="rv-h3 text-ink-2">{{ __('Called off') }}</h2>
            <p class="m-0 text-[13px] text-ink-2 lg:hidden" data-test="archive-cancelled-line">
                {{ trans_choice(':count tournament called off, last :name (:date)|:count tournaments called off, last :name (:date)', $cancelled['total'], ['name' => $cancelled['rows'][0]['name'], 'date' => $cancelled['rows'][0]['date']]) }}
            </p>
            <div class="rv-card max-lg:hidden">
                <table class="rv-tb">
                    <tbody>
                        @foreach ($cancelled['rows'] as $row)
                            <tr wire:key="off-{{ $row['tournament']->id }}" class="text-ink-3" data-test="archive-cancelled-row">
                                <td class="w-[200px] whitespace-nowrap tabular-nums">{{ $row['date'] }}</td>
                                <td><a href="{{ $row['href'] }}" class="text-ink-2 hover:text-ink">{{ $row['name'] }}</a></td>
                                <td class="is-r"><span class="rv-tag is-bad"><x-icon name="close" :size="12" />{{ __('Called off') }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($cancelled['total'] > count($cancelled['rows']))
                <p class="rv-m m-0 max-lg:hidden">{{ trans_choice('+:count more called off|+:count more called off', $cancelled['total'] - count($cancelled['rows'])) }}</p>
            @endif
        </section>
    @endif
</div>
