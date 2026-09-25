<?php

use App\Enums\SeriesStatus;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use App\Models\User;
use App\Support\Series\SeriesPresenter;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One dispute or no-show, 1:1 from AdminDispute.dc.html: the history, both
 * reports side by side with the differing games marked, the evidence
 * (admins only), and the decision with its public reason (NIP `resolution`
 * admin, forfeit or void). The lobby is not shown here.
 *
 * Deviation: the design's "Match chat" excerpt is left out. The chat is
 * NIP-17, end-to-end encrypted between the players; the league cannot read
 * it and stores none (NIP "Chat"), so there is nothing to excerpt.
 */
new #[Title('Dispute')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public SeriesMatch $match;

    /** `report:<id>`, `result`, `void`, `forfeit:<side>` */
    public string $decision = '';

    /** @var list<array{c: int|string|null, d: int|string|null, winner: string|null}> */
    public array $games = [];

    public string $reason = '';

    public string $error = '';

    public function mount(SeriesMatch $match): void
    {
        Gate::authorize('admin');
        $this->match = $match;

        for ($i = 0; $i < $match->best_of; $i++) {
            $game = $match->latestReport?->games[$i] ?? null;
            $this->games[] = ['c' => $game['challenger'] ?? null, 'd' => $game['challenged'] ?? null, 'winner' => $game['winner'] ?? null];
        }
    }

    #[Computed]
    public function case(): SeriesMatch
    {
        return SeriesMatch::query()
            ->with(['challengerLineup.clan', 'challengedLineup.clan', 'reports.user', 'reports.event', 'reports.responseEvent', 'latestReport', 'evidence.user', 'challengeEvent', 'answerEvent', 'resolvedBy'])
            ->findOrFail($this->match->id);
    }

    public function decide(): void
    {
        Gate::authorize('admin');
        $this->error = '';
        [$type, $value] = array_pad(explode(':', $this->decision, 2), 2, null);

        $decision = match ($type) {
            'report' => ['type' => 'report', 'report' => (int) $value],
            'forfeit' => ['type' => 'forfeit', 'winner' => (string) $value],
            'void' => ['type' => 'void'],
            'result' => ['type' => 'result', 'games' => $this->enteredGames()],
            default => null,
        };

        if ($decision === null) {
            $this->error = __('Pick a decision first.');

            return;
        }

        try {
            app(SeriesService::class)->decide($this->match, $this->admin(), $decision, $this->reason);
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        }

        unset($this->case);
        $this->dispatch('toast', tone: 'win', title: __('Decision saved'), text: __('Match :number is decided.', ['number' => $this->match->label()]));
    }

    public function askForNewReport(): void
    {
        Gate::authorize('admin');

        try {
            app(SeriesService::class)->requestNewReport($this->match, $this->admin());
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }

        unset($this->case);
    }

    /**
     * @return list<array{winner: string, challenger: int|null, challenged: int|null}>
     */
    private function enteredGames(): array
    {
        $games = [];

        foreach ($this->games as $row) {
            $c = $row['c'] === null || $row['c'] === '' ? null : (int) $row['c'];
            $d = $row['d'] === null || $row['d'] === '' ? null : (int) $row['d'];
            $winner = $c !== null && $d !== null && $c !== $d ? ($c > $d ? 'challenger' : 'challenged') : ($row['winner'] ?: null);

            if ($winner === null) {
                break;
            }

            $games[] = ['winner' => $winner, 'challenger' => $c, 'challenged' => $d];
        }

        return $games;
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $case = $this->case;
    $viewer = auth()->user();
    $reports = $case->reports;
    $bySide = [];
    foreach ($reports as $r) { $bySide[$r->side] = $r; }
    $compare = array_values($bySide);
    $open = SeriesService::isOpenCase($case) || $case->status === SeriesStatus::Reported;
    $ownClan = $viewer->clanMember !== null && in_array($viewer->clanMember->clan_id, array_filter([$case->challengerLineup?->clan_id, $case->challengedLineup?->clan_id]), true);
    $disputed = $reports->first(fn (SeriesReport $r) => $r->response_reason !== null);
    $age = $case->updated_at?->diffForHumans();
    [$type, $value] = array_pad(explode(':', $decision, 2), 2, null);
    $preview = match ($type) {
        'report' => ($r = $reports->firstWhere('id', (int) $value)) ? __('decided by admin, winner :clan, series :a : :b', ['clan' => $case->sideName($r->score()['challenger'] > $r->score()['challenged'] ? 'challenger' : 'challenged'), 'a' => $r->score()['challenger'], 'b' => $r->score()['challenged']]) : '',
        'forfeit' => __('forfeit, winner :clan', ['clan' => $case->sideName((string) $value)]),
        'void' => __('void, no winner'),
        'result' => __('decided by admin, the entered games'),
        default => __('pick a decision'),
    };
    $option = 'flex min-h-14 cursor-pointer flex-col justify-center gap-0.5 rounded-md border px-4 py-2 text-left';
@endphp

<div class="flex grow flex-col" data-test="admin-dispute">
    <x-admin.nav active="disputes" />

    <div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12">
        <div class="flex flex-wrap items-center gap-4">
            <a href="{{ route('admin.disputes') }}" aria-label="{{ __('Back to disputes') }}" class="btn-w inline-flex size-11 items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="prev" :size="18" /></a>
            <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ $case->noshow_reported_at && $case->reports->isEmpty() ? __('No-show :number', ['number' => $case->label()]) : __('Dispute :number', ['number' => $case->label()]) }}</h1>
            <span class="grow"></span>
            <span @class(['inline-flex h-9 items-center gap-2 rounded-md px-3.5 text-[13px] font-bold', 'bg-loss-tint text-loss' => $open, 'bg-win-tint text-win' => ! $open])>
                <x-icon name="alert" :size="16" />{{ $open ? __('Open since :time', ['time' => $age]) : __('Decided :time', ['time' => $case->finished_at?->diffForHumans()]) }}
            </span>
        </div>

        @if ($disputed)
            <p class="m-0 flex items-center gap-3 rounded-md bg-loss-tint px-4 py-3 text-[13px] shadow-[inset_0_0_0_1px_#5A2A2E]">
                <x-icon name="warn" :size="18" class="shrink-0 text-loss" />{{ __(':clan disputed the :other report.', ['clan' => $case->sideName(SeriesMatch::otherSide($disputed->side)), 'other' => $case->sideName($disputed->side)]) }}
                @if (count($compare) > 1)<a href="#compare">{{ __('Compare both reports') }}</a>@endif
            </p>
        @endif

        <section aria-labelledby="hist-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6">
            <span class="flex items-baseline justify-between"><h2 id="hist-h" class="m-0 text-[15px] font-bold">{{ __('History') }}</h2><span class="text-xs text-ink-2">{{ __('every step of this match, newest right') }}</span></span>
            <ol class="m-0 flex list-none flex-wrap gap-x-8 gap-y-4 p-0">
                <li class="flex flex-col items-center gap-1 text-center text-xs"><span class="text-[11px] text-ink-3">{{ SeriesPresenter::time($case->created_at ?? now(), $viewer, 'D m-d H:i') }}</span><span class="size-3.5 rounded-full border-2 border-edge"></span><b>{{ __('Challenge') }}</b><span class="text-ink-2">{{ $case->challenger_name }}</span></li>
                @if ($case->answered_at)<li class="flex flex-col items-center gap-1 text-center text-xs"><span class="text-[11px] text-ink-3">{{ SeriesPresenter::time($case->answered_at, $viewer, 'D m-d') }}</span><span class="size-3.5 rounded-full border-2 border-edge"></span><b>{{ __('Accepted') }}</b><span class="text-ink-2">{{ $case->challenged_name }}</span></li>@endif
                @foreach ($reports as $r)
                    @php($w = $r->score())
                    <li class="flex flex-col items-center gap-1 text-center text-xs"><span class="text-[11px] text-ink-3">{{ SeriesPresenter::time($r->created_at ?? now(), $viewer, 'D H:i') }}</span><span class="size-3.5 rounded-full bg-btc"></span><b>{{ __('Report :a : :b', ['a' => $w['challenger'], 'b' => $w['challenged']]) }}</b><span class="text-ink-2">{{ $r->user?->displayName() }}</span></li>
                    @if ($r->response_reason)
                        <li class="flex flex-col items-center gap-1 text-center text-xs"><span class="text-[11px] text-ink-3">{{ $r->responded_at ? SeriesPresenter::time($r->responded_at, $viewer, 'D H:i') : '' }}</span><span class="size-3.5 rounded-full border-2 border-loss"></span><b class="text-loss">{{ __('Dispute') }}</b><span class="text-ink-2">{{ $case->sideName(SeriesMatch::otherSide($r->side)) }}</span></li>
                    @endif
                @endforeach
                @if ($case->noshow_reported_at)<li class="flex flex-col items-center gap-1 text-center text-xs"><span class="text-[11px] text-ink-3">{{ SeriesPresenter::time($case->noshow_reported_at, $viewer, 'D H:i') }}</span><span class="size-3.5 rounded-full border-2 border-btc-hi"></span><b class="text-btc-hi">{{ __('No-show reported') }}</b><span class="text-ink-2">{{ $case->sideName((string) $case->noshow_side) }}</span></li>@endif
                <li class="flex flex-col items-center gap-1 text-center text-xs"><span class="text-[11px] text-ink-3">{{ $open ? __('pending') : '' }}</span><span class="size-3.5 rounded-full border-2 border-proof"></span><b class="text-proof">{{ __('Decision') }}</b><span class="text-ink-2">{{ $open ? __('after your decision') : $case->resolution?->label() }}</span></li>
            </ol>
            <x-proof :rows="SeriesPresenter::proofRows($case)" />
        </section>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
                @foreach ([[__('Pairing'), $case->challenger_name.' : '.$case->challenged_name], [__('Format'), 'Rocket League, '.$case->mode.', Bo'.$case->best_of], [__('Played'), $case->start_at ? SeriesPresenter::time($case->start_at, $viewer) : '–']] as [$key, $v])
                    <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-[13px] last:border-0"><span class="text-ink-2">{{ $key }}</span><span>{{ $v }}</span></div>
                @endforeach
            </div>
            <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
                @foreach ([[__('Match kind'), $case->rated ? __('Rated') : __('Casual, no Elo at stake')], [__('League record'), $case->rated ? __('from P7, after the decision') : __('none for casual matches')], [__('Open since'), $age]] as [$key, $v])
                    <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-[13px] last:border-0"><span class="text-ink-2">{{ $key }}</span><span>{{ $v }}</span></div>
                @endforeach
            </div>
        </div>

        @if ($compare !== [])
            <section id="compare" aria-labelledby="cmp-h" class="flex flex-col gap-3">
                <span class="flex flex-wrap items-baseline gap-3"><h2 id="cmp-h" class="m-0 font-display text-xl font-bold">{{ __('Reports side by side') }}</h2><span class="text-xs text-ink-2">{{ __('Games that differ are marked.') }}</span></span>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    @foreach ($compare as $r)
                        @php($otherReport = collect($compare)->first(fn ($x) => $x->id !== $r->id))
                        @php($w = $r->score())
                        <div class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6" data-test="report-{{ $r->side }}">
                            <span class="flex items-center gap-3 pb-2"><x-clan-tag :tag="$case->sideTag($r->side)" /><span class="flex flex-col"><b class="text-[15px]">{{ $case->sideName($r->side) }}</b><span class="text-xs text-ink-2">{{ $r->user?->displayName() }}, {{ __('captain') }}</span></span><span class="grow"></span><span class="inline-flex items-center gap-1 text-xs text-proof"><x-icon name="shield-check" :size="14" />{{ $r->event ? __('Signed') : __('Casual') }}</span></span>
                            @for ($i = 0; $i < $case->best_of; $i++)
                                @php($g = $r->games[$i] ?? null)
                                @php($o = $otherReport?->games[$i] ?? null)
                                @php($same = $otherReport === null || $g == $o)
                                <div @class(['grid h-9 grid-cols-[70px_minmax(0,1fr)_auto] items-center gap-3 px-2 text-[13px]', 'rounded-sm bg-loss-tint shadow-[inset_3px_0_0_#F87171]' => ! $same])>
                                    <span class="text-ink-2">{{ __('Game :n', ['n' => $i + 1]) }}</span>
                                    <b>{{ $g === null ? '–' : ($g['challenger'] !== null ? $g['challenger'].' : '.$g['challenged'] : __(':tag win', ['tag' => $case->sideTag($g['winner'])])) }}</b>
                                    <span @class(['text-xs', 'text-ink-3' => $same, 'text-loss' => ! $same])>{{ $same ? __('same') : ($g === null ? __('not reported') : ($o === null ? __('only here') : __('differs'))) }}</span>
                                </div>
                            @endfor
                            <span class="flex items-baseline gap-3 border-t border-hairline pt-3 text-[13px]"><span>{{ __('Series') }}</span><b class="font-display text-xl">{{ $w['challenger'] }}:{{ $w['challenged'] }}</b><span class="text-ink-2">{{ __(':clan wins', ['clan' => $case->sideName($w['challenger'] > $w['challenged'] ? 'challenger' : 'challenged')]) }}</span><span class="grow"></span><span class="text-xs text-ink-3">{{ __('submitted :time', ['time' => SeriesPresenter::time($r->created_at ?? now(), $viewer, 'H:i')]) }}</span></span>
                            @if ($r->response_reason)
                                <p class="mt-3 mb-0 rounded-md bg-ground px-3.5 py-2.5 text-xs leading-normal text-ink-2 shadow-ring">{{ __('Dispute reason') }}: “{{ $r->response_reason }}”</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
            <div class="flex flex-col gap-5">
                <section aria-labelledby="ev-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
                    <span class="flex items-baseline justify-between"><h2 id="ev-h" class="m-0 text-[15px] font-bold">{{ __('Evidence') }}</h2><span class="text-xs text-ink-2">{{ __('admins only, never published') }}</span></span>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @forelse ($case->evidence as $shot)
                            <a href="{{ route('admin.disputes.evidence', [$case, $shot]) }}" target="_blank" rel="noopener" class="flex flex-col gap-1 text-xs text-ink">
                                <img src="{{ route('admin.disputes.evidence', [$case, $shot]) }}" alt="{{ $shot->name }}" loading="lazy" class="aspect-[16/10] w-full rounded-md bg-ground object-cover shadow-ring">
                                <span class="truncate">{{ $shot->name }}</span><span class="text-ink-3">{{ $shot->user?->displayName() }}, {{ $shot->created_at?->format('H:i') }}</span>
                            </a>
                        @empty
                            <p class="col-span-3 m-0 text-[13px] text-ink-2">{{ __('No screenshots uploaded.') }}</p>
                        @endforelse
                    </div>
                </section>
                <section aria-labelledby="mc-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6">
                    <h2 id="mc-h" class="m-0 text-[15px] font-bold">{{ __('Match chat') }}</h2>
                    <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('The chat is end-to-end encrypted between the players (NIP-17). The league cannot read it, so there is no excerpt here. Ask the captains for screenshots instead.') }}</p>
                </section>
            </div>

            <section aria-labelledby="dec-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#2A2A30] lg:px-6" data-test="decision">
                <span class="flex items-baseline justify-between"><h2 id="dec-h" class="m-0 text-[15px] font-bold">{{ __('Decision') }}</h2><span class="text-xs text-ink-2">{{ __('you decide as :name', ['name' => $viewer->displayName()]) }}</span></span>
                @if (! $open)
                    <p class="m-0 text-[13px] text-ink-2" data-test="decided">{{ __(':resolution by :name: :reason', ['resolution' => $case->resolution?->label() ?? '', 'name' => $case->resolvedBy?->displayName() ?? '', 'reason' => $case->resolution_reason ?? '']) }}</p>
                @elseif ($ownClan)
                    <p class="m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss" data-test="own-clan">{{ __('You cannot decide a case involving your own clan. Another admin decides this one.') }}</p>
                @else
                    <div role="radiogroup" aria-labelledby="dec-h" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($compare as $r)
                            @php($w = $r->score())
                            <button type="button" role="radio" wire:click="$set('decision', 'report:{{ $r->id }}')" aria-checked="{{ $decision === 'report:'.$r->id ? 'true' : 'false' }}" data-test="decide-report-{{ $r->side }}"
                                    @class([$option, 'border-btc bg-btc-press' => $decision === 'report:'.$r->id, 'border-line bg-ground' => $decision !== 'report:'.$r->id])>
                                <b class="text-[13px]">{{ __('Accept :tag report', ['tag' => $case->sideTag($r->side)]) }}</b><span class="text-xs text-ink-2">{{ __('series :a : :b', ['a' => $w['challenger'], 'b' => $w['challenged']]) }}</span>
                            </button>
                        @endforeach
                        <button type="button" role="radio" wire:click="$set('decision', 'result')" aria-checked="{{ $decision === 'result' ? 'true' : 'false' }}" @class([$option, 'border-btc bg-btc-press' => $decision === 'result', 'border-line bg-ground' => $decision !== 'result'])>
                            <b class="text-[13px]">{{ __('Enter the result') }}</b><span class="text-xs text-ink-2">{{ __('goals per game') }}</span>
                        </button>
                        <button type="button" role="radio" wire:click="$set('decision', 'void')" aria-checked="{{ $decision === 'void' ? 'true' : 'false' }}" data-test="decide-void" @class([$option, 'border-btc bg-btc-press' => $decision === 'void', 'border-line bg-ground' => $decision !== 'void'])>
                            <b class="text-[13px]">{{ __('Void match') }}</b><span class="text-xs text-ink-2">{{ __('no winner') }}</span>
                        </button>
                        @foreach (SeriesMatch::SIDES as $side)
                            <button type="button" role="radio" wire:click="$set('decision', 'forfeit:{{ $side }}')" aria-checked="{{ $decision === 'forfeit:'.$side ? 'true' : 'false' }}" @class([$option, 'border-btc bg-btc-press' => $decision === 'forfeit:'.$side, 'border-line bg-ground' => $decision !== 'forfeit:'.$side])>
                                <b class="text-[13px]">{{ __('Forfeit') }}</b><span class="text-xs text-ink-2">{{ __(':clan wins, no game played', ['clan' => $case->sideTag($side)]) }}</span>
                            </button>
                        @endforeach
                    </div>
                    @if ($decision === 'result')
                        <div class="flex flex-col gap-2" data-test="result-entry">
                            @foreach ($games as $index => $row)
                                <div class="grid grid-cols-[70px_60px_12px_60px_minmax(0,1fr)] items-center gap-2 text-[13px]">
                                    <span class="text-ink-2">{{ __('Game :n', ['n' => $index + 1]) }}</span>
                                    <input type="number" min="0" max="99" wire:model="games.{{ $index }}.c" aria-label="{{ __('Goals of :clan in game :n', ['clan' => $case->challenger_tag, 'n' => $index + 1]) }}" class="h-10 rounded-md border border-edge bg-ground px-2 text-center text-ink">
                                    <span class="text-center text-ink-3">:</span>
                                    <input type="number" min="0" max="99" wire:model="games.{{ $index }}.d" aria-label="{{ __('Goals of :clan in game :n', ['clan' => $case->challenged_tag, 'n' => $index + 1]) }}" class="h-10 rounded-md border border-edge bg-ground px-2 text-center text-ink">
                                    <span class="text-xs text-ink-3">{{ __('empty = not played') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <label for="reason" class="flex items-baseline justify-between text-xs text-ink-2"><span>{{ __('Reason') }}</span><span>{{ __('public, part of the result') }}</span></label>
                    <textarea id="reason" wire:model="reason" rows="3" maxlength="500" data-test="decision-reason" class="w-full resize-none rounded-lg border border-edge bg-ground px-3.5 py-3 text-[13px] text-ink"></textarea>
                    <p class="m-0 rounded-md bg-proof-fill px-3.5 py-2.5 text-xs text-proof">{{ __('Result') }}: {{ $preview }}</p>
                    @if ($error)<p class="m-0 text-[13px] text-loss" role="alert" data-test="decision-error">{{ $error }}</p>@endif
                    <div class="flex flex-wrap justify-end gap-3">
                        @if ($case->status === SeriesStatus::Disputed)
                            <x-button variant="quiet" wire:click="askForNewReport" data-test="ask-new-report">{{ $case->new_report_requested_at ? __('New report asked') : __('Ask for a new report') }}</x-button>
                        @endif
                        <x-button icon="shield-check" wire:click="decide" data-test="confirm-decision">{{ __('Confirm decision') }}</x-button>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>
