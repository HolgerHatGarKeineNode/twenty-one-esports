<?php

use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Support\Series\SeriesPresenter;
use App\Support\Tournaments\TournamentDesk;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The live control page of one tournament (user, 2026-10-04: "Die Turnierleitung braucht eine für den Live Betrieb
 * optimierte eigene Seite (nicht die bearbeiten seite) … Disqualifizieren. Turnier-Chat. Live Ansicht Bracket"):
 * everything the direction needs while it runs, on one screen. It holds no logic of its own: the control
 * (components/⚡tournament-control: results, disqualify, pause, restart, call-off, message to all, who blocks what),
 * the desk chat (TournamentDesk, resources/js/deskChat.js) and the public bracket (TournamentView). The route and
 * mount check `manage-tournament`, the control checks it again for every action.
 *
 * Order on the page = order of urgency (2026-10-04, "perfekt für die Steuerung des Turniers"): the open series
 * sorted into lanes (needs you, not checked in, a deadline under 10 minutes, on track), then the control to act,
 * then the bracket to look at. Each card lists its deadlines in time order with a "now" line between the passed
 * and the coming ones.
 */
new #[Layout('layouts::app', ['section' => 'tournaments', 'realtime' => true, 'scripts' => ['resources/js/tournamentDesk.js']])] class extends Component {
    public Tournament $tournament;

    public function mount(Tournament $tournament): void
    {
        // The organizer and the admins control it; a named director gets the chat, the bracket and the director desk.
        abort_unless(Gate::any(['manage-tournament', 'direct-tournament'], $tournament), 403);
        abort_if($tournament->isLeagueWeek(), 404);

        $this->tournament = $tournament;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Live control').': '.$this->tournament->title());
    }

    /** A control step changed the bracket: draw it again at once. */
    #[On('tournament-controlled')]
    public function controlled(): void
    {
        $this->tournament->refresh();
        unset($this->stages, $this->round);
    }

    #[Computed]
    public function desk(): ?array
    {
        return TournamentDesk::for($this->tournament, auth()->user());
    }

    #[Computed]
    public function round(): ?\App\Models\TournamentRound
    {
        return $this->tournament->status === TournamentStatus::Running ? TournamentRunner::currentRound($this->tournament) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function stages(): array
    {
        return in_array($this->tournament->status, [TournamentStatus::Running, TournamentStatus::Finished], true)
            ? (new TournamentView($this->tournament))->stages()
            : [];
    }

    /**
     * Every open series of the tournament with its check-in and deadlines (user, 2026-10-04: "Übersichten darüber, wer
     * eingecheckt ist und wer nicht und ALLE Fristen").
     *
     * @return list<SeriesMatch>
     */
    #[Computed]
    public function openSeries(): array
    {
        return SeriesMatch::query()
            ->whereIn('tournament_match_id', \App\Models\TournamentMatch::query()->where('tournament_id', $this->tournament->id)->select('id'))
            ->whereIn('status', [SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed])
            ->with('latestReport')->orderBy('number')->get()
            ->reject(fn (SeriesMatch $series): bool => $series->isCasualPairing())->values()->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-tournament', $this->tournament);
    }

    /** The director desk only where the directors enter the results (director mode), not where the players report. */
    #[Computed]
    public function canDirect(): bool
    {
        return Gate::allows('direct-tournament', $this->tournament) && $this->tournament->isDirectorMode() && $this->tournament->status === TournamentStatus::Running;
    }

    /**
     * The open series sorted into lanes, most urgent first, each with its deadlines in time order. Reads only what
     * openSeries() loaded, so the board costs no query per series.
     *
     * - `needs`: only the direction moves it: with the admins (overdue_at), disputed, a reported no-show, a report
     *   nobody answered for `unanswered_report_hours`;
     * - `checkin`: started, no game entered, a side not checked in (one side in: the auto no-show; nobody in: the
     *   double no-show at the auto no-show time plus the response time, SeriesService::autoNoShow());
     * - `soon`: a deadline the league acts on is at most 10 minutes away;
     * - `ontrack`: the rest.
     *
     * A row `counts` when the league acts at its time on its own (autoNoShow() for one side or nobody in, the overdue
     * queue, forfeitNoShow(), the confirmation of a report); `note` replaces the countdown where the deadline no longer applies.
     *
     * @return array<'needs'|'checkin'|'soon'|'ontrack', list<array{series: SeriesMatch, state: string, rows: list<array{key: string, label: string, at: CarbonInterface, counts: bool, note: ?string}>, next: ?array{key: string, label: string, at: CarbonInterface, counts: bool, note: ?string}}>>
     */
    protected function board(): array
    {
        $now = now();
        $director = $this->tournament->isDirectorMode();
        $lanes = ['needs' => [], 'checkin' => [], 'soon' => [], 'ontrack' => []];

        foreach ($this->openSeries as $series) {
            $in = array_values(array_filter(SeriesMatch::SIDES, fn (string $side): bool => $series->readyAt($side) !== null));
            $accepted = $series->status === SeriesStatus::Accepted;
            $started = $series->start_at !== null && $series->start_at->lte($now);
            $played = $series->currentGames() !== [];
            $reportedNoShow = $series->noshow_reported_at !== null;
            $autoAt = $director ? null : $series->autoNoshowAt();
            // Nobody in: the double no-show rule decides at the auto no-show time plus the response time (autoNoShow()).
            $doubleAt = $autoAt !== null && $accepted && ! $reportedNoShow && ! $played && $in === [] ? $autoAt->copy()->addMinutes((int) $series->responseMinutes()) : null;

            $rows = array_values(array_filter([
                ['key' => 'start', 'label' => __('Start'), 'at' => $series->start_at, 'counts' => false, 'note' => null],
                ['key' => 'noshow-reportable', 'label' => __('No-show can be reported'), 'at' => $series->noshowReportableAt(), 'counts' => false, 'note' => null],
                ['key' => 'auto-noshow', 'label' => __('Auto no-show if only one side is in'), 'at' => $autoAt,
                    'counts' => $accepted && ! $reportedNoShow && ! $played && count($in) === 1,
                    'note' => match (true) {
                        ! $accepted => null,
                        $reportedNoShow => __('reported'),
                        $played => __('games entered'),
                        count($in) === 2 => __('both in'),
                        default => null,
                    }],
                ['key' => 'double-noshow', 'label' => __('Double no-show if nobody is in'), 'at' => $doubleAt, 'counts' => true, 'note' => null],
                ['key' => 'report-due', 'label' => __('Result due'), 'at' => $series->reportDueAt(), 'counts' => $accepted && $series->overdue_at === null,
                    'note' => match (true) {
                        $series->overdue_at !== null => __('with the admins'),
                        ! $accepted => __('reported'),
                        default => null,
                    }],
                ['key' => 'noshow-answer', 'label' => __('No-show answer due'), 'at' => $series->noshowForfeitAt(), 'counts' => $accepted, 'note' => null],
                ['key' => 'report-answer', 'label' => __('Report answer due'), 'at' => $series->responseDueAt(), 'counts' => true, 'note' => null],
            ], fn (array $row): bool => $row['at'] !== null));
            usort($rows, fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

            $next = collect($rows)->first(fn (array $row): bool => $row['counts'] && $row['at']->gt($now));
            $lane = match (true) {
                $series->overdue_at !== null || $series->status === SeriesStatus::Disputed || $reportedNoShow || $series->isUnansweredReport() => 'needs',
                $accepted && $started && ! $director && ! $played && count($in) < 2 => 'checkin',
                $next !== null && $next['at']->getTimestamp() - $now->getTimestamp() <= 600 => 'soon',
                default => 'ontrack',
            };
            $state = match (true) {
                $reportedNoShow => __('No-show reported'),
                $series->overdue_at !== null => __('With the admins'),
                $series->status === SeriesStatus::Reported => __('Result reported'),
                $series->status === SeriesStatus::Disputed => __('Disputed'),
                ! $started => __('Not started'),
                $lane === 'checkin' => __('Not checked in'),
                default => __('Playing'),
            };

            $lanes[$lane][] = ['series' => $series, 'state' => $state, 'rows' => $rows, 'next' => $next];
        }

        // Within a lane: the nearest deadline first, then the match number.
        $order = fn (array $card): array => [$card['next'] !== null ? $card['next']['at']->getTimestamp() : PHP_INT_MAX, (int) $card['series']->number];

        return array_filter(array_map(function (array $cards) use ($order): array {
            usort($cards, fn (array $a, array $b): int => $order($a) <=> $order($b));

            return $cards;
        }, $lanes));
    }
}; ?>

@php
    $tournament = $this->tournament;
    $viewer = auth()->user();
    $live = in_array($tournament->status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true);
    $running = $tournament->status === TournamentStatus::Running;
    $profile = $tournament->profile();
    // Chess, board games and leaderboards have no series: no check-in, no series deadlines.
    $hasSeries = ! ($profile->isChess() || $profile->isBoard() || $profile->isScore());
    $statusLabel = match ($tournament->status) {
        TournamentStatus::Draft => __('Draft'),
        TournamentStatus::Signup => __('Sign-up open'),
        TournamentStatus::Drawing => __('Draw pending'),
        TournamentStatus::Running => $tournament->isPaused() ? __('Paused') : __('Live now'),
        TournamentStatus::Finished => __('Finished'),
        TournamentStatus::Cancelled => __('Called off'),
    };
    $clock = fn (CarbonInterface $at): string => SeriesPresenter::time($at, $viewer, 'H:i');
    // "in 8 min", "in 1 h 38 min", "in under 1 min": the time left to a deadline still ahead.
    $left = function (CarbonInterface $at): string {
        $seconds = $at->getTimestamp() - now()->getTimestamp();
        $minutes = intdiv(max(0, $seconds), 60);

        return match (true) {
            $minutes < 1 => __('in under 1 min'),
            $minutes < 60 => __('in :minutes min', ['minutes' => $minutes]),
            default => __('in :hours h :minutes min', ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60]),
        };
    };
    $lanes = [
        'needs' => [__('Needs you'), 'shadow-[inset_3px_0_0_var(--color-loss)]', 'bg-loss-tint text-loss'],
        'checkin' => [__('Not checked in'), 'shadow-[inset_3px_0_0_var(--color-btc)]', 'bg-btc-chip text-btc-hi'],
        'soon' => [__('Under 10 minutes'), 'shadow-[inset_3px_0_0_var(--color-btc)]', 'bg-btc-chip text-btc-hi'],
        'ontrack' => [__('On track'), '', 'bg-well text-ink-2'],
    ];
@endphp

<div @class(['flex flex-col gap-8 px-4 pb-16 lg:px-12', 'chat-rail-host xl:[--chat-rail-own:3rem]' => $this->desk !== null])
     data-test="tournament-live" @if ($live) wire:poll.15s.visible x-data="tournamentLive({ id: {{ $tournament->id }} })" @endif>
    <header class="flex flex-col gap-3">
        <span class="flex flex-wrap items-center gap-2 text-xs">
            <span @class(['inline-flex items-center gap-1.5 rounded px-2 py-1 font-bold',
                'bg-btc/15 text-btc' => $running && ! $tournament->isPaused(),
                'bg-raised text-ink-2' => ! $running || $tournament->isPaused()]) data-test="live-status">
                @if ($running && ! $tournament->isPaused())<span class="size-1.5 rounded-full bg-btc"></span>@endif
                {{ $statusLabel }}
            </span>
            @if ($this->round)
                <span class="rounded bg-raised px-2 py-1 text-ink-2" data-test="live-round">{{ __('Round :number', ['number' => $this->round->number]) }}</span>
            @endif
            <span class="text-ink-3">{{ __('Live control') }}</span>
            @if ($live)
                {{-- The clock every deadline below is read against; the poll moves it. --}}
                <time datetime="{{ now()->toIso8601String() }}" class="ms-auto inline-flex items-baseline gap-2 text-ink-2" data-test="live-now">
                    {{ __('Now') }} <b class="font-display text-[15px] font-bold text-ink tabular-nums">{{ $clock(now()) }}</b>
                </time>
            @endif
        </span>
        <h1 class="m-0 font-display text-2xl leading-tight font-extrabold [overflow-wrap:anywhere] lg:text-3xl">{{ $tournament->title() }}</h1>
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Tournament') }}" data-test="live-links">
            @if ($this->canDirect)
                <x-button :href="route('tournaments.director', $tournament)" icon="flag" data-test="live-director">{{ __('Director desk') }}</x-button>
            @endif
            <x-button variant="secondary" :href="route('tournaments.show', $tournament)" icon="tournaments" data-test="live-public">{{ __('Tournament page') }}</x-button>
            <x-button variant="secondary" :href="route('tournaments.tv', $tournament)" icon="expand" target="_blank" data-test="live-tv">{{ __('TV view') }}</x-button>
            @if ($this->canManage)
                <x-button variant="quiet" :href="route('admin.tournaments.edit', $tournament)" icon="settings" data-test="live-edit">{{ __('Edit') }}</x-button>
            @endif
        </nav>
    </header>

    @if ($this->openSeries !== [])
        <section aria-labelledby="live-overview-h" class="flex flex-col gap-6" data-test="live-overview">
            <h2 id="live-overview-h" class="m-0 text-[15px] font-bold">{{ __('Check-in and deadlines') }}</h2>
            @foreach ($this->board() as $lane => $cards)
                @php [$laneTitle, $edge, $chip] = $lanes[$lane]; @endphp
                <section aria-labelledby="live-lane-{{ $lane }}" class="flex flex-col gap-3" data-test="live-lane" data-lane="{{ $lane }}">
                    <h3 id="live-lane-{{ $lane }}" @class(['m-0 flex items-center gap-2 text-[13px] font-bold', 'text-loss' => $lane === 'needs', 'text-btc-hi' => in_array($lane, ['checkin', 'soon'], true), 'text-ink-2' => $lane === 'ontrack'])>
                        {{ $laneTitle }}
                        <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-xs bg-raised px-1.5 text-xs text-ink tabular-nums">{{ count($cards) }}</span>
                    </h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($cards as ['series' => $series, 'state' => $state, 'rows' => $rows, 'next' => $next])
                            @php
                                $nowAt = collect($rows)->search(fn (array $row): bool => $row['at']->isFuture());
                                $nowAt = $nowAt === false ? count($rows) : $nowAt;
                            @endphp
                            <article @class(['flex min-w-0 flex-col gap-3 rounded-lg bg-card px-4 py-4', $edge]) wire:key="ov-{{ $series->id }}" data-test="live-series" data-lane="{{ $lane }}">
                                <header class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                    <a href="{{ route('matches.room', $series) }}" class="inline-flex min-h-6 items-center font-display text-[15px] font-bold text-ink tabular-nums underline decoration-edge underline-offset-4 hover:decoration-btc" wire:navigate aria-label="{{ __('Match room :label', ['label' => $series->label()]) }}">{{ $series->label() }}</a>
                                    <span class="inline-flex h-6 items-center rounded-xs px-2 text-xs font-bold {{ $chip }}" data-test="live-state">{{ $state }}</span>
                                </header>

                                <ul class="m-0 flex list-none flex-col gap-2 p-0 text-[13px]">
                                    @foreach (SeriesMatch::SIDES as $side)
                                        @php $readyAt = $series->readyAt($side); @endphp
                                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3" data-test="live-checkin-{{ $side }}">
                                            <b class="min-w-0 leading-snug font-bold [overflow-wrap:anywhere]" data-test="live-side-name">{{ $side === 'challenger' ? $series->challenger_name : $series->challenged_name }}</b>
                                            @if ($readyAt)
                                                <span class="inline-flex items-center gap-1 text-xs whitespace-nowrap text-win"><x-icon name="check" :size="14" class="shrink-0" />{{ __('in since :time', ['time' => $clock($readyAt)]) }}</span>
                                            @else
                                                <span @class(['inline-flex items-center gap-1 text-xs whitespace-nowrap', 'text-btc-hi' => $series->start_at?->isPast(), 'text-ink-3' => ! $series->start_at?->isPast()])><x-icon name="clock" :size="14" class="shrink-0" />{{ __('not in yet') }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>

                                {{-- Every deadline in time order; the "now" line splits the passed from the coming ones. --}}
                                <ol class="m-0 flex list-none flex-col gap-1.5 border-t border-hairline p-0 pt-3 text-xs" data-test="live-deadlines">
                                    @foreach ($rows as $index => $row)
                                        @if ($index === $nowAt)
                                            <li class="flex items-center gap-2 py-0.5 font-bold text-btc" data-test="live-now-line"><span class="h-px grow bg-btc"></span>{{ __('now :time', ['time' => $clock(now())]) }}</li>
                                        @endif
                                        @php
                                            $past = ! $row['at']->isFuture();
                                            $isNext = $next !== null && $row['key'] === $next['key'];
                                            $soon = $row['counts'] && ! $past && $row['at']->getTimestamp() - now()->getTimestamp() <= 600;
                                        @endphp
                                        <li class="grid grid-cols-[3rem_minmax(0,1fr)] gap-x-2" data-test="live-deadline" data-key="{{ $row['key'] }}" @if ($isNext) data-next @endif>
                                            <span @class(['tabular-nums', 'text-ink-3' => $past, 'font-bold text-ink' => ! $past])>{{ $clock($row['at']) }}</span>
                                            <span class="flex min-w-0 flex-wrap justify-between gap-x-3">
                                                <span @class(['min-w-0 [overflow-wrap:anywhere]', 'text-ink-3' => $past, 'text-ink-2' => ! $past && ! $isNext, 'font-bold text-ink' => $isNext])>{{ $row['label'] }}</span>
                                                <span @class(['ms-auto text-right tabular-nums', 'text-ink-3' => $past || $row['note'] !== null || ! $row['counts'], 'font-bold text-btc-hi' => $soon && $row['note'] === null, 'text-ink-2' => $row['counts'] && ! $past && ! $soon && $row['note'] === null])>
                                                    {{ $row['note'] ?? ($past ? __('passed') : $left($row['at'])) }}
                                                </span>
                                            </span>
                                        </li>
                                    @endforeach
                                    @if ($nowAt === count($rows))
                                        <li class="flex items-center gap-2 py-0.5 font-bold text-btc" data-test="live-now-line"><span class="h-px grow bg-btc"></span>{{ __('now :time', ['time' => $clock(now())]) }}</li>
                                    @endif
                                </ol>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </section>
    @elseif ($running && $hasSeries)
        <p class="m-0 rounded-lg bg-card px-4 py-5 text-[13px] text-ink-2 lg:px-6" data-test="live-empty">{{ __('No match is open right now.') }}</p>
    @endif

    @if ($this->desk)
        <div class="chat-rail xl:mx-0" data-test="desk-rail"><x-tournaments.desk-chat :desk="$this->desk" /></div>
    @endif

    @if ($this->canManage && $tournament->status !== TournamentStatus::Draft)
        {{-- Results, disqualifications, pause, restarts, call-off, message to all players, who blocks what: above the bracket, it is where the direction acts. --}}
        <livewire:tournament-control :tournament="$tournament" :wire:key="'live-control-'.$tournament->id" />
    @endif

    <section id="bracket" aria-labelledby="live-bracket-h" class="flex flex-col gap-3" data-test="live-bracket">
        <h2 id="live-bracket-h" class="m-0 text-[15px] font-bold">{{ __('Bracket') }}</h2>
        @if ($this->stages !== [])
            @include('pages.tournaments.partials.stages', ['stages' => $this->stages, 'tournament' => $tournament])
        @else
            <p class="m-0 rounded-lg bg-card px-4 py-5 text-[13px] text-ink-2 lg:px-6" data-test="live-no-bracket">{{ __('The bracket appears here once it is drawn.') }}</p>
        @endif
    </section>
</div>
