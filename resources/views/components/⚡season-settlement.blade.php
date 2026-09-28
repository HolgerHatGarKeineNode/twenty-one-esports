<?php

use App\Enums\PayoutStatus;
use App\Jobs\PaySeasonPayout;
use App\Models\Season;
use App\Models\SeasonPayout;
use App\Models\User;
use App\Support\FairPlay\AccountLinks;
use App\Support\Payouts\PayoutRunner;
use App\Support\PreSeason;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\SeasonChain\SeasonSettlementRefused;
use App\Support\Wallet\WalletSetup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The settlement of an ended season (P37, SeasonSettlement), inside the
 * season review of AdminSeason: per player the mined sats minus the voided
 * blocks, the review's corrections (void a block with a public reason, a
 * `void-block` label), the approval of the list, then "Pay" per player or
 * for all that are ready, through the league wallet's paying connection.
 * Every payment is queued (PaySeasonPayout) and exactly-once (PayoutRunner).
 *
 * A Lightning address never shows as text: the list says whether a player
 * has one. There is no balance check (user, 2026-09-28): a payment the
 * wallet cannot make fails with "top up the payout wallet" and is retried.
 * Admins only (gate `admin`, as tournament payouts), on mount and in every
 * action, and again in SeasonSettlement.
 */
new class extends Component {
    #[Locked]
    public int $seasonId;

    public string $voidHeight = '';

    public string $voidReason = '';

    public string $error = '';

    public string $notice = '';

    public function mount(Season $season): void
    {
        Gate::authorize('admin');

        $this->seasonId = $season->id;
    }

    #[Computed]
    public function season(): Season
    {
        return Season::query()->findOrFail($this->seasonId);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function review(): array
    {
        return app(SeasonSettlement::class)->review($this->season);
    }

    /**
     * @return Collection<int, SeasonPayout>
     */
    #[Computed]
    public function payouts(): Collection
    {
        return $this->season->payouts()->with(['user', 'event', 'season'])->orderByDesc('amount_sats')->orderBy('id')->get();
    }

    public function void(SeasonSettlement $settlement): void
    {
        Gate::authorize('admin');
        $this->error = $this->notice = '';
        $height = filter_var(trim($this->voidHeight), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($height === false) {
            $this->error = __('Enter the height of the block to void, a whole number from 1.');

            return;
        }

        try {
            $settlement->void($this->season, $this->admin(), $height, $this->voidReason);
            $this->notice = __('Block :height is void. The correction is published with its reason.', ['height' => $height]);
            $this->reset('voidHeight', 'voidReason');
        } catch (SeasonSettlementRefused $refused) {
            $this->error = $refused->getMessage();
        }

        unset($this->season, $this->review);
    }

    public function approve(SeasonSettlement $settlement): void
    {
        Gate::authorize('admin');
        $this->error = $this->notice = '';

        try {
            $settlement->approve($this->season, $this->admin());
            $this->notice = __('The list is approved and the payouts are ready. Nothing is paid until you pay it.');
        } catch (SeasonSettlementRefused $refused) {
            $this->error = $refused->getMessage();
        }

        unset($this->season, $this->review, $this->payouts);
    }

    public function pay(int $payoutId): void
    {
        Gate::authorize('admin');
        $payout = $this->payout($payoutId);

        // A waiting (`open`) payout is never paid: its address needs an approval first.
        if ($payout->status->isPayable()) {
            PaySeasonPayout::dispatch($payout->id);
        }

        unset($this->payouts);
    }

    public function payAll(): void
    {
        Gate::authorize('admin');

        foreach ($this->payouts as $payout) {
            if ($payout->status === PayoutStatus::Pending) {
                PaySeasonPayout::dispatch($payout->id);
            }
        }

        unset($this->payouts);
    }

    /** The admin approves the address the player's profile shows now, named by its fingerprint. */
    public function approveAddress(int $payoutId, string $fingerprint, SeasonSettlement $settlement): void
    {
        Gate::authorize('admin');
        $this->error = $this->notice = '';

        try {
            $settlement->approveAddress($this->payout($payoutId), $this->admin(), $fingerprint);
        } catch (SeasonSettlementRefused $refused) {
            $this->error = $refused->getMessage();
        }

        unset($this->payouts);
    }

    /** Ask the wallet again about a payment whose outcome is unclear. */
    public function check(int $payoutId): void
    {
        Gate::authorize('admin');
        PaySeasonPayout::dispatch($this->payout($payoutId)->id, false);
        unset($this->payouts);
    }

    /** The admin looked into the wallet: that payment did not happen. */
    public function release(int $payoutId, PayoutRunner $runner): void
    {
        Gate::authorize('admin');
        $runner->release($this->payout($payoutId));
        unset($this->payouts);
    }

    private function payout(int $payoutId): SeasonPayout
    {
        return SeasonPayout::query()->where('season_id', $this->seasonId)->findOrFail($payoutId);
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $season = $this->season;
    $review = $this->review;
    $approved = $season->settlement_approved_at !== null;
    $reviewOpen = SeasonSettlement::reviewOpen($season);
    $canPay = WalletSetup::canPay();
    $leagueKey = LeagueKey::fromConfig() !== null;
    $zone = PreSeason::timezoneFor(auth()->user());
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $date = fn ($at): string => CarbonImmutable::instance($at)->setTimezone($zone)->locale(app()->getLocale())->translatedFormat('D j M Y, H:i');
    $payouts = $this->payouts;
    $pending = $payouts->where('status', PayoutStatus::Pending);
    $claimEnds = SeasonSettlement::claimEndsAt($season);
    $topUp = $payouts->contains(fn (SeasonPayout $p): bool => $p->status === PayoutStatus::Failed && in_array($p->reason, ['insufficient_balance', 'budget_exceeded', 'wallet_error'], true));
    $input = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $address = fn (bool $has): string => $has ? '✓ '.__('has address') : __('missing');
    $blocker = $approved ? null : app(SeasonSettlement::class)->blocker($season);
@endphp

<div class="flex flex-col gap-4" data-test="season-settlement" data-approved="{{ $approved ? 'yes' : 'no' }}">
    <div class="flex flex-col gap-1">
        <h3 class="m-0 text-[13px] font-bold">{{ __('Settlement') }}</h3>
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Each player gets the rewards of their blocks, less the blocks the review voids, to the Lightning address in their Nostr profile. No fees. Void blocks before you approve the list; after it the review is closed. Payments are made one click at a time from the payout wallet, and each is confirmed by its preimage.') }}</p>
    </div>

    @unless ($leagueKey)
        <x-admin.flash tone="error" :message="__('The league key is not set up, so nothing can be published yet.')" data-test="settlement-league-key" />
    @endunless
    @if ($error !== '')<x-admin.flash tone="error" :message="$error" data-test="settlement-error" />@endif
    @if ($notice !== '')<x-admin.flash :message="$notice" data-test="settlement-notice" />@endif
    @if ($review['mismatch'])<x-admin.flash tone="error" :message="$review['mismatch']" data-test="settlement-mismatch" />@endif
    @if ($topUp)
        <x-admin.flash tone="error" :message="__('A payment failed at the wallet. Top up the payout wallet, then retry the failed payouts.')" data-test="settlement-top-up" />
    @endif

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            [__('Mined'), $sats($review['mined']), trans_choice(':count block|:count blocks', $review['blocks'])],
            [__('Voided'), $sats($review['voided']), trans_choice(':count correction|:count corrections', $review['voids']->count())],
            [__('To pay'), $sats($review['payout']), trans_choice(':count player|:count players', count(array_filter($review['rows'], fn ($row) => $row['payout'] > 0)))],
            [__('Paid'), $sats((int) $payouts->where('status', PayoutStatus::Paid)->sum('amount_sats')), $claimEnds ? __('claim window ends :when', ['when' => $date($claimEnds)]) : __('claim window starts with the first payout')],
        ] as [$label, $value, $sub])
            <div class="flex min-w-0 flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring" data-test="settlement-total">
                <span class="text-xs text-ink-2">{{ $label }}</span><b class="font-display text-lg leading-tight">{{ $value }}</b><span class="text-xs text-ink-3">{{ $sub }}</span>
            </div>
        @endforeach
    </div>

    @if (! $approved)
        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[640px] border-collapse text-left text-[13px]" data-test="settlement-list">
                <thead class="text-xs text-ink-3">
                    <tr>
                        <th class="py-2 pr-3 font-normal">{{ __('Player') }}</th>
                        <th class="py-2 pr-3 font-normal">{{ __('Blocks') }}</th>
                        <th class="py-2 pr-3 text-right font-normal">{{ __('Mined') }}</th>
                        <th class="py-2 pr-3 text-right font-normal">{{ __('Voided') }}</th>
                        <th class="py-2 pr-3 text-right font-normal">{{ __('Payout') }}</th>
                        <th class="py-2 font-normal">{{ __('Lightning address') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($review['rows'] as $row)
                        <tr class="border-t border-hairline align-top" data-test="settlement-row">
                            <td class="py-2.5 pr-3"><b class="[overflow-wrap:anywhere]">{{ $row['name'] }}</b>@if ($row['linked'])<span class="block text-xs text-loss">{{ __('linked account: withheld') }}</span>@endif</td>
                            <td class="py-2.5 pr-3">
                                @if ($row['heights'] === [])
                                    {{ $row['blocks'] }}
                                @else
                                    <details><summary class="cursor-pointer">{{ $row['blocks'] }}</summary><span class="text-xs text-ink-2">{{ collect($row['heights'])->map(fn ($h) => '#'.$h)->implode(' ') }}</span></details>
                                @endif
                            </td>
                            <td class="py-2.5 pr-3 text-right whitespace-nowrap">{{ $sats($row['mined']) }}</td>
                            <td class="py-2.5 pr-3 text-right whitespace-nowrap">{{ $row['voided'] > 0 ? '−'.$sats($row['voided']) : '0' }}</td>
                            <td class="py-2.5 pr-3 text-right font-bold whitespace-nowrap" data-test="settlement-payout">{{ $sats($row['payout']) }}</td>
                            <td @class(['py-2.5 text-xs whitespace-nowrap', 'text-win' => $row['has_address'], 'text-loss' => ! $row['has_address']]) data-test="settlement-address">{{ $address($row['has_address']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-2.5 text-ink-2">{{ __('No block was mined in this season, so nobody is owed anything.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <div class="flex flex-col gap-2" data-test="settlement-voids">
        <h4 class="m-0 text-[13px] font-bold">{{ __('Corrections') }}</h4>
        @forelse ($review['voids'] as $void)
            <div class="flex flex-col gap-0.5 border-b border-hairline py-2 text-[13px] last:border-0" data-test="settlement-void">
                <span class="flex flex-wrap gap-x-3"><b>{{ __('Block :height void', ['height' => $void->height]) }}</b><span class="text-xs text-ink-2">{{ __('by :name, :when', ['name' => $void->voidedBy?->displayName() ?? substr($void->voided_by_pubkey, 0, 8), 'when' => $date($void->created_at)]) }}</span></span>
                <span class="text-ink-2 [overflow-wrap:anywhere]">{{ $void->reason }}</span>
            </div>
        @empty
            <p class="m-0 text-[13px] text-ink-2">{{ __('No block is voided.') }}</p>
        @endforelse

        @if ($reviewOpen)
            <form wire:submit="void" wire:confirm="{{ __('Void this block? The correction is published on Nostr with its reason and cannot be undone.') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-[8rem_minmax(0,1fr)_auto] sm:items-end" data-test="void-form">
                <label class="flex flex-col gap-1.5 text-xs text-ink-2">{{ __('Block height') }}
                    <input type="text" inputmode="numeric" wire:model="voidHeight" class="{{ $input }}" data-test="void-height">
                </label>
                <label class="flex flex-col gap-1.5 text-xs text-ink-2">{{ __('Public reason') }}
                    <input type="text" wire:model="voidReason" maxlength="{{ SeasonSettlement::REASON_MAX }}" class="{{ $input }}" data-test="void-reason">
                </label>
                <x-button type="submit" variant="secondary" data-test="void-block">{{ __('Void block') }}</x-button>
            </form>
        @endif
    </div>

    @if (! $approved)
        @if ($blocker)
            <p class="m-0 text-[13px] text-loss" data-test="settlement-blocker">{{ $blocker }}</p>
        @else
            <div><x-button wire:click="approve" wire:confirm="{{ __('Approve the list? The review closes: no block can be voided after it.') }}" data-test="approve-settlement">{{ __('Approve the list') }}</x-button></div>
        @endif
    @else
        <div class="flex flex-wrap items-center gap-3">
            <x-button icon="bolt" wire:click="payAll" wire:confirm="{{ __('Send :count payments, :sats sats in all?', ['count' => $pending->count(), 'sats' => $sats((int) $pending->sum('amount_sats'))]) }}" :disabled="! $canPay || $pending->isEmpty()" class="disabled:cursor-default disabled:opacity-50" data-test="settlement-pay-all">{{ __('Pay all ready (:count)', ['count' => $pending->count()]) }}</x-button>
            @unless ($canPay)<span class="text-[13px] text-loss" data-test="settlement-no-wallet">{{ __('The paying connection of the payout wallet is missing, so nothing can be paid out.') }}</span>@endunless
            <span class="text-xs text-ink-3">{{ __('approved :when', ['when' => $date($season->settlement_approved_at)]) }}</span>
        </div>

        <div class="relative overflow-x-auto" @if ($payouts->contains(fn ($p) => $p->status === PayoutStatus::Paying)) wire:poll.5s @endif>
            <table class="w-full min-w-[720px] border-collapse text-left text-[13px]" data-test="settlement-payouts">
                <thead class="text-xs text-ink-3">
                    <tr>
                        <th class="py-2 pr-3 font-normal">{{ __('Player') }}</th>
                        <th class="py-2 pr-3 font-normal">{{ __('Blocks') }}</th>
                        <th class="py-2 pr-3 text-right font-normal">{{ __('Amount') }}</th>
                        <th class="py-2 pr-3 font-normal">{{ __('Lightning address') }}</th>
                        <th class="py-2 pr-3 font-normal">{{ __('Status') }}</th>
                        <th class="py-2 font-normal"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payouts as $payout)
                        @php($current = SeasonSettlement::address($payout->user))
                        @php($expired = SeasonSettlement::claimExpired($payout))
                        @php($frozen = SeasonSettlement::addressFrozen($payout->user))
                        <tr class="border-t border-hairline align-top" wire:key="spo-{{ $payout->id }}" data-test="settlement-payout-row" data-status="{{ $payout->status->value }}">
                            <td class="py-2.5 pr-3"><b class="[overflow-wrap:anywhere]">{{ $payout->name }}</b></td>
                            <td class="py-2.5 pr-3">{{ $payout->blocks }}</td>
                            <td class="py-2.5 pr-3 text-right whitespace-nowrap">{{ $sats($payout->amount_sats) }} {{ __('sats') }}</td>
                            <td @class(['py-2.5 pr-3 text-xs whitespace-nowrap', 'text-win' => $current !== null, 'text-loss' => $current === null]) data-test="settlement-address">{{ $address($current !== null) }}</td>
                            <td class="py-2.5 pr-3">
                                <span @class(['inline-flex h-6 items-center rounded-xs px-2 text-xs font-bold', 'bg-win-tint text-win' => $payout->status === PayoutStatus::Paid, 'bg-loss-tint text-loss' => $payout->status === PayoutStatus::Failed || $expired, 'bg-btc-chip text-btc-hi' => ! $expired && ! in_array($payout->status, [PayoutStatus::Paid, PayoutStatus::Failed], true)])>{{ $expired ? __('Claim window over') : $payout->status->label() }}</span>
                                @if ($expired)
                                    <span class="mt-1 block max-w-[280px] text-xs text-ink-2" data-test="payout-reason">{{ __('The claim window of this season is over: these sats stay in the league reserve.') }}</span>
                                @elseif ($payout->reasonText())
                                    <span class="mt-1 block max-w-[280px] text-xs text-ink-2" data-test="payout-reason">{{ $payout->reasonText() }}</span>
                                @endif
                                @if ($payout->event)<span class="mt-1 block truncate font-mono text-[11px] text-proof" title="{{ $payout->event->event_id }}">2157 · {{ \Illuminate\Support\Str::limit($payout->event->event_id, 16, '…') }}</span>@endif
                            </td>
                            <td class="py-2.5 text-right whitespace-nowrap">
                                @if ($payout->status === PayoutStatus::Open && ! $expired && $payout->reason !== AccountLinks::WITHHELD && $current !== null)
                                    @if ($frozen)
                                        <span class="text-xs text-ink-2" data-test="address-frozen">{{ __('new address, approvable from :when', ['when' => $date(SeasonSettlement::frozenUntil($payout->user))]) }}</span>
                                    @else
                                        <x-button variant="quiet" wire:click="approveAddress({{ $payout->id }}, '{{ SeasonSettlement::fingerprint($current) }}')" wire:confirm="{{ __('Pay these sats to the Lightning address the player’s profile shows now?') }}" data-test="approve-address">{{ __('Approve the new address') }}</x-button>
                                    @endif
                                @endif
                                @if ($payout->status->isPayable())
                                    <x-button variant="quiet" wire:click="pay({{ $payout->id }})" wire:confirm="{{ __('Send :sats sats to :name?', ['sats' => $sats($payout->amount_sats), 'name' => $payout->name]) }}" :disabled="! $canPay" data-test="pay-one">{{ $payout->status === PayoutStatus::Failed ? __('Retry') : __('Pay') }}</x-button>
                                @elseif ($payout->status === PayoutStatus::Paying)
                                    <x-button variant="quiet" wire:click="check({{ $payout->id }})" :disabled="! $canPay">{{ __('Check') }}</x-button>
                                    @if ($payout->reason === 'needs_check')
                                        <x-button variant="secondary" wire:click="release({{ $payout->id }})" wire:confirm="{{ __('Only if the wallet shows no payment for this invoice. Release it?') }}">{{ __('Release') }}</x-button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-2.5 text-ink-2">{{ __('Nobody gets a season payout: no block paid anything after the review.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
