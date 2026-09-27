<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Support\PreSeason;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PotTopUps;
use App\Support\Prizes\PrizePool;
use App\Support\QrCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The working part of a tournament's prize pot on its page (P9): the pot,
 * the prizes and the paid sponsors are the page's own section
 * (partials/prize-pool, from App\Support\Prizes\WalletPrizePool); this
 * component adds the state of the pot, the organizer's and the admin's
 * links, "Add to the pot", and once an admin approved the payouts, the
 * payout of every winner.
 *
 * Adding to the pot: the tournament's own wallet makes a plain invoice
 * (App\Support\Prizes\PotTopUps), shown as a QR code (never as text) with
 * "open in wallet"; the panel checks every few seconds at that wallet
 * whether it was paid. No zap, no league wallet: the pot is the tournament's
 * own (user, 2026-09-27). When the pot's connection may not make invoices,
 * the panel says top-ups are not enabled.
 */
new class extends Component {
    #[Locked]
    public int $tournamentId;

    public int|string $amount = 21000;

    #[Locked]
    public ?int $invoiceId = null;

    public function mount(Tournament $tournament): void
    {
        $this->tournamentId = $tournament->id;
    }

    #[Computed]
    public function tournament(): Tournament
    {
        return Tournament::query()->findOrFail($this->tournamentId);
    }

    /**
     * @return Collection<int, TournamentPayout>
     */
    #[Computed]
    public function payouts(): Collection
    {
        return $this->tournament->payouts_approved_at === null ? collect() : $this->tournament->payouts()->with('event')->get();
    }

    #[Computed]
    public function invoice(): ?IncomingPayment
    {
        return $this->invoiceId === null ? null : IncomingPayment::query()->where('tournament_id', $this->tournamentId)->find($this->invoiceId);
    }

    public function topUp(PotTopUps $topUps): void
    {
        $this->resetErrorBag();

        if (! $this->allowedToInvoice()) {
            return;
        }

        try {
            $this->invoiceId = $topUps->invoice($this->tournament, is_numeric($this->amount) ? (int) $this->amount : 0)->id;
            unset($this->invoice);
        } catch (PoolRefusal $refusal) {
            $this->addError('topup', $refusal->getMessage());
        }
    }

    public function checkInvoice(PotTopUps $topUps): void
    {
        $invoice = $this->invoice;

        if ($invoice !== null && $invoice->status === IncomingPaymentStatus::Pending) {
            $topUps->check($invoice);
            unset($this->invoice, $this->tournament);
        }
    }

    public function closeInvoice(): void
    {
        $this->invoiceId = null;
        unset($this->invoice);
    }

    /**
     * Invoices per minute: `invoices_per_minute` per user, three times that
     * per network (an event's players share one address). Counted before the
     * invoice is made; the open invoices are capped in InvoiceCaps as well.
     */
    private function allowedToInvoice(): bool
    {
        $perMinute = (int) config('esports.wallet.invoices_per_minute', 10);
        $keys = array_filter([
            Auth::id() === null ? null : ['pool-invoice:user:'.Auth::id(), $perMinute],
            ['pool-invoice:ip:'.request()->ip(), $perMinute * 3],
        ]);

        foreach ($keys as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $this->addError('topup', __('Too many invoices at once. Please wait a minute.'));

                return false;
            }
        }

        foreach ($keys as [$key]) {
            RateLimiter::hit($key, 60);
        }

        return true;
    }
}; ?>

@php
    $tournament = $this->tournament;
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $teams = $tournament->profile()->entersTeams();
    $fixed = $tournament->prizeMode() === Tournament::PRIZES_FIXED;
    $canManage = auth()->check() && Gate::allows('manage-tournament', $tournament);
    $isAdmin = auth()->check() && Gate::allows('admin');
    $open = $tournament->isPoolOpen();
    $hasPot = $tournament->pool_opened_at !== null && $tournament->hasOwnWallet();
    $topUps = PotTopUps::enabled($tournament);
    $invoice = $this->invoice;
    $qr = null;

    if ($invoice !== null && $invoice->status === IncomingPaymentStatus::Pending) {
        try {
            $qr = QrCode::svg('lightning:'.$invoice->bolt11, label: __('Lightning invoice for :sats sats', ['sats' => $sats($invoice->amount_sats)]));
        } catch (InvalidArgumentException) {
            $qr = null;
        }
    }
@endphp

