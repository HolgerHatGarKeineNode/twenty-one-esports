<?php

use App\Models\User;
use App\Support\Nostr\Nip05Names;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Nostr address (P47): an optional NIP-05 name on the league's domain,
 * `name@<app host>`, served from /.well-known/nostr.json. Opt-in: nothing is
 * served until the player claims a name. The rules live in Nip05Names:
 * lowercase a-z, 0-9, dot, underscore, hyphen; unique; reserved names; a
 * change limit; the account's deletion releases it; an admin can revoke it.
 *
 * The league never writes the player's profile (kind 0): the page tells the
 * player to put the address into their own Nostr app.
 */
new #[Title('Nostr address')] class extends Component {
    public string $name = '';

    public string $error = '';

    public bool $confirmRelease = false;

    public function mount(): void
    {
        $this->name = $this->user()->nip05_name ?? '';
    }

    public function claim(Nip05Names $names): void
    {
        $this->error = '';
        $this->name = Nip05Names::normalize($this->name);
        $problem = $names->claim($this->user(), $this->name);

        if ($problem !== null) {
            $this->error = $problem;

            return;
        }

        $this->dispatch('nip05-saved');
    }

    public function release(Nip05Names $names): void
    {
        if (! $this->confirmRelease) {
            $this->confirmRelease = true;

            return;
        }

        $names->release($this->user());
        $this->confirmRelease = false;
        $this->name = '';
        $this->error = '';
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $user = auth()->user();
    $names = app(Nip05Names::class);
    $domain = Nip05Names::domain();
    $address = Nip05Names::address($user);
    $next = $names->nextChangeAt($user);
    $tz = \App\Support\PreSeason::timezoneFor($user);
    $inProfile = $address !== null && $user->nip05 === $address;
    $field = 'h-11 min-w-0 grow rounded-md border border-edge bg-ground px-3 font-mono text-[13px] text-ink';
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="nip05-settings">
    <x-settings.header current="nip05" />

    <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,560px)_minmax(0,400px)]">
        <section aria-labelledby="n5-h" class="flex min-w-0 flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="nip05-claim">
            <h2 id="n5-h" class="m-0 text-[15px] font-bold">{{ __('Your address on :domain', ['domain' => $domain]) }}</h2>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Optional. A NIP-05 address makes your key easy to find and shows that you play here. Nostr apps check it against this site.') }}</p>

            @if ($user->nip05_revoked_name !== null && $address === null)
                <p class="m-0 rounded-md bg-well px-3 py-2 text-[13px] leading-normal text-loss" role="alert" data-test="nip05-revoked">{{ __('An admin took back :name@:domain. Pick another name.', ['name' => $user->nip05_revoked_name, 'domain' => $domain]) }}</p>
            @endif

            @if ($address !== null)
                <div class="flex min-w-0 flex-col gap-2 rounded-md bg-well px-3 py-3" data-test="nip05-current">
                    <span class="text-xs text-ink-2">{{ __('Your address') }}</span>
                    <span class="flex min-w-0 flex-wrap items-center gap-2" x-data="{ copied: false }">
                        <b class="min-w-0 font-mono text-sm [overflow-wrap:anywhere]" data-test="nip05-address">{{ $address }}</b>
                        <button type="button" class="inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-card px-3 text-[13px] text-ink" data-test="nip05-copy"
                                x-on:click="navigator.clipboard.writeText(@js($address)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                            <x-icon name="copy" :size="16" x-show="! copied" /><x-icon name="check" :size="16" x-show="copied" x-cloak class="text-btc" /><span>{{ __('Copy') }}</span>
                        </button>
                    </span>
                    @if ($inProfile)
                        <span class="flex items-center gap-1.5 text-xs text-win" data-test="nip05-in-profile"><x-icon name="shield-check" :size="14" />{{ __('Your Nostr profile names it.') }}</span>
                    @else
                        <span class="text-xs leading-normal text-ink-2" data-test="nip05-not-in-profile">{{ __('Next step: open your Nostr app, edit your profile and put this address into the NIP-05 field (sometimes called "Nostr address" or "Verified"). This site never changes your profile.') }}</span>
                    @endif
                </div>
            @endif

            <form wire:submit="claim" class="flex min-w-0 flex-col gap-2">
                <label for="nip05-name" class="text-sm">{{ $address === null ? __('Pick a name') : __('Change your name') }}</label>
                <span class="flex min-w-0 flex-wrap items-center gap-2">
                    <span class="flex min-w-0 grow basis-60 items-center gap-1.5">
                        <input id="nip05-name" type="text" wire:model="name" maxlength="{{ (int) config('esports.nip05.max_length') }}" autocomplete="off" autocapitalize="none" spellcheck="false"
                               pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" class="{{ $field }}" data-test="nip05-input" aria-describedby="nip05-rules">
                        <span class="shrink-0 font-mono text-[13px] text-ink-2 max-sm:max-w-[45%] max-sm:truncate" title="{{ '@'.$domain }}">{{ '@'.$domain }}</span>
                    </span>
                    <x-button type="submit" data-test="nip05-save" :disabled="$next !== null" class="disabled:cursor-default disabled:opacity-60">{{ $address === null ? __('Claim') : __('Change') }}</x-button>
                </span>
                <span id="nip05-rules" class="text-xs leading-normal text-ink-2">{{ __(':min to :max characters: a to z, 0 to 9, dot, underscore, hyphen. You can change it once every :days days.', ['min' => (int) config('esports.nip05.min_length'), 'max' => (int) config('esports.nip05.max_length'), 'days' => (int) config('esports.nip05.change_days')]) }}</span>
                @if ($next !== null)
                    <span class="text-xs text-ink-2" data-test="nip05-next">{{ __('You can pick a new name from :date on.', ['date' => $next->copy()->timezone($tz)->translatedFormat('j M Y, H:i')]) }}</span>
                @endif
                @if ($error !== '')
                    <span class="text-xs text-loss" role="alert" data-test="nip05-error">{{ $error }}</span>
                @endif
                <span role="status" class="flex items-center gap-1.5 text-[13px] text-win" x-data="{ shown: false }" x-show="shown" x-cloak
                      x-on:nip05-saved.window="shown = true; setTimeout(() => shown = false, 2000)" data-test="nip05-saved"><x-icon name="check" :size="16" />{{ __('Saved.') }}</span>
            </form>
        </section>

        @if ($address !== null)
            <section aria-labelledby="n5-rel-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="nip05-release">
                <h2 id="n5-rel-h" class="m-0 text-[15px] font-bold">{{ __('Give the name up') }}</h2>
                <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('The address stops working at once, and someone else may claim the name. Your next name counts as a change. Deleting your account gives it up too.') }}</p>
                <button type="button" wire:click="release" data-test="nip05-release-button"
                        class="inline-flex h-11 cursor-pointer items-center justify-center self-start rounded-md border border-loss px-[18px] text-[13px] text-loss hover:bg-loss-tint">{{ $confirmRelease ? __('Yes, give it up') : __('Give it up') }}</button>
            </section>
        @endif
    </div>
</div>
