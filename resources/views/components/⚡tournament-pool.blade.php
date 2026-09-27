<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\PreSeason;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PrizePool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The working part of a tournament's prize pool on its page (P9): the pot,
 * the split and the paid sponsors are the page's own section
 * (partials/prize-pool, from App\Support\Prizes\LeaguePrizePool); this
 * component adds the target and state of the pool, the organizer's and the
 * admin's links, the zap panel, and once an admin approved the payouts,
 * the payout of every winner.
 *
 * Zapping: "Zap with my key" signs the zap request (NIP-57 9734) with the
 * player's own signer (nostrAction, the request travels as a JSON string);
 * "Pay without Nostr" lets the league sign it with a throwaway key. Either
 * way the league's wallet makes the invoice; the panel shows it and checks
 * every few seconds whether it was paid.
 */
new class extends Component {
    #[Locked]
    public int $tournamentId;

    public int|string $amount = 21000;

    public string $comment = '';

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

    #[Computed]
    public function funded(): int
    {
        return (int) app(PrizePool::class)->potSats($this->tournament);
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
        return $this->invoiceId === null ? null : IncomingPayment::query()->find($this->invoiceId);
    }

    /**
     * The unsigned zap request for the player's signer.
     *
     * @return list<array<string, mixed>>|null
     */
    public function prepareZap(PoolInvoices $invoices): ?array
    {
        $this->resetErrorBag();

        // Only a template: the invoice (and its limit) comes with submitZap().
        if (! Auth::check()) {
            return null;
        }

        try {
            return [$invoices->zapTemplate($this->tournament, $this->sats(), $this->comment)];
        } catch (PoolRefusal $refusal) {
            $this->addError('zap', $refusal->getMessage());

            return null;
        }
    }

    public function submitZap(string $signed, PoolInvoices $invoices): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $request = SignedEvent::fromInput(json_decode($signed, true)[0] ?? null);

        if ($request === null || $request->pubkey !== $user->pubkey) {
            $this->addError('zap', __('This signer holds a different key than the one you logged in with.'));

            return;
        }

        // The signed path makes an invoice too: the same limit as every other (security gate F2).
        if (! $this->allowedToInvoice()) {
            return;
        }

        $this->invoiceWith(fn (): IncomingPayment => $invoices->forZapRequest($request, $request->toJson(), $this->sats()));
    }

    public function zapAnonymously(PoolInvoices $invoices): void
    {
        $this->resetErrorBag();

        if ($this->allowedToInvoice()) {
            $this->invoiceWith(fn (): IncomingPayment => $invoices->anonymousZap($this->tournament, $this->sats(), $this->comment));
        }
    }

    public function checkInvoice(IncomingPayments $payments): void
    {
        $invoice = $this->invoice;

        if ($invoice !== null && $invoice->status === IncomingPaymentStatus::Pending) {
            $payments->check($invoice);
            unset($this->invoice, $this->funded);
        }
    }

    public function closeInvoice(): void
    {
        $this->invoiceId = null;
        unset($this->invoice);
    }

    private function sats(): int
    {
        return is_numeric($this->amount) ? (int) $this->amount : 0;
    }

    /**
     * Invoices per minute: `invoices_per_minute` per user, three times that
     * per network (an event's players share one address). Counted before the
     * invoice is made; the open invoices are capped in PoolInvoices as well.
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
                $this->addError('zap', __('Too many invoices at once. Please wait a minute.'));

                return false;
            }
        }

        foreach ($keys as [$key]) {
            RateLimiter::hit($key, 60);
        }

        return true;
    }

    /**
     * @param  Closure(): IncomingPayment  $make
     */
    private function invoiceWith(Closure $make): void
    {
        try {
            $this->invoiceId = $make()->id;
            unset($this->invoice);
        } catch (PoolRefusal $refusal) {
            $this->addError('zap', $refusal->getMessage());
        }
    }
}; ?>

