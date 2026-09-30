{{--
    The card composer of a casual 1v1 room chat (NIP "Lobby and account
    cards"), inside the roomChat() scope of pages/matches/⚡room: the Rocket
    League host shares a private match (the Age of Empires II host a lobby),
    both EA FC players may share their EA ID, both Age of Empires II players
    their Steam or Xbox name (only on Send card; never shown anywhere else).
    Everything stays in the browser: the card is sealed to the opponent
    like any chat message, the league only hears that it went out.
--}}
@php($aoe = $m->game === 'age-of-empires-2')
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
                <x-button variant="quiet" icon="user" x-on:click="openComposer('account')" ::disabled="sending" data-test="share-account"><span x-text="myOpenCard('account') ? @js($aoe ? __('Change your name') : __('Change EA ID')) : @js($aoe ? __('Share your Steam or Xbox name') : __('Share EA ID'))"></span></x-button>
                <x-button variant="quiet" icon="close" x-show="myOpenCard('account')" x-on:click="sendCard('account', true)" ::disabled="sending" data-test="withdraw-account">{{ $aoe ? __('Withdraw your name') : __('Withdraw EA ID') }}</x-button>
            </span>
        </template>
    </div>

    <form x-show="composer === 'lobby'" x-on:submit.prevent="sendCard('lobby')" class="flex flex-col gap-2" data-test="lobby-form">
        <b class="text-[13px]">{{ $m->game === 'age-of-empires-2' ? __('Age of Empires II lobby') : __('Rocket League private match') }}</b>
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
        {{-- The league's lobby rules travel in the card's text (LobbyRules, P9); shown as sent, in English. --}}
        <span x-show="(casual?.lobbyRules ?? '') !== ''" x-cloak class="text-xs break-words text-ink-3" data-test="card-lobby-rules">{{ __('Sent with the card:') }} <span x-text="casual?.lobbyRules ?? ''"></span></span>
        <span class="flex flex-wrap gap-2">
            <x-button type="submit" icon="send" ::disabled="sending" data-test="send-lobby-card">{{ __('Send card') }}</x-button>
            <x-button variant="quiet" x-on:click="composer = ''">{{ __('Cancel') }}</x-button>
        </span>
    </form>

    <form x-show="composer === 'account'" x-on:submit.prevent="sendCard('account')" class="flex flex-col gap-2" data-test="account-form">
        @if ($aoe)
            {{-- Age of Empires II: the Steam or the Xbox name, whichever the player plays on. --}}
            <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Platform') }}
                <select x-model="accountService" x-on:change="accountId = (casual?.accounts ?? {})[accountService] ?? ''" data-test="card-account-service" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink">
                    <option value="steam">Steam</option>
                    <option value="xbox">Xbox</option>
                </select>
            </label>
        @endif
        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ $aoe ? __('Name') : __('EA ID') }}
            <input x-model="accountId" maxlength="64" autocomplete="off" spellcheck="false" data-test="card-account-id" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink">
        </label>
        {{-- The prefill comes from the player's own gamer tags (settings, P51); say so, and that nothing went out yet. --}}
        <span x-show="accountId !== '' && accountId === (casual?.accounts ?? { ea: casual?.eaId })[accountService]" x-cloak class="flex items-center gap-1.5 text-xs text-ink-2" data-test="card-account-prefilled"><x-icon name="lock" :size="14" class="shrink-0" />{{ __('Filled in from your gamer tags. It is sent only when you press Send card.') }}</span>
        <span class="text-xs text-ink-3">{{ $aoe ? __('Your name goes to your opponent only, so you find each other in the game. The league never sees it.') : __('Your EA ID goes to your opponent only. The friendship stays after the match; remove it in the game if you like.') }}</span>
        <span class="flex flex-wrap gap-2">
            <x-button type="submit" icon="send" ::disabled="sending" data-test="send-account-card">{{ __('Send card') }}</x-button>
            <x-button variant="quiet" x-on:click="composer = ''">{{ __('Cancel') }}</x-button>
        </span>
    </form>

    <p x-show="cardError" x-text="cardError" class="m-0 text-xs text-loss" role="alert"></p>
</div>
