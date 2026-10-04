@props(['bar'])

{{--
    The Nostr bar (P45): the same thing on Nostr, on every page that has a
    Nostr object (player, clan, tournament, game, match, season, stream).
    One row, one panel at a time (resources/js/nostrBar.js):

    - Open in app: `nostr:` link (NIP-21), for the viewer's own Nostr app
    - Share: copy the `nostr:` link, the njump.me link or the page link
    - Message: write a DM, signed and encrypted by the viewer's signer and sent
      from the browser (NIP-17, NIP-04 as fallback); the league stores nothing
    - Zap: the page's LNURL QR code; a Lightning address is never text
    - Follow: shows what changes in the viewer's follow list (kind 3), signs
      only on the panel's button, refuses when the list cannot be read

    Message and Follow only for a signed-in viewer, never for their own key
    ({@see \App\Support\Nostr\NostrBar::personForViewer()}). Every target is
    44 px; on a phone the buttons show their icon, the name is the label.
--}}
@php
    /** @var \App\Support\Nostr\NostrBar $bar */
    $person = $bar->personForViewer();
    $viewer = auth()->user();
    $njump = $bar->entity === null ? null : 'https://njump.me/'.$bar->entity;
    $name = $person['name'] ?? '';
    $button = 'inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink hover:bg-[#222228] hover:text-ink';
