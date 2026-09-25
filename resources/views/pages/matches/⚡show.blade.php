<?php

use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\SeriesMatch;
use App\Support\Series\SeriesPresenter;
use App\Support\Series\SeriesService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The public page of a series, 1:1 from MatchDetail.dc.html: facts, the flow
 * of the games, who played, and the Proof. Public fields only: the lobby
 * never renders here, not even for the two lineups (they have the room).
 *
 * Casual (before Block 0): no Elo, no league record; the right card says so
 * instead of showing stakes. An unknown number shows the "Match not found"
 * state of States.dc.html with a 404.
 */
new #[Title('Match')] #[Layout('layouts::app', ['section' => 'matches'])] class extends Component {
    public int $number;

    public function mount(string $match): void
    {
        $this->number = (int) $match;

        if (! SeriesMatch::query()->where('number', $this->number)->exists()) {
            $latest = SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->max('number');

            abort(response()->view('pages.matches.not-found', ['number' => $this->number, 'latest' => $latest], 404));
        }
    }

    #[Computed]
    public function match(): SeriesMatch
    {
        return SeriesMatch::query()
            ->with(['challengerLineup.clan', 'challengerLineup.seats.user.clanMember', 'challengedLineup.clan', 'challengedLineup.seats.user.clanMember',
                'latestReport.event', 'latestReport.responseEvent', 'challengeEvent', 'answerEvent', 'answeredBy', 'resolvedBy'])
            ->where('number', $this->number)
            ->firstOrFail();
    }

    /**
     * Who played, per side: the latest report's roster, or the lineups'
     * current regulars while nothing is reported.
     *
     * @return list<array{name: string, role: string, side: string, npub: string}>
     */
    #[Computed]
    public function roster(): array
    {
        $match = $this->match;
        $report = $match->latestReport;

        if ($report !== null) {
            return array_map(fn (array $entry) => ['name' => $entry['name'], 'role' => $entry['role'], 'side' => $entry['side'], 'npub' => \App\Support\Nostr\NostrKeys::hexToNpub($entry['pubkey'])], $report->roster);
        }

        $series = app(SeriesService::class);
        $roster = [];

        foreach (SeriesMatch::SIDES as $side) {
            foreach ($series->rosterSeats($match, $side) as $seat) {
                $roster[] = ['name' => $seat->user->displayName(), 'role' => $seat->role->value, 'side' => $side, 'npub' => $seat->user->npub];
            }
        }

        return $roster;
    }
}; ?>

@php
    $match = $this->match;
    $viewer = auth()->user();
    $games = $match->currentGames();
    $wins = SeriesMatch::seriesScore($games);
    $goals = ['challenger' => array_sum(array_map(fn ($g) => (int) ($g['challenger'] ?? 0), $games)), 'challenged' => array_sum(array_map(fn ($g) => (int) ($g['challenged'] ?? 0), $games))];
    $report = $match->latestReport;
    $mySide = $match->captainSideOf($viewer);
    $toAnswer = $match->status === SeriesStatus::Reported && $report !== null && $report->status === ReportStatus::Open && $mySide !== null && $mySide !== $report->side;
    $colors = ['challenger' => '#F7931A', 'challenged' => '#A78BFA'];
    $banner = match (true) {
        $toAnswer => [__(':clan reported · your confirmation needed', ['clan' => $match->sideName($report->side)]), 'bg-btc-chip text-btc-hi'],
        $match->status === SeriesStatus::Reported && $report !== null => [__(':clan reported · waiting for :other', ['clan' => $match->sideName($report->side), 'other' => $match->sideName(SeriesMatch::otherSide($report->side))]), 'bg-btc-chip text-btc-hi'],
        $match->status === SeriesStatus::Disputed => [__('Disputed · an admin decides'), 'bg-loss-tint text-loss'],
        $match->status->hasResult() => [$match->winner === 'none' ? __('Void · no winner') : __(':clan won · :how', ['clan' => $match->sideName((string) $match->winner), 'how' => $match->resolution?->label() ?? '']), 'bg-win-tint text-win'],
        default => [SeriesPresenter::chip($match)['label'], 'bg-well text-ink-2'],
    };
    $height = max(1, count($games)) * 95;
@endphp

