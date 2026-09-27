<?php

use App\Enums\IncomingPaymentStatus;
use App\Livewire\PrizePotPage;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Clans\ClanLogos;
use App\Support\PreSeason;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PotTopUps;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

/*
 * A tournament's prize pool settings (P9), for its organizer and admins
 * (gate `manage-tournament`, checked on the route and again in every
 * action): the pot (the tournament's own wallet, its prizes as percents or
 * fixed amounts, the same section as on the create and edit pages:
 * App\Livewire\PrizePotPage; the prizes can change until sign-up closes,
 * because players sign up under them), and the sponsors with their logos
 * and invoices, made by the pot's own wallet (App\Support\Prizes\PotTopUps).
 * The pot is what that wallet holds. Paying out is on the admins' payouts
 * page.
 */
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends PrizePotPage {
    use WithFileUploads;

    #[Locked]
    public int $tournamentId;

    public string $sponsorName = '';

    public int|string $sponsorSats = '';

    /** @var mixed an uploaded file */
    public $sponsorLogo = null;

    #[Locked]
    public ?int $invoiceId = null;

    public string $saved = '';

    public function mount(Tournament $tournament): void
    {
        Gate::authorize('manage-tournament', $tournament);

        $this->tournamentId = $tournament->id;
        $this->fillPot($tournament);
    }

    protected function potTournament(): ?Tournament
    {
        return $this->tournament;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Prize pool').' · '.$this->tournament->name);
    }

    #[Computed]
    public function tournament(): Tournament
    {
        return Tournament::query()->findOrFail($this->tournamentId);
    }

    /**
     * @return Collection<int, TournamentSponsor>
     */
    #[Computed]
    public function sponsors(): Collection
    {
        return $this->tournament->sponsors()->get();
    }

    #[Computed]
    public function invoice(): ?IncomingPayment
    {
        return $this->invoiceId === null ? null : IncomingPayment::query()->where('tournament_id', $this->tournamentId)->find($this->invoiceId);
    }

    public function savePotSettings(): void
    {
        $this->me();
        $this->saved = '';

        if ($this->savePot($this->tournament)) {
            unset($this->tournament);
            $this->fillPot($this->tournament);
            $this->saved = __('Saved.');
        }
    }

    public function addSponsor(PrizePool $pool): void
    {
        $this->validate([
            'sponsorName' => ['required', 'string', 'max:80'],
            'sponsorSats' => ['required', 'integer', 'min:1', 'max:'.(int) config('esports.wallet.max_sats')],
            'sponsorLogo' => ['nullable', ...ClanLogos::rules()],
        ], attributes: ['sponsorName' => __('name'), 'sponsorSats' => __('pledge'), 'sponsorLogo' => __('logo')]);

        $done = $this->guarded(fn () => $pool->addSponsor($this->tournament, $this->me(), $this->sponsorName, (int) $this->sponsorSats, $this->sponsorLogo));

        if ($done) {
            $this->reset('sponsorName', 'sponsorSats', 'sponsorLogo');
            unset($this->sponsors);
        }
    }

    public function removeSponsor(int $sponsorId, PrizePool $pool): void
    {
        $sponsor = TournamentSponsor::query()->where('tournament_id', $this->tournamentId)->findOrFail($sponsorId);
        $this->guarded(fn () => $pool->removeSponsor($sponsor, $this->me()));
        unset($this->sponsors);
    }

    public function sponsorInvoice(int $sponsorId, PotTopUps $topUps): void
    {
        $sponsor = TournamentSponsor::query()->where('tournament_id', $this->tournamentId)->findOrFail($sponsorId);

        try {
            $this->invoiceId = $topUps->sponsorInvoice($sponsor, $this->me())->id;
            unset($this->invoice);
        } catch (PoolRefusal $refusal) {
            $this->addError('pool', $refusal->getMessage());
        }
    }

    public function checkInvoice(PotTopUps $topUps): void
    {
        if ($this->invoice !== null && $this->invoice->status === IncomingPaymentStatus::Pending) {
            $topUps->check($this->invoice);
            unset($this->invoice, $this->sponsors);
        }
    }

    public function closeInvoice(): void
    {
        $this->invoiceId = null;
        unset($this->invoice);
    }

    private function me(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && Gate::forUser($user)->allows('manage-tournament', $this->tournament), 403);

        return $user;
    }

    /**
     * @param  Closure(): mixed  $action
     */
    private function guarded(Closure $action): bool
    {
        $this->resetErrorBag('pool');

        try {
            $action();

            return true;
        } catch (TournamentRuleViolation $violation) {
            $this->addError('pool', $violation->getMessage());

            return false;
        }
    }
}; ?>

