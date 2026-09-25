<?php

use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Disputes, 1:1 from AdminDisputes.dc.html: series where a captain reported
 * a problem, and no-shows, each with its history and how long it is open.
 * Admins never decide a case of their own clan (enforced on the case page);
 * "Hide matches of my own clans" only filters the list.
 */
new #[Title('Disputes')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    #[Url(except: 'open')]
    public string $tab = 'open';

    #[Url(except: '')]
    public string $mode = '';

    #[Url(except: '')]
    public string $clan = '';

    #[Url(except: 'oldest')]
    public string $sort = 'oldest';

    public bool $hideOwn = false;

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @param  Builder<SeriesMatch>  $query
     * @return Builder<SeriesMatch>
     */
    private function scope(Builder $query, string $tab): Builder
    {
        $user = auth()->user();
        $own = $user instanceof User ? $user->clanMember?->clan_id : null;
        $clan = Clan::query()->where('slug', $this->clan)->first();

        return $query
            ->when($tab === 'open', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('status', SeriesStatus::Disputed)
                ->orWhere(fn (Builder $query) => $query->where('status', SeriesStatus::Accepted)->whereNotNull('noshow_reported_at'))))
            ->when($tab === 'resolved', fn (Builder $query) => $query->where('status', SeriesStatus::Resolved))
            ->when($this->mode !== '', fn (Builder $query) => $query->where('mode', $this->mode))
            ->when($clan !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('challenger_lineup_id', $clan->lineups()->select('id'))->orWhereIn('challenged_lineup_id', $clan->lineups()->select('id'))))
            ->when($this->hideOwn && $own !== null, fn (Builder $query) => $query
                ->whereNotIn('challenger_lineup_id', Lineup::query()->where('clan_id', $own)->select('id'))
                ->whereNotIn('challenged_lineup_id', Lineup::query()->where('clan_id', $own)->select('id')));
    }

    /**
     * @return Collection<int, SeriesMatch>
     */
    #[Computed]
    public function cases(): Collection
    {
        return $this->scope(SeriesMatch::query()->with(['reports.user', 'latestReport']), $this->tab)
            ->orderBy('updated_at', $this->sort === 'newest' ? 'desc' : 'asc')
            ->get();
    }

    /**
     * @return array{open: int, resolved: int}
     */
    #[Computed]
    public function counts(): array
    {
        return ['open' => $this->scope(SeriesMatch::query(), 'open')->count(), 'resolved' => $this->scope(SeriesMatch::query(), 'resolved')->count()];
    }

    /**
     * @return Collection<int, Clan>
     */
    #[Computed]
    public function clans(): Collection
    {
        return Clan::query()->orderBy('name')->get();
    }

    /**
     * The history chips of a case (Report / Dispute / New report / Decision).
     *
     * @return list<array{kind: string, title: string, who: string, note: string}>
     */
    public function history(SeriesMatch $match): array
    {
        $chips = [];

        foreach ($match->reports as $index => $report) {
            $wins = $report->score();
            $chips[] = ['kind' => 'report', 'title' => $index === 0 ? __('Report') : __('New report'), 'who' => $match->sideName($report->side), 'note' => $wins[$report->side].' : '.$wins[SeriesMatch::otherSide($report->side)].', '.trans_choice(':count game|:count games', count($report->games))];

            if ($report->status === ReportStatus::Disputed || ($report->status === ReportStatus::Superseded && $report->response_reason !== null)) {
                $chips[] = ['kind' => 'dispute', 'title' => __('Dispute'), 'who' => $match->sideName(SeriesMatch::otherSide($report->side)), 'note' => '“'.Str::limit((string) $report->response_reason, 24).'”'];
            }
        }

        if ($match->noshow_reported_at !== null) {
            $chips[] = ['kind' => 'noshow', 'title' => __('No-show reported'), 'who' => $match->sideName((string) $match->noshow_side), 'note' => $match->noshow_reported_at->format('H:i').', '.__('match room')];
        }

        $chips[] = $match->status === SeriesStatus::Resolved
            ? ['kind' => 'decided', 'title' => __('Decided'), 'who' => $match->resolution?->label() ?? '', 'note' => '']
            : ['kind' => 'open', 'title' => __('Decision open'), 'who' => __('admin to decide'), 'note' => $match->noshow_reported_at !== null && $match->reports->isEmpty() ? __('Forfeit possible') : __('Who played: recorded')];

        return $chips;
    }
}; ?>

@php
    $select = 'h-11 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $chipClass = [
        'report' => 'border-line bg-well',
        'dispute' => 'border-[#5A2A2E] bg-loss-tint',
        'noshow' => 'border-btc-hi bg-ground',
        'open' => 'border-edge border-dashed bg-transparent',
        'decided' => 'border-proof-ring bg-proof-fill',
    ];
    $chipTitle = ['report' => 'text-ink', 'dispute' => 'text-loss', 'noshow' => 'text-btc-hi', 'open' => 'text-ink-2', 'decided' => 'text-proof'];
@endphp

