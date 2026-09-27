{{--
    The stream chat on /live (P24): NIP-53 kind 1311 under the stream's
    30311, read and written in the browser (resources/js/liveChat.js). From
    lg a column next to the stage as tall as the window allows; below lg the
    "Chat" tab under the stage. wire:ignore: the page's 30-second poll never
    morphs the message list.

    $chat: StreamChat::config(), or null when the stream has no address.
--}}
@php
    $guest = ($chat['me'] ?? null) === null;
    $pickerOptions = $chat === null ? [] : ['locale' => $chat['locale'], 'me' => $chat['me'], 'relays' => $chat['emojiRelays'], 'labels' => ['yourEmoji' => $chat['labels']['yourEmoji'], 'insert' => $chat['labels']['insert']]];
@endphp
<section id="live-chat" aria-labelledby="live-chat-h" wire:ignore data-test="live-chat" class="{{ $class ?? '' }} flex min-h-0 flex-col overflow-hidden rounded-lg bg-card"
         x-bind:class="tab === 'chat' ? '' : 'max-lg:hidden'"
         @if ($chat) x-data="liveChat(@js($chat))" x-on:live-chat-shown.window="shown()" @endif>
    <div class="flex shrink-0 items-center border-b border-hairline px-4 py-3">
        <h2 id="live-chat-h" class="m-0 text-[15px] font-bold">{{ __('Chat') }}</h2>
    </div>

    @if ($chat === null)
        <p class="m-0 px-4 py-4 text-[13px] leading-5 text-ink-2">{{ __('The chat needs the stream to be announced on Nostr, and it is not yet.') }}</p>
    @else
        <div class="relative flex min-h-0 grow flex-col">
            <ol x-ref="list" x-on:scroll.passive="onScroll()" role="log" aria-label="{{ __('Chat messages') }}" data-test="live-chat-list"
                class="m-0 flex min-h-0 grow list-none flex-col overflow-y-auto overscroll-contain px-2 py-2">
                {{-- Pushes a short list to the bottom, where the newest message is. --}}
                <li aria-hidden="true" class="grow"></li>
                <template x-for="row in rows" :key="row.key">
                    <li class="flex flex-col" :data-type="row.type" :data-muted="row.type === 'muted' ? 'true' : null"
                        :class="row.cont ? 'pt-0.5' : 'pt-2'">
                        {{-- A run of muted messages: one line, opened on request. --}}
                        <template x-if="row.type === 'muted'">
                            <span class="flex min-h-8 items-center gap-2 px-2 text-xs text-ink-3" data-test="live-chat-muted">
                                <x-icon name="mute" :size="14" />
                                <span class="min-w-0 grow" x-text="mutedLabel(row)"></span>
                                <button type="button" x-on:click="toggleReveal(row)" :aria-expanded="isRevealed(row).toString()"
                                        class="inline-flex min-h-8 cursor-pointer items-center rounded-control px-2 text-ink-2 underline decoration-edge underline-offset-2 hover:text-ink"
                                        x-text="isRevealed(row) ? t.hide : t.show"></button>
                            </span>
                        </template>

                        {{-- A zap: the amount in the display face, the one loud line in the list. --}}
                        <template x-if="row.type === 'zap'">
                            <div class="mx-1 flex flex-col gap-1 rounded-control bg-btc-chip py-2 pr-3 pl-3 shadow-[inset_2px_0_0_var(--color-bolt)]" data-test="live-chat-zap">
                                <span class="flex items-center gap-2 text-bolt">
                                    <x-icon name="bolt" :size="16" />
                                    <span class="sr-only">{{ __('Zap') }}</span>
                                    <b class="font-display text-base leading-tight font-bold tabular-nums" x-text="sats(row.item.sats)"></b>
                                </span>
                                <span class="flex min-w-0 items-center gap-2 text-xs text-ink-2">
                                    <img :src="avatarOf(row.item.pubkey)" alt="" width="16" height="16" referrerpolicy="no-referrer" loading="lazy"
                                         x-on:error="$el.src = generatedAvatar(row.item.pubkey)" class="size-4 shrink-0 rounded-full bg-raised object-cover">
                                    <span class="truncate" x-text="nameOf(row.item.pubkey)"></span>
                                </span>
                                <p x-show="row.item.tokens.length" class="m-0 text-sm leading-normal break-words text-ink">
                                    <template x-for="(token, i) in row.item.tokens" :key="i">@include('pages.live.chat-token')</template>
                                </p>
                            </div>
                        </template>

                        <template x-if="row.type === 'message'">
                            <div class="group flex gap-2.5 rounded-control px-2 py-0.5 hover:bg-row-hover" :class="row.revealed ? 'shadow-[inset_2px_0_0_var(--color-edge)]' : ''" data-test="live-chat-message" :data-pubkey="row.item.pubkey">
                                <img :src="avatarOf(row.item.pubkey)" alt="" width="24" height="24" referrerpolicy="no-referrer" loading="lazy"
                                     x-on:error="$el.src = generatedAvatar(row.item.pubkey)"
                                     :class="row.cont ? 'invisible h-0' : ''"
                                     class="mt-0.5 size-6 shrink-0 rounded-full bg-raised object-cover">
                                <div class="flex min-w-0 grow flex-col">
                                    <span class="flex min-w-0 items-baseline gap-2"
                                          x-show="! row.cont">
                                        <button type="button" x-on:click="toggleMenu(row.item.id)" :aria-expanded="(menuFor === row.item.id).toString()" :disabled="row.item.pubkey === me"
                                                class="min-h-6 min-w-0 cursor-pointer truncate text-left text-xs font-bold disabled:cursor-default"
                                                :class="row.item.pubkey === me ? 'text-btc-hi' : 'text-ink-2 hover:text-ink'" x-text="nameOf(row.item.pubkey)"></button>
                                        <span x-show="isBot(row.item.pubkey)" class="shrink-0 rounded-tag px-1 text-[11px] leading-4 text-proof shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" data-test="live-chat-bot" x-text="t.bot"></span>
                                        {{-- Calls itself a bot: no badge (anyone can say so), its key instead, so a look-alike of ours is told apart. --}}
                                        <span x-show="selfBot(row.item.pubkey)" class="min-w-0 shrink truncate text-[11px] text-ink-3" data-test="live-chat-npub" x-text="shortNpub(row.item.pubkey)"></span>
                                        <time class="ml-auto shrink-0 text-[11px] text-ink-3 tabular-nums" :datetime="new Date(row.item.created_at * 1000).toISOString()" x-text="time(row.item.created_at)"></time>
                                    </span>
                                    <p class="m-0 text-sm leading-normal break-words text-ink" data-test="live-chat-text">
                                        <template x-for="(token, i) in row.item.tokens" :key="i">@include('pages.live.chat-token')</template>
                                    </p>
                                    <span x-show="menuFor === row.item.id" x-cloak class="flex flex-wrap gap-2 pt-1.5 pb-1">
                                        <button type="button" x-on:click="setMuted(row.item.pubkey, ! isMuted(row.item.pubkey))" data-test="live-chat-mute"
                                                class="btn-w inline-flex h-8 cursor-pointer items-center gap-2 rounded-control border border-line bg-well px-2.5 text-xs text-ink">
                                            <x-icon name="mute" :size="14" /><span x-text="muteLabel(row.item.pubkey)"></span>
                                        </button>
                                    </span>
                                </div>
                            </div>
                        </template>
                    </li>
                </template>
                <li x-show="status === 'connecting'" class="px-2 py-2 text-[13px] text-ink-3">{{ __('Connecting to the chat …') }}</li>
                <li x-show="status === 'live' && items.length === 0" x-cloak class="px-2 py-2 text-[13px] leading-5 text-ink-3" data-test="live-chat-empty">{{ __('No messages yet. Say hello to the stream.') }}</li>
                <li x-show="status === 'off'" x-cloak class="px-2 py-2 text-[13px] leading-5 text-ink-2">{{ __('The chat has no relay here, so it is off.') }}</li>
            </ol>

            {{-- Scrolled up: new messages are counted here instead of pulling the reader down. --}}
            <button type="button" x-show="unseen > 0 && ! atBottom" x-cloak x-transition.opacity.duration.150ms x-on:click="scrollToBottom(true)" data-test="live-chat-new"
                    class="absolute bottom-3 left-1/2 inline-flex h-9 -translate-x-1/2 cursor-pointer items-center gap-1.5 rounded-control bg-btc px-3 text-xs font-bold whitespace-nowrap text-on-btc shadow-[0_8px_24px_rgba(10,10,11,.7)] hover:bg-btc-hi">
                <x-icon name="chevron-down" :size="16" /><span x-text="unseenLabel"></span>
            </button>
        </div>

        @if ($guest)
            <div class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-t border-hairline px-4 py-3" data-test="live-chat-guest">
                <x-button variant="quiet" icon="chat" :href="route('login', ['then' => 'live'])">{{ __('Log in to chat') }}</x-button>
                <span class="text-xs text-ink-3">{{ __('Reading is open to everyone.') }}</span>
            </div>
        @else
            <form x-show="status !== 'off'" x-on:submit.prevent="send()" class="flex shrink-0 flex-col gap-1.5 border-t border-hairline px-3 pt-3 pb-3" data-test="live-chat-form">
                <div class="flex items-center gap-2">
                    <label for="live-chat-input" class="sr-only">{{ __('Message to the stream chat') }}</label>
                    <div class="relative flex min-w-0 grow items-center">
                        <input id="live-chat-input" x-ref="composer" x-model="input" placeholder="{{ __('Say something') }}" autocomplete="off" enterkeyhint="send"
                               maxlength="{{ $chat['maxLength'] * 2 }}" :aria-invalid="(remaining < 0).toString()" aria-describedby="live-chat-status" data-test="live-chat-input"
                               class="h-11 w-full min-w-0 rounded-lg border border-edge bg-ground px-3.5 text-base text-ink placeholder:text-ink-3 lg:text-sm"
                               :class="pointerFine ? 'pr-11' : ''">
                        {{-- Pointer devices only, as in einundzwanzig-group: touch keyboards have their own emoji.
                             x-if, not x-show: on touch the picker is never built at all. --}}
                        <template x-if="pointerFine">
                            <div class="absolute top-1/2 right-1 -translate-y-1/2" x-data="emojiPopover()" x-on:keydown.escape.window="close(true)">
                                <button type="button" x-ref="trigger" x-on:click="toggle()" :aria-expanded="open.toString()" aria-label="{{ __('Insert emoji') }}" title="{{ __('Insert emoji') }}" data-test="live-chat-emoji"
                                        class="inline-flex size-9 cursor-pointer items-center justify-center rounded-control text-ink-2 hover:bg-row-hover hover:text-ink" :class="open ? 'text-btc' : ''">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01"></path></svg>
                                </button>
                                <template x-if="open">
                                    <div>
                                        <template x-teleport="body">
                                            <div data-emoji-panel role="dialog" aria-label="{{ __('Insert emoji') }}" :style="panelStyle" x-on:click.outside="closeUnless($event)"
                                                 x-init="$nextTick(() => $el.querySelector('input[type=search]')?.focus()); new ResizeObserver(() => reposition()).observe($el)"
                                                 class="fixed z-50 rounded-card bg-bar p-2 shadow-[0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]">
                                                <x-emoji-picker :options="$pickerOptions" />
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                    <button type="submit" aria-label="{{ __('Send message') }}" :disabled="sending" data-test="live-chat-send"
                            class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink disabled:opacity-50"><x-icon name="send" :size="16" /></button>
                </div>
                {{-- Under the field: that the message is public, until there is something more urgent to say. --}}
                <p id="live-chat-status" class="m-0 flex items-start justify-between gap-3 px-1 text-xs leading-4">
                    <span class="text-loss" role="alert" x-show="error" x-text="error" data-test="live-chat-error"></span>
                    <span class="text-ink-3" x-show="! error">{{ __('Public on Nostr, visible in every client.') }}</span>
                    <span x-show="remaining < 40" x-cloak class="shrink-0 tabular-nums" :class="remaining < 0 ? 'text-loss' : 'text-ink-3'" x-text="remaining" data-test="live-chat-remaining"></span>
                </p>
            </form>
        @endif
    @endif
</section>