@php
    $tournament = $this->tournament;
    $pool = app(PrizePool::class);
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $sponsorInvoices = PotTopUps::enabled($tournament);
    $funding = $tournament->hasOwnWallet() ? $pool->funding($tournament) : null;
    $ended = $tournament->pool_closed_at !== null || in_array($tournament->status, [\App\Enums\TournamentStatus::Finished, \App\Enums\TournamentStatus::Cancelled], true);
    $invoice = $this->invoice;
@endphp

<div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12" data-test="pool-settings">
    <div class="flex flex-col gap-2">
        <a href="{{ route('tournaments.show', $tournament) }}" class="text-xs text-ink-2">← {{ $tournament->name }}</a>
        <h1 class="m-0 font-display text-[28px] font-bold break-words lg:text-[34px]">{{ __('Prize pool') }}</h1>
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('The pot is a wallet of this tournament’s own: anyone can add sats to it, sponsors too. You set the prizes and the sponsors; an admin checks the tournament at its end and pays the winners from that wallet.') }}</p>
    </div>

    @error('pool')<p class="m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss" role="alert" data-test="pool-error">{{ $message }}</p>@enderror

    <section aria-labelledby="state-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="pool-state">
        <h2 id="state-h" class="m-0 text-[15px] font-bold">{{ __('Status') }}</h2>
        @if (! $tournament->hasOwnWallet())
            <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="pool-no-pot">{{ __('No prize pot yet: connect the tournament’s own wallet below.') }}</p>
        @elseif ($tournament->pool_opened_at === null)
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('The pot opens when the tournament is published.') }}</p>
        @else
            <p class="m-0 text-[13px]">
                <b>{{ $sats((int) $pool->potSats($tournament)) }} {{ __('sats') }}</b>
                · {{ $tournament->isPoolOpen() ? __('open since :date', ['date' => $tournament->pool_opened_at->format('Y-m-d H:i')]) : __('closed at the admin check, :date', ['date' => $tournament->pool_closed_at?->format('Y-m-d H:i')]) }}
            </p>
            @if ($funding !== null && $funding['leftover'] !== null)
                <p class="m-0 text-[13px] text-ink-2" data-test="pool-leftover">{{ $funding['funded']
                    ? __('The fixed prizes are covered; :sats sats are left over after prizes and stay in the wallet.', ['sats' => $sats($funding['leftover'])])
                    : __('Funded :have of :goal sats for the fixed prizes.', ['have' => $sats($funding['have']), 'goal' => $sats((int) $funding['goal'])]) }}</p>
            @endif
        @endif
    </section>

    @unless ($ended)
        @include('pages.admin.partials.prize-pot', ['potTournament' => $tournament, 'potSave' => 'savePotSettings'])
        @if ($saved !== '')<p class="m-0 text-[13px] text-win" role="status" data-test="pool-saved">{{ $saved }}</p>@endif
    @endunless

    @if ($tournament->hasOwnWallet())
    <section aria-labelledby="sponsors-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="pool-sponsors-admin">
        <div class="flex flex-col gap-1">
            <h2 id="sponsors-h" class="m-0 text-[15px] font-bold">{{ __('Sponsors') }}</h2>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('A sponsor pays a Lightning invoice for their pledge, made by the pot’s own wallet. Their logo shows on the tournament page once it is paid; logos stay on this site and are not published on Nostr.') }}</p>
            @unless ($sponsorInvoices)
                <p class="m-0 text-xs text-ink-2" data-test="sponsor-invoices-off">{{ __('Top-ups not enabled for this pot: its connection may not make invoices, so sponsors pay the wallet directly and show without a paid mark.') }}</p>
            @endunless
        </div>

        @if ($invoice)
            <div class="flex flex-col gap-2 rounded-md bg-ground p-4 shadow-ring" data-test="sponsor-invoice" @if ($invoice->status === IncomingPaymentStatus::Pending) wire:poll.5s="checkInvoice" @endif x-data="{ copied: false }">
                @if ($invoice->status === IncomingPaymentStatus::Settled)
                    <p class="m-0 text-[13px] text-win" role="status">{{ __('Paid: :sats sats. The logo shows now.', ['sats' => $sats($invoice->amount_sats)]) }}</p>
                @else
                    <p class="m-0 text-[13px]">{{ __('Send this invoice to :name. It is valid until :time.', ['name' => $invoice->sponsor?->name ?? '', 'time' => $invoice->expires_at->format('Y-m-d H:i')]) }}</p>
                    @php($sponsorQr = rescue(fn () => \App\Support\QrCode::svg('lightning:'.$invoice->bolt11, label: __('Lightning invoice for :sats sats', ['sats' => $sats($invoice->amount_sats)])), null, false))
                    @if ($sponsorQr)
                        <div class="w-full max-w-[240px] self-start rounded-md bg-white p-2" data-test="sponsor-qr">{!! $sponsorQr !!}</div>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <x-button variant="quiet" icon="copy" data-invoice="{{ $invoice->bolt11 }}" x-on:click="navigator.clipboard?.writeText($el.dataset.invoice).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"><span x-text="copied ? @js(__('Copied')) : @js(__('Copy invoice'))">{{ __('Copy invoice') }}</span></x-button>
                        <x-button variant="secondary" wire:click="closeInvoice">{{ __('Close') }}</x-button>
                    </div>
                @endif
            </div>
        @endif

        @if ($this->sponsors->isNotEmpty())
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->sponsors as $sponsor)
                    @php($paid = $sponsor->paidSats())
                    <li class="flex flex-col gap-2 border-t border-hairline py-3 sm:flex-row sm:items-center" wire:key="s-{{ $sponsor->id }}" data-test="sponsor-row">
                        <span class="flex min-w-0 grow items-center gap-3">
                            @if ($sponsor->logoUrl())
                                <img src="{{ $sponsor->logoUrl() }}" alt="" class="h-9 w-auto max-w-[96px] shrink-0 object-contain">
                            @endif
                            <span class="min-w-0">
                                <b class="block truncate text-[13px]">{{ $sponsor->name }}</b>
                                <span class="text-xs text-ink-2">{{ __(':pledged sats pledged, :paid sats paid', ['pledged' => $sats($sponsor->pledged_sats), 'paid' => $sats($paid)]) }}</span>
                            </span>
                        </span>
                        @if (! $ended)
                            <span class="flex shrink-0 flex-wrap gap-2">
                                @if ($sponsorInvoices)
                                    <x-button variant="quiet" wire:click="sponsorInvoice({{ $sponsor->id }})" data-test="sponsor-invoice-button">{{ __('Invoice') }}</x-button>
                                @endif
                                @if ($paid === 0)
                                    <x-button variant="secondary" wire:click="removeSponsor({{ $sponsor->id }})" wire:confirm="{{ __('Remove this sponsor?') }}">{{ __('Remove') }}</x-button>
                                @endif
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @unless ($ended)
            <form wire:submit="addSponsor" class="flex flex-col gap-3 border-t border-hairline pt-4" data-test="sponsor-form">
                <h3 class="m-0 text-[13px] font-bold">{{ __('Add a sponsor') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                        {{ __('Name') }}
                        <input type="text" maxlength="80" wire:model="sponsorName" class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="sponsor-name">
                        @error('sponsorName')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                        {{ __('Pledge in sats') }}
                        <input type="text" inputmode="numeric" wire:model="sponsorSats" class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="sponsor-sats">
                        @error('sponsorSats')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>
                </div>
                <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                    {{ __('Logo (PNG, JPG or WebP, optional)') }}
                    <input type="file" accept="image/png,image/jpeg,image/webp" wire:model="sponsorLogo" class="text-[13px] text-ink">
                    @error('sponsorLogo')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                </label>
                <div><x-button type="submit" variant="quiet" data-test="sponsor-add">{{ __('Add sponsor') }}</x-button></div>
            </form>
        @endunless
    </section>
    @endif
</div>
