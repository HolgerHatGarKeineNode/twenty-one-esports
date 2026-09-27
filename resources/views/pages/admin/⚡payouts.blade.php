<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Jobs\PayTournamentPayout;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\WalletReconciliation;
use App\Support\PreSeason;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\WalletSetup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * AdminPayouts (AdminPayouts.dc.html, P9): prize money per player, sent to
 * the Lightning address in their Nostr profile. Admins only (route
 * middleware `admin`, and again in every action).
 *
 * Per tournament: the admin check at the end (closes the pool, reads the
 * places, writes one payout per player with its fixed key), then "Pay" per
 * player or for all that are ready. Every payment is queued
 * (PayTournamentPayout) and is exactly-once: a second click, a second admin
 * or a retried job never pays twice (PayoutRunner). A payout whose outcome
 * the wallet left unclear stays "Paying" and is looked up again; one whose
 * invoice expired unclear needs a look into the wallet and a "Release".
 *
 * The wallet panel says whether the two NWC connections are set up (never
 * their URIs), and shows the last daily reconciliation. Fail closed: without
 * the paying connection nothing can be approved or paid, and it says so.
 */
new #[Title('Payouts')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    #[Url(as: 'tournament')]
    public ?int $tournamentId = null;

    public string $notice = '';

    public function mount(): void
    {
        Gate::authorize('admin');

        if ($this->tournamentId === null) {
            $this->tournamentId = $this->tournaments->first()?->id;
        }
    }

    /**
     * Tournaments with a pool, newest first.
     *
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        return Tournament::query()->whereNotNull('pool_opened_at')->latest('id')->limit(100)->get();
    }

    #[Computed]
    public function tournament(): ?Tournament
    {
        return $this->tournamentId === null ? null : Tournament::query()->whereNotNull('pool_opened_at')->find($this->tournamentId);
    }

    /**
     * @return Collection<int, TournamentPayout>
     */
    #[Computed]
    public function payouts(): Collection
    {
        return $this->tournament === null ? new Collection : $this->tournament->payouts()->with(['participant', 'event'])->get();
    }

    public function approve(PayoutApproval $approval): void
    {
        Gate::authorize('admin');
        $this->notice = '';

        if ($this->tournament === null) {
            return;
        }

        try {
            $approval->approve($this->tournament, auth()->user());
            $this->notice = __('The pool is closed and the payouts are ready.');
        } catch (TournamentRuleViolation $violation) {
            $this->addError('payouts', $violation->getMessage());
        }

        unset($this->tournament, $this->payouts);
    }

    public function pay(int $payoutId): void
    {
        Gate::authorize('admin');
        $payout = $this->payout($payoutId);

        // An open payout is never paid: its address needs an approval first (approveAddress).
        if ($payout->status->isPayable()) {
            PayTournamentPayout::dispatch($payout->id);
        }

        unset($this->payouts);
    }

    public function payAll(): void
    {
        Gate::authorize('admin');

        foreach ($this->payouts as $payout) {
            if ($payout->status === PayoutStatus::Pending) {
                PayTournamentPayout::dispatch($payout->id);
            }
        }

        unset($this->payouts);
    }

    /** The admin approves the address the player's profile shows now (security gate F3). */
    public function approveAddress(int $payoutId, string $address, PayoutApproval $approval): void
    {
        Gate::authorize('admin');

        try {
            $approval->approveAddress($this->payout($payoutId), auth()->user(), $address);
        } catch (TournamentRuleViolation $violation) {
            $this->addError('payouts', $violation->getMessage());
        }

        unset($this->payouts);
    }

    /** Ask the wallet again about a payment whose outcome is unclear. */
    public function check(int $payoutId): void
    {
        Gate::authorize('admin');
        PayTournamentPayout::dispatch($this->payout($payoutId)->id, false);
        unset($this->payouts);
    }

    /** The admin looked into the wallet: that payment did not happen. */
    public function release(int $payoutId, PayoutRunner $runner): void
    {
        Gate::authorize('admin');
        $runner->release($this->payout($payoutId));
        unset($this->payouts);
    }

    private function payout(int $payoutId): TournamentPayout
    {
        return TournamentPayout::query()->where('tournament_id', $this->tournamentId)->findOrFail($payoutId);
    }
}; ?>

