<?php

use App\Models\NostrEvent;
use App\Models\TrustCountedReport;
use App\Models\TrustDecision;
use App\Models\TrustExclusion;
use App\Models\TrustRank;
use App\Models\TrustReportConfirmation;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustAdmin;
use App\Support\SeasonChain\TrustAdminRefused;
use App\Support\SeasonChain\TrustJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Trust (P7d, NIP "Reports"): the league-labelled reports the trust job has
 * read, and the admin decisions on them. A report waits, without effect on
 * anybody's rank, until an admin confirms it (decision of 2026-11-18); a
 * confirmed report can count, a dismissed one (also a withdrawn
 * confirmation) does not. Excluding a pubkey from the trust graph (raw 0,
 * its list vouches for nobody) is the board's. Every decision takes a reason
 * and goes into the append-only decision log shown here. The next trust run
 * applies it, and its effect is public in the assertions.
 */
new #[Title('Trust')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public string $reason = '';

    /** The hex pubkey picked in <x-player-picker allow-npub>; null = nothing picked. */
    public ?string $key = null;

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * The newest league reports, newest first.
     *
     * @return Collection<int, NostrEvent>
     */
    #[Computed]
    public function reports(): Collection
    {
        return NostrEvent::query()->where('kind', TrustJob::REPORT)->orderByDesc('signed_at')->orderByDesc('id')->limit(100)->get();
    }

    /** @return array<string, TrustReportDismissal> event id => dismissal */
    #[Computed]
    public function dismissals(): array
    {
        return TrustReportDismissal::query()->whereIn('event_id', $this->reports->pluck('event_id'))->get()->keyBy('event_id')->all();
    }

    /** @return array<string, TrustReportConfirmation> event id => confirmation; a dismissal wins over it */
    #[Computed]
    public function confirmations(): array
    {
        return array_diff_key(TrustReportConfirmation::query()->whereIn('event_id', $this->reports->pluck('event_id'))->get()->keyBy('event_id')->all(), $this->dismissals);
    }

    /**
     * The reports waiting for an admin, as the navigation counts them
     * (AdminNavigation::waitingReports()): signed since Block 0 of the live
     * season, neither confirmed nor dismissed, their author not excluded.
     * None without a live season.
     *
     * @return array<string, true> event id => true
     */
    #[Computed]
    public function waiting(): array
    {
        $since = Seasons::live()?->genesis_at->getTimestamp();

        if ($since === null) {
            return [];
        }

        $excluded = array_fill_keys(TrustExclusion::query()->pluck('pubkey')->all(), true);

        return array_fill_keys($this->reports->filter(fn (NostrEvent $report): bool => $report->signed_at >= $since && ! isset($excluded[$report->pubkey])
            && ! isset($this->dismissals[$report->event_id]) && ! isset($this->confirmations[$report->event_id]))->pluck('event_id')->all(), true);
    }

    /**
     * The reports that count now (round 4): counted in the live season,
     * confirmed, and their author not excluded, as of the last trust run.
     *
     * @return array<string, true> event id => true
     */
    #[Computed]
    public function counting(): array
    {
        $season = Seasons::live();

        if ($season === null) {
            return [];
        }

        $excluded = TrustExclusion::query()->pluck('pubkey')->all();

        return array_fill_keys(TrustCountedReport::query()->where('season_id', $season->id)
            ->whereIn('event_id', array_keys($this->confirmations))->whereNotIn('author', $excluded)->pluck('event_id')->all(), true);
    }

    /**
     * @return Collection<int, TrustExclusion>
     */
    #[Computed]
    public function exclusions(): Collection
    {
        return TrustExclusion::query()->latest()->get();
    }

    /**
     * Players the picker does not suggest: the admin (no decision on one's own
     * key) and the keys excluded already.
     *
     * @return list<int>
     */
    #[Computed]
    public function excludedUserIds(): array
    {
        return array_values(User::query()->whereIn('pubkey', $this->exclusions->pluck('pubkey'))->pluck('id')->push(auth()->id())->filter()->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * The newest entries of the append-only decision log (round 3).
     *
     * @return Collection<int, TrustDecision>
     */
    #[Computed]
    public function decisions(): Collection
    {
        return TrustDecision::query()->orderByDesc('id')->limit(50)->get();
    }

    /** @return array<string, string> pubkey => name or short npub, for the reports and exclusions shown */
    #[Computed]
    public function names(): array
    {
        $pubkeys = [...$this->reports->pluck('pubkey')->all(), ...$this->reports->map(fn (NostrEvent $report): string => (string) $this->target($report))->all(), ...$this->exclusions->pluck('pubkey')->all(), ...$this->decisions->pluck('actor_pubkey')->all()];
        $users = User::query()->whereIn('pubkey', array_unique($pubkeys))->get()->keyBy('pubkey');
        $names = [];

        foreach (array_unique($pubkeys) as $pubkey) {
            $names[$pubkey] = $users->get($pubkey)?->displayName() ?? (NostrKeys::isHexPubkey($pubkey) ? Str::limit(NostrKeys::hexToNpub($pubkey), 16, '…') : '–');
        }

        return $names;
    }

    /** @return array<string, int> pubkey => current rank */
    #[Computed]
    public function ranks(): array
    {
        return TrustRank::query()->whereIn('pubkey', $this->reports->pluck('pubkey'))->pluck('rank', 'pubkey')->all();
    }

    public function target(NostrEvent $report): ?string
    {
        foreach ((array) ($report->payload()['tags'] ?? []) as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'p') {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
            }
        }

        return null;
    }

    public function label(NostrEvent $report): string
    {
        foreach ((array) ($report->payload()['tags'] ?? []) as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'l' && ($tag[2] ?? null) === TrustJob::LABEL_NAMESPACE) {
                return (string) ($tag[1] ?? '');
            }
        }

        return '';
    }

    public function confirm(string $eventId): void
    {
        $this->decide(fn (TrustAdmin $trust, User $admin) => $trust->confirm($admin, $eventId, $this->reason));
    }

    /** Dismisses a waiting report, or withdraws the confirmation of a confirmed one. */
    public function dismiss(string $eventId): void
    {
        $this->decide(fn (TrustAdmin $trust, User $admin) => $trust->dismiss($admin, $eventId, $this->reason));
    }

    public function excludeAuthor(string $eventId): void
    {
        $report = NostrEvent::query()->where('kind', TrustJob::REPORT)->where('event_id', $eventId)->first();

        if ($report !== null) {
            $this->decide(fn (TrustAdmin $trust, User $admin) => $trust->exclude($admin, $report->pubkey, $this->reason));
        }
    }

    public function exclude(): void
    {
        $this->resetErrorBag();
        $this->validate(['key' => ['required', 'string', 'max:100']], ['key.required' => __('Pick a player from the suggestions or paste a full npub.')]);

        $this->decide(fn (TrustAdmin $trust, User $admin) => $trust->exclude($admin, (string) $this->key, $this->reason), resetKey: true);
    }

    public function lift(string $pubkey): void
    {
        $this->decide(fn (TrustAdmin $trust, User $admin) => $trust->lift($admin, $pubkey, $this->reason));
    }

    private function decide(Closure $action, bool $resetKey = false): void
    {
        Gate::authorize('admin');
        $admin = auth()->user();
        abort_unless($admin instanceof User, 403);
        $this->resetErrorBag();

        try {
            $action(app(TrustAdmin::class), $admin);
        } catch (TrustAdminRefused $refused) {
            $this->addError('reason', $refused->getMessage());

            return;
        }

        $this->reset($resetKey ? ['reason', 'key'] : ['reason']);
        unset($this->dismissals, $this->confirmations, $this->waiting, $this->counting, $this->exclusions, $this->excludedUserIds, $this->decisions, $this->names);
    }
}; ?>

