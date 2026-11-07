<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Jobs\PayTournamentPayout;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use Illuminate\Support\Facades\DB;
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
 * Every pot is booked in the league wallet (user, 2026-10-02): the panel
 * shows the pot as set, apart from what sponsors paid outside the wallet
 * (never in it, never paid out from it). Fail closed: without the league
 * wallet nothing is approved or paid. The wallet's balance is never read
 * (user, 2026-10-05): a wallet short of sats refuses the payment, which
 * stays retryable (PayoutRunner). A pot approved before the league wallet
 * took over still pays from its own wallet.
 */
new #[Title('Payouts')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    #[Url(as: 'tournament')]
    public ?int $tournamentId = null;

    public string $notice = '';

    /** A payment was started here: the panel polls until nothing is paying any more. */
    public bool $watching = false;

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
        return Tournament::query()->whereNotNull('pool_opened_at')->whereIn('pot_source', [Tournament::POT_LEAGUE, Tournament::POT_WALLET])->latest('id')->limit(100)->get();
    }

    #[Computed]
    public function tournament(): ?Tournament
    {
        return $this->tournamentId === null ? null : Tournament::query()->whereNotNull('pool_opened_at')->whereIn('pot_source', [Tournament::POT_LEAGUE, Tournament::POT_WALLET])->find($this->tournamentId);
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
            // Feedback at once (user, 2026-10-04: "es passiert nichts … da braucht es Status anzeigen"): the payment runs
            // in the queue, the list refreshes itself until it is settled.
            $this->notice = __('Payment of :sats sats to :name started. The status updates here by itself.', ['sats' => PreSeason::formatSats($payout->amount_sats), 'name' => $payout->name]);
            $this->watching = true;
        }

        unset($this->payouts);
    }

    /**
     * The player passes the prize on to the reserve for the next pot (user, 2026-10-04: a director who played for fun);
     * it stays in the split, is never paid, and the tournament page says so.
     */
    public function forward(int $payoutId): void
    {
        Gate::authorize('admin');

        $forwarded = DB::transaction(function () use ($payoutId): ?TournamentPayout {
            $payout = TournamentPayout::query()->where('tournament_id', $this->tournamentId)->lockForUpdate()->findOrFail($payoutId);

            if (! in_array($payout->status, [PayoutStatus::Open, PayoutStatus::Pending, PayoutStatus::Failed], true)) {
                return null;
            }

            $payout->forceFill(['status' => PayoutStatus::Forwarded, 'reason' => null])->save();
            app(\App\Support\Wallet\Ledger::class)->payoutForwarded($payout);

            return $payout;
        });

        $this->notice = $forwarded === null ? __('This prize can no longer be passed on.') : __(':name passed :sats sats on to the next pot.', ['name' => $forwarded->name, 'sats' => PreSeason::formatSats($forwarded->amount_sats)]);
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

    /**
     * Reminds a winner without a Lightning address to add one (user 2026-10-25): bell and DM, NIP-17 to a DM inbox,
     * else NIP-04 (DmRoute), at most once an hour per prize.
     */
    public function remindAddress(int $payoutId, \App\Support\Notifications\Notifier $notifier): void
    {
        Gate::authorize('admin');
        $payout = $this->payout($payoutId);
        $user = $payout->user;

        if ($user === null || $payout->status !== PayoutStatus::Open || ! \Illuminate\Support\Facades\Cache::add('payout:remind:'.$payout->id, true, 3600)) {
            $this->notice = __('This winner was reminded within the last hour.');

            return;
        }

        $locale = $user->locale ?? (string) config('app.locale');
        $channels = $notifier->send($user, \App\Enums\NotificationKind::TournamentNews, new \App\Support\Notifications\Notice(
            __('Your prize from :tournament is waiting', ['tournament' => $payout->tournament->name], $locale),
            __('Add a Lightning address to your profile so the league can pay you :sats sats. It takes two minutes, and the page shows free wallets that give you one.', ['sats' => PreSeason::formatSats($payout->amount_sats)], $locale),
            route('settings.profile'),
            null,
            __('Add my Lightning address', [], $locale),
        ), immediate: true);

        $this->notice = in_array('dm', $channels, true) ? __(':name was reminded by DM and in the bell.', ['name' => $payout->name]) : __(':name was reminded in the bell (no DM: switched off by the player).', ['name' => $payout->name]);
    }

    /** Reads the open winners' profiles from the relays now, so an address added since shows at once (user 2026-10-25). */
    public function refreshProfiles(\App\Support\Nostr\RelayReader $reader): void
    {
        Gate::authorize('admin');
        $pubkeys = $this->payouts->filter(fn (TournamentPayout $payout): bool => $payout->status === PayoutStatus::Open)->pluck('pubkey')->filter()->unique()->values()->all();

        if ($pubkeys === []) {
            $this->notice = __('No open prize waits for a profile.');

            return;
        }

        $relays = array_values(array_unique(array_filter([...(array) config('esports.profile_relays', []), ...(array) config('esports.stream_chat.indexer_relays', []), ...(array) config('esports.relays', [])], 'is_string')));
        $events = $reader->fetchCounted([['kinds' => [0], 'authors' => $pubkeys, 'limit' => count($pubkeys) * 2]], $relays, [], 1, microtime(true) + 10)['events'];
        $updated = \App\Support\Nostr\ProfileCache::acceptEvents($events);

        $this->notice = trans_choice(':count profile read from the relays, :changed changed.|:count profiles read from the relays, :changed changed.', count($events), ['changed' => $updated->count()]);
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
    // Every pot is paid from the league wallet (user, 2026-10-02); a legacy pot approved before from its own.
    $payingHere = $tournament !== null && WalletSetup::potCanPay($tournament);
    $leagueKey = LeagueKey::fromConfig() !== null;
    $approval = app(PayoutApproval::class);
    $prizePool = app(PrizePool::class);
    $fixedMode = $tournament?->prizeMode() === Tournament::PRIZES_FIXED;
    $funded = $tournament ? $prizePool->fundedSats($tournament) : 0;
    $zaps = $tournament ? $prizePool->onTopSats($tournament) : 0;
    $pool = $tournament ? $prizePool->payoutPotSats($tournament) : null;
    $payouts = $this->payouts;
    $pending = $payouts->where('status', PayoutStatus::Pending);
@endphp

{{--
    AdminPayouts.dc.html: the readout (nothing waiting, or how many wait, and the last prize paid), then the
    chosen tournament as one featured card: its cover, name and pot as a display numeral, the closing state
    on the right, the places as a table. Addresses are never shown, only whether one is checked; the one
    exception is the button that approves a changed address (rule: no Lightning address on screens).
--}}
@php
    $waitingAll = \App\Models\TournamentPayout::query()->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Failed])->count();
    $lastPaid = \App\Models\TournamentPayout::query()->where('status', PayoutStatus::Paid)->latest('updated_at')->value('amount_sats');
