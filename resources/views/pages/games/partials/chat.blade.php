{{--
    Game chat (ChessGame.dc.html "Chat", MobileChessGame.dc.html bottom sheet):
    NIP-17 between the two players, over the chat relays, straight from the
    browser (resources/js/gameChat.js). One Alpine instance drives both the
    desktop panel (lg and up, a cell of the game grid) and the mobile sheet.

    $chat: config for gameChat() (me, opponent, relays, muted, match, labels)
--}}
<div class="contents" x-data="gameChat(@js($chat))" data-test="chat">
    @php($opponentName = $chat['opponent']['name'] ?? '')

    {{-- Desktop panel --}}
    <section aria-labelledby="chat-h" class="hidden min-h-[420px] flex-col rounded-lg bg-card lg:order-none lg:col-span-2 lg:flex min-[87.5rem]:col-span-1 min-[87.5rem]:col-start-3 min-[87.5rem]:row-span-4 min-[87.5rem]:row-start-1 min-[87.5rem]:mt-4 min-[87.5rem]:h-[640px]">
        <div class="flex flex-col gap-1 border-b border-hairline px-4 py-2.5">
            <span class="flex items-center justify-between gap-2">
                <span id="chat-h" class="text-[15px] font-bold">{{ __('Chat') }}</span>
                @if ($chat['opponent'])
                    <button type="button" x-on:click="toggleMute()" :aria-pressed="opponentMuted ? 'true' : 'false'" data-test="mute"
                            class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3 text-xs whitespace-nowrap text-ink">
                        <x-icon name="mute" :size="16" class="shrink-0" />
                        <span x-text="opponentMuted ? t.muted : t.mute"></span>
                    </button>
                @endif
            </span>
            <span class="text-[11px] leading-normal text-ink-3">
                @if ($chat['opponent'])
                    {{ __('Only you and :name see this chat. It is not part of the game record. Muting hides their messages for you.', ['name' => $opponentName]) }}
                @else
                    {{ __('The two players chat privately. Spectators do not see it.') }}
                @endif
            </span>
        </div>
        @include('pages.games.partials.chat-body', ['inputId' => 'chatin'])
    </section>

    {{-- Mobile sheet --}}
    @if ($chat['opponent'])
        <div class="lg:hidden" x-data="{ open: false, seen: 0 }" x-effect="open && (seen = messages.length)">
            <div x-show="open" x-cloak aria-hidden="true" class="fixed inset-0 z-30 bg-[rgba(10,10,11,.6)]" x-on:click="open = false"></div>
            <section role="dialog" :aria-modal="open ? 'true' : 'false'" aria-labelledby="sheet-h"
                     class="fixed inset-x-0 bottom-0 z-40 flex flex-col rounded-t-2xl bg-bar shadow-[0_-1px_0_#2A2A30,0_-16px_32px_rgba(10,10,11,.8)]"
                     :class="open ? 'h-[min(520px,80svh)] animate-drop-in' : 'h-[72px]'">
                <button type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="sheet-body" data-test="chat-sheet-toggle"
                        class="flex min-h-[72px] shrink-0 cursor-pointer flex-col items-stretch gap-2 border-0 bg-transparent px-4 pt-2 pb-3 text-left text-ink">
                    <span aria-hidden="true" class="h-1 w-10 self-center rounded-xs bg-edge"></span>
                    <span class="flex items-center gap-2.5">
                        <x-icon name="chat-sheet" :size="18" class="text-ink-2" />
                        <span id="sheet-h" class="text-sm font-bold">{{ __('Chat') }}</span>
                        <template x-if="! open">
                            <span class="flex min-w-0 grow items-center gap-2.5">
                                <span x-show="messages.filter((m) => m.from === 'them').length > seen" class="inline-flex h-5 shrink-0 items-center rounded-[10px] bg-btc px-[7px] text-[11px] font-bold whitespace-nowrap text-on-btc"
                                      x-text="t.unread.replace(':count', Math.max(0, messages.filter((m) => m.from === 'them').length - seen))"></span>
                                <span class="min-w-0 grow truncate text-xs text-ink-2" x-text="opponentMuted ? t.mutedPeek : (messages.length ? messages[messages.length - 1].name + ': ' + messages[messages.length - 1].text : '')"></span>
                            </span>
                        </template>
                        <span x-show="open" class="grow"></span>
                        <span class="text-xs text-btc" x-text="open ? t.close : t.open"></span>
                    </span>
                </button>
                <div id="sheet-body" x-show="open" x-cloak class="flex min-h-0 grow flex-col border-t border-hairline">
                    <span class="flex items-center gap-2 px-4 pt-2">
                        <span class="min-w-0 grow text-[11px] leading-normal text-ink-3">{{ __('Only you and :name see this chat.', ['name' => $opponentName]) }}</span>
                        <button type="button" x-on:click="toggleMute()" :aria-pressed="opponentMuted ? 'true' : 'false'"
                                class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3 text-xs whitespace-nowrap text-ink">
                            <x-icon name="mute" :size="16" class="shrink-0" /><span x-text="opponentMuted ? t.muted : t.mute"></span>
                        </button>
                    </span>
                    @include('pages.games.partials.chat-body', ['inputId' => 'mchat'])
                </div>
            </section>
        </div>
    @endif
</div>
