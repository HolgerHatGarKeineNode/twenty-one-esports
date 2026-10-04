<?php

use App\Models\User;
use App\Support\Lightning\WinnerZaps;
use App\Support\Lightning\ZapRefused;
use App\Support\Nostr\SignerMessages;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Zap the winner (P47, NIP-57): under a finished game, series or tournament,
 * a tip for each winner whose Nostr profile has a Lightning address
 * ({@see WinnerZaps}). A signed-in player previews the zap request, signs it
 * and gets the invoice as a QR code (resources/js/zapWinner.js); anyone can
 * scan the winner's LNURL QR code instead (a plain payment, no receipt).
 * Never the address as text, never a fee: the sats go to the winner.
 * Renders nothing while there is no winner to zap.
 */
new class extends Component {
    #[Locked]
    public string $type;

    #[Locked]
    public string $subject;

    public function mount(string $type, string $subject): void
    {
        abort_unless(in_array($type, WinnerZaps::TYPES, true), 404);

        $this->type = $type;
        $this->subject = $subject;
    }

    /**
     * @return list<array{user: User, lnurl: string, qr: string}>
     */
    #[Computed]
    public function winners(): array
    {
        $viewer = Auth::user();

        return app(WinnerZaps::class)->winners($this->type, $this->subject, $viewer instanceof User ? $viewer : null);
    }

    /**
     * @return array{template?: array<string, mixed>, error?: string}
     */
    public function prepareZap(int $winnerId, int $sats, string $comment, WinnerZaps $zaps): array
    {
        try {
            return ['template' => $zaps->template($this->me(), $this->type, $this->subject, $winnerId, $sats, $comment)];
        } catch (ZapRefused $refused) {
            return ['error' => $refused->getMessage()];
        }
    }

    /**
     * @return array{invoice?: string, qr?: string, error?: string}
     */
    public function zapInvoice(int $winnerId, int $sats, string $comment, string $signed, WinnerZaps $zaps): array
    {
        try {
            return $zaps->invoice($this->me(), $this->type, $this->subject, $winnerId, $sats, $comment, json_decode($signed, true));
        } catch (ZapRefused $refused) {
            return ['error' => $refused->getMessage()];
        }
    }

    private function me(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $winners = $this->winners;
    $viewer = auth()->user();
    $chip = 'inline-flex h-11 min-w-11 cursor-pointer items-center justify-center rounded-md border px-3 text-[13px] tabular-nums';
@endphp
<div @class(['contents' => $winners !== [], 'hidden' => $winners === []])>
    @if ($winners !== [])
        <section aria-labelledby="zw-h-{{ $this->getId() }}" data-test="zap-winner" data-type="{{ $type }}"
                 class="flex min-w-0 flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-5"
                 x-data="zapWinner({ pubkey: @js($viewer?->pubkey), amounts: @js(WinnerZaps::AMOUNTS), messages: @js([...SignerMessages::labels(), 'failed' => __('That did not work. Please try again.'), 'changed' => __('The zap request changed. Check it again, then sign.')]) })">
            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h2 id="zw-h-{{ $this->getId() }}" class="m-0 flex items-center gap-1.5 text-[15px] font-bold"><x-icon name="bolt" :size="16" class="shrink-0 text-bolt" />{{ $type === 'tournament' ? __('Zap the players') : trans_choice('Zap the winner|Zap the winners', count($winners)) }}</h2>
                <span class="text-xs text-ink-2">{{ __('A tip from you, straight to their wallet. The league takes nothing.') }}</span>
            </span>

            <ul class="m-0 flex list-none flex-col gap-2 p-0">
                @foreach ($winners as $winner)
                    @php($user = $winner['user'])
                    <li wire:key="zap-{{ $user->id }}" class="flex min-w-0 flex-col gap-2 rounded-md bg-well px-3 py-2" data-test="zap-winner-row">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-avatar :user="$user" :size="32" class="shrink-0" />
                            <span class="min-w-0 grow truncate text-[13px] font-bold">{{ $user->displayName() }}</span>
                            <button type="button" x-on:click="toggle({{ $user->id }})" :aria-expanded="open === {{ $user->id }}" aria-controls="zap-panel-{{ $this->getId() }}-{{ $user->id }}" data-test="zap-open"
                                    class="btn-p inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc">
                                <x-icon name="bolt" :size="16" class="shrink-0" /><span>{{ __('Zap') }}</span><span class="sr-only">{{ $user->displayName() }}</span>
                            </button>
                        </div>

                        <div id="zap-panel-{{ $this->getId() }}-{{ $user->id }}" x-show="open === {{ $user->id }}" x-cloak class="flex min-w-0 flex-col gap-3 border-t border-hairline pt-3" data-test="zap-panel">
                            @if ($viewer)
                                <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                                    <legend class="mb-1 text-xs text-ink-2">{{ __('Amount in sats') }}</legend>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach (WinnerZaps::AMOUNTS as $amount)
                                            <button type="button" x-on:click="pick({{ $amount }})" :aria-pressed="sats === {{ $amount }}" data-test="zap-amount"
                                                    :class="sats === {{ $amount }} ? 'border-btc bg-btc-chip font-bold text-btc-hi' : 'border-line bg-card text-ink'" class="{{ $chip }}">{{ number_format($amount, 0, '.', ' ') }}</button>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <label class="flex min-w-0 flex-col gap-1.5">
                                    <span class="text-xs text-ink-2">{{ __('Comment (optional, public in the zap)') }}</span>
                                    <input type="text" x-model="comment" x-on:input="step !== 'idle' && reset()" maxlength="{{ WinnerZaps::MAX_COMMENT }}" data-test="zap-comment"
                                           class="h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink">
                                </label>

                                <div class="flex flex-wrap gap-2" x-show="step === 'idle' || step === 'preparing'">
                                    <button type="button" x-on:click="preview()" x-bind:disabled="step === 'preparing'" data-test="zap-preview-button"
                                            class="btn-p inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc disabled:cursor-default disabled:opacity-60">
                                        <span x-text="step === 'preparing' ? @js(__('Preparing…')) : @js(__('Preview the zap'))">{{ __('Preview the zap') }}</span>
                                    </button>
                                </div>

                                {{-- The zap request exactly as it will be signed; signing only on the button below. --}}
                                <div x-show="step === 'preview' || step === 'signing'" x-cloak role="group" aria-label="{{ __('Preview of the zap request') }}" data-test="zap-preview"
                                     class="flex min-w-0 flex-col gap-2 rounded-md border border-line px-3 py-3">
                                    <p class="m-0 text-[13px] leading-normal" x-text="@js(__('A zap of :sats sats to :name, signed with your key (NIP-57, kind 9734).', ['name' => $user->displayName()])).replace(':sats', Number(sats).toLocaleString())"></p>
                                    <p class="m-0 text-xs leading-normal text-ink-2 [overflow-wrap:anywhere]" x-show="(template?.content ?? '') !== ''" x-text="@js(__('Comment:')) + ' ' + (template?.content ?? '')"></p>
                                    <p class="m-0 text-xs leading-normal text-ink-2" x-show="tagsShown.some((tag) => tag[0] === 'e' || tag[0] === 'a')">{{ __('It names what was won, so Nostr apps show the zap there.') }}</p>
                                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                        <x-button variant="quiet" x-on:click="reset()" x-bind:disabled="step === 'signing'" data-test="zap-cancel">{{ __('Cancel') }}</x-button>
                                        <x-button icon="bolt" x-on:click="sign()" x-bind:disabled="step === 'signing'" class="whitespace-nowrap" data-test="zap-sign">
                                            <span x-text="step === 'signing' ? @js(__('Waiting for your signer…')) : @js(__('Sign and get invoice'))">{{ __('Sign and get invoice') }}</span>
                                        </x-button>
                                    </div>
                                </div>

                                <div x-show="step === 'invoice'" x-cloak class="flex min-w-0 flex-col items-start gap-3 sm:flex-row sm:items-center" data-test="zap-invoice">
                                    <div class="size-40 shrink-0 rounded-sm bg-white p-2 [&>svg]:size-full" x-html="qr" data-test="zap-invoice-qr"></div>
                                    <div class="flex min-w-0 flex-col gap-2">
                                        <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Scan with your Lightning wallet, or open it in the wallet on this device. :name gets the sats; their wallet publishes the zap receipt.', ['name' => $user->displayName()]) }}</p>
                                        <span class="flex flex-wrap gap-2">
                                            <a :href="'lightning:' + invoice" class="btn-p inline-flex h-11 min-w-11 items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc hover:text-on-btc" data-test="zap-open-wallet"><x-icon name="bolt" :size="16" />{{ __('Open in wallet') }}</a>
                                            <button type="button" x-on:click="copyInvoice()" class="inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-card px-3 text-[13px] text-ink" data-test="zap-copy-invoice">
                                                <x-icon name="copy" :size="16" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy invoice'))">{{ __('Copy invoice') }}</span>
                                            </button>
                                        </span>
                                    </div>
                                </div>

                                <p role="alert" class="m-0 text-xs leading-normal text-loss" x-show="error" x-text="error" x-cloak data-test="zap-error"></p>
                            @endif

                            {{-- Without a signer: the winner's LNURL as a QR code, never the address as text. --}}
                            <details class="group min-w-0" @if (! $viewer) open @endif data-test="zap-lnurl">
                                <summary class="flex min-h-11 cursor-pointer list-none items-center gap-1.5 text-[13px] text-ink-2 hover:text-ink [&::-webkit-details-marker]:hidden">
                                    <x-icon name="next" :size="14" class="shrink-0 transition-transform group-open:rotate-90" />{{ $viewer ? __('No signer? Scan instead') : __('Scan to tip') }}
                                </summary>
                                <div class="flex min-w-0 items-center gap-4 pt-2">
                                    <div class="size-32 shrink-0 rounded-sm bg-white p-2 [&>svg]:size-full" data-test="zap-lnurl-qr">{!! $winner['qr'] !!}</div>
                                    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Scan with a Lightning wallet: a plain payment to :name, without a zap receipt on Nostr.', ['name' => $user->displayName()]) }}</p>
                                </div>
                            </details>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