<div class="flex grow flex-col" data-test="admin-disputes">
    <x-admin.nav active="disputes" />

    <div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <span class="flex flex-wrap items-baseline gap-4"><h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Disputes') }}</h1><span class="text-[13px] text-ink-2">{{ __('Matches where a captain disputed the result') }}</span></span>
            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-[13px] text-ink-2"><input type="checkbox" wire:model.live="hideOwn" class="size-4 accent-[#F7931A]">{{ __('Hide matches of my own clans') }}</label>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div role="tablist" class="flex gap-1 rounded-lg bg-card p-1">
                @foreach (['open' => __('Open'), 'resolved' => __('Resolved')] as $key => $label)
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            @class(['h-11 cursor-pointer rounded-md border-0 px-4 text-[13px]', 'bg-raised font-bold text-btc' => $tab === $key, 'bg-transparent text-ink-2' => $tab !== $key])>{{ $label }} {{ $this->counts[$key] }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-ink-3">{{ __('Mode') }}<select wire:model.live="mode" class="{{ $select }}"><option value="">{{ __('All modes') }}</option>@foreach (['3v3', '2v2', '1v1'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select></label>
                <label class="flex items-center gap-2 text-xs text-ink-3">{{ __('Clan') }}<select wire:model.live="clan" class="{{ $select }} w-[180px]"><option value="">{{ __('All clans') }}</option>@foreach ($this->clans as $option)<option value="{{ $option->slug }}">{{ $option->name }}</option>@endforeach</select></label>
                <label class="flex items-center gap-2 text-xs text-ink-3">{{ __('Sort') }}<select wire:model.live="sort" class="{{ $select }}"><option value="oldest">{{ __('Oldest first') }}</option><option value="newest">{{ __('Newest first') }}</option></select></label>
            </div>
        </div>

        <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
            <div class="hidden h-11 grid-cols-[100px_minmax(0,1fr)_150px_110px_100px] items-center gap-4 border-b border-hairline text-xs text-ink-2 lg:grid">
                <span>{{ __('Match') }}</span><span>{{ __('History') }}</span><span>{{ __('Reported') }}</span><span>{{ __('Open for') }}</span><span></span>
            </div>
            @forelse ($this->cases as $case)
                @php($report = $case->latestReport)
                @php($wins = $report?->score())
                @php($hours = (int) ($case->updated_at?->diffInHours(now()) ?? 0))
                <div wire:key="c-{{ $case->id }}" class="grid grid-cols-1 items-center gap-3 border-b border-hairline py-4 last:border-0 lg:grid-cols-[100px_minmax(0,1fr)_150px_110px_100px] lg:gap-4" data-test="dispute-row">
                    <span class="flex items-baseline gap-3 lg:flex-col lg:gap-1"><b class="text-lg">{{ $case->label() }}</b><span class="text-xs text-ink-2">{{ $case->mode }}, BO{{ $case->best_of }}</span></span>
                    <ol class="m-0 flex list-none flex-wrap items-center gap-y-2 p-0">
                        @foreach ($this->history($case) as $index => $chip)
                            @if ($index > 0)<span class="h-px w-3 bg-line" aria-hidden="true"></span>@endif
                            <li class="flex min-w-[140px] flex-col gap-0.5 rounded-md border px-3 py-2 text-xs {{ $chipClass[$chip['kind']] }}">
                                <b class="{{ $chipTitle[$chip['kind']] }}">{{ $chip['title'] }}</b><span>{{ $chip['who'] }}</span>@if ($chip['note'])<span class="text-[11px] text-ink-3">{{ $chip['note'] }}</span>@endif
                            </li>
                        @endforeach
                    </ol>
                    <span class="flex flex-col gap-1">
                        <span class="flex items-center gap-2"><x-clan-tag :tag="$case->challenger_tag" size="sm" /><b class="font-display text-lg">{{ $wins ? $wins['challenger'].':'.$wins['challenged'] : '–:–' }}</b><x-clan-tag :tag="$case->challenged_tag" size="sm" /></span>
                        <span class="text-[11px] text-ink-2">{{ $report ? __('Reported by :clan', ['clan' => $case->sideName($report->side)]) : __('No-show, no result') }}</span>
                    </span>
                    <span class="flex flex-col text-xs"><b @class(['text-loss' => $hours >= 48, 'text-btc-hi' => $hours < 48])>{{ $case->updated_at?->diffForHumans(null, true) }}</b>@if ($hours >= 48)<span class="text-ink-3">{{ __('overdue') }}</span>@endif</span>
                    <span><x-button :href="route('admin.disputes.show', $case)" data-test="review">{{ $tab === 'open' ? __('Review') : __('View') }}</x-button></span>
                </div>
            @empty
                <p class="m-0 py-6 text-[13px] text-ink-2">{{ $tab === 'open' ? __('No open disputes. Every result is settled.') : __('No resolved cases yet.') }}</p>
            @endforelse
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-ink-2">
            <span class="flex flex-wrap gap-4">
                @foreach ([['border-line bg-well', __('Report')], ['border-[#5A2A2E] bg-loss-tint', __('Dispute')], ['border-proof-ring bg-proof-fill', __('League record')], ['border-btc-hi', __('No-show report')], ['border-dashed border-edge', __('waiting for an admin')]] as [$class, $label])
                    <span class="inline-flex items-center gap-2"><span class="size-4 rounded-xs border {{ $class }}"></span>{{ $label }}</span>
                @endforeach
            </span>
            <span>{{ __('Open cases older than 48 h are marked red.') }}</span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                [__('Accept a report'), __('One of the two reports stands. The league record says "decided by admin".')],
                [__('Enter the result'), __('You enter the goals per game after checking the evidence.')],
                [__('Forfeit'), __('One side didn\'t show up, reported with the no-show button from 15 min after the start while no game was entered. The other side wins.')],
                [__('Void'), __('The match doesn\'t count, nobody wins. For breakdowns that are nobody\'s fault.')],
            ] as [$title, $text])
                <div class="flex flex-col gap-2 rounded-lg bg-card px-5 py-5"><b class="text-[15px]">{{ $title }}</b><p class="m-0 text-[13px] leading-normal text-ink-2">{{ $text }}</p></div>
            @endforeach
        </div>
        <p class="m-0 max-w-[70ch] text-[13px] leading-normal text-ink-2">{{ __('Every decision is saved with your public reason. Casual matches get no league record; rated ones get it from Block 0. You can\'t decide a case involving your own clan.') }}</p>
    </div>
</div>
