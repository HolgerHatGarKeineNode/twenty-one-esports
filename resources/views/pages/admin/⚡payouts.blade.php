<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Jobs\PayTournamentPayout;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Support\PreSeason;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\PotBalances;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\WalletSetup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
 * Every pot is its tournament's own NWC wallet (user, 2026-09-27): the
 * panel shows that wallet's state (connected or not, never its URI) and its
 * last balance, with "Read balance now". The league wallet is the
 * Season-Chain's and is not shown here. Fail closed: without the pot's
 * connection nothing can be approved or paid. A balance short of the fixed
 * prizes is a warning next to them, not a block (user, 2026-09-27: the
 * admin is responsible); a payment the wallet cannot make fails and can be
 * retried.
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
        return Tournament::query()->whereNotNull('pool_opened_at')->where('pot_source', Tournament::POT_WALLET)->latest('id')->limit(100)->get();
    }

    #[Computed]
    public function tournament(): ?Tournament
    {
        return $this->tournamentId === null ? null : Tournament::query()->whereNotNull('pool_opened_at')->where('pot_source', Tournament::POT_WALLET)->find($this->tournamentId);
    }

    /**
     * @return Collection<int, TournamentPayout>
     */
    #[Computed]
    public function payouts(): Collection
    {
        return $this->tournament === null ? new Collection : $this->tournament->payouts()->with(['participant', 'event'])->get();
    }

    /** Read the pot's wallet balance now (once every 10 s per tournament). */
    public function readBalance(PotBalances $balances): void
    {
        Gate::authorize('admin');
        $this->notice = '';

        if ($this->tournament === null) {
            return;
        }

        $key = 'pot-read:'.$this->tournament->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->addError('payouts', __('Read a moment ago. Wait :seconds s and try again.', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        RateLimiter::hit($key, 10);

        if (! $balances->read($this->tournament)) {
            $this->addError('payouts', __('The wallet did not tell its balance (:code). The last known balance stays.', ['code' => (string) $this->tournament->pot_balance_error]));
        }

        unset($this->tournament);
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
    // Every pot is paid from its tournament's own wallet (user, 2026-09-27).
    $payingHere = $tournament !== null && WalletSetup::potCanPay($tournament);
    $leagueKey = LeagueKey::fromConfig() !== null;
    $approval = app(PayoutApproval::class);
    $fixedMode = $tournament?->prizeMode() === Tournament::PRIZES_FIXED;
    $pool = $tournament ? PrizePool::payable($tournament, (int) $tournament->pot_balance_sats) : null;
    $shortfall = $tournament ? PrizePool::shortfall($tournament, (int) $tournament->pot_balance_sats) : 0;
    // Fixed prizes are approved without a fresh read too; the page says when the last one failed.
    $unread = $fixedMode && ($tournament->pot_balance_error !== null || $tournament->pot_balance_at === null);
    $payouts = $this->payouts;
    $pending = $payouts->where('status', PayoutStatus::Pending);
@endphp

<x-admin.page active="payouts" :title="__('Payouts')" :lead="__('Prize money per player, sent to the Lightning address in their Nostr profile. Every payment has a fixed key: a second click never pays twice.')" data-test="admin-payouts">
    @unless ($leagueKey)
        <x-admin.flash tone="error" :message="__('The league key is not set up (ESPORTS_LEAGUE_NSEC), so no payout can be published.')" data-test="league-key-missing" />
    @endunless

    @if ($this->tournaments->isEmpty())
        <x-admin.panel>
        <x-admin.empty :text="__('No tournament has a prize pot yet. Organizers connect one on their tournament’s prize pool page.')" data-test="payouts-empty">
            <x-button variant="quiet" :href="route('admin.tournaments')">{{ __('All tournaments') }}</x-button>
        </x-admin.empty>
    </x-admin.panel>
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
                <p class="m-0 text-[13px] text-ink-2" data-test="pot-wallet-state">
                    {{ $payingHere ? __('The pot’s own wallet is connected.') : __('The connection of this pot’s own wallet is missing, so nothing can be paid out.') }}
                    {{ __('Balance: :sats sats as of :time', ['sats' => $sats((int) $tournament->pot_balance_sats), 'time' => $tournament->pot_balance_at?->copy()->timezone(\App\Support\LeagueTime::zone())->format('Y-m-d H:i') ?? '–']) }}
                    @if ($tournament->pot_balance_error) · <span class="text-loss">{{ __('the last read failed (:code)', ['code' => $tournament->pot_balance_error]) }}</span>@endif
                </p>
                <p class="m-0 text-[13px] text-ink-2">
                    @if ($fixedMode)
                        {{ __('Fixed prizes: :prizes sats; the pot needs :need sats with the fee reserve.', ['prizes' => implode(' / ', array_map($sats, $tournament->prizeFixed())), 'need' => $sats((int) PrizePool::requiredSats($tournament))]) }}
                    @else
                        {{ __('Split: :split', ['split' => implode(' / ', $tournament->prizeSplit())]) }} · {{ __(':payable sats to split after the fee reserve', ['payable' => $sats((int) $pool)]) }}
                    @endif
                    @if ($tournament->payouts_approved_at) · {{ __('checked :date', ['date' => $tournament->payouts_approved_at->format('Y-m-d H:i')]) }}@endif
                </p>
                @if ($tournament->payouts_approved_at === null)
                    <div><x-button variant="quiet" wire:click="readBalance" wire:loading.attr="disabled" data-test="payouts-read-balance">{{ __('Read balance now') }}</x-button></div>
                @endif
            </div>

            @error('payouts')<p class="m-0 text-[13px] text-loss" role="alert" data-test="payouts-error">{{ $message }}</p>@enderror
            @if ($notice !== '')<p class="m-0 text-[13px] text-win" role="status">{{ $notice }}</p>@endif

            @if ($tournament->payouts_approved_at === null)
                @php($blocker = $approval->blocker($tournament))
                <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('The check reads the pot’s wallet balance again, closes the pot (later payments stay in its wallet), publishes the tournament’s end on Nostr, reads the final places from the bracket and writes one payout per player. Nothing is paid yet.') }}</p>
                @if ($blocker)
                    <p class="m-0 text-[13px] text-loss" data-test="payouts-blocker">{{ $blocker }}</p>
                @else
                    @php($preview = $pool === null ? null : app(PayoutPlan::class)->compute($tournament, $pool))
                    @if ($unread)
                        <p class="m-0 flex max-w-[80ch] items-start gap-2 rounded-md bg-loss-tint px-3 py-2 text-[13px] leading-normal text-loss" role="alert" data-test="payouts-unread">
                            <x-icon name="warn" :size="16" class="mt-0.5 shrink-0" />
                            <span>{{ __('Balance could not be read; you can still approve, the admin is responsible.') }}</span>
                        </p>
                    @endif
                    @if ($shortfall > 0)
                        <p class="m-0 flex max-w-[80ch] items-start gap-2 rounded-md bg-loss-tint px-3 py-2 text-[13px] leading-normal text-loss" role="alert" data-test="payouts-underfunded">
                            <x-icon name="warn" :size="16" class="mt-0.5 shrink-0" />
                            <span>{{ __('Warning: the pot holds :have sats, :missing sats less than the fixed prizes need with the fee reserve (:need sats). You can still approve; a payment the wallet cannot make fails and can be retried after a top-up.', ['have' => $sats((int) $tournament->pot_balance_sats), 'missing' => $sats($shortfall), 'need' => $sats((int) PrizePool::requiredSats($tournament))]) }}</span>
                        </p>
                    @endif
                    @if ($preview)
                        <ul class="m-0 flex list-none flex-col p-0 text-[13px]" data-test="payouts-preview">
                            @foreach ($preview['rows'] as $row)
                                <li class="flex justify-between gap-3 border-t border-hairline py-1.5"><span>{{ $row['place'] }}. {{ $row['user']->displayName() }}</span><b>{{ $sats($row['amount']) }} {{ __('sats') }}</b></li>
                            @endforeach
                            <li class="flex justify-between gap-3 border-t border-hairline py-1.5 text-ink-2"><span>{{ __('Remainder, stays in the pot’s wallet') }}</span><span>{{ $sats($preview['remainder']) }} {{ __('sats') }}</span></li>
                        </ul>
                    @endif
                    <div><x-button wire:click="approve" wire:confirm="{{ __('Close the pool and write the payouts? This cannot be undone.') }}" data-test="approve-payouts">{{ __('Check and close the pool') }}</x-button></div>
                @endif
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <x-button icon="bolt" wire:click="payAll" wire:confirm="{{ __('Send :count payments, :sats sats in all?', ['count' => $pending->count(), 'sats' => $sats((int) $pending->sum('amount_sats'))]) }}" :disabled="! $payingHere || $pending->isEmpty()" class="disabled:cursor-default disabled:opacity-50" data-test="pay-all">{{ __('Pay all ready (:count)', ['count' => $pending->count()]) }}</x-button>
                    @unless ($payingHere)<span class="text-[13px] text-loss">{{ __('The connection of this pot’s own wallet is missing, so nothing can be paid out.') }}</span>@endunless
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
</x-admin.page>