@endphp
<x-admin.page active="payouts" :title="__('Payouts')" :lead="__('Prize money per player, sent to the Lightning address in their Nostr profile. Every payment has a fixed key: a second click never pays twice.')" data-test="admin-payouts">
    <x-slot:readout>
        @if ($waitingAll === 0)
            <span class="flex items-center gap-2"><span class="rv-dot" aria-hidden="true"></span><span class="text-xs text-ink-2">{{ __('No payout waiting') }}</span></span>
        @else
            <span class="flex items-center gap-2"><span class="rv-dot is-p" style="--c: var(--color-btc-hi)" aria-hidden="true"></span><span class="text-xs text-ink-2">{{ trans_choice(':count payout waiting|:count payouts waiting', $waitingAll) }}</span></span>
        @endif
        @if ($lastPaid !== null)
            <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('last paid') }}</span><span class="rv-num text-base text-btc" data-test="readout-value">{{ $sats((int) $lastPaid) }} {{ __('Sats') }}</span></span>
        @endif
    </x-slot:readout>
    @unless ($leagueKey)
        <x-admin.flash tone="error" :message="__('The league key is not set up (ESPORTS_LEAGUE_NSEC), so no payout can be published.')" data-test="league-key-missing" />
    @endunless

    @if ($this->tournaments->isEmpty())
        <x-admin.panel>
        <x-admin.empty :text="__('No tournament has a prize pot yet. Organizers switch one on on their tournament’s prize pool page.')" data-test="payouts-empty">
            <x-button variant="quiet" :href="route('admin.tournaments')">{{ __('All tournaments') }}</x-button>
        </x-admin.empty>
    </x-admin.panel>
    @else
        <div class="flex flex-col sm:max-w-[420px]">
            <span class="rv-lb" id="payouts-tournament-l">{{ __('Tournament') }}</span>
            <x-ui.select-menu model="tournamentId" live labelledby="payouts-tournament-l" :value="$this->tournamentId" data-test="payouts-tournament"
                              :options="$this->tournaments->mapWithKeys(fn ($option): array => [$option->id => $option->name.', '.$option->status->label()])->all()" />
        </div>
    @endif

    @if ($tournament)
        <section aria-labelledby="t-h" class="rv-card rv-glow flex flex-col gap-4 p-4 lg:p-6" style="box-shadow: inset 4px 0 0 var(--color-btc), 0 0 0 1px var(--color-btc), 0 0 28px rgb(247 147 26 / 0.25)" data-test="payouts-tournament-panel"
                 @if ($this->watching || $this->payouts->contains(fn ($payout) => $payout->status === PayoutStatus::Paying)) wire:poll.3s @endif>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <x-game-cover :game="$tournament->game" size="thumb" class="w-24 shrink-0 rounded-xs" />
                <div class="flex min-w-0 grow flex-col gap-1">
                    <h2 id="t-h" class="m-0 flex items-center gap-2 text-[14px] font-bold"><x-icon name="trophy" :size="16" class="shrink-0 text-bolt" /><a href="{{ route('tournaments.show', $tournament) }}" class="min-w-0 [overflow-wrap:anywhere]">{{ $tournament->name }}</a></h2>
                    @if ($pool !== null)
                        <span class="rv-num text-[26px] text-btc" data-test="payouts-pot">{{ $sats((int) $pool) }} {{ __('Sats') }}</span>
                    @endif
                    <p class="m-0 rv-m" data-test="pot-wallet-state">
                        @if ($tournament->hasOwnWallet())
                            {{ $payingHere ? __('Approved before the league wallet took over: paid from the pot’s own wallet.') : __('The connection of this pot’s own wallet is missing, so nothing can be paid out.') }}
                        @else
                            {{ $payingHere ? __('Paid from the league wallet.') : __('The league wallet is not connected, so nothing can be paid out.') }}
                            <span data-test="pot-ledger">{{ __('The pot as set: :pot sats, paid out by share. If the league wallet holds less, top it up.', ['pot' => $sats((int) $pool)]) }}</span>
                        @endif
                    </p>
                    <p class="m-0 rv-m">
                        @if ($fixedMode)
                            {{ __('Fixed prizes: :prizes sats', ['prizes' => implode(' / ', array_map($sats, $tournament->prizeFixed()))]) }}
                        @else
                            {{ __('Split: :split', ['split' => implode(' / ', $tournament->prizeSplit())]) }}, {{ __(':payable sats to split', ['payable' => $sats((int) $pool)]) }}
                        @endif
                    </p>
                </div>
                @if ($tournament->payouts_approved_at)
                    <span class="rv-tag is-ok shrink-0 self-start sm:self-center"><x-icon name="check" :size="12" />{{ __('checked :date', ['date' => \App\Support\Formats::date($tournament->payouts_approved_at)]) }}</span>
                @endif
            </div>

            @error('payouts')<p class="m-0 text-[13px] text-loss" role="alert" data-test="payouts-error">{{ $message }}</p>@enderror
            @if ($notice !== '')<p class="m-0 text-[13px] text-win" role="status">{{ $notice }}</p>@endif

            @if ($tournament->payouts_approved_at === null)
                @php($blocker = $approval->blocker($tournament))
                <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('The check closes the pot (later payments go to the league reserve), publishes the tournament’s end on Nostr, reads the final places from the bracket and writes one payout per player. Nothing is paid yet.') }}</p>
                @if ($blocker)
                    <p class="m-0 text-[13px] text-loss" data-test="payouts-blocker">{{ $blocker }}</p>
                @else
                    @php($preview = $pool === null ? null : app(PayoutPlan::class)->compute($tournament, $pool, $zaps))
                    @if ($preview)
                        <ul class="m-0 flex list-none flex-col p-0 text-[13px]" data-test="payouts-preview">
                            @foreach ($preview['rows'] as $row)
                                <li class="flex justify-between gap-3 border-t border-hairline py-2"><span><span class="rv-num text-btc">{{ $row['place'] }}.</span> {{ $row['user']->displayName() }}</span><b class="tabular-nums">{{ $sats($row['amount']) }} {{ __('sats') }}</b></li>
                            @endforeach
                            <li class="flex justify-between gap-3 border-t border-hairline py-1.5 text-ink-2"><span>{{ __('Remainder, stays in the pot') }}</span><span>{{ $sats($preview['remainder']) }} {{ __('sats') }}</span></li>
                        </ul>
                    @endif
                    <div><x-button wire:click="approve" wire:confirm="{{ __('Close the pool and write the payouts? This cannot be undone.') }}" data-test="approve-payouts">{{ __('Check and close the pool') }}</x-button></div>
                @endif
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <x-button icon="bolt" wire:click="payAll" wire:confirm="{{ __('Send :count payments, :sats sats in all?', ['count' => $pending->count(), 'sats' => $sats((int) $pending->sum('amount_sats'))]) }}" :disabled="! $payingHere || $pending->isEmpty()" class="disabled:cursor-default disabled:opacity-50" data-test="pay-all">{{ __('Pay all ready (:count)', ['count' => $pending->count()]) }}</x-button>
                    @if ($payouts->contains(fn ($p) => $p->status === PayoutStatus::Open))
                        <x-button variant="secondary" icon="retry" wire:click="refreshProfiles" wire:loading.attr="disabled" wire:target="refreshProfiles" data-test="refresh-profiles">
                            <span wire:loading.remove wire:target="refreshProfiles">{{ __('Reload profiles') }}</span>
                            <span wire:loading wire:target="refreshProfiles">{{ __('Reading the relays…') }}</span>
                        </x-button>
                    @endif
                    @unless ($payingHere)<span class="text-[13px] text-loss">{{ __('The connection of this pot’s own wallet is missing, so nothing can be paid out.') }}</span>@endunless
                </div>

                <div class="relative overflow-x-auto" @if ($payouts->contains(fn ($p) => $p->status === PayoutStatus::Paying)) wire:poll.5s @endif>
                    <table class="rv-tb min-w-[860px]">
                        <thead>
                            <tr>
                                <th>{{ __('Place') }}</th>
                                <th>{{ __('Player') }}</th>
                                <th class="is-r">{{ __('Amount (sats)') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Lightning address') }}</th>
                                <th>{{ __('Key') }}</th>
                                <th class="is-r"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payouts as $payout)
                                <tr class="align-top" wire:key="po-{{ $payout->id }}" data-test="admin-payout-row" data-status="{{ $payout->status->value }}">
                                    <td><span class="rv-num text-base text-btc">{{ $payout->place }}.</span></td>
                                    <td>
                                        <b class="[overflow-wrap:anywhere]">{{ $payout->name }}</b>
                                        @if ($payout->participant && $payout->participant->name !== $payout->name)<span class="block rv-m">{{ $payout->participant->name }}</span>@endif
                                    </td>
                                    <td class="is-r whitespace-nowrap font-bold tabular-nums">{{ $sats($payout->amount_sats) }}</td>
                                    <td>
                                        <span @class(['rv-tag', 'is-ok' => $payout->status === PayoutStatus::Paid, 'is-bad' => $payout->status === PayoutStatus::Failed, 'is-btc' => ! in_array($payout->status, [PayoutStatus::Paid, PayoutStatus::Failed], true)])>@if ($payout->status === PayoutStatus::Paid)<x-icon name="check" :size="12" />@endif{{ $payout->status->label() }}</span>
                                        @if ($payout->reasonText())<span class="mt-1 block max-w-[280px] text-xs text-ink-2" data-test="payout-reason">{{ $payout->reasonText() }}</span>@endif
                                        @if (is_array($payout->debug) && $payout->debug !== [])
                                            {{-- The raw trace of the last failed attempt (user 2026-10-25: admins are devs and bitcoiners, show them why). --}}
                                            {{-- wire:ignore.self: the polls morph the row, and its `open` (closed on the server) snapped the details shut (user 2026-10-26). --}}
                                            <details wire:ignore.self wire:key="payout-debug-{{ $payout->id }}" class="mt-1 max-w-[420px]" data-test="payout-debug" x-data="{ copied: false }">
                                                <summary class="min-h-11 cursor-pointer py-2 text-xs text-btc-hi">{{ __('Debug details') }}{{ ($payout->reason ? ' · '.$payout->reason : '') }}</summary>
                                                <dl class="m-0 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-3 gap-y-1 rounded-md bg-well p-2 font-mono text-xs leading-snug">
                                                    @foreach ($payout->debug as $key => $value)
                                                        <dt @class(['text-ink-3', 'text-loss' => in_array($key, ['failed_checks', 'step', 'wallet_code'], true)])>{{ $key }}</dt>
                                                        <dd class="m-0 [overflow-wrap:anywhere] {{ in_array($key, ['failed_checks', 'step', 'wallet_code'], true) ? 'text-loss' : 'text-ink-2' }}">{{ is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</dd>
                                                    @endforeach
                                                </dl>
                                                <button type="button" class="mt-1 min-h-11 cursor-pointer text-xs text-ink-2 underline underline-offset-4 hover:text-ink" data-test="payout-debug-copy"
                                                        x-on:click="navigator.clipboard.writeText(@js(json_encode($payout->debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))).then(() => { copied = true; setTimeout(() => copied = false, 1500) }).catch(() => { copied = 'failed' })">
                                                    <span x-show="! copied">{{ __('Copy as JSON') }}</span>
                                                    <span role="status" aria-live="polite"><span x-show="copied === true" x-cloak>{{ __('Copied') }}</span><span x-show="copied === 'failed'" x-cloak>{{ __('Copy failed, select the text above.') }}</span></span>
                                                </button>
                                            </details>
                                        @endif
                                        @if ($payout->event)<span class="mt-1 block truncate font-mono text-[11px] text-proof" title="{{ $payout->event->event_id }}">2157, {{ \Illuminate\Support\Str::limit($payout->event->event_id, 16, '…') }}</span>@endif
                                    </td>
                                    <td class="text-[13px] text-ink-2" data-test="payout-address-state">{{ $payout->lud16 !== null ? __('checked') : '–' }}</td>
                                    <td class="font-mono text-[11px] text-ink-3" title="{{ $payout->idempotency_key }}">{{ substr($payout->idempotency_key, 0, 10) }}…</td>
                                    <td class="is-r whitespace-nowrap">
                                        @if ($payout->status === PayoutStatus::Open && $payout->reason !== \App\Support\FairPlay\AccountLinks::WITHHELD && ($newAddress = \App\Support\Payouts\PayoutRunner::currentAddress($payout)) !== null)
                                            <x-button variant="quiet" wire:click="approveAddress({{ $payout->id }}, '{{ $newAddress }}')" wire:confirm="{{ __('Pay this prize to :address from now on?', ['address' => $newAddress]) }}" data-test="approve-address">{{ __('Approve :address', ['address' => $newAddress]) }}</x-button>
                                        @endif
                                        @if ($payout->status->isPayable())
                                            <x-button variant="quiet" wire:click="pay({{ $payout->id }})" wire:confirm="{{ __('Send :sats sats to :address?', ['sats' => $sats($payout->amount_sats), 'address' => $payout->name]) }}" x-on:click="navigator.vibrate?.(15)" wire:loading.attr="disabled" wire:target="pay({{ $payout->id }})" :disabled="! $payingHere" data-test="pay-one">
                                                <span wire:loading.remove wire:target="pay({{ $payout->id }})">{{ $payout->status === PayoutStatus::Failed ? __('Retry') : __('Pay') }}</span>
                                                <span wire:loading wire:target="pay({{ $payout->id }})">{{ __('Starting…') }}</span>
                                            </x-button>
                                        @endif
                                        @if ($payout->status === PayoutStatus::Open && $payout->reason === 'no_lud16')
                                            <x-button variant="quiet" wire:click="remindAddress({{ $payout->id }})" wire:loading.attr="disabled" wire:target="remindAddress({{ $payout->id }})" data-test="remind-address">{{ __('Remind') }}</x-button>
                                        @endif
                                        @if (in_array($payout->status, [PayoutStatus::Open, PayoutStatus::Pending, PayoutStatus::Failed], true))
                                            <x-button variant="quiet" wire:click="forward({{ $payout->id }})" wire:confirm="{{ __(':name passes :sats sats on to the next pot? They are not paid out, the tournament page shows it.', ['name' => $payout->name, 'sats' => $sats($payout->amount_sats)]) }}" x-on:click="navigator.vibrate?.(15)" data-test="forward-one">{{ __('Pass on') }}</x-button>
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