@php
    $input = 'rv-in';
    // Excluding a key (and lifting it) is the board's; confirming or dismissing a report is every admin's.
    $board = TrustAdmin::canExclude(auth()->user() instanceof User ? auth()->user() : null);
    $actions = ['confirm' => __('confirmed report'), 'withdraw' => __('withdrew confirmation of report'), 'dismiss' => __('dismissed report'), 'restore' => __('counted report again'), 'exclude' => __('excluded key'), 'lift' => __('lifted exclusion')];
@endphp

<x-admin.page active="trust" :title="__('Trust')" :lead="__('Reports and exclusions of the trust job')" data-test="admin-trust">
    <x-slot:readout>
        <span class="rv-live is-run"><i></i>{{ __('Trust job every 15 min.') }}</span><span class="flex items-baseline gap-2"><span class="rv-m">{{ __('with a rank') }}</span><span class="rv-num text-base text-ink" data-test="readout-value">{{ count($this->ranks) }}</span></span>
    </x-slot:readout>

    <p class="m-0 max-w-[70ch] text-[13px] leading-normal text-ink-2">{{ __('A report has no effect until an admin confirms it; until then it waits. A confirmed report halves its target\'s trust, and at most two count per player. It counts if its author had rank 50 or more in the last run, and at most :count reports of one author count per season. Dismiss a report or withdraw the confirmation and it does not count; exclude a key and it gets rank 0 and vouches for nobody. The next trust run applies your decision.', ['count' => (int) config('esports.trust.reports_per_author')]) }}</p>

    <label class="flex max-w-xl flex-col gap-1 text-xs text-ink-2">{{ __('Reason for your decision (required, saved with it)') }}
        <input type="text" wire:model="reason" maxlength="280" class="{{ $input }}" data-test="trust-reason">
    </label>
    @error('reason')<p class="m-0 text-[13px] text-loss" data-test="trust-error">{{ $message }}</p>@enderror

    <section aria-labelledby="reports-h" class="flex flex-col rounded border border-hairline bg-card px-4 py-2 lg:px-6">
        <h2 id="reports-h" class="m-0 py-3 text-[15px] font-bold">{{ __('Reports') }}</h2>
        @forelse ($this->reports as $report)
            @php($target = $this->target($report))
            @php($dismissal = $this->dismissals[$report->event_id] ?? null)
            @php($confirmation = $this->confirmations[$report->event_id] ?? null)
            <div wire:key="r-{{ $report->event_id }}" class="grid grid-cols-1 gap-2 border-t border-hairline py-3 text-[13px] lg:grid-cols-[120px_minmax(0,1fr)_minmax(0,1fr)_220px] lg:items-center lg:gap-4" data-test="trust-report">
                <span class="text-xs text-ink-2">{{ \Carbon\CarbonImmutable::createFromTimestamp($report->signed_at)->diffForHumans() }}</span>
                <span class="flex min-w-0 flex-col gap-0.5"><b class="truncate">{{ $this->names[$report->pubkey] ?? '–' }} → {{ $target !== null ? ($this->names[$target] ?? '–') : '–' }}</b><span class="text-xs text-ink-2">{{ $this->label($report) }} · {{ __('reporter rank :rank', ['rank' => $this->ranks[$report->pubkey] ?? 0]) }} · @if (isset($this->counting[$report->event_id]))<span class="text-win" data-test="trust-counts">{{ __('confirmed') }}, {{ __('counts') }}</span>@elseif ($confirmation)<span data-test="trust-confirmed">{{ __('confirmed, not counted in the last run') }}</span>@elseif (isset($this->waiting[$report->event_id]))<span class="text-btc-hi" data-test="trust-waiting">{{ __('waiting') }}</span>@else<span data-test="trust-not-counting">{{ __('does not count') }}</span>@endif</span></span>
                <span class="min-w-0 text-xs break-words text-ink-2">{{ Str::limit((string) ($report->payload()['content'] ?? ''), 160) }}@if ($dismissal)<br><span class="text-btc-hi">{{ __('Dismissed: :reason', ['reason' => $dismissal->reason]) }}</span>@elseif ($confirmation)<br><span class="text-btc-hi">{{ __('Confirmed: :reason', ['reason' => $confirmation->reason]) }}</span>@endif</span>
                <span class="flex flex-wrap gap-2">
                    {{-- Keyed: unkeyed sibling buttons would keep the confirm dialog of the button they are morphed from. --}}
                    @if ($confirmation)
                        <button type="button" wire:key="w-{{ $report->event_id }}" wire:click="dismiss('{{ $report->event_id }}')" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs whitespace-nowrap text-ink" data-test="trust-withdraw">{{ __('Withdraw confirmation') }}</button>
                    @else
                        {{-- R18: confirming lowers a player's rank, so it asks first; the reason field stays required. --}}
                        <button type="button" wire:key="c-{{ $report->event_id }}" wire:click="confirm('{{ $report->event_id }}')" wire:confirm="{{ __('Confirm this report? At the next trust run it halves the trust of :name; two confirmed reports can lock a player out of rated play.', ['name' => $target !== null ? ($this->names[$target] ?? '–') : '–']) }}" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs whitespace-nowrap text-ink" data-test="trust-confirm">{{ __('Confirm') }}</button>
                        @unless ($dismissal)
                            <button type="button" wire:key="d-{{ $report->event_id }}" wire:click="dismiss('{{ $report->event_id }}')" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs whitespace-nowrap text-ink" data-test="trust-dismiss">{{ __('Dismiss') }}</button>
                        @endunless
                    @endif
                    @if ($board)
                        <button type="button" wire:key="xa-{{ $report->event_id }}" wire:click="excludeAuthor('{{ $report->event_id }}')" class="h-11 cursor-pointer rounded-md border border-[#5A2A2E] bg-transparent px-3 text-xs text-loss" data-test="trust-exclude-author">{{ __('Exclude reporter') }}</button>
                    @endif
                </span>
            </div>
        @empty
            <x-admin.empty :text="__('No reports yet.')" class="border-t border-hairline py-4" />
        @endforelse
    </section>

    <section aria-labelledby="exclusions-h" class="flex flex-col gap-3 rounded border border-hairline bg-card p-4 lg:px-6">
        <h2 id="exclusions-h" class="rv-h3">{{ __('Excluded keys') }}</h2>
        @if ($board)
            <form wire:submit="exclude" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <x-player-picker id="trust-key" wire:model="key" allow-npub :label="__('Player or npub')" :exclude="$this->excludedUserIds" class="flex-1" />
                <button type="submit" class="h-11 cursor-pointer rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss" data-test="trust-exclude">{{ __('Exclude') }}</button>
            </form>
            @error('key')<p class="m-0 text-[13px] text-loss" role="alert" data-test="trust-key-error">{{ $message }}</p>@enderror
        @else
            <p class="m-0 text-[13px] text-ink-2">{{ __('Only the board excludes keys or lifts an exclusion.') }}</p>
        @endif
        @forelse ($this->exclusions as $exclusion)
            <div wire:key="x-{{ $exclusion->pubkey }}" class="flex flex-wrap items-center justify-between gap-2 border-t border-hairline py-3 text-[13px]" data-test="trust-exclusion">
                <span class="flex min-w-0 flex-col gap-0.5"><b class="truncate">{{ $this->names[$exclusion->pubkey] ?? '–' }}</b><span class="text-xs break-words text-ink-2">{{ $exclusion->reason }}</span></span>
                @if ($board)
                    <button type="button" wire:click="lift('{{ $exclusion->pubkey }}')" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs text-ink" data-test="trust-lift">{{ __('Lift') }}</button>
                @endif
            </div>
        @empty
            <x-admin.empty :text="__('No key is excluded.')" />
        @endforelse
    </section>

    <section aria-labelledby="log-h" class="flex flex-col gap-1 rounded border border-hairline bg-card p-4 lg:px-6" data-test="trust-log">
        <h2 id="log-h" class="m-0 pb-2 text-[15px] font-bold">{{ __('Decision log') }} <span class="text-xs font-normal text-ink-3">{{ __('every decision and every undo, never changed') }}</span></h2>
        @forelse ($this->decisions as $decision)
            <div wire:key="d-{{ $decision->id }}" class="flex flex-col gap-0.5 border-t border-hairline py-2 text-[13px] sm:flex-row sm:gap-4" data-test="trust-decision">
                <span class="shrink-0 text-xs text-ink-2 sm:w-[140px]">{{ $decision->created_at?->diffForHumans() }}</span>
                <span class="min-w-0 break-words"><b>{{ $this->names[$decision->actor_pubkey] ?? '–' }}</b> {{ $actions[$decision->action] ?? $decision->action }} <span class="font-mono text-xs text-ink-2">{{ Str::limit($decision->target, 16, '…') }}</span>: {{ $decision->reason }}</span>
            </div>
        @empty
            <x-admin.empty :text="__('No decisions yet.')" />
        @endforelse
    </section>
</x-admin.page>
