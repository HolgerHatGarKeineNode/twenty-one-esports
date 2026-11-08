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
@if ($m->joined_at !== null)
{{-- Both in (R20 "folded after confirmation", boards MatchRoom-4a … 4c): the card shrinks to one line, name and password stay readable with their copy buttons. --}}
<div wire:key="lobby-pin-{{ $hostSide }}-joined" wire:ignore x-data="lobbyPin(@js(['sharedBy' => __('Shared by :name at :time'), 'sharedByMe' => __('Shared by you at :time')]))" data-test="lobby-pin" data-compact="1">
    <template x-if="card">
        <section aria-label="{{ __('Lobby of :name', ['name' => $m->sideName($hostSide)]) }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[4px] border border-btc-ring bg-feature px-4 py-2.5" data-test="lobby-pin-card">
            <span class="flex items-center gap-1.5 text-[13px] text-ink-3"><x-icon name="lock" :size="14" class="shrink-0 text-btc-hi" /><span class="sm:sr-only">{{ __('Lobby of :name', ['name' => $m->sideName($hostSide)]) }}</span></span>
            @foreach (['name' => __('Name'), 'password' => __('Password')] as $field => $label)
                <span class="flex min-w-0 items-center gap-2 max-sm:w-full">
                    <span class="rv-m w-16 shrink-0 text-[13px] sm:w-auto">{{ $label }}</span>
                    <b class="min-w-0 font-mono text-[15px] [overflow-wrap:anywhere] max-sm:grow" x-text="card.{{ $field }}" data-test="lobby-pin-{{ $field }}"></b>
                    <button type="button" x-on:click="copy(card.{{ $field }}, '{{ $field }}')" aria-label="{{ $field === 'name' ? __('Copy lobby name') : __('Copy password') }}" data-test="lobby-pin-copy-{{ $field }}" class="rv-b2 size-11 shrink-0 px-0">
                        <span x-show="copied !== '{{ $field }}'"><x-icon name="copy" :size="14" /></span><span x-show="copied === '{{ $field }}'" x-cloak class="text-win"><x-icon name="check" :size="14" /></span>
                    </button>
                </span>
            @endforeach
            <span class="grow max-sm:hidden"></span>
            <span class="rv-tag is-ok"><x-icon name="check" :size="12" />{{ __('both joined') }}</span>
            @if ($iHost)
                {{-- The host keeps Replace and Close in the result step: a lobby that crashed mid-series gets a new card. --}}
                <span class="flex flex-wrap gap-2">
                    <x-button variant="quiet" icon="retry" x-on:click="act('compose')" ::disabled="pin.busy" data-test="lobby-pin-replace">{{ __('Replace') }}</x-button>
                    <x-button variant="quiet" icon="close" x-on:click="act('close')" ::disabled="pin.busy" data-test="lobby-pin-close">{{ __('Close lobby') }}</x-button>
                </span>
            @endif
        </section>
    </template>
    @if ($iHost)
        {{-- A closed card in the result step: the host can share a new one, as in the lobby step. --}}
        <template x-if="! card">
            <div><x-button variant="secondary" icon="key" x-on:click="act('compose')" ::disabled="pin.busy" data-test="casual-share">{{ __('Share lobby') }}</x-button></div>
        </template>
    @endif