@php
    $tournament = $this->tournament;
    $pool = app(PrizePool::class);
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $teams = $tournament->profile()->entersTeams();
    $canManage = auth()->check() && Gate::allows('manage-tournament', $tournament);
    $isAdmin = auth()->check() && Gate::allows('admin');
    $open = $tournament->isPoolOpen();
    $ownWallet = $tournament->hasOwnWallet();
    $target = $tournament->prize_target_sats;
    $invoice = $this->invoice;
@endphp

<section @if ($tournament->pool_opened_at === null) aria-labelledby="pool-panel-h" @else aria-label="{{ __('Zap the pool') }}" @endif class="flex flex-col gap-4 px-4 lg:px-12" data-test="pool-panel">
    @if ($tournament->pool_opened_at === null)
        <h2 id="pool-panel-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Prize pool') }}</h2>
    @endif
    <div class="flex flex-col gap-3 rounded-card bg-card p-4 sm:flex-row sm:items-start sm:justify-between sm:p-6">
        <div class="flex min-w-0 flex-col gap-1.5">
            @if ($tournament->pool_opened_at === null)
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="pool-none">{{ __('This tournament has no prize pool yet.') }}</p>
            @else
                {{-- The pot, its target and progress are in the pool section above (partials/prize-pool). --}}
                <p class="m-0 text-xs text-ink-3" data-test="pool-state">
                    @if ($ownWallet)
                        {{ __('Held in the tournament’s own wallet, paid out from it; :percent % stays back for routing fees.', ['percent' => PrizePool::WALLET_FEE_PERCENT]) }} ·
                    @else
                        {{ trans_choice(':count payment|:count payments', $pool->contributions($tournament)) }} ·
                    @endif
                    {{ $open ? __('open to everyone until the tournament ends') : __('closed at the admin check, :date', ['date' => $tournament->pool_closed_at?->format('Y-m-d H:i')]) }}
                </p>
                <p class="m-0 max-w-[80ch] text-xs leading-normal text-ink-2">
                    {{ $teams ? __('A team’s share is split equally among its roster. Tied places share their percentages.') : __('Tied places share their percentages.') }}
                    {{ __('When the tournament has ended and an admin has checked it, each share goes to the player’s own Lightning address from their Nostr profile. No address yet? The share waits until they add one.') }}
                </p>
            @endif
        </div>
        @if ($canManage || $isAdmin)
            <div class="flex shrink-0 flex-wrap gap-2">
                @if ($canManage)
                    <x-button variant="quiet" :href="route('tournaments.pool', $tournament)" data-test="to-pool-settings">{{ __('Prize pool settings') }}</x-button>
                @endif
                @if ($isAdmin && $tournament->pool_opened_at !== null)
                    <x-button variant="secondary" :href="route('admin.payouts', ['tournament' => $tournament->id])" data-test="to-payouts">{{ __('Payouts') }}</x-button>
                @endif
            </div>
        @endif
    </div>

    @if ($open && ! $ownWallet)
        <div class="flex flex-col gap-3 rounded-card bg-card p-4 sm:p-6" data-test="zap-panel"
             x-data="nostrAction({ pubkey: @js(auth()->user()?->pubkey), messages: @js(SignerMessages::labels()) })">
            <h3 class="m-0 flex items-center gap-1.5 text-[13px] font-bold"><span class="flex text-bolt"><x-icon name="bolt-toast" :size="16" /></span>{{ __('Zap the pool') }}</h3>

            @if ($invoice === null)
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Amount') }}">
                    @foreach ([2100, 21000, 210000] as $preset)
                        <button type="button" wire:click="$set('amount', {{ $preset }})" @class(['inline-flex h-11 cursor-pointer items-center rounded-md border px-3 text-[13px]', 'border-btc bg-btc-chip font-bold text-btc-hi' => (int) $amount === $preset, 'border-line bg-well text-ink' => (int) $amount !== $preset]) aria-pressed="{{ (int) $amount === $preset ? 'true' : 'false' }}">{{ $sats($preset) }}</button>
                    @endforeach
                    <label class="flex h-11 items-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink-2">
                        <span class="sr-only">{{ __('Amount in sats') }}</span>
                        <input type="number" min="1" max="{{ (int) config('esports.wallet.max_sats') }}" step="1" wire:model.live.debounce.400ms="amount" class="w-24 bg-transparent text-ink outline-none" data-test="zap-amount">
                        {{ __('sats') }}
                    </label>
                </div>
                <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                    {{ __('Comment (optional, public)') }}
                    <input type="text" maxlength="140" wire:model="comment" class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="zap-comment">
                </label>
                <div class="flex flex-wrap gap-2">
                    @auth
                        <x-button icon="bolt" x-on:click="run('prepareZap', 'submitZap')" x-bind:disabled="busy" data-test="zap-signed">{{ __('Zap with my key') }}</x-button>
                    @endauth
                    <x-button :variant="auth()->check() ? 'quiet' : 'primary'" wire:click="zapAnonymously" wire:loading.attr="disabled" data-test="zap-anonymous">{{ __('Pay without Nostr') }}</x-button>
                </div>
                <p class="m-0 text-xs leading-normal text-ink-3">{{ __('The league’s wallet makes the invoice. Every payment gets a public zap receipt that names this tournament, so anyone can recount the pool.') }}</p>
            @else
                <div class="flex flex-col gap-3" data-test="zap-invoice" @if ($invoice->status === IncomingPaymentStatus::Pending) wire:poll.3s="checkInvoice" @endif>
                    @if ($invoice->status === IncomingPaymentStatus::Settled)
                        <p class="m-0 rounded-md bg-win-tint px-3 py-2 text-[13px] text-win" role="status" data-test="zap-received">{{ __('Received: :sats sats. Thank you!', ['sats' => $sats($invoice->amount_sats)]) }}</p>
                        <div><x-button variant="quiet" wire:click="closeInvoice" data-test="zap-again">{{ __('Zap again') }}</x-button></div>
                    @elseif ($invoice->status === IncomingPaymentStatus::Expired)
                        <p class="m-0 text-[13px] text-loss" role="status">{{ __('This invoice expired unpaid.') }}</p>
                        <div><x-button variant="quiet" wire:click="closeInvoice">{{ __('New invoice') }}</x-button></div>
                    @else
                        <p class="m-0 text-[13px]">{{ __('Pay :sats sats with any Lightning wallet. This page notices the payment by itself.', ['sats' => $sats($invoice->amount_sats)]) }}</p>
                        <div x-data="{ copied: false }" class="flex flex-col gap-2">
                            <code class="block max-h-24 overflow-y-auto rounded-md bg-well px-3 py-2 font-mono text-[11px] break-all text-ink-2 shadow-ring" data-test="zap-bolt11">{{ $invoice->bolt11 }}</code>
                            <div class="flex flex-wrap gap-2">
                                <x-button :href="'lightning:'.$invoice->bolt11" icon="bolt">{{ __('Open in wallet') }}</x-button>
                                <x-button variant="quiet" icon="copy" data-invoice="{{ $invoice->bolt11 }}" x-on:click="navigator.clipboard?.writeText($el.dataset.invoice).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                                    <span x-text="copied ? @js(__('Copied')) : @js(__('Copy invoice'))">{{ __('Copy invoice') }}</span>
                                </x-button>
                                <x-button variant="secondary" wire:click="closeInvoice">{{ __('Cancel') }}</x-button>
                            </div>
                        </div>
                        <p class="m-0 text-xs text-ink-3">{{ __('Valid until :time.', ['time' => $invoice->expires_at->copy()->timezone(\App\Support\LeagueTime::zone())->format('H:i')]) }}</p>
                    @endif
                </div>
            @endif

            <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>
            @error('zap')<p class="m-0 text-[13px] text-loss" role="alert" data-test="zap-error">{{ $message }}</p>@enderror
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
