<?php

use App\Models\AccountLink;
use App\Models\FalseReport;
use App\Models\User;
use App\Support\FairPlay\AccountLinks;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\FairPlayRefused;
use App\Support\LeagueTime;
use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Fair play (P41): an admin links the accounts of one person to its main
 * account (App\Support\FairPlay\AccountLinks: the others lose rated play and
 * prizes, results between them are voided) and undoes a link; both take a
 * reason and stay in the record. Below, the players locked from rated play
 * after confirmed false reports (decided on the dispute page) and the newest
 * of those reports. Nothing is detected automatically.
 */
new #[Title('Fair play')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    /** The hex pubkey of the main account, picked in <x-player-picker allow-npub>. */
    public ?string $main = null;

    /** The hex pubkey of the second account. */
    public ?string $linked = null;

    public string $reason = '';

    public string $notice = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @return Collection<int, AccountLink>
     */
    #[Computed]
    public function links(): Collection
    {
        return AccountLink::query()->active()->withCount('voids')->orderBy('main_pubkey')->orderBy('id')->get();
    }

    /**
     * The links undone, newest first.
     *
     * @return Collection<int, AccountLink>
     */
    #[Computed]
    public function history(): Collection
    {
        return AccountLink::query()->whereNotNull('unlinked_at')->withCount('voids')->orderByDesc('unlinked_at')->limit(50)->get();
    }

    /**
     * @return Collection<int, FalseReport>
     */
    #[Computed]
    public function falseReports(): Collection
    {
        return FalseReport::query()->with(['seriesMatch', 'decidedBy'])->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();
    }

    /**
     * The players locked from rated play now, with the end.
     *
     * @return array<string, \Carbon\CarbonImmutable>
     */
    #[Computed]
    public function locked(): array
    {
        $locked = [];

        foreach (FairPlay::barred($this->falseReports->pluck('pubkey')->unique()->values()->all()) as $pubkey => $bar) {
            if ($bar['kind'] === 'locked' && $bar['until'] !== null) {
                $locked[$pubkey] = $bar['until'];
            }
        }

        return $locked;
    }

    /** @return array<string, User> pubkey => account */
    #[Computed]
    public function users(): array
    {
        $pubkeys = [
            ...$this->links->flatMap(fn (AccountLink $link): array => [$link->main_pubkey, $link->linked_pubkey, $link->linked_by_pubkey])->all(),
            ...$this->history->flatMap(fn (AccountLink $link): array => [$link->main_pubkey, $link->linked_pubkey, (string) $link->unlinked_by_pubkey])->all(),
            ...$this->falseReports->pluck('pubkey')->all(),
        ];

        return User::query()->whereIn('pubkey', array_unique($pubkeys))->get()->keyBy('pubkey')->all();
    }

    public function name(string $pubkey): string
    {
        return ($this->users[$pubkey] ?? null)?->displayName() ?? (NostrKeys::isHexPubkey($pubkey) ? Str::limit(NostrKeys::hexToNpub($pubkey), 16, '…') : '–');
    }

    public function link(): void
    {
        $this->decide(function (AccountLinks $links, User $admin): string {
            $this->validate([
                'main' => ['required', 'string', 'max:100'],
                'linked' => ['required', 'string', 'max:100'],
            ], [
                'main.required' => __('Pick the main account.'),
                'linked.required' => __('Pick the second account.'),
            ]);

            $done = $links->link($admin, (string) $this->main, (string) $this->linked, $this->reason);
            $this->reset(['main', 'linked']);

            return __('Linked. Results voided between the accounts: :voided. Prizes withheld: :withheld.', ['voided' => $done['voided'], 'withheld' => $done['withheld']]);
        });
    }

    public function unlink(int $linkId): void
    {
        $this->decide(function (AccountLinks $links, User $admin) use ($linkId): string {
            $link = AccountLink::query()->active()->find($linkId) ?? throw new FairPlayRefused(__('This link was undone already.'));
            $released = $links->unlink($admin, $link, $this->reason);

            return __('Unlinked. Rated play and prizes are back; prizes released: :released. Voided results stay void.', ['released' => $released]);
        });
    }

    /**
     * @param  Closure(AccountLinks, User): string  $action
     */
    private function decide(Closure $action): void
    {
        Gate::authorize('admin');
        $admin = auth()->user();
        abort_unless($admin instanceof User, 403);
        $this->resetErrorBag();
        $this->notice = '';

        try {
            $this->notice = $action(app(AccountLinks::class), $admin);
        } catch (FairPlayRefused $refused) {
            $this->addError('reason', $refused->getMessage());

            return;
        }

        $this->reset('reason');
        unset($this->links, $this->history, $this->users);
    }
}; ?>