<div class="flex grow flex-col gap-6 px-4 pt-6 pb-10 lg:mx-auto lg:w-full lg:max-w-[1200px] lg:px-0 lg:pt-10" data-test="match-detail">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Series :number', ['number' => $match->label()]) }}</h1>
        <span class="text-[13px] text-ink-2">Rocket League · {{ $match->challenger_name }} vs {{ $match->challenged_name }}</span>
        <button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard?.writeText(window.location.href); copied = true; setTimeout(() => copied = false, 1500)"
                :aria-label="copied ? @js(__('Link copied')) : @js(__('Copy link'))" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2">
            <x-icon name="copy" :size="16" />
        </button>
        <span class="grow"></span>
        <span class="inline-flex min-h-8 items-center rounded-md px-3.5 text-[13px] font-bold {{ $banner[1] }}" data-test="match-banner">{{ $banner[0] }}</span>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([
                [__('Challenge sent'), SeriesPresenter::time($match->created_at ?? now(), $viewer).($match->answered_at && $match->start_at ? ', '.__('accepted :time', ['time' => SeriesPresenter::time($match->answered_at, $viewer, 'H:i')]) : '')],
                [__('Played'), $match->start_at ? SeriesPresenter::time($match->start_at, $viewer).($match->finished_at ? ' · '.__('result :time', ['time' => SeriesPresenter::time($match->finished_at, $viewer, 'H:i')]) : '') : __('not yet')],
                [__('Format'), 'Rocket League · '.$match->mode.' · BO'.$match->best_of],
                [__('Lobby'), __('Hosted by :clan, lineups only', ['clan' => $match->challenger_name])],
            ] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-4 border-b border-hairline py-2 text-[13px] last:border-0 lg:grid-cols-[180px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([
                [__('Match kind'), $match->rated ? __('Rated') : __('Casual'), ''],
                [__('Elo before'), $match->rated ? __('with the league record') : __('no rating, casual'), 'text-ink-2'],
                [__('At stake'), $match->rated ? __('with the league record') : __('nothing, casual until Block 0'), 'text-ink-2'],
                [__('League record'), $match->rated ? __('after both captains confirm') : __('none for casual matches'), 'text-btc-hi'],
            ] as [$key, $value, $class])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-4 border-b border-hairline py-2 text-[13px] last:border-0 lg:grid-cols-[180px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span class="{{ $class }}">{{ $value }}</span></div>
            @endforeach
        </div>
    </div>

    <section aria-labelledby="flow-h" class="flex flex-col gap-3">
        <h2 id="flow-h" class="m-0 font-display text-xl font-bold">{{ __('Flow') }}</h2>
        <div class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            @if ($games === [])
                <p class="m-0 text-[13px] text-ink-2">{{ __('No game played yet. The flow fills up as the games are entered.') }}</p>
            @else
                <div class="relative grid grid-cols-[112px_minmax(0,1fr)_128px] gap-2 lg:grid-cols-[180px_minmax(0,1fr)_220px]" style="height: {{ $height }}px" data-test="flow">
                    <div class="relative">
                        @foreach ($games as $index => $game)
                            <span class="absolute inset-x-0 -translate-y-1/2 text-right text-[11px] leading-tight text-ink-2 lg:text-xs lg:whitespace-nowrap" style="top: {{ ($index + 0.5) / count($games) * 100 }}%">
                                {{ __('Game :n', ['n' => $index + 1]) }} · {{ $game['challenger'] !== null ? $game['challenger'].':'.$game['challenged'] : '?' }} · {{ $match->sideTag($game['winner']) }}
                            </span>
                        @endforeach
                    </div>
                    <svg viewBox="0 0 700 {{ $height }}" preserveAspectRatio="none" class="h-full w-full" role="img" aria-label="{{ __('Games flowing to their winners') }}">
                        @foreach ($games as $index => $game)
                            @php($y = ($index + 0.5) / count($games) * $height)
                            @php($t = ($game['winner'] === 'challenger' ? 0.22 : 0.52) * $height)
                            @php($width = $game['challenger'] !== null ? max(4, ($game['challenger'] + $game['challenged']) * 3) : 6)
                            <path d="M0,{{ $y }} C350,{{ $y }} 350,{{ $t }} 700,{{ $t }}" fill="none" stroke="{{ $colors[$game['winner']] }}" stroke-opacity=".85" stroke-width="{{ $width }}" vector-effect="non-scaling-stroke" />
                        @endforeach
                        <path d="M0,{{ $height * 0.3 }} C350,{{ $height * 0.3 }} 350,{{ $height * 0.88 }} 700,{{ $height * 0.88 }}" fill="none" stroke="#6B6B70" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                    </svg>
                    <div class="relative text-xs">
                        @foreach (SeriesMatch::SIDES as $side)
                            <span class="absolute inset-x-0 -translate-y-1/2 text-[11px] leading-tight lg:text-xs lg:whitespace-nowrap" style="top: {{ $side === 'challenger' ? 22 : 52 }}%; color: {{ $colors[$side] }}">
                                {{ $match->sideName($side) }} · {{ trans_choice(':count goal|:count goals', $goals[$side]) }}@if ($match->winner === $side) · {{ __('winner') }}@endif
                            </span>
                        @endforeach
                        <span class="absolute inset-x-0 top-[88%] -translate-y-1/2 text-[11px] leading-tight text-ink-2 lg:text-xs lg:whitespace-nowrap">{{ __('League record') }}</span>
                    </div>
                </div>
            @endif
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Width = goals in the game, team goals from the end screen. A game marked "goals unknown" flows at a fixed width and only counts as a win.') }}@if (! $match->status->hasResult() && $games !== []) {{ __('Shown as provisional until both captains confirm.') }}@endif</p>
        </div>
    </section>

    <section aria-labelledby="io-h" class="flex flex-col gap-3">
        <h2 id="io-h" class="m-0 font-display text-xl font-bold">{{ __('Inputs & Outputs') }}</h2>
        <div class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_48px_minmax(0,1fr)] lg:items-center">
                <ul class="m-0 flex list-none flex-col gap-1 p-0" data-test="roster">
                    @foreach ($this->roster as $entry)
                        <li class="grid min-h-10 grid-cols-[20px_minmax(0,1fr)_auto] items-center gap-3 text-[13px]">
                            <span class="size-5 rounded-full" style="background: {{ $colors[$entry['side']] }}"></span>
                            <span class="flex min-w-0 flex-col"><span class="flex min-w-0 items-center gap-1.5"><span class="truncate"><b>{{ $entry['name'] }}</b> <span class="text-ink-3">· {{ \App\Enums\LineupRole::tryFrom($entry['role'])?->label() }}</span></span>@if ($entry['npub'] !== auth()->user()?->npub)<x-copy-npub :npub="$entry['npub']" :name="$entry['name']" />@endif</span><span class="text-[11px] text-ink-3">{{ $match->sideName($entry['side']) }}</span></span>
                            <span class="inline-flex items-center gap-1 text-win">@if ($report)<x-icon name="check" :size="14" />{{ __('played') }}@else<span class="text-ink-3">{{ __('regular') }}</span>@endif</span>
                        </li>
                    @endforeach
                </ul>
                <span class="hidden justify-center text-win lg:flex" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></span>
                <ul class="m-0 flex list-none flex-col gap-2 p-0">
                    @foreach (SeriesMatch::SIDES as $side)
                        <li class="grid min-h-10 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 text-[13px]">
                            <span class="flex flex-col"><span style="color: {{ $colors[$side] }}">{{ $match->sideName($side) }}</span><span class="text-[11px] text-ink-3">{{ $match->rated ? __('Elo with the league record') : __('casual, no Elo') }}</span></span>
                            <b @class(['text-win' => $match->winner === $side, 'text-ink-2' => $match->winner !== $side])>{{ $wins[$side] }}</b>
                        </li>
                    @endforeach
                    <li class="grid min-h-10 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 text-[13px]">
                        <span class="flex flex-col"><span>{{ __('League record') }}</span><span class="text-[11px] text-ink-3">{{ $match->rated ? __('written once both captains confirm') : __('casual matches get none') }}</span></span>
                        <b class="text-btc-hi">{{ $match->rated ? __('pending') : '–' }}</b>
                    </li>
                </ul>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-4 border-t border-hairline pt-4">
                <span class="text-[13px] text-ink-2">{{ __('Series') }} <b class="text-ink">{{ $wins['challenger'] }} : {{ $wins['challenged'] }}</b> · {{ trans_choice(':count game|:count games', count($games)) }} · {{ trans_choice(':count goal|:count goals', $goals['challenger'] + $goals['challenged']) }}</span>
                <span class="grow"></span>
                @if ($toAnswer)
                    <a href="{{ route('matches.room', $match) }}#check" class="inline-flex h-11 items-center rounded-md border border-loss px-5 text-[15px] text-loss hover:text-loss" data-test="detail-report-problem">{{ __('Report a problem') }}</a>
                    <a href="{{ route('matches.room', $match) }}#check" class="btn-p inline-flex h-11 items-center gap-2 rounded-md bg-btc px-5 text-[15px] font-bold text-on-btc hover:text-on-btc"><x-icon name="shield-check" :size="18" />{{ __('Accept result') }}</a>
                @elseif ($match->participantSideOf($viewer) !== null && $match->status->isRunning())
                    <x-button :href="route('matches.room', $match)" icon="matches">{{ __('Open the match room') }}</x-button>
                @endif
            </div>
            @if ($match->resolution_reason)
                <p class="mt-3 mb-0 rounded-md bg-ground px-3.5 py-2.5 text-xs leading-normal text-ink-2 shadow-ring">{{ __('Admin decision') }}: {{ $match->resolution_reason }}</p>
            @endif
        </div>
    </section>

    <x-proof toggle="show" class="border-0 bg-proof-fill shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" :rows="SeriesPresenter::proofRows($match)">
        {{ $match->rated ? __('Every step of this series is a Nostr event anyone can check.') : __('A casual match publishes no events: the NIP gives casual games no match-flow events. Rated matches start at Block 0.') }}
    </x-proof>
</div>