@php
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $tournament = $this->tournament;
    $paying = WalletSetup::canPay();
    // A pot in the tournament's own wallet is paid from that wallet (P9 scope addition).
    $ownWallet = $tournament?->hasOwnWallet() === true;
    $payingHere = $tournament === null ? $paying : WalletSetup::canPay($tournament);
    $receiving = WalletSetup::canReceive();
    $leagueKey = LeagueKey::fromConfig() !== null;
    $reconciliation = WalletReconciliation::query()->latest('id')->first();
    $approval = app(PayoutApproval::class);
    $pool = $tournament ? (int) app(PrizePool::class)->payableSats($tournament) : 0;
    $payouts = $this->payouts;
    $pending = $payouts->where('status', PayoutStatus::Pending);
@endphp

<div class="flex grow flex-col" data-test="admin-payouts">
    <x-admin.nav active="payouts" />

    <div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12">
        <div class="flex flex-col gap-2">
            <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Payouts') }}</h1>
            <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Prize money per player, sent to the Lightning address in their Nostr profile. Every payment has a fixed key: a second click never pays twice.') }}</p>
        </div>

        <section aria-labelledby="wallet-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="wallet-panel">
            <h2 id="wallet-h" class="m-0 text-[15px] font-bold">{{ __('League wallet (NWC)') }}</h2>
            <dl class="m-0 grid gap-x-6 gap-y-1 text-[13px] sm:grid-cols-[max-content_1fr]">
                <dt class="text-ink-2">{{ __('Paying connection') }}</dt>
                <dd class="m-0" data-test="wallet-paying">@if ($paying)<span class="text-win">{{ __('set up') }}</span>@else<span class="text-loss">{{ __('not set up (ESPORTS_NWC_URI): nothing can be paid out') }}</span>@endif</dd>
                <dt class="text-ink-2">{{ __('Receiving connection') }}</dt>
                <dd class="m-0" data-test="wallet-receiving">@if ($receiving)<span class="text-win">{{ __('set up') }}</span>@else<span class="text-loss">{{ __('not set up (ESPORTS_NWC_RECEIVE_URI): no zaps or sponsor invoices') }}</span>@endif</dd>
                <dt class="text-ink-2">{{ __('League key') }}</dt>
                <dd class="m-0">@if ($leagueKey)<span class="text-win">{{ __('set up') }}</span>@else<span class="text-loss">{{ __('not set up (ESPORTS_LEAGUE_NSEC)') }}</span>@endif</dd>
                <dt class="text-ink-2">{{ __('Last reconciliation') }}</dt>
                <dd class="m-0" data-test="wallet-reconciliation">
                    @if ($reconciliation === null)
                        {{ __('none yet (runs daily)') }}
                    @elseif ($reconciliation->error !== null)
                        <span class="text-loss">{{ __(':date: the wallet did not answer (:error)', ['date' => $reconciliation->created_at->format('Y-m-d H:i'), 'error' => $reconciliation->error]) }}</span>
                    @elseif ($reconciliation->agrees())
                        <span class="text-win">{{ __(':date: wallet and pots agree, :sats sats', ['date' => $reconciliation->created_at->format('Y-m-d H:i'), 'sats' => $sats($reconciliation->ledger_sats)]) }}</span>
                    @else
                        <span class="text-loss">{{ __(':date: the wallet holds :wallet sats, the pots :book sats', ['date' => $reconciliation->created_at->format('Y-m-d H:i'), 'wallet' => $sats((int) $reconciliation->wallet_sats), 'book' => $sats($reconciliation->ledger_sats)]) }}</span>
                    @endif
                </dd>
            </dl>
        </section>

        @if ($this->tournaments->isEmpty())
            <p class="m-0 text-[13px] text-ink-2" data-test="payouts-empty">{{ __('No tournament has a prize pool yet. Organizers open one on their tournament’s prize pool page.') }}</p>
        @else
            <label class="flex flex-col gap-1.5 text-xs text-ink-2 sm:max-w-[420px]">
                {{ __('Tournament') }}
                <select wire:model.live="tournamentId" class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="payouts-tournament">
                    @foreach ($this->tournaments as $option)
                        <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->status->label() }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($tournament)
            <section aria-labelledby="t-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="payouts-tournament-panel">
                <div class="flex flex-col gap-1">
                    <h2 id="t-h" class="m-0 text-[15px] font-bold"><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></h2>
                    <p class="m-0 text-[13px] text-ink-2">
                        @if ($ownWallet)
                            {{ __('Own wallet: :sats sats as of :time, :payable after the fee reserve', ['sats' => $sats((int) $tournament->pot_balance_sats), 'time' => $tournament->pot_balance_at?->format('Y-m-d H:i') ?? '–', 'payable' => $sats($pool)]) }} ·
                        @else
                            {{ __('Pool: :sats sats', ['sats' => $sats($pool)]) }} ·
                        @endif
                        {{ __('Split: :split', ['split' => implode(' / ', $tournament->prizeSplit())]) }}
                        @if ($tournament->payouts_approved_at) · {{ __('checked :date', ['date' => $tournament->payouts_approved_at->format('Y-m-d H:i')]) }}@endif
                    </p>
                </div>

                @error('payouts')<p class="m-0 text-[13px] text-loss" role="alert" data-test="payouts-error">{{ $message }}</p>@enderror
                @if ($notice !== '')<p class="m-0 text-[13px] text-win" role="status">{{ $notice }}</p>@endif

                @if ($tournament->payouts_approved_at === null)
                    @php($blocker = $approval->blocker($tournament))
                    <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('The check closes the pool (later payments go to the league reserve), publishes the tournament’s end on Nostr, reads the final places from the bracket and writes one payout per player. Nothing is paid yet.') }}</p>
                    @if ($blocker)
                        <p class="m-0 text-[13px] text-loss" data-test="payouts-blocker">{{ $blocker }}</p>
                    @else
                        @php($preview = app(PayoutPlan::class)->compute($tournament, $pool))
                        @if ($preview)
                            <ul class="m-0 flex list-none flex-col p-0 text-[13px]" data-test="payouts-preview">
                                @foreach ($preview['rows'] as $row)
                                    <li class="flex justify-between gap-3 border-t border-hairline py-1.5"><span>{{ $row['place'] }}. {{ $row['user']->displayName() }}</span><b>{{ $sats($row['amount']) }} {{ __('sats') }}</b></li>
                                @endforeach
                                <li class="flex justify-between gap-3 border-t border-hairline py-1.5 text-ink-2"><span>{{ $ownWallet ? __('Remainder, stays in the pot’s wallet') : __('Remainder to the league reserve') }}</span><span>{{ $sats($preview['remainder']) }} {{ __('sats') }}</span></li>
                            </ul>
                        @endif
                        <div><x-button wire:click="approve" wire:confirm="{{ __('Close the pool and write the payouts? This cannot be undone.') }}" data-test="approve-payouts">{{ __('Check and close the pool') }}</x-button></div>
                    @endif
                @else
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button icon="bolt" wire:click="payAll" wire:confirm="{{ __('Send :count payments, :sats sats in all?', ['count' => $pending->count(), 'sats' => $sats((int) $pending->sum('amount_sats'))]) }}" :disabled="! $payingHere || $pending->isEmpty()" class="disabled:cursor-default disabled:opacity-50" data-test="pay-all">{{ __('Pay all ready (:count)', ['count' => $pending->count()]) }}</x-button>
                        @unless ($payingHere)<span class="text-[13px] text-loss">{{ $ownWallet ? __('The connection of this pot’s own wallet is missing, so nothing can be paid out.') : __('No paying wallet connection is set up (ESPORTS_NWC_URI), so nothing can be paid out.') }}</span>@endunless
                    </div>

                    <div class="relative overflow-x-auto" @if ($payouts->contains(fn ($p) => $p->status === PayoutStatus::Paying)) wire:poll.5s @endif>
                        <table class="w-full min-w-[860px] border-collapse text-left text-[13px]">
                            <thead class="text-xs text-ink-3">
                                <tr>
                                    <th class="py-2 pr-3 font-normal">{{ __('Place') }}</th>
                                    <th class="py-2 pr-3 font-normal">{{ __('Player') }}</th>
                                    <th class="py-2 pr-3 font-normal">{{ __('Lightning address') }}</th>
                                    <th class="py-2 pr-3 text-right font-normal">{{ __('Amount') }}</th>
                                    <th class="py-2 pr-3 font-normal">{{ __('Status') }}</th>
                                    <th class="py-2 pr-3 font-normal">{{ __('Key') }}</th>
                                    <th class="py-2 font-normal"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($payouts as $payout)
                                    <tr class="border-t border-hairline align-top" wire:key="po-{{ $payout->id }}" data-test="admin-payout-row" data-status="{{ $payout->status->value }}">
                                        <td class="py-2.5 pr-3">{{ $payout->place }}.</td>
                                        <td class="py-2.5 pr-3">
                                            <b class="[overflow-wrap:anywhere]">{{ $payout->name }}</b>
                                            @if ($payout->participant && $payout->participant->name !== $payout->name)<span class="block text-xs text-ink-3">{{ $payout->participant->name }}</span>@endif
                                        </td>
                                        <td class="py-2.5 pr-3 text-xs [overflow-wrap:anywhere]">{{ $payout->lud16 ?? '—' }}</td>
                                        <td class="py-2.5 pr-3 text-right whitespace-nowrap">{{ $sats($payout->amount_sats) }} {{ __('sats') }}</td>
                                        <td class="py-2.5 pr-3">
                                            <span @class(['inline-flex h-6 items-center rounded-xs px-2 text-xs font-bold', 'bg-win-tint text-win' => $payout->status === PayoutStatus::Paid, 'bg-loss-tint text-loss' => $payout->status === PayoutStatus::Failed, 'bg-btc-chip text-btc-hi' => ! in_array($payout->status, [PayoutStatus::Paid, PayoutStatus::Failed], true)])>{{ $payout->status->label() }}</span>
                                            @if ($payout->reasonText())<span class="mt-1 block max-w-[280px] text-xs text-ink-2" data-test="payout-reason">{{ $payout->reasonText() }}</span>@endif
                                            @if ($payout->event)<span class="mt-1 block truncate font-mono text-[11px] text-proof" title="{{ $payout->event->event_id }}">2157 · {{ \Illuminate\Support\Str::limit($payout->event->event_id, 16, '…') }}</span>@endif
                                        </td>
                                        <td class="py-2.5 pr-3 font-mono text-[11px] text-ink-3" title="{{ $payout->idempotency_key }}">{{ substr($payout->idempotency_key, 0, 10) }}…</td>
                                        <td class="py-2.5 text-right whitespace-nowrap">
                                            @if ($payout->status === PayoutStatus::Open && ($newAddress = \App\Support\Payouts\PayoutRunner::currentAddress($payout)) !== null)
                                                <x-button variant="quiet" wire:click="approveAddress({{ $payout->id }}, '{{ $newAddress }}')" wire:confirm="{{ __('Pay this prize to :address from now on?', ['address' => $newAddress]) }}" data-test="approve-address">{{ __('Approve :address', ['address' => $newAddress]) }}</x-button>
                                            @endif
                                            @if ($payout->status->isPayable())
                                                <x-button variant="quiet" wire:click="pay({{ $payout->id }})" wire:confirm="{{ __('Send :sats sats to :address?', ['sats' => $sats($payout->amount_sats), 'address' => $payout->lud16 ?? $payout->name]) }}" :disabled="! $payingHere" data-test="pay-one">{{ $payout->status === PayoutStatus::Failed ? __('Retry') : __('Pay') }}</x-button>
                                            @elseif ($payout->status === PayoutStatus::Paying)
                                                <x-button variant="quiet" wire:click="check({{ $payout->id }})" :disabled="! $payingHere">{{ __('Check') }}</x-button>
                                                @if ($payout->reason === 'needs_check')
                                                    <x-button variant="secondary" wire:click="release({{ $payout->id }})" wire:confirm="{{ __('Only if the wallet shows no payment for this invoice. Release it?') }}">{{ __('Release') }}</x-button>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($payouts->isEmpty())
                        <p class="m-0 text-[13px] text-ink-2">{{ __('Nobody gets a payout: the pool was empty or no place was paid.') }}</p>
                    @endif
                @endif
            </section>
        @endif
    </div>
</div>