</div>
@else
<div wire:key="lobby-pin-{{ $hostSide }}-{{ $running ? 'run' : 'wait' }}-{{ $pinShared ? 'shared' : 'open' }}-{{ $guestJoins ? 'join' : 'in' }}" wire:ignore
     x-data="lobbyPin(@js(['sharedBy' => __('Shared by :name at :time'), 'sharedByMe' => __('Shared by you at :time')]))" data-test="lobby-pin">
    <template x-if="card">
        {{-- Board MatchRoom-2c: the host's lobby, name and password in plain 22 px mono, each with its copy button. --}}
        <div class="rv-lobby" data-test="lobby-pin-card">
            <div class="flex items-start gap-3">
                <x-game-cover :game="$m->game" size="thumb" class="h-10 w-[72px] shrink-0 rounded-[2px] object-cover max-sm:hidden" />
                <span class="flex min-w-0 grow flex-col gap-0.5">
                    <b class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-[15px] text-ink [overflow-wrap:anywhere]"><x-icon name="lock" :size="14" class="shrink-0 text-btc-hi" /><span class="min-w-0">{{ __('Lobby of :name', ['name' => $m->sideName($hostSide)]) }} <span class="font-normal text-ink-2">({{ __('host') }})</span></span></b>
                    <span class="rv-m text-[13px]" x-text="sharedLine" data-test="lobby-pin-by"></span>
                </span>
            </div>
            <b class="text-[15px] max-sm:hidden">{{ __('Join this lobby in :game', ['game' => $isRl ? 'Rocket League' : 'Age of Empires II']) }}</b>
            @foreach (['name' => __('Name'), 'password' => __('Password')] as $field => $label)
                <div class="flex min-w-0 flex-col gap-1">
                    <span class="rv-m text-[13px]">{{ $label }}</span>
                    <div class="flex min-w-0 gap-2">
                        <b class="rv-cred" x-text="card.{{ $field }}" data-test="lobby-pin-{{ $field }}"></b>
                        <button type="button" x-on:click="copy(card.{{ $field }}, '{{ $field }}')" aria-label="{{ $field === 'name' ? __('Copy lobby name') : __('Copy password') }}" data-test="lobby-pin-copy-{{ $field }}"
                                class="rv-b2 rv-cred-copy max-sm:w-11 max-sm:px-0">
                            <span x-show="copied !== '{{ $field }}'" class="inline-flex items-center gap-2"><x-icon name="copy" :size="16" /><span class="max-sm:sr-only">{{ $field === 'name' ? __('Copy name') : __('Copy password') }}</span></span>
                            <span x-show="copied === '{{ $field }}'" x-cloak class="inline-flex items-center gap-2 text-win"><x-icon name="check" :size="16" /><span class="max-sm:sr-only">{{ __('Copied') }}</span></span>
                        </button>
                    </div>
                </div>
            @endforeach
            @if ($iHost)
                @if ($running && ! $pinShared)
                    {{-- An open card the league has not counted (sent before the check-in): one click re-sends it to the opponent and counts it. --}}
                    <p class="m-0 border-t border-hairline pt-2 text-xs leading-normal text-ink-2">{{ __('Still open? Confirm it, and :name gets it again.', ['name' => $otherName]) }}</p>
                    <x-button icon="check" x-on:click="act('confirm')" ::disabled="pin.busy" class="{{ $big }} max-lg:hidden" data-room-primary data-test="lobby-pin-confirm">{{ __('Still valid') }}</x-button>
                @endif
                <span class="flex flex-wrap gap-2">
                    <x-button variant="quiet" icon="retry" x-on:click="act('compose')" ::disabled="pin.busy" data-test="lobby-pin-replace">{{ __('Replace') }}</x-button>
                    <x-button variant="quiet" icon="close" x-on:click="act('close')" ::disabled="pin.busy" data-test="lobby-pin-close">{{ __('Close lobby') }}</x-button>
                </span>
            @elseif ($running && ! $pinShared)
                <p class="m-0 border-t border-hairline pt-2 text-xs leading-normal text-ink-2">{{ __('Waiting for :name to confirm the lobby is still valid.', ['name' => $otherName]) }}</p>
            @elseif ($guestJoins)
                <x-button icon="check" x-on:click="$wire.casualJoined()" class="{{ $big }} max-lg:hidden" data-room-primary data-test="casual-joined">{{ __('I am in the lobby') }}</x-button>
            @endif
        </div>
    </template>
    @if ($running)
        <template x-if="! card">
            {{-- Boards 2a (host shares) and 2b (guest waits): the same card, with the two fields still empty. --}}
            <div class="rv-lobby text-[13px] leading-normal text-ink-2" aria-busy="{{ ! $iHost && ! $pinShared ? 'true' : 'false' }}">
                <div class="flex items-start gap-3">
                    <x-game-cover :game="$m->game" size="thumb" class="h-10 w-[72px] shrink-0 rounded-[2px] object-cover max-sm:hidden" />
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <b class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-[15px] text-ink [overflow-wrap:anywhere]"><x-icon name="lock" :size="14" class="shrink-0 text-btc-hi" /><span class="min-w-0">{{ __('Lobby of :name', ['name' => $m->sideName($hostSide)]) }} <span class="font-normal text-ink-2">({{ __('host') }})</span></span></b>
                        @if ($iHost)
                            @unless ($pinShared)
                                <span class="rv-m text-[13px]">{{ $isRl ? __('Create a private match in Rocket League and share its name and password in the chat.') : __('Host a lobby in Age of Empires II with a password and spectators allowed, then share its name and password in the chat.') }}</span>
                            @endunless
                        @elseif (! $pinShared)
                            <span class="rv-m text-[13px]">{{ __('Waiting for :name to share the lobby in the chat.', ['name' => $otherName]) }}</span>
                        @elseif ($guestJoins)
                            {{-- Shared, but no card on screen here (the chat is closed, or the host closed it): the steps' own words, and the action. --}}
                            <span class="rv-m text-[13px]">{{ $isRl ? __('Join the private match with the name and password from the lobby card, then confirm here.') : __('Find the lobby by its name in the lobby browser, join with the password from the lobby card, then confirm here.') }}</span>
                        @endif
                    </span>
                </div>
                @if (! $iHost && ! $pinShared)
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" aria-hidden="true">
                        @foreach ([__('Name'), __('Password')] as $label)
                            <span class="flex flex-col gap-1"><span class="rv-m text-[13px]">{{ $label }}</span><span class="block h-14 rounded-[4px] border border-dashed border-dash bg-card"></span></span>
                        @endforeach
                    </div>
                @endif
                @if ($iHost)
                    <div><x-button icon="key" x-on:click="act('compose')" ::disabled="pin.busy" class="{{ $big }} max-lg:hidden" data-room-primary data-test="casual-share">{{ __('Share lobby') }}</x-button></div>
                @elseif ($guestJoins)
                    <div><x-button icon="check" x-on:click="$wire.casualJoined()" class="{{ $big }} max-lg:hidden" data-room-primary data-test="casual-joined">{{ __('I am in the lobby') }}</x-button></div>
                @endif
            </div>
        </template>
    @endif
    <p x-show="pin.error" x-cloak x-text="pin.error" class="m-0 mt-2 text-xs text-loss" role="alert" data-test="lobby-pin-error"></p>
</div>
@endif
