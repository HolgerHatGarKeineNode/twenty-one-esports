{{--
    The pinned lobby card (2026-10-02): the host's open card, read from the decrypted chat in this browser
    (roomChat publishes it to Alpine.store('lobbyPin')); name and password never reach the league, nor
    this component's state. wire:ignore keeps the drawn card across renders; the key re-creates it when
    what the league decides here changes: the host seat, the flow running, the lobby counted as shared.
    Included by partials/casual-steps (Rocket League and Age of Empires II, a player, the match accepted and
    started): above the clock while the flow runs, under the check-in before. Needs its $m, $hostSide,
    $running, $iHost, $isRl, $otherName and $big.
--}}
@php
    $pinShared = $m->lobby_shared_at !== null;
    // The guest's step "Joined" (CasualMatches::markJoined()): offered with the card, as the steps did before (no claim pending).
    $guestJoins = ! $iHost && $running && $pinShared && $m->joined_at === null && $m->noshow_reported_at === null;
@endphp
<div wire:key="lobby-pin-{{ $hostSide }}-{{ $running ? 'run' : 'wait' }}-{{ $pinShared ? 'shared' : 'open' }}-{{ $guestJoins ? 'join' : 'in' }}" wire:ignore
     x-data="lobbyPin(@js(['sharedBy' => __('Shared by :name at :time'), 'sharedByMe' => __('Shared by you at :time')]))" data-test="lobby-pin">
    <template x-if="card">
        <div class="flex flex-col gap-2 rounded-md bg-well px-4 py-3 shadow-[inset_0_0_0_1px_#B9640A]" data-test="lobby-pin-card">
            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <b class="flex items-center gap-1.5 text-[13px]"><x-icon name="key" :size="14" class="shrink-0 text-btc-hi" />{{ __('Join this lobby in :game', ['game' => $isRl ? 'Rocket League' : 'Age of Empires II']) }}</b>
                <span class="text-[11px] text-ink-2" x-text="sharedLine" data-test="lobby-pin-by"></span>
            </span>
            @foreach (['name' => __('Name'), 'password' => __('Password')] as $field => $label)
                <span class="grid min-h-11 grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-2 border-t border-hairline pt-2 text-[13px]">
                    <span class="text-xs text-ink-2">{{ $label }}</span>
                    <b class="font-mono break-all" x-text="card.{{ $field }}" data-test="lobby-pin-{{ $field }}"></b>
                    <button type="button" x-on:click="copy(card.{{ $field }}, '{{ $field }}')" aria-label="{{ $field === 'name' ? __('Copy lobby name') : __('Copy password') }}" data-test="lobby-pin-copy-{{ $field }}"
                            class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-card text-ink-2">
                        <span x-show="copied !== '{{ $field }}'"><x-icon name="copy" :size="16" /></span>
                        <span x-show="copied === '{{ $field }}'" x-cloak class="text-win"><x-icon name="check" :size="16" /></span>
                    </button>
                </span>
            @endforeach
            @if ($iHost)
                @if ($running && ! $pinShared)
                    {{-- An open card the league has not counted (sent before the check-in): one click re-sends it to the opponent and counts it. --}}
                    <p class="m-0 border-t border-hairline pt-2 text-xs leading-normal text-ink-2">{{ __('Still open? Confirm it, and :name gets it again.', ['name' => $otherName]) }}</p>
                    <x-button icon="check" x-on:click="act('confirm')" ::disabled="pin.busy" class="{{ $big }}" data-test="lobby-pin-confirm">{{ __('Still valid') }}</x-button>
                @endif
                <span class="flex flex-wrap gap-2">
                    <x-button variant="quiet" icon="retry" x-on:click="act('compose')" ::disabled="pin.busy" data-test="lobby-pin-replace">{{ __('Replace') }}</x-button>
                    <x-button variant="quiet" icon="close" x-on:click="act('close')" ::disabled="pin.busy" data-test="lobby-pin-close">{{ __('Close lobby') }}</x-button>
                </span>
            @elseif ($running && ! $pinShared)
                <p class="m-0 border-t border-hairline pt-2 text-xs leading-normal text-ink-2">{{ __('Waiting for :name to confirm the lobby is still valid.', ['name' => $otherName]) }}</p>
            @elseif ($guestJoins)
                <x-button icon="check" x-on:click="$wire.casualJoined()" class="{{ $big }}" data-test="casual-joined">{{ __('I am in the lobby') }}</x-button>
            @endif
        </div>
    </template>
    @if ($running)
        <template x-if="! card">
            <div class="flex flex-col gap-3 text-[13px] leading-normal text-ink-2">
                @if ($iHost)
                    @unless ($pinShared)
                        <p class="m-0">{{ $isRl ? __('Create a private match in Rocket League and share its name and password in the chat.') : __('Host a lobby in Age of Empires II with a password and spectators allowed, then share its name and password in the chat.') }}</p>
                    @endunless
                    <div><x-button icon="key" x-on:click="act('compose')" ::disabled="pin.busy" class="{{ $big }}" data-test="casual-share">{{ __('Share lobby') }}</x-button></div>
                @elseif (! $pinShared)
                    <p class="m-0">{{ __('Waiting for :name to share the lobby in the chat.', ['name' => $otherName]) }}</p>
                @elseif ($guestJoins)
                    {{-- Shared, but no card on screen here (the chat is closed, or the host closed it): the steps' own words, and the action. --}}
                    <p class="m-0">{{ $isRl ? __('Join the private match with the name and password from the lobby card, then confirm here.') : __('Find the lobby by its name in the lobby browser, join with the password from the lobby card, then confirm here.') }}</p>
                    <div><x-button icon="check" x-on:click="$wire.casualJoined()" class="{{ $big }}" data-test="casual-joined">{{ __('I am in the lobby') }}</x-button></div>
                @endif
            </div>
        </template>
    @endif
    <p x-show="pin.error" x-cloak x-text="pin.error" class="m-0 mt-2 text-xs text-loss" role="alert" data-test="lobby-pin-error"></p>
</div>
