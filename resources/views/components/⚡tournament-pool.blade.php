<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Lightning\ZapRefused;
use App\Support\Nostr\SignerMessages;
use App\Support\PreSeason;
use App\Support\Prizes\InvoiceCaps;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PotTopUps;
use App\Support\Prizes\PotZaps;
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
 * links, "Fill the pot", and once an admin approved the payouts, the
 * payout of every winner.
 *
 * "Fill the pot" (user, 2026-10-03: one card instead of two), one set of
 * amounts for both ways in:
 *
 * - "Zap with Nostr" (user, 2026-10-02): a NIP-57 zap to the tournament's
 *   event (App\Support\Prizes\PotZaps, signed in the browser by
 *   resources/js/zapWinner.js); with its receipt it goes on the sponsors'
 *   wall and on top of the pot. A guest logs in first.
 * - "Pay without Nostr": the league wallet makes a plain invoice for the
 *   amount picked, booked for this tournament's pot as announced
 *   (App\Support\Prizes\PotTopUps), shown as a QR code (never as text)
 *   with "open in wallet". The organizer's and the admin's top-up is the
 *   same. When the league wallet cannot make invoices, the card says top-ups
 *   are not enabled.
 *
 * Either invoice stays on the card until it settles or runs out (user,
 * 2026-10-03: „21 sats gezapped, aber es kommt keine automatische Meldung,
 * dass es ankam."): the card polls it every 3 seconds, asks the league wallet
 * about it at most every LOOKUP_SECONDS seconds (the settle path of
 * App\Support\Prizes\IncomingPayments, never without the wallet's answer),
 * then says thank you and has the page render its pot and sponsors' wall
 * again (`pot-filled`), or says it expired. It only ever shows an invoice its
 * viewer asked for (invoice()).
 */
new class extends Component {
    /** How often the card asks the league wallet about its invoice, at most (`wallet:sync` asks about every open one in the background). */
    public const LOOKUP_SECONDS = 5;

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

    /**
     * The card's invoice, only while it is its viewer's own: asked for by the
     * same account, or by a guest from the same network. Another viewer (a
     * replayed card) gets none, and nothing is looked up for them.
     */
    #[Computed]
    public function invoice(): ?IncomingPayment
    {
        if ($this->invoiceId === null) {
            return null;
        }

        $requester = InvoiceCaps::requester();

        return IncomingPayment::query()->where('tournament_id', $this->tournamentId)->whereKey($this->invoiceId)
            ->when($requester['requester_user_id'] !== null,
                fn ($query) => $query->where('requester_user_id', $requester['requester_user_id']),
                fn ($query) => $query->whereNull('requester_user_id')->where('requester_ip_hash', $requester['requester_ip_hash']))
            ->first();
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

    /**
     * @return array{template?: array<string, mixed>, error?: string}
     */
    public function prepareZap(int $tournamentId, int $sats, string $comment, PotZaps $zaps): array
    {
        try {
            return ['template' => $zaps->template($this->zapper(), $this->tournament, $sats, $comment)];
        } catch (ZapRefused $refused) {
            return ['error' => $refused->getMessage()];
        }
    }

    /**
     * @return array{invoice?: string, qr?: string, error?: string}
     */
    public function zapInvoice(int $tournamentId, int $sats, string $comment, string $signed, PotZaps $zaps): array
    {
        if (! $this->allowedToInvoice()) {
            return ['error' => __('Too many invoices at once. Please wait a minute.')];
        }

        try {
            $answer = $zaps->invoice($this->zapper(), $this->tournament, $sats, $comment, json_decode($signed, true));
            // The card shows the zap's invoice itself, like a top-up's, and notices when it is paid.
            $this->invoiceId = $answer['id'];
            unset($this->invoice);

            return ['invoice' => $answer['invoice'], 'qr' => $answer['qr']];
        } catch (ZapRefused $refused) {
            return ['error' => $refused->getMessage()];
        }
    }

    private function zapper(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function checkInvoice(PotTopUps $topUps): void
    {
        $invoice = $this->invoice;

        if ($invoice !== null && $invoice->status === IncomingPaymentStatus::Pending) {
            // At most every LOOKUP_SECONDS per invoice (its `checked_at`), however often the card or anyone calls this.
            $fresh = $topUps->check($invoice, self::LOOKUP_SECONDS);
            unset($this->invoice, $this->tournament);

            if ($fresh->status === IncomingPaymentStatus::Settled) {
                $this->dispatch('pot-filled');
            }

            // Still waiting and not run out: the card shows the same, so the 3 s poll answers without a render (P3).
            if ($fresh->status === IncomingPaymentStatus::Pending && ! $this->hasRunOut($fresh)) {
                $this->skipRender();
            }
        }
    }

    /**
     * Whether the invoice ran out: expired here, or past its expiry with the
     * wallet asked once since then and still unpaid (the wallet's answer
     * turns it `expired` only after a grace, App\Support\Prizes\IncomingPayments).
     */
    private function hasRunOut(IncomingPayment $invoice): bool
    {
        return $invoice->status === IncomingPaymentStatus::Expired
            || ($invoice->status === IncomingPaymentStatus::Pending && $invoice->expires_at->isPast()
                && $invoice->checked_at !== null && $invoice->checked_at->greaterThanOrEqualTo($invoice->expires_at));
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
    $hasPot = $tournament->pool_opened_at !== null && $tournament->hasPot();
    $topUps = PotTopUps::enabled($tournament);
    $zapOpen = rescue(fn () => PotZaps::open($tournament), false, false);
    $viewer = auth()->user();
    // A zap is signed with the viewer's own Nostr key: a guest logs in first, an account without a key pays without Nostr.
    $zapper = $viewer instanceof User && is_string($viewer->pubkey) && $viewer->pubkey !== '' ? $viewer->pubkey : null;
    $invoice = $this->invoice;
    $expired = $invoice !== null && $this->hasRunOut($invoice);
    $waiting = $invoice !== null && $invoice->status === IncomingPaymentStatus::Pending && ! $expired;
    $qr = null;

    if ($waiting) {
        try {
            $qr = QrCode::svg('lightning:'.$invoice->bolt11, label: __('Lightning invoice for :sats sats', ['sats' => $sats($invoice->amount_sats)]));
        } catch (InvalidArgumentException) {
            $qr = null;
        }
    }
@endphp

<section @if (! $hasPot) aria-labelledby="pool-panel-h" @else aria-label="{{ __('Fill the pot') }}" @endif class="flex flex-col gap-4 px-4 lg:px-12" data-test="pool-panel">
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
                        ? __('Kept in the league wallet for this tournament alone; the fixed prizes are paid once the pot covers them and :percent % for routing fees.', ['percent' => PrizePool::WALLET_FEE_PERCENT])
                        : __('Kept in the league wallet for this tournament alone; :percent % stays back for routing fees.', ['percent' => PrizePool::WALLET_FEE_PERCENT]) }} ·
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
        {{--
            Fill the pot (user, 2026-10-03: one card instead of "Zap the pot" and "Add to the pot"): one set of
            amounts for both ways in. "Zap with Nostr" first: on top of the pot and on the sponsors' wall. Beside it
            "Pay without Nostr": an invoice of the league wallet for the amount picked, part of the pot as announced,
            not on the wall; the organizer's and the admin's top-up as well. Never an address as text.
        --}}
        <div id="pot-fill" class="flex scroll-mt-24 flex-col gap-3 rounded-card bg-card p-4 sm:p-6" data-test="pot-fill"
             x-data="zapWinner({ pubkey: @js($zapper), amounts: @js(PotZaps::AMOUNTS), sats: @js(is_numeric($this->amount) ? (int) $this->amount : PotZaps::AMOUNTS[2]), messages: @js([...SignerMessages::labels(), 'failed' => __('That did not work. Please try again.'), 'changed' => __('The zap request changed. Check it again, then sign.')]) })"
             x-init="open = {{ $tournament->id }}">
            <h3 class="m-0 flex items-center gap-1.5 text-[13px] font-bold"><span class="flex text-bolt"><x-icon name="bolt" :size="16" /></span>{{ __('Fill the pot') }}</h3>

            @if (! $topUps)
                <p class="m-0 text-[13px] text-ink-2" data-test="topup-off">{{ __('Top-ups not enabled for this pot.') }}</p>
            @elseif ($invoice !== null)
                {{-- Either way in: polled until it settles or runs out. Leaving it resets the zap steps too. --}}
                <div class="flex flex-col gap-3" data-test="topup-invoice" @if ($waiting) wire:poll.3s="checkInvoice" @endif>
                    @if ($invoice->status === IncomingPaymentStatus::Settled)
                        <p class="m-0 rounded-md bg-win-tint px-3 py-2 text-[13px] text-win" role="status" data-test="topup-received">{{ __('✓ :sats sats arrived, thank you!', ['sats' => $sats($invoice->amount_sats)]) }}</p>
                        <div><x-button variant="quiet" x-on:click="reset()" wire:click="closeInvoice" data-test="topup-again">{{ __('Add more') }}</x-button></div>
                    @elseif ($expired)
                        <p class="m-0 text-[13px] text-loss" role="status" data-test="topup-expired">{{ __('Invoice expired — create a new one') }}</p>
                        <div><x-button variant="quiet" x-on:click="reset()" wire:click="closeInvoice">{{ __('New invoice') }}</x-button></div>
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
                            <x-button variant="secondary" x-on:click="reset()" wire:click="closeInvoice">{{ __('Cancel') }}</x-button>
                        </div>
                        <p class="m-0 text-xs text-ink-3">{{ __('Valid until :time.', ['time' => $invoice->expires_at->copy()->timezone(\App\Support\LeagueTime::zone())->format('H:i')]) }}</p>
                    @endif
                </div>
            @else
                <p class="m-0 max-w-[80ch] text-xs leading-normal text-ink-2" data-test="pot-fill-hint">
                    @if ($zapOpen)
                        <span class="block">{{ __('Zaps add on top, on the sponsors’ wall.') }}</span>
                    @endif
                    <span class="block">{{ __('Without Nostr: part of the pot, no wall.') }}</span>
                </p>
                <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                    <legend class="mb-1 text-xs text-ink-2">{{ __('Amount in sats') }}</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach (PotZaps::AMOUNTS as $amount)
                            <button type="button" x-on:click="pick({{ $amount }})" :aria-pressed="Number(sats) === {{ $amount }}" data-test="pot-fill-amount"
                                    :class="Number(sats) === {{ $amount }} ? 'border-btc bg-btc-chip font-bold text-btc-hi' : 'border-line bg-well text-ink'"
                                    class="inline-flex h-11 min-w-11 cursor-pointer items-center justify-center rounded-md border px-3 text-[13px] tabular-nums">{{ $sats($amount) }}</button>
                        @endforeach
                        <label class="flex h-11 items-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink-2">
                            <span class="sr-only">{{ __('Another amount in sats') }}</span>
                            <input type="number" min="1" max="{{ (int) config('esports.wallet.max_sats') }}" step="1" x-model.number="sats" x-on:input="step !== 'idle' && reset()"
                                   class="w-24 bg-transparent text-ink outline-none" data-test="topup-amount">
                            {{ __('sats') }}
                        </label>
                    </div>
                </fieldset>
                @if ($zapOpen && $zapper)
                    <label class="flex min-w-0 flex-col gap-1.5 sm:max-w-md">
                        <span class="text-xs text-ink-2">{{ __('Comment (optional, public in the zap)') }}</span>
                        <input type="text" x-model="comment" x-on:input="step !== 'idle' && reset()" maxlength="{{ PotZaps::MAX_COMMENT }}" data-test="pot-zap-comment"
                               class="h-11 w-full min-w-0 rounded-md border border-line bg-well px-3 text-[13px] text-ink">
                    </label>
                @endif
                <div class="grid gap-2 sm:flex sm:flex-wrap" x-show="step === 'idle' || step === 'preparing'">
                    @if ($zapOpen && $zapper)
                        <x-button icon="bolt" x-on:click="preview()" x-bind:disabled="step === 'preparing'" data-test="pot-zap-preview">
                            <span x-text="step === 'preparing' ? @js(__('Preparing…')) : @js(__('Zap with Nostr'))">{{ __('Zap with Nostr') }}</span>
                        </x-button>
                    @elseif ($zapOpen && $viewer === null)
                        <x-button icon="bolt" :href="route('login')" data-test="pot-zap-login">{{ __('Log in to zap') }}</x-button>
                    @endif
                    <x-button variant="secondary" icon="bolt-toast" x-on:click="$wire.amount = Number(sats); $wire.topUp()" wire:loading.attr="disabled" data-test="topup">{{ __('Pay without Nostr') }}</x-button>
                </div>
                @if ($zapOpen && $zapper)
                    <div x-show="step === 'preview' || step === 'signing'" x-cloak role="group" aria-label="{{ __('Preview of the zap request') }}" data-test="pot-zap-request"
                         class="flex min-w-0 flex-col gap-2 rounded-md border border-line px-3 py-3">
                        <p class="m-0 text-[13px] leading-normal" x-text="@js(__('A zap of :sats sats to the prize pot of :name, signed with your key (NIP-57, kind 9734).', ['name' => $tournament->name])).replace(':sats', Number(sats).toLocaleString())"></p>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <x-button variant="quiet" x-on:click="reset()" x-bind:disabled="step === 'signing'">{{ __('Cancel') }}</x-button>
                            <x-button icon="bolt" x-on:click="sign()" x-bind:disabled="step === 'signing'" class="whitespace-nowrap" data-test="pot-zap-sign-button">
                                <span x-text="step === 'signing' ? @js(__('Waiting for your signer…')) : @js(__('Sign and get invoice'))">{{ __('Sign and get invoice') }}</span>
                            </x-button>
                        </div>
                    </div>
                    <p role="alert" class="m-0 text-xs leading-normal text-loss" x-show="error" x-text="error" x-cloak data-test="pot-zap-error"></p>
                @endif
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
                                    <span @class(['inline-flex h-6 items-center rounded-xs px-2 text-xs font-bold', 'bg-win-tint text-win' => $payout->status === PayoutStatus::Paid, 'bg-raised text-ink-2' => $payout->status === PayoutStatus::Forwarded, 'bg-btc-chip text-btc-hi' => ! in_array($payout->status, [PayoutStatus::Paid, PayoutStatus::Forwarded], true)])>{{ $payout->status->label() }}</span>
                                    @if ($payout->status === PayoutStatus::Forwarded)
                                        <span class="mt-1 block text-xs text-ink-2" data-test="payout-forwarded">{{ __(':name passed the prize on: it goes into the next pot.', ['name' => $payout->name]) }}</span>
                                    @endif
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
