{{--
    The card composer of a casual 1v1 room chat (NIP "Lobby and account
    cards"), inside the roomChat() scope of pages/matches/⚡room: the Rocket
    League host shares a private match, both EA FC players may share their EA
    ID. Everything stays in the browser: the card is sealed to the opponent
    like any chat message, the league only hears that it went out.
--}}
<div x-show="status === 'live' && cardKinds.length > 0" x-cloak class="flex flex-col gap-3 border-t border-hairline px-4 py-3 lg:px-6" data-test="card-composer">
    <div x-show="composer === ''" class="flex flex-wrap gap-2">
        <template x-if="cardKinds.includes('lobby')">
            <span class="contents">
                <x-button variant="quiet" icon="key" x-on:click="openComposer('lobby')" ::disabled="sending" data-test="share-lobby"><span x-text="myOpenCard('lobby') ? @js(__('New lobby card')) : @js(__('Share lobby'))"></span></x-button>
                <x-button variant="quiet" icon="close" x-show="myOpenCard('lobby')" x-on:click="sendCard('lobby', true)" ::disabled="sending" data-test="close-lobby">{{ __('Close lobby') }}</x-button>
            </span>
        </template>
        <template x-if="cardKinds.includes('account')">
            <span class="contents">
                <x-button variant="quiet" icon="user" x-on:click="openComposer('account')" ::disabled="sending" data-test="share-account"><span x-text="myOpenCard('account') ? @js(__('Change EA ID')) : @js(__('Share EA ID'))"></span></x-button>
                <x-button variant="quiet" icon="close" x-show="myOpenCard('account')" x-on:click="sendCard('account', true)" ::disabled="sending" data-test="withdraw-account">{{ __('Withdraw EA ID') }}</x-button>
            </span>
        </template>
    </div>

    <form x-show="composer === 'lobby'" x-on:submit.prevent="sendCard('lobby')" class="flex flex-col gap-2" data-test="lobby-form">
        <b class="text-[13px]">{{ __('Rocket League private match') }}</b>
        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Name') }}
            <input x-model="lobbyName" maxlength="64" autocomplete="off" spellcheck="false" data-test="card-lobby-name" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink">
        </label>
        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Password') }}
            <span class="flex gap-2">
                <input x-model="lobbyPassword" maxlength="64" autocomplete="off" spellcheck="false" data-test="card-lobby-password" class="h-11 min-w-0 grow rounded-md border border-edge bg-ground px-3 font-mono text-sm text-ink">
                <button type="button" x-on:click="newPassword()" aria-label="{{ __('Suggest a new password') }}" class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="retry" :size="16" /></button>
            </span>
        </label>
        <span class="text-xs text-ink-3">{{ __('A fresh random password for every match: an old one may still sit in stored messages.') }}</span>
        <span class="flex flex-wrap gap-2">
            <x-button type="submit" icon="send" ::disabled="sending" data-test="send-lobby-card">{{ __('Send card') }}</x-button>
            <x-button variant="quiet" x-on:click="composer = ''">{{ __('Cancel') }}</x-button>
        </span>
    </form>

    <form x-show="composer === 'account'" x-on:submit.prevent="sendCard('account')" class="flex flex-col gap-2" data-test="account-form">
        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('EA ID') }}
            <input x-model="accountId" maxlength="64" autocomplete="off" spellcheck="false" data-test="card-account-id" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink">
        </label>
        <span class="text-xs text-ink-3">{{ __('Your EA ID goes to your opponent only. The friendship stays after the match; remove it in the game if you like.') }}</span>
        <span class="flex flex-wrap gap-2">
            <x-button type="submit" icon="send" ::disabled="sending" data-test="send-account-card">{{ __('Send card') }}</x-button>
            <x-button variant="quiet" x-on:click="composer = ''">{{ __('Cancel') }}</x-button>
        </span>
    </form>

    <p x-show="cardError" x-text="cardError" class="m-0 text-xs text-loss" role="alert"></p>
</div>
