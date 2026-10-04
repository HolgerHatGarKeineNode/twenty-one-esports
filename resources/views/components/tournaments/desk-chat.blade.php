@props(['desk'])

{{--
    The tournament desk on the tournament page (App\Support\Tournaments\TournamentDesk, resources/js/deskChat.js): the
    private NIP-17 group of the players and the tournament direction, end-to-end encrypted, never stored by the league.
    Always open (user, 2026-10-04: "Das mit dem Chat ist schon ok, aber leider versteckt hinter einem Button-Klick,
    das ist schlechte UX."): below xl a panel in the page's flow right under the "What to do now" hero, its history
    scrolling inside a fixed height; from xl the page's `.chat-rail` holds it as a sticky side column between the
    header and the dock (resources/css/app.css), like the game chats. The desk buttons on this page and #desk in the
    address bring it into view and put the cursor into its field. A message of the direction carries its mark.
    wire:ignore: the page's poll must not draw over the chat.

    `$desk`: TournamentDesk::for() for the viewer (never null here).
--}}
<section id="desk" wire:ignore aria-labelledby="desk-h" x-data="deskChat(@js($desk))" x-effect="sync()"
         x-on:desk-open.window="$event.detail === {{ (int) $desk['desk'] }} && focusDesk()" x-on:hashchange.window="location.hash === '#desk' && focusDesk()"
         class="flex flex-col overflow-hidden rounded-lg border-t-2 border-btc bg-card shadow-ring" data-test="desk-chat">
    <header class="flex shrink-0 items-center gap-3 border-b border-hairline px-4 py-3 lg:px-5">
        <x-icon name="chat" :size="18" class="mt-0.5 shrink-0 self-start text-btc" />
        <span class="flex min-w-0 grow flex-col">
            <h2 id="desk-h" class="m-0 text-[15px] font-bold">{{ __('Tournament desk') }}</h2>
            <span class="inline-flex items-center gap-1.5 text-xs text-ink-2"><x-icon name="lock" :size="12" class="shrink-0" />{{ __('private to the players and the tournament direction') }}</span>
        </span>
    </header>

    {{-- A fixed height below xl (shorter while nothing was written), the column's height from xl; the history scrolls inside. --}}
    <div class="relative flex min-h-0 flex-col xl:h-auto xl:grow" :class="messages.length > 0 ? 'h-72 sm:h-80' : 'h-48'" data-test="desk-body">
        <ol x-ref="list" x-effect="arrived(messages)" x-on:scroll.passive="onScroll()" role="log" aria-label="{{ __('Tournament desk') }}"
            class="m-0 flex min-h-0 grow list-none flex-col gap-3 overflow-y-auto overscroll-contain px-4 py-3 text-sm leading-normal lg:px-5" data-test="desk-messages">
            <li aria-hidden="true" class="mt-auto"></li>
            <li x-show="hasOlder" class="self-center">
                <button type="button" x-on:click="loadOlder()" class="btn-w inline-flex min-h-11 cursor-pointer items-center rounded-md border border-line bg-transparent px-3 text-xs text-ink-2">{{ __('Earlier messages') }}</button>
            </li>
            {{-- The start of the conversation says what this room is; it scrolls away with the history. --}}
            <li x-show="! hasOlder" class="text-xs leading-normal text-ink-2" data-test="desk-hint">
                @if ($desk['manager'])
                    {{ __('Every player of this tournament reads along. Your messages are marked as the tournament direction.') }}
                @else
                    {{ __('A problem, a bug, a question? Write to the tournament direction here. Every player of this tournament reads along.') }}
                @endif
                {{ __('End-to-end encrypted over Nostr: the league server never receives or stores these messages.') }}
            </li>
            <template x-for="m in visible" :key="m.id">
                <li class="flex max-w-[85%] flex-col gap-1 rounded-md px-3 py-2" :class="[m.from === 'me' ? 'self-end bg-btc-press' : 'self-start bg-well', m.manager ? 'shadow-[inset_0_0_0_1px_var(--color-btc)]' : '']" :data-from="m.from" :data-manager="m.manager ? '1' : '0'">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px]" :class="m.from === 'me' ? 'text-btc-hi' : 'text-ink-2'">
                        <span x-text="m.name + ', ' + time(m.at)"></span>
                        <span x-show="m.manager" class="inline-flex h-5 items-center gap-1 rounded-xs bg-btc-chip px-1.5 font-bold text-btc-hi" data-test="desk-direction"><x-icon name="shield-check" :size="12" /><span x-text="t.direction"></span></span>
                    </span>
                    <span class="break-words" x-text="m.text"></span>
                </li>
            </template>
            <li x-show="status === 'live' && messages.length === 0" class="text-ink-3">{{ __('No messages yet. Ask the tournament direction anything.') }}</li>
            <li x-show="status === 'starting'" class="text-ink-3">{{ __('Connecting to the chat …') }}</li>
            <li x-show="status === 'needs-signer'" class="flex flex-col items-start gap-2 text-ink-2">
                <span>{{ __('Open it to read and write messages.') }}</span>
                <x-button variant="quiet" icon="chat" x-on:click="connect()" data-test="desk-connect">{{ __('Open chat') }}</x-button>
            </li>
            <li x-show="status === 'no-nip44'" class="text-ink-2">{{ __('Your signer cannot encrypt messages (NIP-44), so the chat is off. A Nostr extension or signer app with NIP-44 turns it on.') }}</li>
            <li x-show="status === 'no-relays'" class="text-ink-2">{{ __('The chat has no relay here, so it is off.') }}</li>
            <li x-show="error" class="text-loss" role="alert" x-text="error"></li>
        </ol>
        <button type="button" x-show="unseen > 0" x-cloak x-on:click="toBottom()" data-test="desk-new"
                class="absolute bottom-3 left-1/2 inline-flex h-9 -translate-x-1/2 cursor-pointer items-center gap-1.5 rounded-control bg-btc px-3 text-xs font-bold whitespace-nowrap text-on-btc shadow-[0_8px_24px_rgba(10,10,11,.7)] hover:bg-btc-hi">
            <x-icon name="chevron-down" :size="16" /><span>{{ __('New messages') }}</span><span x-text="'(' + unseen + ')'"></span>
        </button>
    </div>

    <form x-show="status === 'live'" x-on:submit.prevent="send()" class="flex shrink-0 gap-2 border-t border-hairline px-4 pt-3 pb-4 lg:px-5" data-test="desk-form">
        <label for="deskchat" class="sr-only">{{ __('Message to the tournament desk') }}</label>
        <input id="deskchat" x-ref="input" x-model="input" placeholder="{{ __('Message') }}" autocomplete="off" maxlength="500" class="h-11 min-w-0 grow rounded-lg border border-edge bg-ground px-3.5 text-sm text-ink placeholder:text-ink-3">
        <button type="submit" aria-label="{{ __('Send message') }}" :disabled="sending" class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink disabled:opacity-50" data-test="desk-send"><x-icon name="send" :size="16" /></button>
    </form>
</section>