@endphp
<section aria-label="{{ __('On Nostr') }}" data-test="nostr-bar" data-context="{{ $bar->context }}" @if ($bar->entity) data-entity="{{ $bar->entity }}" @endif
         x-data="nostrBar(@js([
             'me' => $viewer?->pubkey,
             'follow' => $bar->follow ? ($person['pubkey'] ?? null) : null,
             'dm' => $bar->message ? ($person['pubkey'] ?? null) : null,
             'relays' => \App\Support\Nostr\NostrBar::browserRelays(),
             'labels' => [
                 'signer' => \App\Support\Nostr\SignerMessages::labels(),
                 'failed' => __('That did not work. Please try again.'),
                 'followNotRead' => __('Your follow list could not be read from all your relays (:answered of :asked answered). Nothing was changed. Please try again later.'),
                 'followNoRelayList' => __('No relay list of yours (NIP-65, kind 10002) was found, so it is unknown where your follow list lives. Nothing was changed. Publish a relay list in your Nostr app first.'),
                 'followChanged' => __('Your follow list changed in the meantime. Here is the new state; nothing was sent.'),
                 'followAlready' => __('You already follow :name.', ['name' => $name]),
                 'dm_no_encryption' => __('Your signer cannot encrypt messages (neither NIP-44 nor NIP-04).'),
                 'dm_no_dm_relays' => __(':name has no relays for direct messages, and your signer has no NIP-04 to reach them otherwise.', ['name' => $name]),
                 'dm_no_nip44' => __("Your signer can't send private messages the modern way — update it or use one that supports NIP-44."),
                 'dm_empty' => __('Write a message first.'),
                 'dm_self' => __('That is your own key.'),
             ],
         ]))"
         x-on:keydown.escape="close()"
         {{ $attributes->class('flex min-w-0 flex-col rounded-lg bg-card px-3 py-2 lg:px-4') }}>
    <div class="flex min-w-0 flex-wrap items-center gap-1.5 sm:gap-2">
        <span class="mr-auto flex min-h-11 items-center gap-1.5 text-xs font-bold tracking-wide text-ink-2 uppercase">
            <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" class="shrink-0 text-[#a86ef0]"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.5"></circle><circle cx="12" cy="12" r="3" fill="currentColor"></circle></svg>
            {{-- "Nostr" alone on a phone, so five buttons still fit the row at 375 px. --}}
            <span class="max-sm:hidden">{{ __('On Nostr') }}</span><span class="sm:hidden">Nostr</span>
        </span>

        @if ($bar->entity)
            <a href="nostr:{{ $bar->entity }}" data-test="nostr-open" class="{{ $button }}" aria-label="{{ __('Open in your Nostr app') }}" title="{{ __('Open in your Nostr app') }}">
                <x-icon name="expand" :size="16" class="shrink-0" /><span class="max-sm:sr-only">{{ __('Open in app') }}</span>
            </a>
        @endif

        <button type="button" data-test="nostr-share" class="{{ $button }}" x-on:click="toggle('share')" :aria-expanded="panel === 'share'" aria-controls="nostr-panel-share" aria-label="{{ __('Share') }}">
            <x-icon name="link" :size="16" class="shrink-0" /><span class="max-sm:sr-only">{{ __('Share') }}</span>
        </button>

        @if ($bar->message && $person)
            <button type="button" data-test="nostr-message" class="{{ $button }}" x-on:click="toggle('dm')" :aria-expanded="panel === 'dm'" aria-controls="nostr-panel-dm" aria-label="{{ __('Message :name', ['name' => $name]) }}">
                <x-icon name="chat" :size="16" class="shrink-0" /><span class="max-sm:sr-only">{{ __('Message') }}</span>
            </button>
        @endif

        @if ($bar->zapQr)
            <button type="button" data-test="nostr-zap" class="{{ $button }}" x-on:click="toggle('zap')" :aria-expanded="panel === 'zap'" aria-controls="nostr-panel-zap" aria-label="{{ __('Zap') }}">
                <x-icon name="bolt" :size="16" class="shrink-0 text-bolt" /><span class="max-sm:sr-only">{{ __('Zap') }}</span>
            </button>
        @endif

        @if ($bar->follow && $person)
            <button type="button" data-test="nostr-follow" class="{{ $button }}" x-on:click="toggle('follow')" :aria-expanded="panel === 'follow'" aria-controls="nostr-panel-follow" aria-label="{{ __('Follow :name', ['name' => $name]) }}">
                <x-icon name="user" :size="16" class="shrink-0" /><span class="max-sm:sr-only">{{ __('Follow') }}</span>
            </button>
        @endif
    </div>

    {{-- Share --}}
    <div id="nostr-panel-share" x-show="panel === 'share'" x-cloak class="flex min-w-0 flex-col gap-2 border-t border-hairline py-3" data-test="nostr-share-panel">
        @if ($bar->entity)
            <div class="flex min-w-0 items-center gap-2">
                <span class="min-w-0 grow truncate font-mono text-xs text-ink-2" title="nostr:{{ $bar->entity }}">nostr:{{ $bar->entity }}</span>
                <button type="button" class="{{ $button }}" data-test="nostr-copy-uri" x-on:click="copy(@js('nostr:'.$bar->entity), 'uri')">
                    <x-icon name="copy" :size="16" x-show="copied !== 'uri'" /><x-icon name="check" :size="16" x-show="copied === 'uri'" x-cloak class="text-btc" /><span class="max-sm:sr-only">{{ __('Copy nostr: link') }}</span>
                </button>
            </div>
            <div class="flex min-w-0 items-center gap-2">
                <a href="{{ $njump }}" rel="noopener noreferrer" target="_blank" class="min-h-11 min-w-0 grow content-center truncate text-xs" data-test="nostr-njump">{{ __('Open on njump.me') }}</a>
                <button type="button" class="{{ $button }}" data-test="nostr-copy-njump" x-on:click="copy(@js($njump), 'njump')">
                    <x-icon name="copy" :size="16" x-show="copied !== 'njump'" /><x-icon name="check" :size="16" x-show="copied === 'njump'" x-cloak class="text-btc" /><span class="max-sm:sr-only">{{ __('Copy njump link') }}</span>
                </button>
            </div>
        @else
            <p class="m-0 text-xs text-ink-2" data-test="nostr-no-entity">{{ __('Not on Nostr yet: the league publishes it once there is something to sign.') }}</p>
        @endif
        <div class="flex min-w-0 items-center gap-2">
            <span class="min-w-0 grow truncate text-xs text-ink-2">{{ __('Link to this page') }}</span>
            <button type="button" class="{{ $button }}" data-test="nostr-copy-page" x-on:click="copy(@js($bar->url), 'page')">
                <x-icon name="copy" :size="16" x-show="copied !== 'page'" /><x-icon name="check" :size="16" x-show="copied === 'page'" x-cloak class="text-btc" /><span class="max-sm:sr-only">{{ __('Copy link') }}</span>
            </button>
        </div>
        <span role="status" class="sr-only" x-text="copied ? @js(__('Copied')) : ''"></span>
    </div>

    {{-- Zap: the page's QR code, never an address as text --}}
    @if ($bar->zapQr)
        <div id="nostr-panel-zap" x-show="panel === 'zap'" x-cloak class="flex min-w-0 items-center gap-4 border-t border-hairline py-3" data-test="nostr-zap-panel">
            <div class="size-32 shrink-0 rounded-sm bg-white p-2 [&>img]:size-full [&>svg]:size-full" data-test="nostr-zap-qr">
                @if (str_starts_with($bar->zapQr, 'data:'))
                    <img src="{{ $bar->zapQr }}" width="112" height="112" alt="{{ __('QR code to zap') }}" class="[image-rendering:pixelated]">
                @else
                    {!! $bar->zapQr !!}
                @endif
            </div>
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Scan with a Lightning wallet or a Nostr app to zap.') }}</p>
        </div>
    @endif

    {{-- Message --}}
    @if ($bar->message && $person)
        <div id="nostr-panel-dm" x-show="panel === 'dm'" x-cloak class="flex min-w-0 flex-col gap-2 border-t border-hairline py-3" data-test="nostr-dm-panel">
            <label for="nostr-dm-text-{{ $bar->context }}" class="text-[13px]">{{ __('Message to :name', ['name' => $name]) }}</label>
            <textarea id="nostr-dm-text-{{ $bar->context }}" x-ref="dmText" x-model="dmText" rows="3" maxlength="2000" data-test="nostr-dm-text"
                      class="min-h-24 w-full min-w-0 rounded-md border border-edge bg-ground p-3 text-sm text-ink"></textarea>
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <button type="button" data-test="nostr-dm-send" x-on:click="sendDm()" x-bind:disabled="dmStep === 'sending' || dmText.trim() === ''"
                        class="btn-p inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc disabled:cursor-default disabled:opacity-60">
                    <x-icon name="send" :size="16" class="shrink-0" /><span x-text="dmStep === 'sending' ? @js(__('Sending…')) : @js(__('Sign and send'))">{{ __('Sign and send') }}</span>
                </button>
                <span class="min-w-0 basis-60 grow text-xs leading-normal text-ink-3">{{ __('Encrypted and signed by your own signer, sent from this browser to :name\'s relays. The league does not store it.', ['name' => $name]) }}</span>
            </div>
            <p role="status" class="m-0 text-xs text-win" x-show="dmStep === 'sent' && dmFormat === 'nip17'" x-cloak data-test="nostr-dm-sent">{{ __('Sent, end-to-end encrypted (NIP-17).') }}</p>
            {{-- P45 audit F3: the older format only after the sender agreed, and only for a recipient without a DM relay list (P1, 2026-10-04). --}}
            <div class="flex min-w-0 flex-col gap-2 rounded-md bg-well px-3 py-3" x-show="dmStep === 'confirm'" x-cloak data-test="nostr-dm-confirm" role="alertdialog" aria-labelledby="nostr-dm-confirm-text-{{ $bar->context }}">
                <p id="nostr-dm-confirm-text-{{ $bar->context }}" class="m-0 text-xs leading-normal text-ink">
                    <span x-show="dmConfirm === 'no_dm_relays'">{{ __('Every relay asked answered, and :name has no DM relay list, so NIP-17 cannot reach them. Send it as an older NIP-04 DM? Relays then see who wrote to whom and when, not what.', ['name' => $name]) }}</span>
                </p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" data-test="nostr-dm-send-nip04" x-on:click="sendDm(true)"
                            class="btn-p inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc">{{ __('Send as NIP-04') }}</button>
                    <button type="button" class="{{ $button }}" x-on:click="dmStep = 'idle'; dmConfirm = ''">{{ __('Cancel') }}</button>
                </div>
            </div>
            <p role="status" class="m-0 text-xs text-win" x-show="dmStep === 'sent' && dmFormat === 'nip04'" x-cloak data-test="nostr-dm-sent-nip04">{{ __('Sent as an older NIP-04 DM. Relays can see who wrote to whom and when, not what.') }}</p>
            <p role="alert" class="m-0 text-xs text-loss" x-show="dmStep === 'unsent'" x-cloak>{{ __('Signed, but no relay took it. Please try again later.') }}</p>
            <p role="alert" class="m-0 text-xs text-loss" x-show="dmError" x-text="dmError" x-cloak data-test="nostr-dm-error"></p>
        </div>
    @endif

    {{-- Follow: preview first, sign only on the button --}}
    @if ($bar->follow && $person)
        <div id="nostr-panel-follow" x-show="panel === 'follow'" x-cloak class="flex min-w-0 flex-col gap-2 border-t border-hairline py-3" data-test="nostr-follow-panel" :data-step="followStep">
            <p class="m-0 text-xs text-ink-2" x-show="followStep === 'reading'">{{ __('Reading your follow list from your relays…') }}</p>
            <template x-if="followStep === 'preview' || followStep === 'signing'">
                <div class="flex min-w-0 flex-col gap-2">
                    <p class="m-0 text-[13px]" data-test="nostr-follow-preview"
                       x-text="@js(__('You follow :before accounts. After this: :after, with :name added. Nothing else changes.', ['name' => $name])).replace(':before', followInfo.before).replace(':after', followInfo.after)"></p>
                    <p class="m-0 text-xs leading-normal text-loss" x-show="followInfo.fresh && ! followInfo.newIdentity" data-test="nostr-follow-fresh">{{ __('No follow list of yours was found on your relays. This starts a new list with :name only. If you follow people in another app, check there first that its relays are yours too.', ['name' => $name]) }}</p>
                    {{-- Re-audit: no relay list and no follow list anywhere read. The real one may live elsewhere, so a new list only on the player's word. --}}
                    <p class="m-0 text-xs leading-normal text-loss" x-show="followInfo.newIdentity" role="alert" data-test="nostr-follow-new-list-warning">{{ __('We found no follow list of yours on the relays we read. Following here starts a NEW list with only this person. If you already follow people, follow from your usual client instead.') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" data-test="nostr-follow-sign" x-show="! followInfo.newIdentity" x-on:click="confirmFollow()" x-bind:disabled="followStep === 'signing'"
                                class="btn-p inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc disabled:cursor-default disabled:opacity-60">
                            <x-icon name="check" :size="16" class="shrink-0" /><span x-text="followStep === 'signing' ? @js(__('Waiting for your signer…')) : @js(__('Sign and follow'))">{{ __('Sign and follow') }}</span>
                        </button>
                        <button type="button" data-test="nostr-follow-new-list" x-show="followInfo.newIdentity" x-on:click="confirmFollow(true)" x-bind:disabled="followStep === 'signing'"
                                class="btn-p inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc disabled:cursor-default disabled:opacity-60">
                            <x-icon name="check" :size="16" class="shrink-0" /><span x-text="followStep === 'signing' ? @js(__('Waiting for your signer…')) : @js(__('Start a new list'))">{{ __('Start a new list') }}</span>
                        </button>
                        <button type="button" class="{{ $button }}" x-on:click="close()">{{ __('Cancel') }}</button>
                    </div>
                </div>
            </template>
            <p role="status" class="m-0 text-xs text-win" x-show="followStep === 'done'" x-cloak data-test="nostr-follow-done"
               x-text="@js(__('You follow :name now. Signed by you, taken by :count relays.', ['name' => $name])).replace(':count', followInfo?.published ?? 0)"></p>
            <p role="alert" class="m-0 text-xs text-loss" x-show="followStep === 'unpublished'" x-cloak>{{ __('Signed, but no relay took it. Please try again later.') }}</p>
            <p role="status" class="m-0 text-xs text-ink-2" x-show="followStep === 'following'" x-cloak data-test="nostr-follow-already">{{ __('You already follow :name.', ['name' => $name]) }}</p>
            <p role="alert" class="m-0 text-xs text-loss" x-show="followError" x-text="followError" x-cloak data-test="nostr-follow-error"></p>
        </div>
    @endif
</section>