<section @if (! $hasPot) aria-labelledby="pool-panel-h" @else aria-label="{{ __('Add to the pot') }}" @endif class="flex flex-col gap-4 px-4 lg:px-12" data-test="pool-panel">
    @if (! $hasPot)
        <h2 id="pool-panel-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Prize pool') }}</h2>
    @endif
    <div class="flex flex-col gap-3 rounded-card bg-card p-4 sm:flex-row sm:items-start sm:justify-between sm:p-6">
        <div class="flex min-w-0 flex-col gap-1.5">
            @if (! $hasPot)
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="pool-none">{{ __('This tournament has no prize pool yet.') }}</p>
            @else
                <p class="m-0 text-xs text-ink-3" data-test="pool-state">
                    {{ $fixed
                        ? __('Held in the tournament’s own wallet, paid out from it; the fixed prizes are paid once it covers them and :percent % for routing fees.', ['percent' => PrizePool::WALLET_FEE_PERCENT])
                        : __('Held in the tournament’s own wallet, paid out from it; :percent % stays back for routing fees.', ['percent' => PrizePool::WALLET_FEE_PERCENT]) }} ·
                    {{ $open ? __('open to everyone until the tournament ends') : __('closed at the admin check, :date', ['date' => $tournament->pool_closed_at?->format('Y-m-d H:i')]) }}
                </p>
                <p class="m-0 max-w-[80ch] text-xs leading-normal text-ink-2">
                    {{ $teams
                        ? ($fixed ? __('A team’s share is split equally among its roster. Tied places share the sum of their amounts.') : __('A team’s share is split equally among its roster. Tied places share their percentages.'))
                        : ($fixed ? __('Tied places share the sum of their amounts.') : __('Tied places share their percentages.')) }}
                    {{ __('When the tournament has ended and an admin has checked it, each share goes to the player’s own Lightning address from their Nostr profile. No address yet? The share waits until they add one.') }}
                </p>
            @endif
        </div>
        @if ($canManage || $isAdmin)
            <div class="flex shrink-0 flex-wrap gap-2">
                @if ($canManage)
                    <x-button variant="quiet" :href="route('tournaments.pool', $tournament)" data-test="to-pool-settings">{{ __('Prize pool settings') }}</x-button>
                @endif
                @if ($isAdmin && $hasPot)
                    <x-button variant="secondary" :href="route('admin.payouts', ['tournament' => $tournament->id])" data-test="to-payouts">{{ __('Payouts') }}</x-button>
                @endif
            </div>
        @endif
    </div>

    @if ($hasPot && $open)
        <div id="pot-topup" class="flex scroll-mt-24 flex-col gap-3 rounded-card bg-card p-4 sm:p-6" data-test="topup-panel">
            <h3 class="m-0 flex items-center gap-1.5 text-[13px] font-bold"><span class="flex text-bolt"><x-icon name="bolt-toast" :size="16" /></span>{{ __('Add to the pot') }}</h3>

            @if (! $topUps)
                <p class="m-0 text-[13px] text-ink-2" data-test="topup-off">{{ __('Top-ups not enabled for this pot.') }}</p>
            @elseif ($invoice === null)
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Amount') }}">
                    @foreach ([2100, 21000, 210000] as $preset)
                        <button type="button" wire:click="$set('amount', {{ $preset }})" @class(['inline-flex h-11 cursor-pointer items-center rounded-md border px-3 text-[13px]', 'border-btc bg-btc-chip font-bold text-btc-hi' => (int) $this->amount === $preset, 'border-line bg-well text-ink' => (int) $this->amount !== $preset]) aria-pressed="{{ (int) $this->amount === $preset ? 'true' : 'false' }}">{{ $sats($preset) }}</button>
                    @endforeach
                    <label class="flex h-11 items-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink-2">
                        <span class="sr-only">{{ __('Amount in sats') }}</span>
                        <input type="number" min="1" max="{{ (int) config('esports.wallet.max_sats') }}" step="1" wire:model.live.debounce.400ms="amount" class="w-24 bg-transparent text-ink outline-none" data-test="topup-amount">
                        {{ __('sats') }}
                    </label>
                </div>
                <div><x-button icon="bolt" wire:click="topUp" wire:loading.attr="disabled" data-test="topup">{{ __('Create invoice') }}</x-button></div>
                <p class="m-0 text-xs leading-normal text-ink-3">{{ __('The tournament’s own wallet makes the invoice; the sats go straight into its pot.') }}</p>
            @else
                <div class="flex flex-col gap-3" data-test="topup-invoice" @if ($invoice->status === IncomingPaymentStatus::Pending) wire:poll.3s="checkInvoice" @endif>
                    @if ($invoice->status === IncomingPaymentStatus::Settled)
                        <p class="m-0 rounded-md bg-win-tint px-3 py-2 text-[13px] text-win" role="status" data-test="topup-received">{{ __('Received: :sats sats. Thank you!', ['sats' => $sats($invoice->amount_sats)]) }}</p>
                        <div><x-button variant="quiet" wire:click="closeInvoice" data-test="topup-again">{{ __('Add more') }}</x-button></div>
                    @elseif ($invoice->status === IncomingPaymentStatus::Expired)
                        <p class="m-0 text-[13px] text-loss" role="status">{{ __('This invoice expired unpaid.') }}</p>
                        <div><x-button variant="quiet" wire:click="closeInvoice">{{ __('New invoice') }}</x-button></div>
                    @else
                        <p class="m-0 text-[13px]">{{ __('Scan with any Lightning wallet to pay :sats sats. This page notices the payment by itself.', ['sats' => $sats($invoice->amount_sats)]) }}</p>
                        @if ($qr !== null)
                            <div class="w-full max-w-[280px] self-start rounded-md bg-white p-2" data-test="topup-qr">{!! $qr !!}</div>
                        @endif
                        <div class="flex flex-wrap gap-2" x-data="{ copied: false }">
                            <x-button :href="'lightning:'.$invoice->bolt11" icon="bolt">{{ __('Open in wallet') }}</x-button>
                            <x-button variant="quiet" icon="copy" data-invoice="{{ $invoice->bolt11 }}" x-on:click="navigator.clipboard?.writeText($el.dataset.invoice).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy invoice'))">{{ __('Copy invoice') }}</span>
                            </x-button>
                            <x-button variant="secondary" wire:click="closeInvoice">{{ __('Cancel') }}</x-button>
                        </div>
                        <p class="m-0 text-xs text-ink-3">{{ __('Valid until :time.', ['time' => $invoice->expires_at->copy()->timezone(\App\Support\LeagueTime::zone())->format('H:i')]) }}</p>
                    @endif
                </div>
            @endif

            @error('topup')<p class="m-0 text-[13px] text-loss" role="alert" data-test="topup-error">{{ $message }}</p>@enderror
        </div>
    @endif

    @if ($this->payouts->isNotEmpty())
        <div class="flex flex-col gap-2 rounded-card bg-card p-4 sm:p-6" data-test="pool-payouts">
            <h3 class="m-0 text-[13px] font-bold">{{ __('Payouts') }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] border-collapse text-left text-[13px]">
                    <thead class="text-xs text-ink-3">
                        <tr>
                            <th class="py-2 pr-3 font-normal">{{ __('Place') }}</th>
                            <th class="py-2 pr-3 font-normal">{{ __('Player') }}</th>
                            <th class="py-2 pr-3 text-right font-normal">{{ __('Amount') }}</th>
                            <th class="py-2 font-normal">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->payouts as $payout)
                            <tr class="border-t border-hairline align-top" wire:key="payout-{{ $payout->id }}" data-test="payout-row" data-status="{{ $payout->status->value }}">
                                <td class="py-2 pr-3">{{ $payout->place }}.</td>
                                <td class="py-2 pr-3 [overflow-wrap:anywhere]">{{ $payout->name }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $sats($payout->amount_sats) }} {{ __('sats') }}</td>
                                <td class="py-2">
                                    <span @class(['inline-flex h-6 items-center rounded-xs px-2 text-xs font-bold', 'bg-win-tint text-win' => $payout->status === PayoutStatus::Paid, 'bg-btc-chip text-btc-hi' => $payout->status !== PayoutStatus::Paid])>{{ $payout->status->label() }}</span>
                                    @if ($payout->status === PayoutStatus::Open && $payout->reasonText())
                                        <span class="mt-1 block text-xs text-ink-2">{{ $payout->reasonText() }}</span>
                                    @endif
                                    @if ($payout->event)
                                        <span class="mt-1 block truncate font-mono text-[11px] text-proof" title="{{ $payout->event->event_id }}">2157 · {{ \Illuminate\Support\Str::limit($payout->event->event_id, 16, '…') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
