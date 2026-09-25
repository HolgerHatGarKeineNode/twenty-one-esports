{{-- Messages and input of the game chat, shared by the desktop panel and the mobile sheet (partials/chat). --}}
<ol aria-live="polite" class="m-0 flex min-h-0 grow list-none flex-col justify-end gap-3 overflow-y-auto px-4 py-3 text-[13px] leading-normal" data-test="chat-messages">
    <template x-for="m in messages" :key="m.id">
        <li class="flex flex-col gap-0.5" :data-from="m.from">
            <span class="text-[11px]" :class="{ 'text-btc-hi': m.from === 'me', 'text-ink-2': m.from === 'them', 'text-ink-3': m.from === 'server' }">
                <span class="whitespace-nowrap" x-text="m.name"></span> <span class="text-ink-3" x-text="'· ' + time(m.at)"></span>
            </span>
            <span class="break-words" :class="m.from === 'server' ? 'text-ink-2' : 'text-ink'" x-text="m.text"></span>
        </li>
    </template>
    <li x-show="status === 'live' && messages.length === 0" class="text-ink-3">{{ __('No messages yet. Say hello.') }}</li>
    <li x-show="status === 'starting'" class="text-ink-3">{{ __('Connecting to the chat …') }}</li>
    <li x-show="status === 'needs-signer'" class="flex flex-col items-start gap-2 text-ink-2">
        <span>{{ __('The chat is end-to-end encrypted with your Nostr key. Open it to read and write messages.') }}</span>
        <x-button variant="quiet" icon="chat" x-on:click="connect()" data-test="chat-connect">{{ __('Open chat') }}</x-button>
    </li>
    <li x-show="status === 'no-nip44'" class="text-ink-2" data-test="chat-no-nip44">{{ __('Your signer cannot encrypt messages (NIP-44), so the chat is off. A Nostr extension or signer app with NIP-44 turns it on; the game itself works as usual.') }}</li>
    <li x-show="status === 'no-relays'" class="text-ink-2">{{ __('The chat has no relay here, so it is off.') }}</li>
    <li x-show="error" class="text-loss" role="alert" x-text="error"></li>
</ol>
<form x-show="status === 'live'" x-on:submit.prevent="send()" class="flex gap-2 border-t border-hairline px-4 pt-3 pb-4">
    <label for="{{ $inputId }}" class="sr-only">{{ __('Message to :name', ['name' => $chat['opponent']['name'] ?? '']) }}</label>
    <input id="{{ $inputId }}" x-model="input" placeholder="{{ __('Message') }}" autocomplete="off" maxlength="500" data-test="chat-input"
           class="h-11 min-w-0 grow rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3 max-lg:text-sm">
    <button type="submit" aria-label="{{ __('Send message') }}" :disabled="sending" data-test="chat-send"
            class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink disabled:opacity-50"><x-icon name="send" :size="16" /></button>
</form>