@php
    $input = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $grouped = $this->links->groupBy('main_pubkey');
@endphp

<x-admin.page active="fair-play" :title="__('Fair play')" :lead="__('Linked accounts of one player, and players locked after false result reports')" :notice="$notice" notice-test="fair-play-notice" data-test="admin-fair-play">
    <x-admin.panel :title="__('Link accounts')" :meta="__('one player, several accounts')">
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Pick the main account and a second account of the same player. The second account then plays no rated matches and wins no prizes; its unpaid prizes are withheld for your review. Results between the two accounts are voided and their rated Elo is taken back. Unlinking brings rated play and prizes back, but voided results stay void.') }}</p>
        <form wire:submit="link" wire:confirm="{{ __('Link these accounts? Results between them are voided.') }}" class="flex flex-col gap-3" data-test="fair-play-link-form">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <x-player-picker id="fair-play-main" wire:model="main" allow-npub :label="__('Main account')" :exclude="[(int) auth()->id()]" />
                <x-player-picker id="fair-play-linked" wire:model="linked" allow-npub :label="__('Second account')" :exclude="[(int) auth()->id()]" />
            </div>
            @error('main')<p class="m-0 text-[13px] text-loss" role="alert" data-test="fair-play-main-error">{{ $message }}</p>@enderror
            @error('linked')<p class="m-0 text-[13px] text-loss" role="alert" data-test="fair-play-linked-error">{{ $message }}</p>@enderror
            <label class="flex max-w-xl flex-col gap-1.5 text-xs text-ink-2">{{ __('Reason for your decision (required, saved with it)') }}
                <input type="text" wire:model="reason" maxlength="280" class="{{ $input }}" data-test="fair-play-reason">
            </label>
            @error('reason')<p class="m-0 text-[13px] text-loss" role="alert" data-test="fair-play-error">{{ $message }}</p>@enderror
            <span class="flex flex-wrap gap-3">
                <x-button type="submit" icon="shield-check" data-test="fair-play-link">{{ __('Link accounts') }}</x-button>
            </span>
        </form>
    </x-admin.panel>

    <x-admin.panel :title="__('Linked accounts')" :meta="trans_choice(':count link|:count links', $this->links->count())" flush data-test="fair-play-links">
        @forelse ($grouped as $mainPubkey => $links)
            <div class="flex flex-col border-t border-hairline py-3 first:border-t-0" wire:key="main-{{ $mainPubkey }}" data-test="fair-play-person">
                <span class="flex flex-wrap items-baseline gap-x-2 text-[13px]"><span class="text-xs text-ink-3">{{ __('Main account') }}</span><b class="[overflow-wrap:anywhere]">{{ $this->name($mainPubkey) }}</b></span>
                <ul class="m-0 flex list-none flex-col p-0">
                    @foreach ($links as $link)
                        <li class="flex flex-col gap-2 border-t border-hairline py-2 first:border-t-0 sm:flex-row sm:items-center sm:justify-between" wire:key="link-{{ $link->id }}" data-test="fair-play-link-row">
                            <span class="flex min-w-0 flex-col gap-0.5 text-[13px]">
                                <b class="[overflow-wrap:anywhere]">{{ $this->name($link->linked_pubkey) }}</b>
                                <span class="text-xs break-words text-ink-2">{{ __('Linked by :admin on :time: :reason', ['admin' => $this->name($link->linked_by_pubkey), 'time' => LeagueTime::stamp($link->created_at ?? now()), 'reason' => $link->reason]) }}</span>
                                <span class="text-xs text-ink-3">{{ trans_choice(':count result voided|:count results voided', $link->voids_count) }}</span>
                            </span>
                            <x-button variant="secondary" wire:click="unlink({{ $link->id }})" wire:confirm="{{ __('Unlink this account? It plays rated and wins prizes again. Voided results stay void.') }}" data-test="fair-play-unlink">{{ __('Unlink') }}</x-button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <x-admin.empty :text="__('No accounts are linked.')" />
        @endforelse
    </x-admin.panel>

    <x-admin.panel :title="__('False reports')" :meta="__(':count within :days days lock rated play for :lock days', ['count' => FairPlay::threshold(), 'days' => FairPlay::windowDays(), 'lock' => FairPlay::lockDays()])" flush data-test="fair-play-false-reports">
        @forelse ($this->falseReports as $report)
            <div class="flex flex-col gap-0.5 border-t border-hairline py-2 text-[13px] first:border-t-0 sm:flex-row sm:items-baseline sm:gap-4" wire:key="fr-{{ $report->id }}" data-test="fair-play-false-report">
                <span class="shrink-0 text-xs text-ink-2 sm:w-[180px]">{{ LeagueTime::stamp($report->created_at ?? now()) }}</span>
                <span class="min-w-0 [overflow-wrap:anywhere]">
                    <b>{{ $this->name($report->pubkey) }}</b>
                    @if ($report->seriesMatch)
                        · <a href="{{ route('admin.disputes.show', $report->seriesMatch) }}" class="inline-flex min-h-6 items-center">{{ __('Match :number', ['number' => $report->seriesMatch->label()]) }}</a>
                    @endif
                    @if (isset($this->locked[$report->pubkey]))
                        · <span class="text-loss" data-test="fair-play-locked">{{ __('no rated play until :time', ['time' => LeagueTime::stamp($this->locked[$report->pubkey])]) }}</span>
                    @endif
                </span>
            </div>
        @empty
            <x-admin.empty :text="__('No confirmed false reports. Decide a dispute as a false report on its dispute page.')" />
        @endforelse
    </x-admin.panel>

    <x-admin.panel :title="__('Undone links')" :meta="__('every link stays in the record')" flush data-test="fair-play-history">
        @forelse ($this->history as $link)
            <div class="flex flex-col gap-0.5 border-t border-hairline py-2 text-[13px] first:border-t-0" wire:key="h-{{ $link->id }}" data-test="fair-play-history-row">
                <span class="[overflow-wrap:anywhere]"><b>{{ $this->name($link->linked_pubkey) }}</b> <span class="text-ink-3">→</span> {{ $this->name($link->main_pubkey) }}</span>
                <span class="text-xs break-words text-ink-2">{{ __('Linked by :admin on :time: :reason', ['admin' => $this->name($link->linked_by_pubkey), 'time' => LeagueTime::stamp($link->created_at ?? now()), 'reason' => $link->reason]) }}</span>
                <span class="text-xs break-words text-ink-2">{{ __('Unlinked by :admin on :time: :reason', ['admin' => $this->name((string) $link->unlinked_by_pubkey), 'time' => LeagueTime::stamp($link->unlinked_at ?? now()), 'reason' => (string) $link->unlink_reason]) }}</span>
            </div>
        @empty
            <x-admin.empty :text="__('No link was undone yet.')" />
        @endforelse
    </x-admin.panel>
</x-admin.page>
